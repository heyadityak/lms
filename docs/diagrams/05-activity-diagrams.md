# 05 — Activity diagrams

UML activity diagrams drawn as Mermaid flowcharts. **Swimlanes are subgraphs** (one per actor or layer). Rounded boxes are actions, diamonds are decisions, stadiums are start/end. Rule ids (V1–V9, G1–G7) refer to [USE_CASES.md](../USE_CASES.md) §3.4 and §3.11; error codes to [API_GUIDELINES.md](../API_GUIDELINES.md) §4.

## 1. Apply for leave (UC-E5)

```mermaid
flowchart TB
    subgraph EMP["Employee (browser / API client)"]
        s([Start]) --> f["Fill type, dates, day part, reason"]
        f --> send["Submit form / POST /api/v1/leave-requests<br/>optional Idempotency-Key"]
        ok([See request pending])
        err([See 422 problem with code])
    end

    subgraph MW["Middleware"]
        m1{"module api on?<br/>(API only)"}
        m2{"JWT valid (via BFF or API client)<br/>and user active?"}
        m3{"Idempotency-Key<br/>seen before?"}
        replay["Replay stored response<br/>Idempotent-Replayed: true"]
    end

    subgraph APP["SubmitLeaveRequest (DB transaction, users row locked)"]
        v0{"Form Request valid?<br/>V9 reason, dates are dates"}
        v1{"V1 type exists<br/>and active?"}
        v2{"V2 start ≥ today − max_backdate_days?"}
        v3{"V3 end ≥ start and same<br/>leave year (LEAVE_YEAR_START_MONTH)?"}
        v4{"V4 half day only if one date<br/>and type allows it?"}
        cnt["WorkingDayCalculator: drop weekends<br/>(work.weekend_days ?? LMS_WEEKEND_DAYS),<br/>drop holidays only if module holidays on,<br/>half day = 0.5"]
        v5{"V5 days > 0?"}
        v6{"V6 no pending / approved<br/>request overlaps?"}
        v7{"V7 tracks_balance?<br/>days ≤ balance − pending"}
        v8{"V8 approver exists?<br/>manager, else other hr_admin"}
        ins["Insert leave_requests status=pending, days<br/>insert leave_request_actions submitted"]
        exc{"Postgres EXCLUDE<br/>constraint 23P01?"}
        commit["Commit; store response for key (24 h)"]
    end

    subgraph SYS["Queue / worker"]
        n1{"module notifications on?"}
        q["Dispatch LeaveRequestSubmitted afterCommit<br/>to Redis queue"]
        mail["Worker mails approver(s)"]
        skip["No mail"]
    end

    send --> m1
    m1 -- no --> e404([404 NOT_FOUND])
    m1 -- yes --> m2
    m2 -- no --> e401([401 UNAUTHENTICATED / login redirect])
    m2 -- yes --> m3
    m3 -- same body --> replay --> ok
    m3 -- different body --> err
    m3 -- new or no key --> v0
    v0 -- no --> err
    v0 -- yes --> v1
    v1 -- no --> err
    v1 -- yes --> v2
    v2 -- no --> err
    v2 -- yes --> v3
    v3 -- no --> err
    v3 -- yes --> v4
    v4 -- no --> err
    v4 -- yes --> cnt --> v5
    v5 -- "no: NO_WORKING_DAYS" --> err
    v5 -- yes --> v6
    v6 -- "no: LEAVE_OVERLAP" --> err
    v6 -- yes --> v7
    v7 -- "no: INSUFFICIENT_BALANCE" --> err
    v7 -- "yes / LWP skips check" --> v8
    v8 -- "no: NO_APPROVER" --> err
    v8 -- yes --> ins --> exc
    exc -- "yes: race lost, LEAVE_OVERLAP" --> err
    exc -- no --> commit --> n1
    n1 -- yes --> q --> mail
    n1 -- no --> skip
    commit --> ok
```

Notes: the error path rolls the transaction back, so nothing is half-written. The `users` row lock serialises concurrent submissions of the same user; the exclusion constraint is the backstop if the lock is bypassed (D-06).

## 2. Approve or reject (UC-M2, UC-M3, UC-H6)

