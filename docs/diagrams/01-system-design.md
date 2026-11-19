# 01 — System design (C4-style context, containers, deployment, scaling)

How the white-label LMS fits together at runtime and how it is shipped. Sources: [SYSTEM_DESIGN.md](../SYSTEM_DESIGN.md) §1–3, §8–11, §13 and ADRs D-01, D-09, D-14, D-15, D-20 (Keycloak), D-21 (API contract), D-22 (in-app user admin) in [decisions.md](../../.logs/decisions.md).

> **Redis, RustFS and horizontal scaling (coordinator requirements, 2026-11-19).** These diagrams show Redis 7 as the session, cache, lock, rate-limit and queue backend, RustFS (S3-compatible) as object storage, and `app` / `worker` as N stateless replicas. This replaces D-03 ("`database` driver, no Redis") and the local branding volume of D-15. The platform agent owns the superseding ADR and the SYSTEM_DESIGN §1/§3/§9 edits.

C4 notation is drawn with flowcharts, because Mermaid's `C4Context` is experimental and lays out poorly on GitHub. Shapes: person = stadium, container = rectangle, data store = cylinder, external system = dashed border.

## 1. System context (C4 level 1)

```mermaid
flowchart TB
    emp([Employee<br/>role=employee])
    mgr([Manager<br/>role=manager])
    hr([HR Admin<br/>role=hr_admin<br/>also branding, modules, user admin])
    api([API client<br/>mobile app / script])
    op([Vendor / operator<br/>env: licensed modules,<br/>brand defaults, secrets])

    lms["<b>Leave Management System</b><br/>white-label, single-tenant<br/>Laravel 12 / PHP-FPM 8.4"]

    kc["Keycloak 26<br/>realm lms: login, MFA,<br/>realm roles = RBAC authority"]:::ext
    dir["Customer directory<br/>LDAP / AD, SAML, Entra ID"]:::ext
    smtp["SMTP relay<br/>(Mailpit in dev)"]:::ext
    s3["RustFS (S3-compatible)<br/>prod: any S3-compatible store<br/>logo + favicon"]:::ext
    ghcr["GHCR<br/>ghcr.io/heyadityak/&lt;repo&gt;/app, /nginx"]:::ext

    emp -->|apply, cancel, view balances| lms
    mgr -->|approve / reject reports' requests| lms
    hr -->|users, types, holidays, reports,<br/>branding, modules| lms
    api -->|/api/v1 JSON, Bearer JWT| lms
    emp & mgr & hr -->|sign in OIDC| kc
    api -->|auth code + PKCE<br/>client lms-api-cli| kc
    lms -->|OIDC code exchange, JWKS,<br/>Admin REST API as lms-admin| kc
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
    browser([Browser]) -->|HTTPS| lb
    client([API client]) -->|HTTPS Bearer JWT| lb

    subgraph dep["Customer deployment (one per customer, D-01)"]
        lb["Ingress / load balancer<br/>TLS, HSTS<br/>no sticky sessions"]
        nginx["nginx<br/>nginx:stable-alpine<br/>static, FastCGI proxy,<br/>X-Request-Id, /nginx-health"]
        app["app × N<br/>php-fpm :9000<br/>routes, middleware, controllers,<br/>actions, policies, Blade, /api/v1"]
        worker["worker × N<br/>queue:work redis"]
        sched["scheduler × 1<br/>schedule:work<br/>onOneServer + withoutOverlapping"]
        oneoff["one-off job<br/>migrate --force<br/>lms:accrue-year"]
        kc["keycloak × N<br/>Keycloak 26, realm lms<br/>clients lms-web, lms-api-cli, lms-admin"]
        pg[("PostgreSQL 17<br/>db lms: domain tables, settings,<br/>user_admin_audits, failed_jobs<br/>db keycloak: Keycloak tables")]
        redis[("Redis 7<br/>sessions, cache, JWKS, admin token,<br/>locks, rate limits, idempotency, queue")]
    end

    s3[("RustFS (S3-compatible)<br/>BRANDING_DISK=s3")]
    smtp["SMTP / Mailpit"]

    lb --> nginx
    lb -->|/realms/*| kc
    nginx -->|"FastCGI (PHP_FPM_HOST)"| app
    app -->|SQL| pg
    app -->|"session, cache, throttle, jobs"| redis
    app -->|"put / stream logo, favicon"| s3
    app -->|"token exchange, JWKS, Admin REST<br/>(KEYCLOAK_INTERNAL_URL)"| kc
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
- Every PHP process runs the **same `app` image** (D-09) with a different command. `nginx` and `keycloak` (`quay.io/keycloak/keycloak:26`) are separate images.
- `app` and `worker` are stateless: no local sessions, uploads or cache. Any replica serves any request, so no sticky sessions.
- Keycloak uses its own `keycloak` database on the same Postgres server (D-20). The browser reaches it at `KEYCLOAK_BASE_URL` (issuer); the app uses `KEYCLOAK_INTERNAL_URL` for back-channel calls.
- Health: nginx `/nginx-health`; app `/healthz` (no I/O) and `/readyz` (Postgres + Redis, **not** Keycloak, to avoid cascading restarts); Keycloak `/health/ready`.

## 3. Deployment view: build once, deploy per customer

```mermaid
flowchart LR
    subgraph gh["GitHub"]
        repo["repo main / v* tag"] --> ci["Actions ci.yml<br/>lint, unit,<br/>feature (Postgres 17 + Redis 7 services,<br/>OpenAPI response validation)"]
        ci -->|all green| pkg["package job<br/>buildx target app-prod + nginx"]
    end
    pkg -->|push :sha :semver :main| ghcr[("GHCR<br/>…/app, …/nginx")]

    subgraph custA["Customer A (own namespace / VM)"]
        envA["env: APP_BRAND_NAME=Acme Leave<br/>BRAND_PRIMARY_COLOR, BRAND_ACCENT_COLOR<br/>MODULES_ENABLED=holidays,reports,notifications,user_management<br/>LEAVE_YEAR_START_MONTH=4<br/>KEYCLOAK_BASE_URL=https://sso.acme.example<br/>BRANDING_DISK=s3, AWS_ENDPOINT, AWS_BUCKET"]
        secA["secrets: APP_KEY, DB_PASSWORD, REDIS_PASSWORD,<br/>MAIL_PASSWORD, AWS_SECRET_ACCESS_KEY,<br/>KEYCLOAK_CLIENT_SECRET, KEYCLOAK_ADMIN_CLIENT_SECRET,<br/>KC_DB_PASSWORD"]
        relA["release: migrate --force (one-off)<br/>then roll app / worker / scheduler"]
        runA["nginx + app×N + worker×N + scheduler×1"]
        kcA["Keycloak cluster A<br/>login theme: Acme"]
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
        runB["nginx + app×N + worker×N + scheduler×1"]
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
- **Build, release, run** (12-factor V): one immutable image per commit. Release = image digest + that customer's env. `config:cache`, `route:cache`, `view:cache` run in the entrypoint at container start.
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
    n1 --> a1["app #1"]
    n1 --> a2["app #2"]
    n2 --> a2
    n2 --> a3["app #N"]

    subgraph shared["Shared state (the only stateful tier)"]
        pg[("Postgres 17<br/>lms + keycloak DBs")]
        redis[("Redis 7<br/>session · cache · JWKS · locks ·<br/>throttle · idempotency · queue")]
        s3[("RustFS (S3-compatible)<br/>branding assets")]
    end

    a1 & a2 & a3 --> pg
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
| nginx | N | Stateless, only proxies to `PHP_FPM_HOST`. |
| app (php-fpm) | N (CPU / latency) | Sessions, cache, JWKS, admin token, idempotency keys in Redis; uploads on RustFS. `Settings` cache invalidated in Redis, so every replica sees changes on the next request. |
| worker | N (queue depth) | Redis queue pop is atomic. SIGTERM finishes the current job. |
| scheduler | 1 (extra replicas harmless) | `onOneServer()` takes a Redis atomic lock per run; `withoutOverlapping()` stops a long accrual overlapping itself. Accrual is idempotent anyway (unique ledger index). |
| keycloak | N | Clustered (Infinispan) with shared `keycloak` DB. The app keeps validating JWTs from the Redis-cached JWKS during short Keycloak outages. |
| migrations | one-off | Never in the web entrypoint, so N replicas don't race. Expand/contract for breaking changes. |
| Postgres / Redis / RustFS | managed | Outside the app's scaling unit. |
