# 01 — System design (C4-style context, containers, deployment, scaling)

How the white-label LMS fits together at runtime and how it is shipped. Sources: [SYSTEM_DESIGN.md](../SYSTEM_DESIGN.md) §1–3, §8–11, §13 and ADRs D-01, D-09, D-14, D-15, D-20…D-27 in [decisions.md](../../.logs/decisions.md).

> Runtime choices shown here: Next.js frontend + BFF (`web`) and API-only Laravel on one origin behind NGINX (D-43), FrankenPHP + Octane app replicas (D-27), Redis 7 for sessions/cache/locks/rate limits/queue (D-23), RustFS (S3-compatible) object storage and first-class horizontal scaling (D-24), Keycloak identity on the upstream image (D-20, D-22, D-29, D-33), CI/GHCR (D-25).

C4 notation is drawn with flowcharts, because Mermaid's `C4Context` is experimental and lays out poorly on GitHub. Shapes: person = stadium, container = rectangle, data store = cylinder, external system = dashed border.

## 1. System context (C4 level 1)

```mermaid
flowchart TB
    emp([Employee<br/>role=employee])
    mgr([Manager<br/>role=manager])
    hr([HR Admin<br/>role=hr_admin<br/>HR operations])
    adm([Admin<br/>role admin, additive<br/>branding, modules, user admin])
    api([API client<br/>mobile app / script])
    op([Vendor / operator<br/>env: licensed modules,<br/>brand defaults, secrets])

    lms["<b>Leave Management System</b><br/>white-label, single-tenant<br/>Next.js frontend + BFF (web)<br/>Laravel 12 API / FrankenPHP + Octane, PHP 8.4"]

    kc["Keycloak 26<br/>realm lms: login, MFA,<br/>realm roles = RBAC authority"]:::ext
    dir["Customer directory<br/>LDAP / AD, SAML, Entra ID"]:::ext
    smtp["SMTP relay<br/>(Mailpit in dev)"]:::ext
    s3["RustFS (S3-compatible)<br/>prod: any S3-compatible store<br/>logo + favicon"]:::ext
    ghcr["GHCR<br/>ghcr.io/heyadityak/lms/app, /nginx, /web"]:::ext

    emp -->|apply, cancel, view balances| lms
    mgr -->|approve / reject reports' requests| lms
    hr -->|users' HR fields, types, holidays,<br/>working week, reports| lms
    adm -->|branding, modules,<br/>Keycloak users + roles| lms
    api -->|/api/v1 JSON, Bearer JWT| lms
    emp & mgr & hr & adm -->|sign in OIDC| kc
    api -->|auth code + PKCE<br/>client lms-api-cli| kc
    lms -->|"BFF: OIDC code exchange + refresh (lms-web)<br/>API: JWKS, Admin REST API as lms-admin"| kc
    kc -->|federation / brokering| dir
    op -->|deploys image + env per customer| lms
    lms -->|queued notification mails| smtp
    kc -->|password / verify mails| smtp
    lms -->|store / read branding assets| s3
    ghcr -->|images pulled at deploy| lms

    classDef ext stroke-dasharray: 5 5
```

## 2. Containers (C4 level 2), one customer deployment

