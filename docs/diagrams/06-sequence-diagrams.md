# 06 — Sequence diagrams

Runtime interactions. `App` is a FrankenPHP replica running Laravel Octane in worker mode on `:8000` (D-27); NGINX proxies to it over plain HTTP (no FastCGI). Redis logical DBs follow D-23 (cache 1, session 2, queue 3). Keycloak details follow D-20/D-22; API conventions follow [API_GUIDELINES.md](../API_GUIDELINES.md).

| # | Flow |
|---|---|
| 1 | Web login (OIDC auth code + PKCE, provisioning, Redis session) |
| 2 | RP-initiated logout |
| 3 | API call with a Keycloak Bearer JWT (JWKS cached in Redis) |
| 4 | Apply for leave (web), queue, worker, mail |
| 5 | Approve leave |
| 6 | Cancel approved leave with ledger refund |
| 7 | Scheduled year-start accrual (Redis lock, idempotent) |
| 8 | `/readyz` health check |
| 9 | Logo upload to RustFS + theme render via CSS variables |
| 10 | API error handling and `Idempotency-Key` |
| 11 | Admin creates a user (Keycloak first, compensation on failure) |

## 1. Web login: OIDC authorization code flow

```mermaid
sequenceDiagram
    autonumber
    actor U as User (browser)
    participant N as NGINX
    participant A as App (FrankenPHP / Octane)
    participant R as Redis (session DB 2, cache DB 1)
    participant K as Keycloak (realm lms)
    participant DB as Postgres

    U->>N: GET /login
    N->>A: proxy_pass HTTP :8000
    A->>A: Socialite keycloak driver: state + PKCE verifier
    A->>R: store state, code_verifier in new session
    A-->>U: 302 to KEYCLOAK_PUBLIC_URL/realms/lms/protocol/openid-connect/auth
    U->>K: login page (customer-configured upstream Keycloak, LDAP / AD / SAML broker possible)
    K-->>U: 302 /auth/callback?code=…&state=…
    U->>N: GET /auth/callback
    N->>A: proxy_pass
    A->>R: load session, compare state
    A->>K: POST token endpoint via KEYCLOAK_BASE_URL (code, verifier, lms-web secret)
    K-->>A: id_token, access_token, refresh_token
    A->>R: JwksCache get keycloak:jwks (fetch certs on miss or unknown kid)
    A->>A: verify RS256, iss, aud/azp, exp with leeway
    A->>DB: KeycloakUserProvisioner: find by keycloak_sub, else link by verified email, else insert
    A->>DB: sync name, email, role (highest LMS realm role), last_login_at
    alt no LMS realm role or user inactive
        A-->>U: 403 access denied page (no session created)
    else ok
        A->>A: Auth::login(user), session()->regenerate()
        A->>R: write session (user id, id_token for logout), new id
        A-->>U: 302 / with session cookie (Secure, HttpOnly, SameSite=Lax)
    end
    Note over A,R: No sticky sessions: any replica can serve the next request because the session lives in Redis.
```

## 2. RP-initiated logout

```mermaid
sequenceDiagram
    autonumber
    actor U as User (browser)
    participant N as NGINX
    participant A as App (FrankenPHP / Octane)
    participant R as Redis (session)
    participant K as Keycloak

    U->>N: POST /logout (CSRF token)
    N->>A: proxy_pass
    A->>R: read id_token from session
    A->>R: invalidate session, regenerate CSRF token
    A-->>U: 302 KEYCLOAK_PUBLIC_URL/…/logout?id_token_hint=…&post_logout_redirect_uri=APP_URL
    U->>K: end-session request
    K->>K: end SSO session (all clients of this browser)
    K-->>U: 302 APP_URL (signed-out page)
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
    A->>A: middleware module:api (404 problem if off)
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

## 4. Apply for leave (web), queue and mail

```mermaid
sequenceDiagram
    autonumber
    actor E as Employee (browser)
    participant N as NGINX
    participant A as App (FrankenPHP / Octane)
    participant MW as Middleware (auth, active, module)
    participant C as LeaveRequestController
    participant FR as StoreLeaveRequestRequest
    participant AC as SubmitLeaveRequest
    participant DB as Postgres
    participant R as Redis (queue DB 3)
    participant W as Worker (queue:work)
    participant M as SMTP

    E->>N: POST /leave-requests (CSRF, form)
    N->>A: proxy_pass HTTP :8000
    A->>MW: session from Redis, auth, EnsureUserIsActive
    MW->>C: store()
    C->>C: authorize create (LeaveRequestPolicy)
    C->>FR: validate V9 + shapes
    FR-->>C: validated data
    C->>AC: handle(user, data)
    AC->>DB: BEGIN, SELECT users FOR UPDATE (requester)
    AC->>DB: load leave type (V1), holidays if module on
    AC->>AC: V2..V5, WorkingDayCalculator days
    AC->>DB: V6 overlap query, V7 balance = SUM ledger − pending
    AC->>AC: V8 ApproverResolver
    AC->>DB: INSERT leave_requests pending, INSERT leave_request_actions submitted
    AC->>DB: COMMIT
    AC->>R: afterCommit dispatch LeaveRequestSubmitted (if notifications on)
    AC-->>C: LeaveRequest
    C-->>E: 302 /leave-requests/ID with flash
    W->>R: pop job
    W->>DB: load request, approver(s)
    W->>M: mail "[Brand] Leave request from Alice"
    alt SMTP fails 3 times
        W->>DB: INSERT failed_jobs
    end
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

## 9. Logo upload to RustFS and theme render

```mermaid
sequenceDiagram
    autonumber
    actor H as Admin (role admin)
    participant A as App (via NGINX)
    participant AC as UpdateBranding
    participant S3 as RustFS (S3-compatible, BRANDING_DISK=s3)
    participant DB as Postgres (settings)
    participant R as Redis (cache)
    actor V as Any user

    H->>A: PUT /admin/settings/branding (multipart logo, colours)
    A->>A: UpdateBrandingRequest: hex regex, png/jpg/webp ≤ 1 MB, ≤ 1024 px, no SVG
    A->>AC: handle(data, logo)
    AC->>S3: putObject logo-sha1.png (private bucket)
    AC->>DB: upsert settings brand.logo_path, brand.primary_color
    AC->>S3: delete previous logo object
    AC->>R: Cache::forget settings:all
    A-->>H: 302 settings page
    V->>A: GET /dashboard
    A->>R: Settings get (miss → load settings from DB, cache)
    A->>A: BrandingComposer (per request, scoped): name, colours, contrast, logo URL
    A-->>V: HTML with style :root --brand-primary, --brand-accent, --brand-primary-contrast
    V->>A: GET /branding/logo?v=sha1
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
