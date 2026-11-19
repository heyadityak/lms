# 02 — Data flow diagrams (DFD)

Gane–Sarson style drawn with Mermaid flowcharts:

| Symbol | Meaning | Mermaid shape |
|---|---|---|
| Rectangle | External entity (outside the system) | `[ ]` |
| Circle | Process, numbered `P1…` | `(( ))` |
| Double-bar box | Data store, numbered `D1…` | `[[ ]]` |
| Arrow label | Data that flows | edge text |

Business rules come from [USE_CASES.md](../USE_CASES.md); tables from [SYSTEM_DESIGN.md](../SYSTEM_DESIGN.md) §4; Redis layout from D-23; Keycloak from D-20/D-22.

## Data stores

| Store | Backing | Tables / keys | Written by |
|---|---|---|---|
| D1 Users | Postgres | `users` (mirror of Keycloak identity + LMS fields) | P1, P10, P11 |
| D2 Departments | Postgres | `departments` | P10 |
| D3 Leave types | Postgres | `leave_types` | P10 |
| D4 Holidays | Postgres | `holidays` | P10 |
| D5 Leave requests | Postgres | `leave_requests` | P2, P3, P4 |
| D6 Request actions (audit) | Postgres | `leave_request_actions` | P2, P3, P4 |
| D7 Leave ledger | Postgres | `leave_ledger_entries` (append-only) | P5 only |
| D8 Settings | Postgres | `settings` (`brand.*`, `modules.disabled`, `work.weekend_days`) | P9 |
| D9 Failed jobs | Postgres | `failed_jobs` | P7 |
| D10 Branding assets | RustFS (S3-compatible), `BRANDING_DISK=s3` | `logo-{sha1}.{ext}`, `favicon-{sha1}.{ext}` | P9 |
| D11 Web session (browser cookie) | Encrypted Auth.js cookie held by the browser, readable only by `web` (`AUTH_SECRET`); no server store since D-43 | access, refresh and id token, `expires_at` | P1 |
| D12 Cache, locks, rate limits | Redis DB 1 | `settings:all`, `keycloak:jwks`, `keycloak:admin-token`, `idem:{user}:{key}`, schedule / guard locks, `throttle:*` | P1, P2, P6, P9, P11 |
| D13 Queue | Redis DB 3 | `queues:default` (notification jobs) | P2, P3, P4 → read by P7 |
| D14 User-admin audit | Postgres | `user_admin_audits` (append-only) | P11 |
| Config (not a store) | env | `MODULES_ENABLED`, `APP_BRAND_NAME`, `BRAND_*`, `LMS_WEEKEND_DAYS`, `LEAVE_YEAR_START_MONTH`, `KEYCLOAK_*` | operator at deploy |

## Level 0: context

```mermaid
flowchart LR
    EMP[Employee]
    MGR[Manager]
    HR[HR Admin]
    ADM[Admin]
    API[API client]
    OP[Operator / env]
    KC[Keycloak IdP]
    MAIL[SMTP server]
    CLK[Clock / scheduler tick]

    LMS((0<br/>Leave Management<br/>System))

    EMP -->|leave application, cancel| LMS
    LMS -->|balances, request status,<br/>holiday calendar| EMP
    MGR -->|approve / reject + comment| LMS
    LMS -->|approval inbox, team balances| MGR
    HR -->|departments, leave types, holidays,<br/>working week, adjustments,<br/>users' HR fields| LMS
    ADM -->|branding, modules,<br/>identity users + roles| LMS
    LMS -->|reports CSV, all requests| HR
    API -->|Bearer JWT + JSON leave ops| LMS
    LMS -->|JSON / problem+json| API
    KC -->|ID / access tokens, JWKS,<br/>realm roles, admin API results| LMS
    LMS -->|auth redirects, code exchange,<br/>admin API writes: users, roles,<br/>enable, execute-actions email| KC
    OP -->|licensed modules, brand defaults,<br/>leave-year start, secrets| LMS
    CLK -->|year-start trigger| LMS
    LMS -->|leave notification mails| MAIL
```

Password, MFA and account mails are sent by Keycloak itself, not by the LMS.

## Level 1a: identity and leave lifecycle

```mermaid
flowchart LR
    EMP[Employee / API client]
    MGR[Manager / HR Admin]
    KC[Keycloak IdP]
    MAIL[SMTP server]

    P1((P1<br/>Authenticate<br/>+ provision))
    P2((P2<br/>Apply leave))
    P3((P3<br/>Approve / reject))
    P4((P4<br/>Cancel))
    P5((P5<br/>Balance ledger))
    P7((P7<br/>Notifications))

    D1[[D1 users]]
    D3[[D3 leave_types]]
    D4[[D4 holidays]]
    D5[[D5 leave_requests]]
    D6[[D6 leave_request_actions]]
    D7[[D7 leave_ledger_entries]]
    D8[[D8 settings]]
    D9[[D9 failed_jobs]]
    D11[[D11 Web session cookie<br/>encrypted, web only]]
    D12[[D12 Redis cache / JWKS /<br/>idempotency / throttle]]
    D13[[D13 Redis queue]]

    EMP -->|browser via web BFF / Bearer JWT| P1
    KC -->|"code → tokens (web BFF), JWKS"| P1
    P1 -->|cached JWKS| D12
    P1 -->|upsert by keycloak_sub,<br/>sync name, email, role, is_admin| D1
    P1 -->|"tokens (web BFF only)"| D11
    P1 -->|authenticated user| P2

    EMP -->|type, dates, day_part, reason,<br/>Idempotency-Key| P2
    D12 -->|stored response if key replayed| P2
    D3 -->|quota, allow_half_day,<br/>max_backdate_days, tracks_balance| P2
    D4 -->|holiday dates if module on| P2
    D8 -->|modules.disabled,<br/>work.weekend_days| P2
    D5 -->|overlaps, pending days| P2
    P5 -->|available balance| P2
    P2 -->|request status=pending, days| D5
    P2 -->|action submitted| D6
    P2 -->|response for key, 24 h| D12
    P2 -->|LeaveRequestSubmitted job| D13

    MGR -->|decision + comment| P3
    D5 -->|pending request, locked| P3
    D1 -->|requester's manager_id, role| P3
    P5 -->|available balance re-check| P3
    P3 -->|status, decided_by, decided_at,<br/>decision_comment| D5
    P3 -->|action approved / rejected| D6
    P3 -->|debit −days| P5
    P3 -->|LeaveRequestDecided job| D13

    EMP -->|cancel| P4
    MGR -->|HR override cancel| P4
    D5 -->|status, start_date| P4
    P4 -->|status=cancelled, cancelled_at| D5
    P4 -->|action cancelled| D6
    P4 -->|reversal +days if it was approved| P5
    P4 -->|LeaveRequestCancelled job| D13

    P5 -->|append row| D7
    D7 -->|SUM days per user, type, year| P5
    P5 -->|entitled / used / pending / available| EMP

    D13 -->|job payload| P7
    D8 -->|brand.name, modules.disabled| P7
    P7 -->|mail| MAIL
    P7 -->|after 3 tries| D9
```