```mermaid
flowchart TB
    subgraph APR["Approver (manager / hr_admin)"]
        s([Open approval inbox]) --> pick["Pick a pending request"]
        pick --> dec{"Approve or reject?"}
        done([See updated request])
        e([See problem: 403 / 409 / 422])
    end

    subgraph POL["LeaveRequestPolicy::decide"]
        p1{"actor is requester?"}
        p2{"actor is requester's active<br/>manager, or any hr_admin?"}
    end

    subgraph ACT["Approve / RejectLeaveRequest (transaction, request row locked)"]
        st{"status == pending?<br/>LeaveStatus::canTransitionTo"}
        rc{"reject: comment given?"}
        rej["status=rejected, decision_comment,<br/>decided_by, decided_at"]
        re6{"re-check V6 overlap"}
        re7{"re-check V7 balance<br/>excluding this request"}
        tb{"type tracks_balance?"}
        deb["Append ledger debit −days"]
        apr["status=approved, decided_by, decided_at"]
        act["Insert leave_request_actions row"]
        ev["LeaveRequestDecided afterCommit<br/>(if notifications on)"]
    end

    dec --> p1
    p1 -- "yes: FORBIDDEN" --> e
    p1 -- no --> p2
    p2 -- "no: FORBIDDEN" --> e
    p2 -- yes --> st
    st -- "no: INVALID_TRANSITION 409" --> e
    st -- yes --> rc
    rc -- "reject without comment: COMMENT_REQUIRED" --> e
    rc -- "reject with comment" --> rej --> act
    rc -- approve --> re6
    re6 -- "overlap: LEAVE_OVERLAP" --> e
    re6 -- ok --> re7
    re7 -- "short: INSUFFICIENT_BALANCE" --> e
    re7 -- ok --> tb
    tb -- yes --> deb --> apr
    tb -- "no (LWP)" --> apr
    apr --> act --> ev --> done
```

## 3. Cancel (UC-E7, HR override)

```mermaid
flowchart TB
    subgraph U["Requester or hr_admin"]
        s([Click cancel / POST …/cancel]) --> c["Optional comment"]
        done([Request cancelled])
        e([403 / 409 problem])
    end

    subgraph POL["LeaveRequestPolicy::cancel"]
        st0{"Current status?"}
        who{"Actor?"}
        fut{"start_date > today?"}
        own{"hr_admin cancelling<br/>own started leave?"}
    end

    subgraph ACT["CancelLeaveRequest (transaction, row locked)"]
        rev["Append ledger reversal +days<br/>(full amount, only if a debit exists)"]
        set["status=cancelled, cancelled_at<br/>action row cancelled"]
        n["LeaveRequestCancelled to the other party<br/>afterCommit, if notifications on"]
    end

    c --> st0
    st0 -- "rejected / cancelled: INVALID_TRANSITION" --> e
    st0 -- pending --> who
    st0 -- approved --> who
    who -- "someone else, not hr_admin: FORBIDDEN" --> e
    who -- "requester, pending" --> set
    who -- "hr_admin, pending" --> set
    who -- "requester, approved" --> fut
    fut -- "no, already started: FORBIDDEN" --> e
    fut -- yes --> rev
    who -- "hr_admin, approved" --> own
    own -- "yes: FORBIDDEN" --> e
    own -- no --> rev
    rev --> set --> n --> done
```

The reversal is unique per request (partial unique index `(leave_request_id, kind)`), so a retried cancel can never refund twice.

## 4. Year-start accrual and carry-forward (UC-S1, UC-H9)

```mermaid
flowchart TB
    subgraph SCH["Scheduler (any replica) / one-off"]
        s([Day 1 of LEAVE_YEAR_START_MONTH, 00:10<br/>or artisan lms:accrue-year YEAR]) --> lk{"Redis lock acquired?<br/>onOneServer + withoutOverlapping"}
        lk -- "no: another replica runs it" --> stop([Skip this tick])
    end

    subgraph JOB["RunYearStartAccrual(year)"]
        lk -- yes --> yr["year = LeaveYear::current() unless given"]
        yr --> users["Active users with date_of_joining<br/>≤ LeaveYear::end(year)"]
        users --> lu{"next user?"}
        lu -- none --> sum["Log AccrualSummary<br/>(users, rows written, rows skipped)"] --> end1([Done])
        lu -- user --> tx["Begin transaction for this user"]
        tx --> lt{"next active tracked type?"}
        lt -- none --> cm["Commit"] --> lu
        lt -- type --> clo["closing = SUM ledger year−1"]
        clo --> car["carry = cap null ? closing : min(closing, cap)<br/>carry = max(0, carry)"]
        car --> lap{"closing − carry > 0?"}
        lap -- yes --> wl["Write lapse −(closing − carry) in year−1"]
        lap -- no --> cf
        wl --> cf{"carry > 0?"}
        cf -- yes --> wc["Write carry_forward +carry in year"]
        cf -- no --> gr
        wc --> gr["grant = annual_quota, prorated if joined in this leave year:<br/>months_remaining = 12 − ((join_month − start_month + 12) mod 12)"]
        gr --> clip["Clip so carry + grant ≤ max_balance"]
        clip --> wg["Write grant +grant in year"]
        wg --> idem{"Unique violation on<br/>(user, type, year, kind)?"}
        idem -- "yes: already accrued, skip row" --> lt
        idem -- no --> lt
    end
```