```mermaid
flowchart LR
    browser([Browser]) -->|HTTPS, HttpOnly session cookie| lb
    client([API client]) -->|HTTPS Bearer JWT| lb

    subgraph dep["Customer deployment (one per customer, D-01)"]
        lb["Ingress / load balancer<br/>TLS, HSTS<br/>no sticky sessions"]
        nginx["nginx × N (edge)<br/>nginx:stable-alpine<br/>path routing, security headers,<br/>HTTP proxy_pass, X-Request-Id"]
        web["web × N<br/>Next.js standalone :3000 (node:24-alpine)<br/>pages (antd v6), Better Auth /auth/*,<br/>BFF /bff/api/* → /api/v1"]
        app["app × N<br/>FrankenPHP + Octane worker mode :8000<br/>API only: /api/v1, middleware,<br/>actions, policies, Resources"]
        worker["worker × N<br/>queue:work redis"]
        sched["scheduler × 1<br/>schedule:work<br/>onOneServer + withoutOverlapping"]
        oneoff["one-off jobs<br/>migrate --force, lms:accrue-year<br/>web: auth-migrate (lms_auth)"]
        kc["keycloak × N<br/>Keycloak 26, realm lms<br/>clients lms-web, lms-api-cli, lms-admin"]
        pg[("PostgreSQL 17<br/>db lms: domain tables, settings,<br/>user_admin_audits, failed_jobs<br/>db keycloak: Keycloak tables<br/>db lms_auth: Better Auth sessions, tokens")]
        redis[("Redis 7<br/>cache, JWKS, admin token,<br/>locks, rate limits, idempotency, queue")]
    end

    s3[("RustFS (S3-compatible)<br/>BRANDING_DISK=s3")]
    smtp["SMTP / Mailpit"]

    lb --> nginx
    lb -->|/realms/*| kc
    nginx -->|"/ (WEB_UPSTREAM web:3000)"| web
    nginx -->|"/api/, /healthz, /readyz, /branding/<br/>(APP_UPSTREAM app:8000)"| app
    web -->|"Bearer JWT via LMS_API_URL (http://nginx)"| nginx
    web -->|"discovery, token exchange, refresh<br/>(KEYCLOAK_BASE_URL)"| kc
    web -->|"sessions, OAuth state, tokens<br/>(AUTH_DATABASE_URL, db lms_auth)"| pg
    app -->|SQL| pg
    app -->|"cache, throttle, jobs"| redis
    app -->|"put / stream logo, favicon"| s3
    app -->|"JWKS, Admin REST<br/>(KEYCLOAK_BASE_URL)"| kc
    kc -->|JDBC| pg
    kc -->|SMTP| smtp
    worker -->|pop jobs| redis
    worker -->|SQL| pg
    worker -->|SMTP| smtp
    sched -->|"lock + enqueue"| redis
    sched -->|"SQL (accrual)"| pg
    oneoff --> pg
```

Notes:
- `web` is the Next.js frontend and BFF (D-43): browsers only talk to `web` (pages, `/auth/*`, `/bff/*`) and to `/branding/*` assets; tokens stay server-side in the `lms_auth` database (Better Auth, D-45; the browser only has an HttpOnly session cookie) and are attached when the BFF calls `/api/v1` back through nginx. Third-party API clients call `/api/v1` directly.
- Every PHP process runs the **same `app` image** (FrankenPHP, D-09/D-27) with a different command: web = `php artisan octane:frankenphp` on `:8000` (or classic FrankenPHP with `APP_SERVER_MODE=classic`), worker = `queue:work`, scheduler = `schedule:work`, one-off = `migrate`. `nginx`, `web` and `keycloak` are separate images.
- NGINX is the edge reverse proxy: TLS in prod (or the platform LB), static files, security headers, `proxy_pass` over HTTP. FrankenPHP's own auto-HTTPS is off (`SERVER_NAME=:8000`).
- `web`, `app` and `worker` are stateless: no per-process session state (web sessions live in Postgres `lms_auth`, D-45), no local uploads or cache. Any replica serves any request, so no sticky sessions.
- Keycloak uses its own `keycloak` database on the same Postgres server (D-20). The browser reaches it at `KEYCLOAK_PUBLIC_URL` (issuer); `web` and `app` use `KEYCLOAK_BASE_URL` for back-channel calls.
- Health: nginx `/nginx-health`; web `:3000/bff/healthz` (no I/O); app (served by Octane, image `HEALTHCHECK` curls `:8000/healthz`) `/healthz` (no I/O) and `/readyz` (Postgres + Redis, **not** Keycloak, to avoid cascading restarts); Keycloak `/health/ready`.

## 3. Deployment view: build once, deploy per customer

