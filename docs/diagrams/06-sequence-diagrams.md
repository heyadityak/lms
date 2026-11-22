# 06 — Sequence diagrams

Runtime interactions. `Web` is a Next.js replica (pages + Better Auth + BFF, D-43, D-45); browsers never hold Keycloak tokens, which live in the Postgres DB `lms_auth`. `App` is an API-only FrankenPHP replica running Laravel Octane in worker mode on `:8000` (D-27); NGINX proxies to it over plain HTTP (no FastCGI). Redis logical DBs follow D-23 (cache 1, queue 3; no sessions since D-43). Keycloak details follow D-20/D-22; API conventions follow [API_GUIDELINES.md](../API_GUIDELINES.md).

| # | Flow |
|---|---|
| 1 | Web login via the Next.js BFF (Better Auth, OIDC auth code + PKCE, session in `lms_auth`, provisioning on the first API call) |
| 2 | RP-initiated logout via the BFF |
| 3 | API call with a Keycloak Bearer JWT (JWKS cached in Redis) |
| 4 | Apply for leave: Browser → NGINX → Next BFF → NGINX → app, queue, worker, mail |
| 5 | Approve leave |
| 6 | Cancel approved leave with ledger refund |
| 7 | Scheduled year-start accrual (Redis lock, idempotent) |
| 8 | `/readyz` health check |
| 9 | Logo upload through the BFF to RustFS + theme render in Next (antd tokens) |
| 10 | API error handling and `Idempotency-Key` |
| 11 | Admin creates a user (Keycloak first, compensation on failure) |

## 1. Web login via the Next.js BFF (OIDC authorization code + PKCE)

```mermaid
sequenceDiagram
    autonumber
    actor U as User (browser)
    participant N as NGINX
    participant W as Web (Next.js + Better Auth)
    participant AU as Postgres lms_auth
    participant K as Keycloak (realm lms)
    participant A as App (FrankenPHP / Octane, API only)
    participant DB as Postgres lms

    U->>N: GET / (no session cookie)
    N->>W: proxy_pass / → web:3000
    W->>N: GET /api/v1/branding (server side, cached 30 s)
    N->>A: proxy_pass /api/ → app:8000
    A-->>W: brand + enabled modules (public)
    W-->>U: sign-in page themed with brand tokens
    Note over W,K: at startup: discovery from KEYCLOAK_BASE_URL. Keycloak (hostname v2, backchannel dynamic) returns issuer, auth and end-session on KEYCLOAK_PUBLIC_URL, token and JWKS on KEYCLOAK_BASE_URL
    U->>N: POST /auth/sign-in/social {provider keycloak} (Origin checked)
    N->>W: proxy_pass
    W->>AU: INSERT verification (state, PKCE verifier, nonce)
    W-->>U: 302 KEYCLOAK_PUBLIC_URL/realms/lms/protocol/openid-connect/auth (client lms-web, S256, nonce)
    U->>K: login page (customer upstream Keycloak, LDAP / AD / SAML broker possible)
    K-->>U: 302 APP_URL/auth/callback/keycloak?code=…&state=…
    U->>N: GET /auth/callback/keycloak
    N->>W: proxy_pass
    W->>AU: load + delete verification row (state, verifier)
    W->>K: POST token endpoint via KEYCLOAK_BASE_URL (code, verifier, lms-web secret)
    K-->>W: access_token, refresh_token, id_token
    W->>W: verify id_token (JWKS, iss, aud, nonce)
    W->>AU: upsert user mirror + account (tokens encrypted), INSERT session
    W-->>U: 302 / + Set-Cookie session token (signed) + session_data (JWE cache), HttpOnly, Secure, SameSite=Lax
    U->>N: GET / (session cookie)
    N->>W: proxy_pass
    W->>AU: account access token (session from JWE cookie cache)
    W->>N: GET /api/v1/me, Authorization Bearer (server component)
    N->>A: proxy_pass
    A->>A: module:api passes (azp = lms-web), KeycloakJwtGuard verifies RS256, iss, azp, exp
    A->>DB: KeycloakUserProvisioner: find by keycloak_sub, else link by verified email, else insert, then sync role, is_admin
    alt no base role / inactive
        A-->>W: 403 / 401 problem+json
        W->>AU: DELETE session
        W-->>U: access-denied page, session cookie cleared
    else ok
        A-->>W: 200 user
        W-->>U: dashboard HTML (antd, role- and module-aware nav)
    end
    Note over W,AU: Any web replica serves any step: OAuth state, sessions and tokens are in lms_auth, cookies are signed/encrypted with the shared BETTER_AUTH_SECRET. No sticky sessions, no in-memory state.
```