Idempotent twice over: the Redis lock prevents concurrent runs, and the partial unique index makes a re-run write nothing new (`insertOrIgnore`).

## 5. White-label and working-week settings update (UC-B1–B6, UC-H16)

```mermaid
flowchart TB
    subgraph ADM["admin (D-29)"]
        s([Open Settings]) --> what{"Branding or modules?"}
        ok([Saved, page re-themed])
        e([422 with field errors])
    end

    subgraph HRL["hr_admin"]
        ws([Open Working week]) --> wsub["Submit weekend_days<br/>or null = reset"]
    end

    subgraph WW["UpdateWorkingWeek (D-30)"]
        w1{"each 1–7, distinct,<br/>at least one working day?"}
        w2["Upsert settings work.weekend_days<br/>(null = delete row, back to LMS_WEEKEND_DAYS)<br/>existing leave_requests.days untouched"]
    end

    subgraph BR["UpdateBranding / ResetBranding"]
        b1{"name 1–60 chars,<br/>colours match ^#RRGGBB?"}
        b2{"logo: png/jpg/webp ≤ 1 MB ≤ 1024px?<br/>favicon: png/ico ≤ 256 KB?<br/>SVG rejected"}
        b3["Store file on BRANDING_DISK (RustFS / S3)<br/>as logo-sha1.ext"]
        b4["Upsert settings rows brand.*<br/>(reset = delete rows)"]
    end

    subgraph MOD["UpdateModules"]
        m1["Load licensed = MODULES_ENABLED (env)<br/>and registry available flags"]
        m2{"Every module to enable is<br/>licensed AND available?"}
        m3["settings modules.disabled =<br/>licensed − enabled by admin"]
    end

    subgraph CACHE["Settings / Redis"]
        c1["Cache::forget settings:all"]
        c2["Next request on any replica:<br/>effective = DB override ?? env default"]
    end

    what -- branding --> b1
    b1 -- no --> e
    b1 -- yes --> b2
    b2 -- no --> e
    b2 -- yes --> b3 --> b4 --> c1
    what -- modules --> m1 --> m2
    m2 -- "no: MODULE_NOT_LICENSED" --> e
    m2 -- yes --> m3 --> c1
    wsub --> w1
    w1 -- "no: VALIDATION_FAILED" --> e
    w1 -- yes --> w2 --> c1
    c1 --> c2 --> ok
```

An `admin` can only **switch off** what the env licenses; an unlicensed module can never be switched on from the UI or API (D-14). Disabled modules return 404 and disappear from menus (D-17). A weekend change only affects requests submitted afterwards; stored `days` are never recalculated (D-30).

## 6. First-login provisioning (UC-E1, USE_CASES §3.10, D-43)