```mermaid
flowchart LR
    subgraph gh["GitHub"]
        repo["repo heyadityak/lms<br/>main / v* tag"] --> ci["Actions ci.yml<br/>lint, openapi lint, unit,<br/>feature (Postgres 17 + Redis 7 services,<br/>OpenAPI response validation),<br/>compose smoke test"]
        ci -->|all green| pkg["package job<br/>buildx app-prod (FrankenPHP) + nginx + web (Next)<br/>smoke incl. Playwright e2e<br/>amd64 on main, +arm64 on v* tags"]
    end
    pkg -->|push :sha :semver :main| ghcr[("GHCR<br/>ghcr.io/heyadityak/lms/app<br/>ghcr.io/heyadityak/lms/nginx<br/>ghcr.io/heyadityak/lms/web")]

    subgraph custA["Customer A (own namespace / VM)"]
        envA["env: APP_BRAND_NAME=Acme Leave<br/>BRAND_PRIMARY_COLOR, BRAND_ACCENT_COLOR<br/>MODULES_ENABLED=holidays,reports,notifications,user_management<br/>LEAVE_YEAR_START_MONTH=4<br/>KEYCLOAK_BASE_URL=https://sso.acme.example<br/>BRANDING_DISK=s3, AWS_ENDPOINT, AWS_BUCKET"]
        secA["secrets: APP_KEY, DB_PASSWORD, REDIS_PASSWORD,<br/>MAIL_PASSWORD, AWS_SECRET_ACCESS_KEY,<br/>KEYCLOAK_ADMIN_CLIENT_SECRET, KC_DB_PASSWORD<br/>web: BETTER_AUTH_SECRET, KEYCLOAK_CLIENT_SECRET,<br/>AUTH_DATABASE_URL"]
        relA["release: migrate --force (one-off)<br/>then roll app / web / worker / scheduler"]
        runA["nginx + web×N (Next) + app×N (FrankenPHP)<br/>+ worker×N + scheduler×1"]
        kcA["Keycloak cluster A<br/>upstream image, realm lms imported"]
        dbA[("Postgres A<br/>lms + keycloak")]
        rA[("Redis A")]
        bA[("RustFS (S3-compatible)<br/>bucket A")]
        envA --> runA
        secA --> runA
        relA --> runA
        runA --> dbA
        runA --> rA
        runA --> bA
        runA --> kcA
        kcA --> dbA
    end

    subgraph custB["Customer B (AD-managed users)"]
        envB["env: APP_BRAND_NAME=Globex HR<br/>MODULES_ENABLED=holidays,reports,api,notifications<br/>(user_management off: users come from AD)<br/>LEAVE_YEAR_START_MONTH=1"]
        secB["secrets (own values)"]
        runB["nginx + web×N (Next) + app×N (FrankenPHP)<br/>+ worker×N + scheduler×1"]
        kcB["Keycloak cluster B<br/>LDAP federation to Globex AD"]
        dbB[("Postgres B")]
        rB[("Redis B")]
        bB[("RustFS (S3-compatible)<br/>bucket B")]
        ad["Globex Active Directory"]
        envB --> runB
        secB --> runB
        runB --> dbB
        runB --> rB
        runB --> bB
        runB --> kcB
        kcB --> dbB
        kcB -->|LDAP| ad
    end

    ghcr -->|same image digest| runA
    ghcr -->|same image digest| runB
```

Notes:
- **Build, release, run** (12-factor V): one immutable image per role per commit (`app`, `nginx`, `web`); the `web` image bakes in no deployment config (no `NEXT_PUBLIC_*`, D-43). Release = image digest + that customer's env. `config:cache`, `route:cache`, `view:cache` run in the entrypoint at container start.
- Env holds **defaults and the licensed ceiling** (D-14). Admin overrides (brand, disabled modules) are rows in that customer's `settings` table.
- Secrets come from the orchestrator (Kubernetes Secret, Docker secret, cloud secret manager) as env vars. Never in the image, never committed.
- Nothing is shared between customers: separate Postgres, Redis, Keycloak and bucket (D-01).
- Object storage is **RustFS (S3-compatible)** in dev/compose. In prod `AWS_ENDPOINT` / `AWS_BUCKET` / `AWS_USE_PATH_STYLE_ENDPOINT` may point at any S3-compatible store with no code change.