## 2. RP-initiated logout via the BFF

```mermaid
sequenceDiagram
    autonumber
    actor U as User (browser)
    participant N as NGINX
    participant W as Web (Next.js BFF + Better Auth)
    participant AU as Postgres lms_auth
    participant K as Keycloak

    U->>N: POST /bff/logout (session cookie, Origin header)
    N->>W: proxy_pass
    W->>W: Origin = APP_URL origin? else 403
    W->>AU: auth.api.signOut: read account id_token, DELETE session
    W-->>U: 303 KEYCLOAK_PUBLIC_URL/…/logout?id_token_hint=…&post_logout_redirect_uri=APP_URL/&client_id=lms-web + cookies cleared
    U->>K: end-session request
    K->>K: end SSO session, revoke refresh tokens
    K-->>U: 302 APP_URL/ (signed-out, sign-in page)
```

## 3. API call with a Keycloak Bearer JWT

```mermaid
sequenceDiagram
    autonumber
    participant C as API client
    participant K as Keycloak
    participant N as NGINX
    participant A as App (Octane)
    participant G as KeycloakJwtGuard
    participant R as Redis (cache DB 1)
    participant DB as Postgres

    C->>K: auth code + PKCE (client lms-api-cli, loopback redirect)
    K-->>C: access_token (RS256 JWT, 5 min)
    C->>N: GET /api/v1/me/balances, Authorization Bearer
    N->>A: proxy_pass
    A->>A: middleware module:api (404 problem if off, but azp = lms-web, the BFF, always passes)
    A->>G: auth:keycloak-jwt
    G->>R: get keycloak:jwks
    alt cache miss or unknown kid
        G->>R: lock keycloak:jwks-refresh
        G->>K: GET /realms/lms/protocol/openid-connect/certs (KEYCLOAK_BASE_URL)
        K-->>G: JWKS
        G->>R: put keycloak:jwks TTL KEYCLOAK_JWKS_CACHE_TTL
    end
    G->>G: verify signature, iss = KEYCLOAK_PUBLIC_URL/realms/lms, aud/azp allowed, exp/nbf
    alt invalid token
        G-->>C: 401 application/problem+json UNAUTHENTICATED, WWW-Authenticate Bearer
    else valid
        G->>DB: KeycloakUserProvisioner sync by sub (role from realm_access.roles)
        G->>G: is_active? else 401
        A->>R: throttle:api (rate limiter, DB 1)
        A->>DB: LeaveBalanceService summary(user, LeaveYear current)
        A-->>C: 200 {data, meta, links} + X-RateLimit-Limit, X-RateLimit-Remaining
    end
```

## 4. Apply for leave: Browser → NGINX → Next BFF → NGINX → app

```mermaid
sequenceDiagram
    autonumber
    actor E as Employee (browser)
    participant N as NGINX
    participant W as Web (Next.js BFF)
    participant AU as Postgres lms_auth
    participant K as Keycloak
    participant A as App (FrankenPHP / Octane)
    participant MW as Middleware (module:api, auth:keycloak-jwt, active, throttle, idempotent)
    participant AC as SubmitLeaveRequest
    participant DB as Postgres
    participant R as Redis (cache DB 1, queue DB 3)
    participant Wk as Worker (queue:work)
    participant M as SMTP

    E->>N: POST /bff/api/leave-requests (cookie, Origin, Idempotency-Key, JSON)
    N->>W: proxy_pass / → web:3000
    W->>W: Origin check, session from JWE cookie cache
    W->>AU: account tokens for the session
    opt access token expires within 60 s
        W->>K: refresh_token grant (KEYCLOAK_BASE_URL)
        K-->>W: new access + refresh token (rotated)
        W->>AU: UPDATE account tokens
    end
    W->>N: POST LMS_API_URL/api/v1/leave-requests, Authorization Bearer, Idempotency-Key, X-Forwarded-For
    N->>A: proxy_pass /api/ → app:8000
    A->>MW: azp = lms-web passes module:api, verify JWT, active, throttle, idempotency slot in R
    MW->>AC: StoreLeaveRequestRequest validated, policy create
    AC->>DB: BEGIN, SELECT users FOR UPDATE (requester)
    AC->>AC: V1..V8, WorkingDayCalculator days
    AC->>DB: INSERT leave_requests pending + leave_request_actions submitted, COMMIT
    AC->>R: afterCommit dispatch LeaveRequestSubmitted (if notifications on)
    alt rule violated
        A-->>W: 422 problem+json (code, errors)
    else ok
        A-->>W: 201 {data} + Location + X-RateLimit-*
    end
    W-->>E: same status, body and allow-listed headers
    E->>E: TanStack Query invalidates balances and requests, problem errors mapped to form fields
    Wk->>R: pop job
    Wk->>DB: load request, approver(s)
    Wk->>M: mail "[Brand] Leave request from Alice" with link APP_URL/leave-requests/ID
```