```mermaid
flowchart TB
    subgraph B["Browser"]
        s([Click Sign in]) --> kcl["Keycloak login page<br/>(customer-configured upstream Keycloak,<br/>maybe LDAP / SSO broker)"]
        home([Dashboard])
        deny([Access denied page])
    end

    subgraph W["Web: Better Auth callback /auth/callback/keycloak (BFF)"]
        st{"state (lms_auth verification row)<br/>+ PKCE verifier valid?"}
        ex["Exchange code at KEYCLOAK_BASE_URL<br/>(confidential client lms-web),<br/>verify ID token (JWKS, iss, aud, nonce)"]
        ck["Upsert user mirror + account tokens,<br/>create session in lms_auth,<br/>set HttpOnly session cookie"]
        me["Server component: GET /api/v1/me<br/>with Bearer access token"]
        clr["Delete session (lms_auth) + cookie"]
    end

    subgraph G["App: KeycloakJwtGuard"]
        vt{"JWT valid? (RS256 via JwksCache,<br/>iss, azp, exp)"}
        rl{"realm_access.roles has a base role<br/>employee, manager or hr_admin?"}
        act{"user.is_active?"}
    end

    subgraph P["KeycloakUserProvisioner"]
        f1{"users row with keycloak_sub = sub?"}
        f2{"row with same email,<br/>keycloak_sub null, email_verified?"}
        link["Link: set keycloak_sub"]
        new["Create user: name, email, role,<br/>department / manager null, is_active true"]
        sync["Sync name, email, role (highest base role wins),<br/>is_admin (has admin), last_login_at"]
        doj{"date_of_joining known now for<br/>the first time (claim or row)?"}
        grant["GrantInitialEntitlement (prorated)"]
    end

    kcl --> st
    st -- no --> deny
    st -- yes --> ex --> ck --> me --> vt
    vt -- no --> clr
    vt -- yes --> rl
    rl -- "no (403)" --> clr
    rl -- yes --> f1
    f1 -- yes --> sync
    f1 -- no --> f2
    f2 -- yes --> link --> sync
    f2 -- no --> new --> sync
    sync --> doj
    doj -- yes --> grant --> act
    doj -- no --> act
    act -- "no (401)" --> clr --> deny
    act -- yes --> home
```

Laravel has no login callback any more (D-43): the first API call with a new `sub` is the first login, whether it comes from the web BFF (`azp` = `lms-web`) or a third-party API client, so both are provisioned identically.

## 7. Role change guardrails (UC-U6, G1–G7)

```mermaid
flowchart TB
    subgraph HR["admin (UI or PUT /api/v1/admin/users/ID/roles)"]
        s([Choose new roles for a user]) --> sub["Submit roles: one of employee / manager / hr_admin,<br/>plus optional admin"]
        ok([Role changed, audit written])
        e([Problem response])
    end

    subgraph G["Middleware, policy, UserAdminGuard"]
        g0{"module user_management on?"}
        g1{"G1 actor is admin?"}
        g4{"G4 one base role + optional admin,<br/>all from the allow-list?"}
        g2{"G2 target is the actor?"}
        lock["Take Redis lock lock:admin-guard"]
        g3{"G3 target is admin, change removes it,<br/>and they are the last active admin?"}
    end

    subgraph KC["AssignRolesAction via KeycloakAdminClient"]
        tok["Get lms-admin token (Redis cache)"]
        add["Add the new base role (+ admin if requested)"]
        rem["Remove the other base roles<br/>(and admin if not requested;<br/>non-LMS roles untouched)"]
        kerr{"Keycloak response?"}
    end

    subgraph DB["Local mirror"]
        mir["users.role = base role,<br/>users.is_admin"]
        aud["Insert user_admin_audits outcome success"]
        audf["Insert user_admin_audits outcome failed + error_code"]
        unl["Release lock"]
    end

    sub --> g0
    g0 -- no --> e404([404 NOT_FOUND])
    g0 -- yes --> g1
    g1 -- "no: FORBIDDEN 403" --> e
    g1 -- yes --> g4
    g4 -- "no: VALIDATION_FAILED 422" --> e
    g4 -- yes --> g2
    g2 -- "yes: SELF_MODIFICATION_FORBIDDEN 403" --> audf2["Insert user_admin_audits outcome failed"] --> e
    g2 -- no --> lock --> g3
    g3 -- "yes: LAST_ADMIN 409" --> audf
    g3 -- no --> tok --> add --> rem --> kerr
    kerr -- "2xx" --> mir --> aud
    kerr -- "federated provider refuses: IDP_READ_ONLY 422" --> audf
    kerr -- "timeout / 5xx: IDP_UNAVAILABLE 503" --> audf
    audf --> unl
    aud --> unl
    unl -- success --> ok
    unl -- failure --> e
```

The last-admin count (active users with `is_admin`) uses the local mirror under a Redis lock so two concurrent demotions cannot both pass. Disable (UC-U4) applies the same G2/G3 checks. Drift with Keycloak (roles changed directly in the Keycloak console) is repaired at the user's next login by the provisioner.