## 4. Horizontal scaling view

```mermaid
flowchart TB
    users([Browsers / API clients]) --> lb["Load balancer<br/>round-robin, no sticky sessions"]
    lb --> n1["nginx #1"]
    lb --> n2["nginx #2"]
    lb -->|/realms/*| kc1["keycloak #1"]
    lb -->|/realms/*| kc2["keycloak #N"]
    kc1 <-->|"Infinispan cluster (KC_CACHE=ispn)"| kc2
    n1 & n2 -->|"/ :3000"| wb1["web #1<br/>Next.js + BFF"]
    n1 & n2 -->|"/ :3000"| wb2["web #N"]
    wb1 & wb2 -.->|"/api/v1 + Bearer, back through nginx"| n1
    n1 -->|"/api/ :8000"| a1["app #1<br/>FrankenPHP + Octane"]
    n1 -->|"/api/ :8000"| a2["app #2"]
    n2 -->|"/api/ :8000"| a2
    n2 -->|"/api/ :8000"| a3["app #N"]

    subgraph shared["Shared state (the only stateful tier)"]
        pg[("Postgres 17<br/>lms + keycloak + lms_auth DBs")]
        redis[("Redis 7<br/>cache · JWKS · locks ·<br/>throttle · idempotency · queue")]
        s3[("RustFS (S3-compatible)<br/>branding assets")]
    end

    a1 & a2 & a3 --> pg
    wb1 & wb2 -->|lms_auth| pg
    a1 & a2 & a3 --> redis
    a1 & a2 & a3 --> s3
    kc1 & kc2 --> pg

    w1["worker #1"] & w2["worker #N"] -->|pop jobs| redis
    w1 & w2 --> pg
    w1 & w2 -->|SMTP| mail["SMTP"]

    s1["scheduler<br/>(1 replica, more are safe)"] -->|"Cache::lock via onOneServer()<br/>+ withoutOverlapping()"| redis
    s1 --> pg

    mig["migrate one-off job<br/>once per release,<br/>before new replicas start"] --> pg
```

| Tier | Scale | Why it is safe |
|---|---|---|
| nginx | N | Stateless edge proxy to `WEB_UPSTREAM` and `APP_UPSTREAM` over HTTP (no FastCGI), re-resolved via `NGINX_RESOLVER`. |
| web (Next.js + BFF) | N (CPU / SSR latency) | Sessions, OAuth state, tokens and auth rate limits in Postgres `lms_auth` (Better Auth, D-45), 5-min JWE cookie cache readable by any replica (shared `BETTER_AUTH_SECRET`); per-replica fetch cache holds only public branding (≤ 30 s). |
| app (FrankenPHP + Octane) | N (CPU / latency); `OCTANE_WORKERS` per replica, recycled after `OCTANE_MAX_REQUESTS` | Bearer-only (no sessions); cache, JWKS, admin token, idempotency keys in Redis; uploads on RustFS. `Settings` cache invalidated in Redis, so every replica sees changes on the next request. |
| worker | N (queue depth) | Redis queue pop is atomic. SIGTERM finishes the current job. |
| scheduler | 1 (extra replicas harmless) | `onOneServer()` takes a Redis atomic lock per run; `withoutOverlapping()` stops a long run overlapping itself. Yearly accrual is idempotent via the ledger unique index (`month` null). Monthly accrual (00:15 on the 1st) is idempotent via the grant unique index that includes `month`. The 08:00 reminder is idempotent via `approval_reminders`. The 09:00 escalation is idempotent via `leave_requests.escalated_at`. |
| keycloak | N | Clustered (Infinispan) with shared `keycloak` DB. The app keeps validating JWTs from the Redis-cached JWKS during short Keycloak outages. |
| migrations | one-off | Never in the web entrypoint, so N replicas don't race. Expand/contract for breaking changes. |
| Postgres / Redis / RustFS | managed | Outside the app's scaling unit. |