## 5. Approve leave

```mermaid
sequenceDiagram
    autonumber
    actor B as Manager (browser)
    participant A as App (via NGINX)
    participant P as LeaveRequestPolicy
    participant AC as ApproveLeaveRequest
    participant DB as Postgres
    participant R as Redis queue

    B->>A: POST /leave-requests/ID/approve (comment)
    A->>P: decide(bob, request)
    P-->>A: allowed (direct manager, not own)
    A->>AC: handle(request, bob, comment)
    AC->>DB: BEGIN, SELECT leave_requests FOR UPDATE
    alt status is not pending
        AC-->>A: InvalidTransition
        A-->>B: 409 "Request is no longer pending"
    else pending
        AC->>DB: re-check overlap and balance excluding this request
        AC->>DB: INSERT leave_ledger_entries debit −days (if tracks_balance)
        AC->>DB: UPDATE status approved, decided_by, decided_at
        AC->>DB: INSERT leave_request_actions approved
        AC->>DB: COMMIT
        AC->>R: afterCommit LeaveRequestDecided to requester
        A-->>B: 302 approvals inbox
    end
```

## 6. Cancel an approved leave with ledger refund

```mermaid
sequenceDiagram
    autonumber
    actor X as Requester or HR admin
    participant A as App (via NGINX)
    participant P as LeaveRequestPolicy
    participant AC as CancelLeaveRequest
    participant DB as Postgres
    participant R as Redis queue

    X->>A: POST /leave-requests/ID/cancel
    A->>P: cancel(actor, request)
    alt requester and start_date ≤ today
        P-->>X: 403 (HR can still cancel)
    else allowed
        A->>AC: handle(request, actor)
        AC->>DB: BEGIN, SELECT … FOR UPDATE
        AC->>AC: LeaveStatus approved canTransitionTo cancelled
        AC->>DB: INSERT ledger reversal +days (unique per request)
        AC->>DB: UPDATE status cancelled, cancelled_at
        AC->>DB: INSERT leave_request_actions cancelled
        AC->>DB: COMMIT
        AC->>R: afterCommit LeaveRequestCancelled to the other party
        A-->>X: 200 / redirect, balance restored
    end
```

## 7. Scheduled year-start accrual (idempotent)

```mermaid
sequenceDiagram
    autonumber
    participant S1 as Scheduler replica 1
    participant S2 as Scheduler replica 2
    participant R as Redis (cache DB 1, locks)
    participant J as AccrueYear / RunYearStartAccrual
    participant DB as Postgres

    Note over S1,S2: Day 1 of LEAVE_YEAR_START_MONTH, 00:10 APP_TIMEZONE
    S1->>R: onOneServer lock (SET NX, expiry)
    S2->>R: onOneServer lock (SET NX)
    R-->>S1: acquired
    R-->>S2: not acquired, skip
    S1->>R: withoutOverlapping mutex
    S1->>J: lms:accrue-year (year = LeaveYear current)
    loop each active user with date_of_joining
        J->>DB: BEGIN
        loop each active tracked leave type
            J->>DB: closing = SUM(days) year−1
            J->>DB: INSERT … ON CONFLICT DO NOTHING lapse (year−1)
            J->>DB: INSERT … ON CONFLICT DO NOTHING carry_forward (year)
            J->>DB: INSERT … ON CONFLICT DO NOTHING grant (clipped to max_balance)
        end
        J->>DB: COMMIT
    end
    J-->>S1: AccrualSummary (rows written / skipped), JSON log line
    S1->>R: release locks
    Note over J,DB: A re-run (manual one-off or retry) writes 0 rows: partial unique index (user, type, year, kind).
```