## Level 1b: administration and system jobs

```mermaid
flowchart LR
    HR[HR Admin]
    ADM[Admin]
    OP[Operator / env]
    CLK[Scheduler tick]
    BR[Any browser]
    KC[Keycloak Admin API]

    P5((P5<br/>Balance ledger))
    P6((P6<br/>Year-start accrual<br/>+ carry-forward))
    P8((P8<br/>Reports / CSV))
    P9((P9<br/>Branding, module +<br/>working-week settings))
    P10((P10<br/>Master data:<br/>depts, types,<br/>holidays, LMS user fields))
    P11((P11<br/>Identity user admin<br/>module user_management))

    D1[[D1 users]]
    D2[[D2 departments]]
    D3[[D3 leave_types]]
    D4[[D4 holidays]]
    D5[[D5 leave_requests]]
    D7[[D7 leave_ledger_entries]]
    D8[[D8 settings]]
    D10[[D10 RustFS branding assets]]
    D12[[D12 Redis cache + locks]]
    D14[[D14 user_admin_audits]]

    CLK -->|day 1 of LEAVE_YEAR_START_MONTH, 00:10| P6
    HR -->|lms:accrue-year YEAR one-off| P6
    P6 -->|onOneServer + withoutOverlapping lock| D12
    D1 -->|active users, date_of_joining| P6
    D3 -->|annual_quota, carry_forward_cap,<br/>max_balance| P6
    D7 -->|closing balance year−1| P6
    P6 -->|lapse, carry_forward, grant| P5
    P5 -->|append| D7

    HR -->|dept, type, holiday CRUD,<br/>manager, joining date, deactivate,<br/>adjustment| P10
    P10 --> D2
    P10 --> D3
    P10 --> D4
    P10 -->|LMS fields, is_active| D1
    P10 -->|first joining date → prorated grant,<br/>adjustment ± days| P5
    P10 -->|deactivate: cancel pending| D5

    ADM -->|create, profile, enable/disable,<br/>roles, password reset| P11
    P11 -->|admin token, admin guard lock,<br/>idempotency| D12
    P11 -->|REST writes as lms-admin| KC
    KC -->|user id, federationLink,<br/>409 / 5xx errors| P11
    P11 -->|503 IDP_UNAVAILABLE if KC down,<br/>no local fallback| ADM
    P11 -->|mirror: keycloak_sub, name,<br/>email, role, is_admin, is_active| D1
    P11 -->|every action + outcome| D14
    P11 -->|prorated grant if joining date set| P5

    HR -->|filters: dates, dept, type, status, year| P8
    D5 --> P8
    D7 --> P8
    D1 --> P8
    D2 --> P8
    P8 -->|JSON page / CSV stream| HR

    OP -->|APP_BRAND_NAME, BRAND_*,<br/>MODULES_ENABLED licensed set| P9
    ADM -->|name, colours, logo, favicon,<br/>modules to disable| P9
    HR -->|weekend days| P9
    P9 -->|override rows| D8
    P9 -->|validated PNG / JPEG / WebP| D10
    P9 -->|forget settings:all| D12
    D8 -->|effective settings| P9
    D10 -->|asset stream /branding/logo| BR
    P9 -->|CSS vars, brand object| BR
```

Notes:
- **P5 is the only writer of D7**, and it only appends. Balance is always derived (`SUM(days)`), never stored (D-02).
- P1 never sees a password. It receives tokens from Keycloak, verifies them against the cached JWKS (D12), and mirrors identity into D1 (`keycloak_sub`, name, email, role, is_admin).
- P11 writes **Keycloak first**, then D1. If the D1 write fails after a create, P11 deletes the Keycloak user again (compensation) and records it in D14.
- P9 never writes env. A module can be disabled only if it is licensed (D-14). `work.weekend_days` changes apply to new submissions only; stored `days` in D5 are never recalculated (D-30).
- P11 reads Keycloak live; when Keycloak is down it answers 503 `IDP_UNAVAILABLE` and never serves D1 as a stale list (D-32).
- `holidays` off ⇒ D4 is not read by P2 (D-17). `notifications` off ⇒ nothing is pushed to D13. `user_management` off ⇒ P11 does not exist; HR still edits LMS fields via P10.