## 7a. Daily pending-approval reminder (UC-S4)

```mermaid
sequenceDiagram
    autonumber
    participant S1 as Scheduler replica 1
    participant S2 as Scheduler replica 2
    participant R as Redis cache DB 1 locks
    participant J as RemindPendingApprovals
    participant DB as Postgres
    participant Q as Redis queue

    Note over S1,S2: Every day at 08:00 APP_TIMEZONE
    S1->>R: onOneServer lock
    S2->>R: onOneServer lock
    R-->>S1: acquired
    R-->>S2: not acquired, skip
    S1->>R: withoutOverlapping mutex
    S1->>J: lms:remind-pending-approvals
    alt notifications module off
        J-->>S1: queued 0, no rows written
    else module on
        loop each active manager or hr_admin, ascending users.id
            J->>DB: count LeaveRequestQuery approvableBy
            alt count is 0
                Note over J,DB: no row, a later run today can still mail them
            else count greater than 0
                J->>DB: BEGIN
                J->>DB: INSERT approval_reminders ON CONFLICT DO NOTHING
                alt one row inserted
                    J->>DB: COMMIT
                    J->>Q: afterCommit PendingApprovalsReminder with that count
                else already reminded today
                    J->>DB: COMMIT
                end
            end
        end
    end
    S1->>R: release locks
```

The lock only stops two schedulers at once. The unique `(user_id, reminded_on)` stops the second mail. Queue retries deliver the same job. They do not insert another row.

## 8. Readiness check `/readyz`

```mermaid
sequenceDiagram
    autonumber
    participant O as Orchestrator / LB
    participant A as App (Octane :8000)
    participant DB as Postgres
    participant R as Redis

    O->>A: GET /readyz (no session, no auth)
    A->>DB: select 1, migrations table exists
    A->>R: PING
    alt both ok
        A-->>O: 200 {"status":"ready","checks":{"db":"ok","redis":"ok"}}
    else any failure
        A-->>O: 503 {"status":"unavailable","checks":{…:"fail"}}
        O->>O: remove replica from rotation (no restart)
    end
    Note over O,A: /healthz is liveness only (no I/O). Keycloak, S3 and SMTP are not checked, so their outages never restart app replicas. The image HEALTHCHECK curls :8000/healthz.
```

## 9. Logo upload through the BFF and theme render in Next

```mermaid
sequenceDiagram
    autonumber
    actor H as Admin (role admin)
    participant W as Web (Next.js BFF, via NGINX)
    participant A as App (via NGINX)
    participant AC as UpdateBranding
    participant S3 as RustFS (S3-compatible, BRANDING_DISK=s3)
    participant DB as Postgres (settings)
    participant R as Redis (cache)
    actor V as Any user

    H->>W: PUT /bff/api/settings/branding/assets/logo (multipart, Origin)
    W->>A: PUT /api/v1/settings/branding/assets/logo, Bearer (body streamed)
    A->>A: policy manageBranding, png/jpg/webp ≤ 1 MB, ≤ 1024 px, no SVG
    A->>AC: handle(logo)
    AC->>S3: putObject logo-sha1.png (private bucket)
    AC->>DB: upsert settings brand.logo_path
    AC->>S3: delete previous logo object
    AC->>R: forget settings:all (after commit)
    A-->>W: 200 {data: Branding}
    W->>W: revalidateTag('branding') on this replica
    W-->>H: 200, admin screen re-renders
    V->>W: GET /dashboard (any web replica)
    W->>A: GET /api/v1/branding (cached ≤ BRANDING_REVALIDATE_SECONDS per replica)
    A->>R: Settings get (miss → DB, cache)
    A-->>W: name, colours, contrast, logo_url, favicon_url, modules
    W->>W: validate hex, ConfigProvider theme token (cssVar) + generateMetadata title/icon
    W-->>V: HTML with antd CSS variables, logo img APP_URL/branding/logo?v=sha1
    V->>A: GET /branding/logo?v=sha1 (NGINX → app)
    A->>S3: stream object
    A-->>V: image, Cache-Control public max-age=300, ETag
```

## 10. API error handling and `Idempotency-Key`

```mermaid
sequenceDiagram
    autonumber
    participant C as API client
    participant A as App (Octane)
    participant I as EnsureIdempotency
    participant R as Redis (cache DB 1)
    participant AC as SubmitLeaveRequest
    participant H as Exception handler (problem+json)

    C->>A: POST /api/v1/leave-requests, Idempotency-Key K1, body B
    A->>I: handle
    I->>R: SET NX idem:alice:K1 {processing, hash(B)} EX 86400
    R-->>I: OK (first time)
    I->>AC: next()
    alt business rule fails (e.g. overlap)
        AC-->>H: LeaveRuleViolation LEAVE_OVERLAP
        H-->>I: 422 application/problem+json {type, title, status, detail, instance, code, errors}
        I->>R: store 422 response for K1
        I-->>C: 422 problem
    else created
        AC-->>I: 201 {data}
        I->>R: store 201 response for K1
        I-->>C: 201 + Location + X-RateLimit-*
    end
    Note over C,A: Network drops, client retries
    C->>A: POST same K1, same body B
    I->>R: GET idem:alice:K1
    R-->>I: stored response, hash matches
    I-->>C: replay original status and body + Idempotent-Replayed: true
    C->>A: POST K1 with different body B2
    I-->>C: 422 IDEMPOTENCY_KEY_REUSED
    C->>A: POST K1 while the first is still processing
    I-->>C: 409 IDEMPOTENCY_IN_PROGRESS + Retry-After 1
    Note over A,H: Other mappings: no/bad JWT 401, policy 403, module off 404, InvalidTransition 409, throttle 429 + Retry-After, Keycloak down 503 IDP_UNAVAILABLE. 5xx releases the key.
```

## 11. Admin creates a user (Keycloak first, compensation)

```mermaid
sequenceDiagram
    autonumber
    actor H as Admin (dave, role admin)
    participant A as App (Octane)
    participant AC as CreateUserAction
    participant KC as KeycloakAdminClient (HTTP)
    participant R as Redis (cache)
    participant K as Keycloak Admin REST API
    participant DB as Postgres

    H->>A: POST /api/v1/admin/users (Idempotency-Key, username, email, names, role, LMS fields)
    A->>A: module user_management, realm role admin, StoreIdentityUserRequest
    A->>AC: handle(data, dave)
    AC->>KC: createUser(rep)
    KC->>R: get keycloak:admin-token
    alt token missing or near expiry
        KC->>K: POST token (client_credentials, lms-admin)
        K-->>KC: access_token
        KC->>R: cache until expires_in − 30 s
    end
    KC->>K: POST /admin/realms/lms/users (enabled, no credentials)
    alt 409 duplicate username or email
        K-->>KC: 409
        KC-->>AC: IdpConflict
        AC->>DB: INSERT user_admin_audits create failed IDP_CONFLICT
        A-->>H: 409 problem IDP_CONFLICT
    else 5xx or timeout
        KC-->>AC: IdpUnavailable
        AC->>DB: INSERT user_admin_audits create failed IDP_UNAVAILABLE
        A-->>H: 503 problem IDP_UNAVAILABLE + Retry-After
    else 201 Location …/users/KC_ID
        KC->>K: POST …/users/KC_ID/role-mappings/realm [base role, + admin if is_admin]
        AC->>DB: BEGIN, INSERT users (keycloak_sub = KC_ID, LMS fields), grant if joining date
        alt local insert fails (e.g. constraint)
            AC->>DB: ROLLBACK
            AC->>KC: deleteUser(KC_ID) compensation
            KC->>K: DELETE /admin/realms/lms/users/KC_ID
            AC->>DB: INSERT user_admin_audits compensate_delete
            A-->>H: 422 / 500 problem, no orphan anywhere
        else ok
            AC->>DB: INSERT user_admin_audits create success, COMMIT
            AC->>KC: afterCommit executeActionsEmail(KC_ID, [UPDATE_PASSWORD, VERIFY_EMAIL])
            KC->>K: PUT …/users/KC_ID/execute-actions-email
            A-->>H: 201 IdentityUser
        end
    end
```

If the compensation delete itself fails (Keycloak went down mid-flight), the audit row records `compensate_delete` with outcome `failed` and the Keycloak id, so an admin can clean up; the next login of that identity would otherwise be provisioned normally.
