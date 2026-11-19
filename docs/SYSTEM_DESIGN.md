# LMS — System Design

Status: v1.5 (architect, 2026-11-19; Next.js frontend + BFF, Laravel API-only D-43; Keycloak D-20, API contract D-21, in-app user admin D-22, Redis D-23, scaling/RustFS D-24, CI D-25, leave year D-26, FrankenPHP D-27, `admin` role D-29, editable weekend D-30, review answers D-31…D-34). Business rules live in [USE_CASES.md](USE_CASES.md). Where this file and USE_CASES disagree, USE_CASES wins for business rules and this file wins for technical design.

## 1. Context & constraints

| Item | Choice |
|---|---|
| Product | White-label, **single-tenant** LMS. One deployment (and one DB) per customer. |
| Runtime | **FrankenPHP** (PHP **8.4**) + **Laravel Octane** worker mode on `:8000` (D-27), Laravel **12.x**, PostgreSQL **17**, NGINX (stable, alpine) as edge reverse proxy |
| UI | **Next.js 16** frontend (`web/`, App Router, TypeScript strict, standalone output, Node 24 LTS alpine, non-root, `:3000`), **Ant Design v6** (`antd` + `@ant-design/nextjs-registry` + `@ant-design/icons` v6, D-45), TanStack Query, typed client generated from the OpenAPI spec. Runtime theming via antd `ConfigProvider` tokens (CSS variables, always on in v6). Screens: [UI_GUIDELINES.md](UI_GUIDELINES.md). Laravel is **API-only** (D-43, supersedes D-05). See §6.1. |
| API | JSON, `/api/v1`, contract-first: [`docs/api/openapi.yaml`](api/openapi.yaml) + [`docs/API_GUIDELINES.md`](API_GUIDELINES.md) (D-21). Bearer JWT from Keycloak (D-20). |
| Identity | **Keycloak 26** (own container, `keycloak` DB on the same Postgres server, clustered in prod). Realm `lms`, realm roles `employee|manager|hr_admin` (base, one per user) + `admin` (additive, D-29), clients `lms-web` (confidential), `lms-api-cli` (public, PKCE), `lms-admin` (service account, D-22). See §10.1 (D-20). |
| Web auth | **BFF** in Next.js: Better Auth (generic OAuth plugin → Keycloak, confidential client `lms-web`, auth code + PKCE), tokens stored server-side in Postgres DB `lms_auth`, browser gets only an HttpOnly session cookie; `/bff/api/*` proxies to Laravel `/api/v1/*` with `Authorization: Bearer`. Laravel has no sessions and no login routes. No passwords in the app (D-43, D-45). |
| Queue / cache / locks / rate limits | **Redis 7** (`phpredis`), D-23. Separate logical DBs: `default` 0, `cache` 1 (cache, atomic locks, rate limiters, JWKS, admin token, idempotency keys), queue 3; DB 2 (`session`) unused since D-43 (`SESSION_DRIVER=array`). Key prefix `REDIS_PREFIX=lms-`. `failed_jobs` stays in Postgres. |
| Mail | SMTP via env. `log` mailer in tests, Mailpit in compose. |
| Files | Laravel disk `BRANDING_DISK=s3` (and `FILESYSTEM_DISK=s3`): **RustFS** (S3-compatible) in dev/compose, any S3-compatible store in prod. The `branding` local disk exists only for single-node installs (D-24). |
| Scaling | `web`, `app`, `worker`, `nginx`, `keycloak` scale horizontally; no sticky sessions; scheduler tasks `onOneServer()`; migrations as a one-off job (D-24, §3.1). |
| Delivery | Docker images `ghcr.io/heyadityak/lms/{app,nginx,web}` (amd64 on `main`, amd64+arm64 on `v*` tags), 12-factor (D-25, §13) |

## 2. Architecture

Full set of diagrams (context, containers, deployment, scaling, DFD, class, sequence, activity, state): [docs/diagrams/](diagrams/README.md).

```mermaid
flowchart LR
    B[Browser] -->|HTTPS, session cookie| LB[Ingress / load balancer<br/>no sticky sessions]
    C[API client] -->|Bearer JWT| LB
    LB --> N[nginx × N]
    LB -->|/realms/*| KC[keycloak × N]
    N -->|"/ (pages, /auth/*, /bff/*)"| WB[web: Next.js + BFF × N :3000]
    WB -->|"/api/v1 + Bearer (via nginx)"| N
    WB -->|token, refresh, JWKS| KC
    N -->|"/api/, /healthz, /readyz, /branding/ :8000"| A[app: FrankenPHP + Octane × N]
    A --> PG[(PostgreSQL 17<br/>db lms + db keycloak)]
    A --> RD[(Redis 7<br/>cache, locks,<br/>rate limits, queue)]
    A --> FS[(RustFS / S3<br/>branding assets)]
    A -->|OIDC, JWKS, Admin API| KC
    KC --> PG
    W[worker: queue:work × N] --> RD
    W --> PG
    W -->|SMTP| M[Mail server / Mailpit]
    S[scheduler × 1: schedule:work] -->|onOneServer lock| RD
    S --> PG
    J[one-off: migrate / lms:accrue-year] --> PG
```

Layers inside the app:

```mermaid
flowchart TB
    R[Routes api.php + health/branding] --> MW[Middleware: module:x, auth:keycloak-jwt, active, throttle]
    MW --> CT[Controllers Api\V1 thin]
    CT --> FR[Form Requests validation]
    CT --> PO[Policies authorization]
    CT --> AC[Actions domain writes]
    AC --> SV[Services WorkingDayCalculator, LeaveBalanceService, Settings, Modules]
    AC --> MD[Eloquent Models + Enums]
    AC --> EV[Notifications ShouldQueue]
    MD --> DB[(Postgres)]
```

## 3. Container topology

One Dockerfile with multi-stage targets (D-09, D-27) for `app` and `nginx`, plus `web/Dockerfile` for the Next.js frontend (D-43). Same `app` (FrankenPHP) image runs as HTTP API (Octane), worker, scheduler and one-off.

| Service (compose) | Image / target | Command | Ports | Prod? | Notes |
|---|---|---|---|---|---|
| `nginx` | `Dockerfile` target `nginx` | default | `8080:80` (dev) | yes | `nginx:stable-alpine`. Edge reverse proxy + load balancer (D-43 routing): `/api/`, `/healthz`, `/readyz`, `/branding/` → upstream `app` (`APP_UPSTREAM`, default `app:8000`); everything else → upstream `web` (`WEB_UPSTREAM`, default `web:3000`); both re-resolved via `NGINX_RESOLVER`, keepalive, **no FastCGI**. `large_client_header_buffers 4 16k` (chunked session cookie). Sets security headers, `X-Request-Id`, `X-Forwarded-*`. TLS termination in prod (or the platform LB). Logs to stdout/stderr. No Laravel `public/` copy any more. |
| `web` | `web/Dockerfile` (stages `deps`, `build`, `runner`; `node:24-alpine`) | `node server.js` (Next standalone) | 3000 (internal) | yes, N replicas | Next.js frontend + BFF (§6.1). Non-root `node` user. `HEALTHCHECK` `wget -qO- http://127.0.0.1:3000/bff/healthz`. Stateless process: sessions and tokens in Postgres `lms_auth` (`AUTH_DATABASE_URL`), `BETTER_AUTH_SECRET` shared by all replicas (D-45); Next fetch cache per replica only holds public branding (≤ 30 s). Calls Laravel via `LMS_API_URL` (`http://nginx`) and Keycloak via `KEYCLOAK_BASE_URL` (discovery, token, JWKS). Auth migrations: one-off `node scripts/auth-migrate.mjs`. Dev: `web-dev` tooling service (bind mount, `npm run dev`/lint/test), defined by platform. |
| `app` | target `app-dev` (dev) / `app-prod` | `php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --workers=${OCTANE_WORKERS} --max-requests=${OCTANE_MAX_REQUESTS}` (dev adds `--watch` optional); `APP_SERVER_MODE=classic` → `frankenphp php-server --listen :8000 --root public/` | 8000 (internal) | yes | FrankenPHP worker mode (D-27). `SERVER_NAME=:8000` disables Caddy auto-HTTPS. `HEALTHCHECK curl -fsS http://127.0.0.1:8000/healthz`. Octane handles SIGTERM. Dev bind-mounts `./:/app`. |
| `worker` | same as app | `php artisan queue:work --tries=3 --backoff=10 --max-time=3600` | — | yes | Restart policy `unless-stopped`. SIGTERM finishes the current job. |
| `scheduler` | same as app | `php artisan schedule:work` | — | yes (1 replica; more are safe) | Every scheduled task uses `->onOneServer()->withoutOverlapping()` (Redis locks). |
| `postgres` | `postgres:17-alpine` | — | `5432:5432` (dev) | managed DB in prod | `docker/postgres/init/01-test-db.sql` creates `lms_test`, `keycloak` and `lms_auth` (Better Auth, D-45). Healthcheck `pg_isready`. Named volume `pgdata`. |
| `redis` | `redis:7-alpine` | `redis-server --appendonly yes` | internal | managed Redis in prod (TLS via `REDIS_SCHEME=tls` / `REDIS_URL`) | Healthcheck `redis-cli ping`. Volume `redisdata`. |
| `rustfs` | `rustfs/rustfs` | — | `9000` S3 API, `9001` console | **dev** (prod: any S3-compatible store) | `RUSTFS_ACCESS_KEY` / `RUSTFS_SECRET_KEY`. Volume `s3data`. Healthcheck `/health`. |
| `s3-init` | `amazon/aws-cli` | `head-bucket \|\| create-bucket $AWS_BUCKET` | — | dev; prod bucket is provisioned by IaC | Init container, idempotent. `app`/`worker`/`scheduler` wait for `service_completed_successfully`. |
| `keycloak` | upstream `quay.io/keycloak/keycloak:26.x` everywhere (dev `start-dev --import-realm`; prod `start`, clustered cache `ispn` by default). No custom image is built or published (D-33). | — | `8081:8080` (dev) | yes, ≥2 replicas clustered | DB `keycloak` on the same Postgres (`docker/postgres/init` creates it). Dev realm import `docker/keycloak/realm-lms.json` (realm, roles, clients, dev users). Prod: customers import/configure the documented realm (§10.1) in their upstream Keycloak. Health on management port 9000 `/health/ready`. |
| `mailpit` | `axllent/mailpit` | — | `8025` UI, `1025` SMTP | **dev only** | Also Keycloak's SMTP in dev. |
| one-off | same as app | `docker compose run --rm app php artisan migrate --force` | — | yes | Admin processes (12-factor XII). |
| one-off (web) | same as web | `docker compose run --rm web node scripts/auth-migrate.mjs` | — | yes | Better Auth schema in `lms_auth` (D-45), before rolling out web replicas. |

Volumes (dev): `pgdata`, `redisdata`, `s3data`. No app container has local state.

### 3.1 Horizontal scaling rules (D-24)

| Rule | How |
|---|---|
| No sticky sessions | Laravel is stateless (Bearer JWT, no sessions). `web` sessions, OAuth state and tokens live in the shared Postgres DB `lms_auth` (Better Auth, D-45), with a 5-minute JWE cookie cache any replica can read with the shared `BETTER_AUTH_SECRET`; no per-process auth state (Better Auth's rate limiter uses the DB too). BFF CSRF = SameSite=Lax + Origin check. |
| Shared cache, locks, rate limits | Redis `cache` DB. `Cache::lock()`, `RateLimiter`, `Settings` cache invalidation are cluster-wide. |
| Uploads | Only on the shared S3 disk (RustFS/S3). Never `storage/app` in prod. |
| Scheduler | `onOneServer()` + `withoutOverlapping()` on every scheduled task (Redis atomic locks), so running >1 scheduler is harmless. |
| Queue | Redis queue; N workers; jobs idempotent; `failed_jobs` in Postgres. |
| Migrations | One-off jobs per release (`php artisan migrate --force`; web: `node scripts/auth-migrate.mjs`), before rolling out new replicas. Never in the entrypoint. |
| Health | `/healthz` liveness (no I/O); `/readyz` readiness = Postgres `select 1` + Redis `PING` (not Keycloak). |
| nginx → app / web | `APP_UPSTREAM` (`app:8000`) and `WEB_UPSTREAM` (`web:3000`) are service names; nginx `proxy_pass`es over HTTP and re-resolves them with `NGINX_RESOLVER` so new replicas are picked up. The BFF calls back through nginx (`LMS_API_URL=http://nginx`) so app replicas stay balanced even with Node keepalive. |
| Octane workers | Each replica runs `OCTANE_WORKERS` long-lived PHP workers recycled after `OCTANE_MAX_REQUESTS`. No request state may survive a request (AGENTS.md §4 worker-mode rules). |

### Dockerfile targets

| Target | FROM | Contents |
|---|---|---|
| `app-base` | `dunglas/frankenphp:<pinned 1.x>-php8.4-alpine` (exact tag pinned, e.g. `1.9.1-php8.4-alpine`) | `install-php-extensions pdo_pgsql redis intl zip opcache pcntl bcmath`. `docker/php/php.ini` (opcache, `memory_limit`). `SERVER_NAME=:8000`, `OCTANE_SERVER=frankenphp`. Workdir `/app`. Non-root user. `HEALTHCHECK CMD curl -fsS http://127.0.0.1:8000/healthz`. Caddy Early Hints / Mercure **not** enabled (Later). |
| `app-dev` | `app-base` | + composer binary (`COPY --from=composer:2`), `UID`/`GID` build args. No code copied (bind mount). |
| `vendor` | `composer:2` | `composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader` using `composer.json/lock` only (layer cache) |
| `app-prod` | `app-base` | Copy code + vendor. `opcache.validate_timestamps=0`. **No** `config:cache` at build (env unknown). Entrypoint runs `php artisan config:cache && php artisan route:cache && php artisan view:cache`, then `exec "$@"`; default CMD starts Octane (or classic FrankenPHP when `APP_SERVER_MODE=classic`). Worker/scheduler/one-off override CMD with artisan commands. No `.env` in image. |
| `nginx` | `nginx:stable-alpine` | `docker/nginx/default.conf.template` (two upstreams `app` + `web`, path routing, headers). No static files (D-43). |
| `web/Dockerfile` `runner` | `node:24-alpine` | `.next/standalone` + `.next/static` + `public/`, `USER node`, `ENV PORT=3000 HOSTNAME=0.0.0.0`, no env baked in (no `NEXT_PUBLIC_*`), `CMD ["node","server.js"]`. |

`.dockerignore`: `.git`, `vendor`, `node_modules`, `web/` (own context and `web/.dockerignore`), `.env*` (but not `.env.example`), `storage/logs/*`, `storage/app/*`, `.logs`, `tests` (prod only; dev uses bind mount).

## 4. Data model

All tables use `bigIncrements id` and `timestamps()` (`timestamptz`) unless noted. Day quantities are `numeric(5,1)`, always multiples of 0.5. PHP casts them with `'float'`. Multiples of 0.5 are exact in binary floating point, so float math is safe.

```mermaid
erDiagram
    departments ||--o{ users : has
    users ||--o{ users : "manager_id"
    users ||--o{ leave_requests : submits
    leave_types ||--o{ leave_requests : of
    leave_requests ||--o{ leave_request_actions : history
    users ||--o{ leave_request_actions : actor
    users ||--o{ leave_ledger_entries : owns
    leave_types ||--o{ leave_ledger_entries : of
    leave_requests |o--o{ leave_ledger_entries : "debit/reversal"
    users |o--o{ settings : updated_by
    users ||--o{ user_admin_audits : actor
    users |o--o{ user_admin_audits : target

    departments {
        bigint id PK
        char(26) public_id UK "ULID"
        varchar(20) code UK
        varchar(100) name
    }
    users {
        bigint id PK
        varchar(100) name
        char(26) public_id UK "ULID, exposed in API"
        varchar(255) keycloak_sub UK "nullable until first login; OIDC sub"
        varchar(255) email UK "lowercased, synced from token"
        varchar(20) role "employee|manager|hr_admin; cache of the base realm role"
        boolean is_admin "default false; cache of realm role admin (D-29)"
        bigint department_id FK "nullable, restrictOnDelete"
        bigint manager_id FK "nullable -> users, nullOnDelete"
        varchar(50) employee_code UK "nullable"
        varchar(100) designation "nullable"
        varchar(50) employment_type "nullable"
        date date_of_joining "nullable until HR or token sets it"
        boolean is_active "default true"
        timestamptz last_login_at "nullable"
    }
    leave_types {
        bigint id PK
        char(26) public_id UK "ULID"
        varchar(10) code UK "immutable"
        varchar(100) name
        numeric annual_quota "5,1 default 0"
        boolean tracks_balance "default true"
        numeric carry_forward_cap "5,1 nullable: null=all"
        numeric max_balance "5,1 nullable: null=no cap"
        boolean allow_half_day "default true"
        smallint max_backdate_days "default 0"
        boolean is_active "default true"
    }
    holidays {
        bigint id PK
        char(26) public_id UK "ULID"
        date date UK
        varchar(100) name
    }
    leave_requests {
        bigint id PK
        char(26) public_id UK "ULID"
        bigint user_id FK
        bigint leave_type_id FK
        date start_date
        date end_date
        varchar(20) day_part "full|first_half|second_half"
        numeric days "5,1 server-computed"
        varchar(20) status "pending|approved|rejected|cancelled"
        text reason
        text handover_note "nullable"
        bigint decided_by FK "nullable -> users"
        timestamptz decided_at "nullable"
        text decision_comment "nullable"
        timestamptz cancelled_at "nullable"
    }
    leave_request_actions {
        bigint id PK
        char(26) public_id UK "ULID"
        bigint leave_request_id FK "cascadeOnDelete"
        bigint actor_id FK "-> users"
        varchar(20) action "submitted|approved|rejected|cancelled"
        varchar(20) from_status "nullable"
        varchar(20) to_status
        text comment "nullable"
        timestamptz created_at "no updated_at"
    }
    leave_ledger_entries {
        bigint id PK
        char(26) public_id UK "ULID"
        bigint user_id FK
        bigint leave_type_id FK
        smallint year
        varchar(20) kind "grant|carry_forward|lapse|debit|reversal|adjustment"
        numeric days "5,1 signed"
        bigint leave_request_id FK "nullable"
        bigint actor_id FK "nullable (null=system)"
        varchar(255) note "nullable"
        timestamptz created_at "no updated_at"
    }
    settings {
        varchar(100) key PK
        jsonb value
        bigint updated_by FK "nullable -> users"
        timestamptz updated_at
    }
    user_admin_audits {
        bigint id PK
        char(26) public_id UK "ULID"
        bigint actor_id FK "-> users (admin)"
        bigint target_user_id FK "nullable -> users (null if create failed before the local row)"
        varchar(255) keycloak_user_id "nullable"
        varchar(30) action "create|update_profile|enable|disable|assign_role|password_reset|compensate_delete"
        jsonb changes "before/after of changed fields, never secrets"
        varchar(10) outcome "success|failed"
        varchar(40) error_code "nullable, problem code"
        varchar(64) request_id "nullable"
        timestamptz created_at "no updated_at"
    }
```

### Constraints & indexes (put these in migrations)

| Table | Constraint / index |
|---|---|
| `users` | `CHECK (role IN (...))`, `CHECK (manager_id <> id)`, unique `(keycloak_sub)` (NULLs allowed), index `(department_id)`, `(manager_id)` |
| all API-exposed tables (`users`, `departments`, `leave_types`, `holidays`, `leave_requests`, `leave_request_actions`, `leave_ledger_entries`) | `public_id char(26) NOT NULL UNIQUE` (ULID, Laravel `HasUlids` with `uniqueIds() = ['public_id']`, `getRouteKeyName() = 'public_id'`). bigint `id` stays the PK/FK and is never exposed (D-21). |
| `leave_requests` | `CHECK (end_date >= start_date)`, `CHECK (days > 0)`, `CHECK (day_part = 'full' OR start_date = end_date)`, `CHECK (status IN (...))`, index `(user_id, status)`, `(status, start_date)` |
| `leave_requests` | **Overlap backstop:** `CREATE EXTENSION IF NOT EXISTS btree_gist;` then `ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_no_overlap EXCLUDE USING gist (user_id WITH =, daterange(start_date, end_date, '[]') WITH &&) WHERE (status IN ('pending','approved'));` Catch SQLSTATE `23P01` → 422 overlap error. |
| `leave_ledger_entries` | index `(user_id, leave_type_id, year)`. Partial unique `(user_id, leave_type_id, year, kind) WHERE kind IN ('grant','carry_forward','lapse')`. Partial unique `(leave_request_id, kind) WHERE leave_request_id IS NOT NULL`, so each request gets at most one debit and one reversal. |
| `leave_request_actions` | index `(leave_request_id, created_at)` |
| `holidays` | unique `(date)` |

Laravel framework tables (keep the stock migrations): `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches` (unused with Redis, kept as a fallback driver, D-23), `failed_jobs` (used). **Not created** (D-20): `password_reset_tokens` (drop it from the stock users migration), `personal_access_tokens` (do not run `install:api` with Sanctum; create `routes/api.php` by hand). Keycloak keeps its own tables in the separate `keycloak` database.

### Enums (`app/Enums`, string-backed)
`Role` (`employee`, `manager`, `hr_admin`, `admin`; `users.role` holds only a base role, `admin` is mirrored to `users.is_admin`, D-29), `LeaveStatus`, `DayPart`, `LedgerKind`, `LeaveAction`, `Module` (`holidays`, `reports`, `api`, `notifications`, `user_management`, `multi_level_approval`, `delegation`). Models cast columns to these enums.

## 5. Domain components

| Class | Responsibility |
|---|---|
| `App\Services\WorkingDayCalculator` | `count(CarbonImmutable $start, CarbonImmutable $end, DayPart $part, array $weekendIsoDays, array $holidayDates): float`. A **pure function** with no DB access, so it is unit-testable. A thin wrapper `forRange()` loads holidays (only if the `holidays` module is on) and the weekend days from `Settings::get('work.weekend_days')` (override ?? `LMS_WEEKEND_DAYS`, D-30). |
| `App\Services\LeaveBalanceService` | `balance()`, `pending()`, `available()`, `summary(User, int $year)` (entitled/used/pending/available per type). |
| `App\Actions\Leave\SubmitLeaveRequest` | Validation V1–V8 (USE_CASES §3.4) inside `DB::transaction`, with `User::lockForUpdate()` on the requester row to serialise that user's submissions. |
| `App\Actions\Leave\ApproveLeaveRequest` / `RejectLeaveRequest` / `CancelLeaveRequest` | Lock the request row (`lockForUpdate`). Assert the current status, else throw `InvalidTransition` (409). Write the ledger entry + action row. Dispatch the notification `afterCommit`. |
| `App\Actions\Accrual\GrantInitialEntitlement`, `RunYearStartAccrual` | USE_CASES §3.7. |
| `App\Actions\Users\DeactivateUser` | Set inactive, cancel pending requests. (No Laravel sessions, D-43: `KeycloakJwtGuard` refuses inactive users with 401 and the BFF then drops its session cookie.) |
| `App\Actions\Users\UpdateUserAction` | HR edit. LMS fields always (department, manager with cycle check, joining date, employee code, designation, employment type; first `date_of_joining` → `GrantInitialEntitlement`). Profile fields (email, first/last name) only with `user_management` on and a non-federated user: Keycloak `PUT` first, then mirror. Audited. |
| `App\Actions\Users\CreateUserAction` | UC-U2. `KeycloakAdminClient::createUser()` (enabled, no credentials) → id → role mapping → `DB::transaction` insert `users` with `keycloak_sub` + LMS fields → `executeActionsEmail([UPDATE_PASSWORD, VERIFY_EMAIL])` after commit (link lifespan `config('keycloak.actions_email_lifespan')`, default 86400 s). Local failure ⇒ `deleteUser()` compensation + `compensate_delete` audit. |
| `App\Actions\Users\AssignRolesAction` | UC-U6. Allow-list `employee|manager|hr_admin|admin`, `UserAdminGuard` (G2–G3 under Redis lock `lock:admin-guard`), set the base realm role (remove the other two), add/remove `admin`, mirror `users.role` + `users.is_admin`. Audited. |
| `App\Actions\Settings\UpdateWorkingWeek` | UC-H16 (D-30). Validates ISO weekdays 1–7, unique, ≤ 6 items; writes or deletes `work.weekend_days`; invalidates the settings cache. Never touches existing `leave_requests.days`. |
| `App\Actions\Users\SetUserEnabledAction` | UC-U4. Guardrails, Keycloak `enabled` flag, on disable `logoutUser()` then `DeactivateUser`; on enable local `is_active=true`. Audited. |
| `App\Actions\Users\SendPasswordResetAction` | UC-U5. Refuses federated users. `executeActionsEmail([UPDATE_PASSWORD])`. Audited. |
| `App\Services\UserAdminGuard` | G2–G5 checks (USE_CASES §3.11). Pure parts unit-tested. |
| `App\Support\Keycloak\KeycloakAdminClient` (interface) + `HttpKeycloakAdminClient` | Admin REST wrapper: `searchUsers`, `countUsers`, `getUser`, `createUser`, `updateUser`, `deleteUser`, `setEnabled`, `logoutUser`, `executeActionsEmail`, `realmRolesOf`, `addRealmRoles`, `removeRealmRoles`. Client-credentials token for `lms-admin` cached in Redis (`keycloak:admin-token`, TTL = `expires_in − 30 s`). Maps Keycloak 409 → `IdpConflict`, connect error/timeout/5xx → `IdpUnavailable`, federation refusal → `IdpReadOnly`. Bound in a service provider; tests bind `FakeKeycloakAdminClient`. |
| `App\Models\UserAdminAudit` | Append-only like the ledger (`update`/`delete` throw). |
| `App\Auth\KeycloakUserProvisioner` | `provision(array $claims): User`. Find by `keycloak_sub`, else link by verified email, else create. Sync `name`, `email`, `role` (`Role::fromKeycloakRoles(realm_access.roles)`, highest base role wins; none → `AuthorizationException`) and `is_admin` (`admin` in `realm_access.roles`). Grants initial entitlement when a `date_of_joining` claim first appears. Used only by the API guard: the first API call (the frontend calls `GET /me` right after sign-in) is the first login (D-43). |
| `App\Auth\KeycloakJwtGuard` | Custom guard `keycloak-jwt` (`Auth::extend`). Reads `Authorization: Bearer`, verifies RS256 signature with `JwksCache`, checks `iss` = `{KEYCLOAK_PUBLIC_URL}/realms/{realm}`, `aud`/`azp` in `KEYCLOAK_ALLOWED_AUDIENCES`, `exp`/`nbf` (`KEYCLOAK_JWT_LEEWAY`), then `KeycloakUserProvisioner` and `is_active`. Any failure → 401 problem+json. Uses `firebase/php-jwt`. |
| `App\Auth\JwksCache` | Fetches `{KEYCLOAK_BASE_URL}/realms/{realm}/protocol/openid-connect/certs` and caches it (`Cache::remember('keycloak:jwks', KEYCLOAK_JWKS_CACHE_TTL)` in Redis). On unknown `kid`, refetches once (key rotation), guarded by a Redis lock `keycloak:jwks-refresh` that is held for 10 s and never released, so bogus `kid`s cost Keycloak at most one call per 10 s (D-39). |
| `App\Support\Settings` (**scoped** binding, `$this->app->scoped()`) | Key/value read with env fallback. Cached in Redis (§8). Scoped so Octane workers resolve settings per request (D-27). |
| `App\Support\Modules` (scoped binding) | `enabled(Module $m): bool`, `all()` for the admin UI. |
| `App\Notifications\*` | `LeaveRequestSubmitted`, `LeaveRequestDecided`, `LeaveRequestCancelled` (no `AccountCreated`: Keycloak owns account mails, D-20). All `ShouldQueue`, mail channel. Sent only if `notifications` is enabled. |
| `App\Console\Commands\AccrueYear` | `lms:accrue-year {year?}` → `RunYearStartAccrual`. Scheduled `yearlyOn(config('lms.leave_year_start_month'), 1, '00:10')->onOneServer()->withoutOverlapping()` (D-24, D-26). |

## 6. HTTP surface

### 6.1 Frontend (Next.js + BFF, D-43)

**Edge routing (one origin, no CORS):**

| Path (NGINX) | Upstream | Notes |
|---|---|---|
| `/api/` | `app` | Laravel only: `/api/v1/*`, `/api/docs`, `/api/openapi.yaml`. Next never defines `/api/*`. |
| `/healthz`, `/readyz`, `/branding/` | `app` | Health and public branding assets. |
| `= /nginx-health` | nginx | |
| `/` (everything else) | `web` | Pages, `/auth/*` (Better Auth `basePath`), `/bff/*` (BFF handlers), `/_next/*`. |

**Next.js routes:**

| Path | Who (UI gate; Laravel Policies decide) | Module | API used (via `/bff/api`) |
|---|---|---|---|
| `/signin` (landing page), `POST /auth/sign-in/social`, `/auth/callback/keycloak`, `/auth/*` | public (Better Auth) | core | Keycloak |
| `POST /bff/logout` | session | core | Better Auth `signOut` → 303 to Keycloak end-session |
| `/bff/api/[...path]` | session | — | proxy to `/api/v1/{path}` |
| `GET /bff/healthz` | public, container healthcheck | — | none |
| `/` dashboard | any | core | `/me`, `/me/balances`, `/approvals` (count), Team Time Off (manager, hr_admin): `/leave-requests?filter[status]=approved,pending&filter[from]&filter[to]&include=user`, Next Holidays: `/holidays` (module) |
| `/leave-requests`, `/leave-requests/new`, `/leave-requests/[id]` | any | core | `/me/leave-requests`, `/leave-types`, `POST /leave-requests/preview` (debounced day count + balance), `POST /leave-requests` (`Idempotency-Key` per form submit), `/leave-requests/{id}`, `/cancel` |
| `/approvals` | manager, hr_admin | core | `/approvals`, `/approve`, `/reject` |
| `/team` | manager, hr_admin | core | `/users`, `/users/{id}/balances`, `/leave-requests` |
| `/holidays` | any | `holidays` | `/holidays` |
| `/admin/users`, `/admin/users/[id]` (HR fields, adjustments, deactivate) | hr_admin | core | `/users*`, `/users/{id}/ledger-entries` |
| `/admin/departments`, `/admin/leave-types`, `/admin/holidays` | hr_admin | core / `holidays` | CRUD endpoints |
| `/admin/reports` | hr_admin | `reports` | `/reports/*` (CSV streamed through the BFF) |
| `/admin/settings/working-week` | hr_admin | core | `/settings/working-week` |
| `/admin/settings/branding`, `/admin/settings/modules` | admin | core | `/settings/branding*`, `/modules*` |
| `/admin/identity-users`, `/admin/identity-users/new`, `/admin/identity-users/[id]` | admin | `user_management` | `/admin/users*` (503 `IDP_UNAVAILABLE` page, D-32) |

The paths for leave requests and approvals keep the old web paths, so notification mail links (`APP_URL` + path) work.

**Auth (BFF, D-45).** Better Auth `1.7.26` (exact pin), handler at `/auth/[...all]` (`basePath: '/auth'`, `baseURL: APP_URL`), `genericOAuth` provider `keycloak`: confidential client `lms-web`, auth code + PKCE S256 + `state` + OIDC `nonce`, ID token verified (`requireIdTokenVerification`). Endpoints come from discovery on the **internal** URL `${KEYCLOAK_BASE_URL}/realms/${KEYCLOAK_REALM}/.well-known/openid-configuration`: Keycloak hostname v2 (`KC_HOSTNAME` = public URL, `KC_HOSTNAME_BACKCHANNEL_DYNAMIC=true`) returns `issuer`, authorization and end-session on the public URL and token, userinfo and JWKS on the internal one, so no endpoint is hard-coded. No email/password, no other providers; the Better Auth `user` table only mirrors Keycloak users. Store: Postgres DB `lms_auth` (`AUTH_DATABASE_URL`, separate from `lms`): `session`, `account` (access/refresh/ID token, encrypted at rest), `verification` (OAuth state, so the callback may hit any replica), `rateLimit`. Cookies: `__Secure-lms.session_token` (signed) + `__Secure-lms.session_data` (JWE cookie cache, 5 min), HttpOnly, Secure in prod, SameSite=Lax; no tokens in cookies. Token use: one server helper gets the access token for the session's Keycloak account (`getAccessToken`, refreshing when < 60 s are left via `refreshToken`; Keycloak `lms-web` revokes refresh tokens, max reuse 1; on refresh failure re-read once, then delete the session ⇒ sign in again, silent while the Keycloak SSO session lives). Logout: `POST /bff/logout` (Origin-checked) → `auth.api.signOut` deletes the session and returns the Keycloak end-session URL with `id_token_hint`, `post_logout_redirect_uri=APP_URL/`, `client_id` → 303. Schema migrations: one-off `node scripts/auth-migrate.mjs` (§12). The browser never sees tokens.

**BFF proxy** `/bff/api/[...path]` → `${LMS_API_URL}/api/v1/{path}`: adds `Authorization: Bearer`, `X-Forwarded-For/-Proto/-Host`; forwards `Accept`, `Content-Type`, `Idempotency-Key`, streamed body; never `Cookie`. Returns status, body and `Content-Type`, `Content-Disposition`, `Location`, `Retry-After`, `X-RateLimit-*`, `Idempotent-Replayed`, `X-Request-Id`, `WWW-Authenticate` unchanged. No session ⇒ own `401` problem+json `UNAUTHENTICATED`; Laravel 401 ⇒ also clears the cookie. CSRF: mutating methods need `Origin` = `APP_URL` origin (else 403 `FORBIDDEN`). Details: API_GUIDELINES §5.1.

**Data.** Types from `openapi-typescript` (`web/src/lib/api/schema.d.ts`, committed, CI checks freshness), client `openapi-fetch`. Server components call `${LMS_API_URL}/api/v1` directly with the session's token; client components use TanStack Query against `/bff/api` (invalidate lists after mutations). Problem+json `errors` map to form field errors; `code` drives messages.

**UI.** Ant Design v6 (`antd`, D-45), `AntdRegistry` for SSR style extraction, `ConfigProvider` theme from branding (§8.2). Screens follow the Figma community file "Employee Leave Management" mapped onto antd (Layout + Sider nav, Card/Statistic balances, Table requests, Calendar holidays/team leave, Form + DatePicker.RangePicker apply, Drawer/Modal details); inventory, component mapping, tokens and breakpoints in [UI_GUIDELINES.md](UI_GUIDELINES.md). antd components are client components; data comes from server components or TanStack Query. Dark mode out of MVP.

**Theming and modules.** See §8.2 and §8.3.

**Laravel non-API routes (registered without session/CSRF middleware):** `GET /healthz`, `GET /readyz`, `GET /branding/{asset}` (`logo`|`favicon`, bundled default when not overridden), `GET /api/docs`, `GET /api/openapi.yaml` (`module:api`). Nothing else renders HTML.

### 6.2 API (`/api/v1`)

**Contract-first (D-21).** The source of truth is [`docs/api/openapi.yaml`](api/openapi.yaml) (OpenAPI 3.1); conventions are in [`docs/API_GUIDELINES.md`](API_GUIDELINES.md). Routes, Resources and error codes must match the spec; QA validates every API feature-test response against it. Docs UI: `/api/docs` (only when the `api` module is on).

Middleware on every `/api/v1` route except `GET /branding`: `module:api` (404 when off for third-party callers; tokens with `azp` = `KEYCLOAK_CLIENT_ID`, the BFF, pass, D-43) → `auth:keycloak-jwt` (401) → `active` → `throttle:api` (Redis, `X-RateLimit-*`, `Retry-After`); `POST /leave-requests` and `POST /admin/users` add `idempotent` (`EnsureIdempotency`, Redis 24 h). Errors are rendered as `application/problem+json` by the exception handler for `api/*` (API_GUIDELINES §4). `/api/docs` serves Swagger UI / Redoc for `docs/api/openapi.yaml` behind `module:api` (no auth; the spec holds no secrets). Envelope `{data, meta, links}`, snake_case, ULID `id`s (the `public_id` column), RFC 9457 `application/problem+json` errors with a stable `code`.

| Area | Endpoints (all under `/api/v1`) | Roles (`x-roles`) |
|---|---|---|
| Public branding (no auth, no `module:api`, limiter `public`, D-43) | `GET /branding` (brand + enabled module keys) | public |
| Me | `GET /me`, `GET /me/balances`, `GET /me/leave-requests`, `GET /me/ledger-entries` | any |
| Leave types | `GET /leave-types`, `GET /leave-types/{id}`; `POST`, `PATCH /leave-types/{id}` | read any; write hr_admin |
| Holidays (`holidays` module) | `GET /holidays`, `GET /holidays/{id}`; `POST`, `PATCH`, `DELETE /holidays/{id}` | read any; write hr_admin |
| Leave requests | `GET /leave-requests` (visible to caller), `POST /leave-requests/preview` (dry run: days + balance, D-43), `POST /leave-requests` (`Idempotency-Key`), `GET /leave-requests/{id}`, `POST /leave-requests/{id}/approve`, `/reject`, `/cancel` | policy `view/create/decide/cancel` |
| Approvals | `GET /approvals?filter[status]=pending` | manager, hr_admin |
| Balances / ledger | `GET /users/{id}/balances`, `GET /users/{id}/ledger-entries`, `POST /users/{id}/ledger-entries` (adjustment) | self/manager-of/hr_admin; write hr_admin |
| Users (LMS fields) | `GET /users`, `GET /users/{id}`, `PATCH /users/{id}` (department, manager, joining date, codes; role read-only here), `POST /users/{id}/deactivate`, `/activate` | hr_admin (manager: own reports, read) |
| Identity admin (`user_management` module, D-22) | `GET/POST /admin/users` (`Idempotency-Key` on POST), `GET/PATCH /admin/users/{id}`, `POST /admin/users/{id}/enable`, `/disable`, `PUT /admin/users/{id}/roles`, `POST /admin/users/{id}/password-reset`. Keycloak down ⇒ 503 `IDP_UNAVAILABLE` on all of them, no local fallback (D-32). | admin |
| Departments | `GET/POST /departments`, `GET/PATCH/DELETE /departments/{id}` | read any; write hr_admin |
| Settings | `GET/PATCH /settings/branding`, `DELETE /settings/branding` (reset), `PUT/DELETE /settings/branding/assets/{asset}`, `GET /modules`, `PATCH /modules/{key}`; `GET/PATCH /settings/working-week` (D-30) | branding + modules: admin (branding GET: any); working week: hr_admin (GET: any) |
| Reports (`reports` module) | `GET /reports/leave-requests`, `GET /reports/balances` (`Accept: application/json` or `text/csv`) | hr_admin |
| Health (outside v1, no auth) | `GET /healthz`, `GET /readyz` | public |

Tokens are obtained from Keycloak (client `lms-api-cli`, auth code + PKCE; the BFF uses `lms-web`), never from the app. There are no `/tokens` endpoints.

## 7. State machine
See USE_CASES §3.6 (diagram + transition/actor table). Implementation: `LeaveStatus::canTransitionTo(LeaveStatus $to): bool` holds the allowed edges. Actions call it. Policies decide **who**. The enum decides **whether**.

## 8. White-label: settings, theming, modules

### 8.1 Settings resolution
`effective(key) = settings.value (DB override) ?? config default (from env)`. Read via `App\Support\Settings::get(key)`. Cached with `Cache::rememberForever('settings:all', …)` (Redis cache store) and invalidated on every write. Memoised per request only (scoped binding; never in a static or singleton under Octane). There is no cross-replica staleness beyond one request because every replica reads the same Redis cache.

| Setting key | Env default | Validation | Used in |
|---|---|---|---|
| `brand.name` | `APP_BRAND_NAME` (default `"Leave Manager"`) | string 1–60 | `<title>`, header, mail `from.name`, mail subjects |
| `brand.primary_color` | `BRAND_PRIMARY_COLOR` (default `#1f6feb`) | regex `^#[0-9a-fA-F]{6}$` | `--brand-primary` |
| `brand.accent_color` | `BRAND_ACCENT_COLOR` (default `#0e8a16`) | same | `--brand-accent` |
| `brand.logo_path` | none → `public/img/default-logo.png` | upload: `mimes:png,jpg,jpeg,webp`, `max:1024` KB, `dimensions:max_width=1024,max_height=1024` | header, emails (absolute URL) |
| `brand.favicon_path` | none → `public/favicon.ico` | `mimes:png,ico`, `max:256` | `<link rel=icon>` |
| `modules.disabled` | `[]` | array of `Module` values ⊆ licensed | `Modules` |
| `work.weekend_days` | `LMS_WEEKEND_DAYS` (default `6,7`) | array of int 1–7, distinct, ≤ 6 items (at least one working day); edited by `hr_admin` (UC-H16, D-30) | `WorkingDayCalculator::forRange` (new submissions/approval checks only; stored `days` are never recalculated) |

`config/branding.php` holds the env defaults plus `disk => env('BRANDING_DISK', 's3')`. The `s3` disk (private bucket `AWS_BUCKET`) is configured by the standard `AWS_*` env vars (+ `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT=true` for RustFS; any S3-compatible store in prod). In dev the bucket is created by the `s3-init` container. `config/filesystems.php` also keeps a `branding` local disk (`storage_path('app/branding')`) for single-node installs only; it breaks with >1 replica (D-24). Composer dep: `league/flysystem-aws-s3-v3`.

### 8.2 Theming (Next.js, D-43; amends D-16)
- Laravel exposes the effective brand at public `GET /api/v1/branding` (`name`, colours, `primary_contrast` computed in PHP by luminance, `logo_url`, `favicon_url`, enabled `modules`). Values are regex-validated hex on write and re-validated on read (fallback to default).
- The Next root layout (server component) fetches it (`revalidate: BRANDING_REVALIDATE_SECONDS`, default 30, tag `branding`), re-validates the hex values again and renders `<AntdRegistry><ConfigProvider theme={{ token: { colorPrimary, colorLink, colorInfo, borderRadius, fontFamily } }}>` (antd v6 always emits CSS variables, D-45); antd derives its palette and emits CSS variables, so there is no per-customer build. Token mapping and breakpoints: [UI_GUIDELINES.md](UI_GUIDELINES.md). `generateMetadata` sets `<title>` (brand name) and `icons` (favicon URL).
- Branding writes through the BFF call `revalidateTag('branding')` on the serving replica; other replicas pick changes up within the TTL.
- Assets are served by `GET /branding/{asset}` → `Storage::disk(config('branding.disk'))->response($path)` (or the bundled default) with `Cache-Control: public, max-age=300` and ETag. Uploaded file names are `logo-{sha1}.{ext}`; URLs carry `?v=<sha1|default>`, so caching is safe. Nothing is baked into any image.
- SVG uploads are **rejected** (stored-XSS risk).
- Mail keeps Blade markdown templates; the brand name/logo come from `Brand::fromSettings()` per job.

### 8.3 Module registry `config/modules.php`

```php
return [
    'licensed' => array_filter(array_map('trim', explode(',', env('MODULES_ENABLED', 'holidays,reports,api,notifications,user_management')))),
    'registry' => [
        'holidays'             => ['label' => 'Holiday calendar',     'available' => true],
        'reports'              => ['label' => 'Reports & exports',    'available' => true],
        'api'                  => ['label' => 'REST API',             'available' => true],
        'notifications'        => ['label' => 'Email notifications',  'available' => true],
        'multi_level_approval' => ['label' => 'Multi-level approval', 'available' => false], // Later
        'delegation'           => ['label' => 'Approval delegation',  'available' => false], // Later
        'user_management'      => ['label' => 'User management (Keycloak)', 'available' => true], // D-22
    ],
];
```

`Modules::enabled($m) = in_array($m, licensed) && registry[$m].available && !in_array($m, Settings::get('modules.disabled'))`.

**Deployment checklist (D-34):** for a customer whose realm is backed by a directory (LDAP/AD user federation or Entra ID / SAML brokering), leave `user_management` **out** of `MODULES_ENABLED`; users and roles are then managed in the directory/Keycloak. `.env.example` keeps it listed with a comment saying so. There is no auto-detection.

Gating points (all four are required):

| Layer | Mechanism |
|---|---|
| Routes | Middleware alias `module` (`EnsureModuleEnabled`): `Route::middleware('module:reports')->group(...)`. Calls `abort(404)` when off. |
| Frontend | `modules` from `GET /api/v1/branding`: nav items hidden; module route segments (`/holidays`, `/admin/reports`, `/admin/identity-users`, …) call `notFound()` (Next 404). Laravel's 404 stays authoritative (D-43). |
| Domain | `WorkingDayCalculator::forRange` checks `holidays`. Notification dispatch checks `notifications`. |
| Admin UI | Next `/admin/settings/modules` lists `GET /modules`. Unlicensed/unavailable (`locked`) modules are disabled switches. `PATCH /modules/{key}` accepts only licensed+available keys (422 otherwise). `api` may be switched off: it only blocks third-party clients (D-43). |

## 9. 12-factor mapping

| # | Factor | Implementation |
|---|---|---|
| I | Codebase | One repo, many deploys (one per customer). Images tagged by git SHA/semver. |
| II | Dependencies | `composer.lock` and `web/package-lock.json` committed (exact pins for `better-auth`, `antd`). PHP extensions declared in Dockerfile. No reliance on host tools (host has no PHP). |
| III | Config | Everything via env (`.env` only in dev, never in image). `.env.example` lists every key. Branding defaults + licensed modules via env. Admin overrides in DB are *data*, not config. |
| IV | Backing services | Postgres (`lms`, and `lms_auth` for web via `AUTH_DATABASE_URL`), Redis, SMTP, S3 (RustFS), Keycloak by URL/env (`DB_*`, `AUTH_DATABASE_URL`, `REDIS_*`, `MAIL_*`, `AWS_*`, `KEYCLOAK_*`). Swap without code change. |
| V | Build, release, run | CI builds an immutable image. Release = image + env. Caches (`config:cache` etc.) are built at container start. |
| VI | Processes | Stateless processes: no Laravel sessions; web sessions/tokens in Postgres `lms_auth` (D-45), cache/locks/queue in Redis, uploads on the shared S3 disk. No local state in prod containers, no sticky sessions. |
| VII | Port binding | Fully satisfied: the app (FrankenPHP/Octane `:8000`) and web (Next standalone `:3000`) are self-contained HTTP servers; nginx on 80 is the path router. |
| VIII | Concurrency | Scale `nginx`, `web`, `app`, `worker`, `keycloak` horizontally. `scheduler` = 1 replica, tasks `onOneServer()` + `withoutOverlapping()` (Redis locks). See §3.1. |
| IX | Disposability | Fast start. Octane drains workers on SIGTERM; `queue:work` finishes the current job on SIGTERM. |
| X | Dev/prod parity | Same Dockerfile, same Postgres (17) and Redis (7) majors, same drivers, S3 API in dev too (RustFS). Only Mailpit and the Keycloak start mode (`start-dev` + realm import) differ. |
| XI | Logs | `LOG_CHANNEL=stderr`, `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`. nginx access/error → stdout/stderr. No log files. |
| XII | Admin processes | `docker compose run --rm app php artisan migrate --force`, `... lms:accrue-year 2027`, `... db:seed --class=DemoSeeder`; web: `docker compose run --rm web node scripts/auth-migrate.mjs`. In prod: same images as one-off jobs. |

### Environment variables (`.env.example` must contain all)

| Var | Dev default | Notes |
|---|---|---|
| `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` | `LMS`, `local`, (generate), `true`, `http://localhost:8080` | |
| `APP_TIMEZONE` | `UTC` | wire into `config/app.php` |
| `LOG_CHANNEL`, `LOG_STDERR_FORMATTER`, `LOG_LEVEL` | `stderr`, `Monolog\Formatter\JsonFormatter`, `debug` | |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `pgsql`, `postgres`, `5432`, `lms`, `lms`, `secret` | |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `array`, `redis`, `redis` | D-23, D-43 (API-only: no sessions; `SESSION_CONNECTION` removed) |
| `REDIS_CLIENT`, `REDIS_URL`, `REDIS_SCHEME`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD` | `phpredis`, empty, `tcp`, `redis`, `6379`, empty, `null` | managed/TLS Redis: `REDIS_SCHEME=tls` or `REDIS_URL=tls://…` |
| `REDIS_DB`, `REDIS_CACHE_DB`, `REDIS_QUEUE_DB`, `REDIS_PREFIX` | `0`, `1`, `3`, `lms-` | separate logical DBs so `cache:clear` never drops jobs (DB 2 unused since D-43) |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS` | `smtp`, `mailpit`, `1025`, `noreply@example.test` | from-name = brand name |
| `LMS_WEEKEND_DAYS` | `6,7` | ISO weekdays; default only, `hr_admin` may override via `work.weekend_days` (D-30) |
| `LEAVE_YEAR_START_MONTH` | `1` | 1–12, first month of the leave year (D-26). `4` = April–March. |
| `LMS_SL_MAX_BACKDATE_DAYS` | `7` | seed default for SL `max_backdate_days` |
| `APP_BRAND_NAME`, `BRAND_PRIMARY_COLOR`, `BRAND_ACCENT_COLOR` | `Leave Manager`, `#1f6feb`, `#0e8a16` | |
| `MODULES_ENABLED` | `holidays,reports,api,notifications,user_management` | licensed set; drop `user_management` for directory-backed realms (D-34) |
| `FILESYSTEM_DISK`, `BRANDING_DISK` | `s3`, `s3` | `branding` (local) only for single-node installs |
| `RUSTFS_ACCESS_KEY`, `RUSTFS_SECRET_KEY` | `lmsdev`, `lmsdevsecret` | dev RustFS server credentials (compose only) |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | `${RUSTFS_ACCESS_KEY}`, `${RUSTFS_SECRET_KEY}`, `us-east-1`, `lms`, `http://rustfs:9000`, `true` | any S3-compatible store in prod |
| `KEYCLOAK_BASE_URL` | `http://keycloak:8080` | **back-channel** URL used by app/worker (token exchange, userinfo, JWKS, Admin API) |
| `KEYCLOAK_PUBLIC_URL` | `http://localhost:8081` | browser redirects and token issuer: expected `iss` = `{KEYCLOAK_PUBLIC_URL}/realms/{KEYCLOAK_REALM}` |
| `KEYCLOAK_REALM` | `lms` | |
| `KEYCLOAK_CLIENT_ID` | `lms-web` | app: the first-party BFF client; tokens with this `azp` pass `module:api` (D-43). The secret lives only in `web` (`KEYCLOAK_CLIENT_SECRET`, D-45). |
| `KEYCLOAK_ALLOWED_AUDIENCES` | `lms-web,lms-api-cli` | a JWT is accepted only if its `aud` (or `azp`) is in this list |
| `KEYCLOAK_JWT_LEEWAY` | `30` | seconds of clock skew for `exp`/`nbf`/`iat` |
| `KEYCLOAK_JWKS_CACHE_TTL` | `3600` | seconds, Redis |
| `KEYCLOAK_ADMIN_CLIENT_ID`, `KEYCLOAK_ADMIN_CLIENT_SECRET` | `lms-admin`, `lms-admin-dev-secret` | service-account client for the Admin REST API (D-22). **Secret**. |
| `KEYCLOAK_ADMIN`, `KEYCLOAK_ADMIN_PASSWORD` | `admin`, `admin` | compose only: Keycloak bootstrap admin (`KC_BOOTSTRAP_ADMIN_*`) |
| Keycloak container | — | `KC_DB=postgres`, `KC_DB_URL=jdbc:postgresql://postgres:5432/keycloak`, `KC_DB_USERNAME`, `KC_DB_PASSWORD`, `KC_HOSTNAME=${KEYCLOAK_PUBLIC_URL}`, `KC_HOSTNAME_BACKCHANNEL_DYNAMIC=true`, `KC_HTTP_ENABLED=true` (dev), `KC_HEALTH_ENABLED=true`; prod runs the upstream image with `start` (cache `ispn` is the default, D-33) |
| `APP_SERVER_MODE` | `octane` | `octane` (FrankenPHP worker mode) or `classic` (non-worker FrankenPHP, debugging fallback) |
| `OCTANE_SERVER` | `frankenphp` | |
| `OCTANE_WORKERS` | `auto` | workers per replica (auto = CPU count) |
| `OCTANE_MAX_REQUESTS` | `500` | recycle a worker after N requests (`--max-requests`) |
| `SERVER_NAME` | `:8000` | Caddy site address; disables FrankenPHP auto-HTTPS |
| `APP_UPSTREAM` | `app:8000` | nginx template: HTTP upstream of the app replicas |
| `NGINX_RESOLVER` | `127.0.0.11` | DNS nginx uses to re-resolve `APP_UPSTREAM` (k8s: kube-dns IP) |
| `TRUSTED_PROXIES` | `*` (dev) | behind ingress; prod: the nginx and web networks (client IP via `X-Forwarded-For` for the `public` limiter) |
| `WEB_UPSTREAM` | `web:3000` | nginx template: HTTP upstream of the web replicas (D-43) |

**`web` container env (D-43, runtime only, no `NEXT_PUBLIC_*`):**

| Var | Dev default | Notes |
|---|---|---|
| `APP_URL` | `http://localhost:8080` | public origin; Better Auth `baseURL` (callback `${APP_URL}/auth/callback/keycloak`), trusted origin, BFF Origin check, post-logout redirect |
| `BETTER_AUTH_SECRET` | (generate, `openssl rand -base64 32`) | **Secret**, ≥ 32 random bytes, identical on all web replicas (cookie signing/encryption, token encryption at rest). Rotation: `BETTER_AUTH_SECRETS` (D-45) |
| `AUTH_DATABASE_URL` | `postgres://lms:secret@postgres:5432/lms_auth` | Better Auth store, DB `lms_auth` (not Laravel's `lms`); own DB user in prod. **Secret** (contains the password) |
| `KEYCLOAK_CLIENT_ID`, `KEYCLOAK_CLIENT_SECRET` | `lms-web`, `lms-web-dev-secret` | confidential client; **Secret**, regenerate per deployment; the secret exists only in `web` |
| `KEYCLOAK_BASE_URL`, `KEYCLOAK_REALM` | `http://keycloak:8080`, `lms` | discovery is fetched from the internal URL; Keycloak returns browser-facing URLs for authorization/end-session (D-45) |
| `LMS_API_URL` | `http://nginx` | internal base URL of Laravel through nginx (BFF + server components) |
| `BRANDING_REVALIDATE_SECONDS` | `30` | Next fetch cache TTL for `GET /api/v1/branding` |
| `AUTH_SESSION_MAX_AGE` | `36000` | Better Auth `session.expiresIn` in seconds; keep ≤ Keycloak SSO Session Max |
| `PORT`, `HOSTNAME`, `NODE_ENV` | `3000`, `0.0.0.0`, `production` | |

## 10. Security

| Concern | Control |
|---|---|
| AuthN web | BFF (D-43, D-45): Keycloak OIDC auth code (client `lms-web`, `state` + `nonce` + PKCE S256, ID token verified against the JWKS) run server-side by Better Auth in `web`. Tokens stored in `lms_auth.account`, encrypted at rest; the browser holds only the HttpOnly SameSite=Lax session cookies, never a token. Every API call carries the access token to Laravel, which verifies it (`KeycloakJwtGuard`), provisions/syncs the user and checks `is_active`. Logout is RP-initiated (end-session with `id_token_hint`). Passwords, MFA, lockout and brute-force detection live in Keycloak. |
| AuthN API | Bearer JWT (RS256) from Keycloak (client `lms-api-cli`, public + PKCE). Guard `keycloak-jwt` checks signature (JWKS cached in Redis, refetch on unknown `kid`), `iss`, `aud`/`azp` against `KEYCLOAK_ALLOWED_AUDIENCES`, `exp`/`nbf` with `KEYCLOAK_JWT_LEEWAY`. No app-issued tokens. Inactive user → 401. |
| Roles | Keycloak realm roles are the RBAC authority; `users.role` (base role) and `users.is_admin` are caches refreshed on every login/API call. Roles change in the app only through UC-U6, by an `admin` (D-22, D-29). |
| AuthZ | Role enum + **Policies** (`LeaveRequestPolicy`: `view, create, cancel, decide`; `UserPolicy` HR fields, `LeaveTypePolicy`, `HolidayPolicy`, `DepartmentPolicy`, `SettingsPolicy::updateWorkingWeek` → `hr_admin`; `SettingsPolicy::manageBranding`/`manageModules`, `UserPolicy::manageIdentity` (UC-U*) → `admin`, D-29). `Gate::before` is **not** used (HR must not approve their own requests). Every controller method calls `$this->authorize()` / `Gate::authorize()`. |
| CSRF | Laravel: Bearer only, no cookies, so no CSRF surface. BFF: SameSite=Lax session cookie + `Origin` must equal the `APP_URL` origin on `POST/PUT/PATCH/DELETE` to `/bff/*`; Better Auth's own Origin check on its `/auth/*` POSTs; OIDC `state` + PKCE protect the callback. |
| Rate limiting | `RateLimiter::for('api', 60/min by user id or IP)`, `RateLimiter::for('public', 300/min by client IP)` on `GET /api/v1/branding` with `X-RateLimit-*` / `Retry-After` headers (D-21). Credential brute-force protection is Keycloak's. |
| Input | Form Requests on every write. Eloquent bindings only (no raw string SQL with input). Mass assignment via `$fillable`. |
| Output | React escaping; no `dangerouslySetInnerHTML`. Brand colours injected only as validated hex CSS variables. Mail: Blade `{{ }}` escaping. |
| Uploads | Mime + size + dimension validation. No SVG. Random hashed names. Private disk served via controller. |
| Headers | nginx (edge, in front of web and app): `X-Content-Type-Options nosniff`, `X-Frame-Options DENY`, `Referrer-Policy strict-origin-when-cross-origin`, `server_tokens off`. HSTS at ingress. |
| Data | No hard deletes of users or requests. Ledger append-only (model `update()`/`delete()` throw). |
| Secrets | Only via env / orchestrator secrets. Never committed, never in the image. |

### 10.1 Identity (Keycloak, D-20)

| Item | Spec |
|---|---|
| Realm | `lms` per deployment. Realm roles `employee`, `manager`, `hr_admin` (base, exactly one per user) and `admin` (additive: branding, modules, identity admin, D-29). Default role for new users: `employee`. Customers run **upstream** Keycloak and import/configure this realm; the LMS publishes no Keycloak image or theme (D-33). |
| Clients | `lms-web`: confidential, standard flow only, PKCE S256 required, used server-side by the Next.js BFF (D-43), redirect `${APP_URL}/auth/callback/keycloak`, post-logout redirect `${APP_URL}/*`, no web origins, revoke refresh token on with max reuse 1. `lms-api-cli`: public, standard flow + PKCE S256, loopback redirect URIs. `lms-dev-cli` (**dev realm import only**): direct access grants for local smoke tests, audience mapper adds `lms-api-cli` so the API accepts its tokens, never imported in prod. All three: realm roles in `realm_access.roles` (mapper also on ID token + userinfo). `default-roles-lms` includes `employee`. `lms-admin`: confidential, **service account only** (client credentials, no browser flows), default client scopes `basic` + `kc-admin-api` (Keycloak 26.8 rejects Admin API tokens without the admin audience), service-account roles `realm-management`: `manage-users`, `view-users`, `query-users` (effective set also has `query-groups`, a composite of `view-users`) — never `realm-admin` (D-22, D-39). |
| Claims used | `sub` → `users.keycloak_sub`, `email`, `email_verified`, `name`, `realm_access.roles`, optional `date_of_joining` (user-attribute mapper). |
| Enterprise | User federation (LDAP / Active Directory), identity brokering (SAML 2.0, OIDC e.g. Entra ID / Azure AD, Google), MFA/OTP/WebAuthn, password policy — all configured in Keycloak per customer, no app change. Login page branding (display name, optional theme) is the customer's own Keycloak configuration (UC-B9, D-33). Directory-backed realms run without `user_management` (D-34). |
| Resource authorization | Laravel Policies (direct manager approves, nobody approves own, HR override rules). Keycloak roles only say *what kind of user*. |
| Availability | Prod: ≥2 Keycloak replicas, clustered (Infinispan, `KC_CACHE=ispn`), DB `keycloak` on the managed Postgres. The app only needs Keycloak at login and for JWKS refresh; JWT validation works from the Redis-cached JWKS during short Keycloak outages. Identity admin (`/admin/users`, web identity-user pages) needs the Admin API and returns 503 `IDP_UNAVAILABLE` during an outage, with no stale local fallback; HR pages on local data keep working (D-32). |
| Health | `/readyz` does **not** call Keycloak (avoids cascading restarts); Keycloak exposes its own `/health/ready` (`KC_HEALTH_ENABLED`). |

## 11. Observability

| Item | Spec |
|---|---|
| Logs | JSON lines to stderr (Monolog `JsonFormatter`). Add context via `Log::withContext(['request_id' => …, 'user_id' => …])` in middleware. `X-Request-Id` comes from nginx `$request_id` (forwarded as `proxy_set_header X-Request-Id`) and is echoed in the response header. |
| Domain events | `Log::info('leave.approved', [...ids, days])` for each transition, accrual run summary, settings change. |
| `GET /healthz` | Liveness, served by Octane on `:8000`. No DB. `200 {"status":"ok"}`. Registered without session/CSRF middleware (Laravel has none since D-43). |
| `GET /readyz` | Readiness. `DB::select('select 1')` + `Schema::hasTable('migrations')` and Redis `PING` → `200 {"status":"ready","checks":{"db":"ok","redis":"ok"}}`, else `503 {"status":"unavailable","checks":{…"fail"}}`. Does **not** check Keycloak, S3 or SMTP (a dependency blip must not restart every replica). No secrets in output. |
| nginx | `location = /nginx-health { return 200; }` for the nginx container's own healthcheck. |
| Queue | Redis queue; `failed_jobs` table in Postgres. `php artisan queue:failed` as a one-off. |
| Keycloak | `/health/ready`, `/metrics` on the management port (9000). |
| Compose healthchecks | web `wget :3000/bff/healthz`, postgres `pg_isready`, redis `redis-cli ping`, rustfs `/health`, keycloak `/health/ready`, nginx `/nginx-health`; `s3-init` must complete before app/worker/scheduler start. app: `curl -fsS http://127.0.0.1:8000/healthz` (image `HEALTHCHECK`); worker/scheduler: healthcheck disabled (no HTTP). |

## 12. Migrations strategy

- Migrations are **forward-only in prod**. Write `down()` for dev convenience, but never rely on it.
- Run as a one-off **before** rolling out new app containers: `docker compose run --rm app php artisan migrate --force`. Never auto-migrate in the web entrypoint (avoids races with N replicas).
- Expand/contract for breaking changes: add nullable column → deploy code writing both → backfill (one-off command) → enforce NOT NULL / drop old in a later release.
- Postgres-specific DDL (`btree_gist`, exclusion constraint, partial unique indexes, CHECKs) via `DB::statement()` inside migrations. Feature tests run on real Postgres, so these are tested.
- Seeders: `LeaveTypeSeeder` (CL/SL/EL/LWP, idempotent `updateOrCreate` by code) runs in all envs. `DemoSeeder` runs **only** when `APP_ENV=local|testing`: departments CE/IT/MH/EC/IC/CL/CH from the original, 2026+2027 holidays, and the four **dev users** that also exist in the dev Keycloak realm import (`docker/keycloak/realm-lms.json`): `alice` (`alice@example.test`, employee, IT, manager = bob), `bob` (`bob@example.test`, manager, IT), `carol` (`carol@example.test`, hr_admin, HR), `dave` (`dave@example.test`, employee + `admin`, IT, D-29). Local rows are seeded with `keycloak_sub = null` and are linked by verified email on first login (USE_CASES §3.10). Passwords exist only in Keycloak (dev value documented in the realm file, DEV ONLY). No other demo users are seeded, because users without a Keycloak account cannot log in.
- Migration file order: departments → users (alter stock: drop `password`, `remember_token`, `email_verified_at`, `password_reset_tokens`; add `public_id`, `keycloak_sub`, LMS columns) → leave_types → holidays → leave_requests → leave_request_actions → leave_ledger_entries → settings → user_admin_audits.
- Keycloak's schema lives in the separate `keycloak` database and is migrated by Keycloak itself on start.
- Better Auth's schema (`user`, `session`, `account`, `verification`, `rateLimit`) lives in the separate `lms_auth` database and is migrated by the one-off `node scripts/auth-migrate.mjs` in the `web` image (owner: frontend, D-45), before web replicas roll out. Laravel never reads it.

## 13. CI/CD (GitHub Actions)

Two workflows (D-25, D-38, D-43). `.github/workflows/ci.yml` (`lint`, `openapi`, `unit`, `feature`, web jobs), triggers: `pull_request`, `push` to `main`, `workflow_dispatch`, `workflow_call`; superseded PR runs are cancelled. `.github/workflows/package.yml` (`ci` → `smoke` → `package`), triggers: `push` to `main`, tags `v*`, `workflow_dispatch`; it calls `ci.yml` as a reusable workflow, so images are only pushed after CI and the smoke test pass. Smoke does not run on PRs.

| Job | Runs on | Steps | Needs |
|---|---|---|---|
| `lint` | ubuntu-latest | `shivammathur/setup-php@v2` (php 8.4, ext `pdo_pgsql,intl,bcmath,zip,redis`), composer cache, `composer install`, `vendor/bin/pint --test` | — |
| `openapi` | ubuntu-latest | `npx --yes @redocly/cli@<pinned> lint docs/api/openapi.yaml` (fails on errors; the 2 documented warnings on `/healthz` `/readyz` are accepted) | — |
| `unit` | ubuntu-latest | same PHP setup, `cp .env.example .env && php artisan key:generate`, `php artisan test --testsuite=Unit` | — |
| `feature` | ubuntu-latest + services `postgres:17` (`POSTGRES_DB=lms_test`, `POSTGRES_USER=lms`, `POSTGRES_PASSWORD=secret`, healthcheck `pg_isready`, port 5432) and `redis:7` (healthcheck `redis-cli ping`, port 6379) | same setup, env `DB_HOST=127.0.0.1 DB_DATABASE=lms_test REDIS_HOST=127.0.0.1`, `php artisan test --testsuite=Feature`. API tests validate responses against `docs/api/openapi.yaml`. No Keycloak: tests sign JWTs with a test key and fake `JwksCache` / `KeycloakAdminClient`. | — |
| `web-lint` | ubuntu-latest, `actions/setup-node` (Node 24, npm cache, `working-directory: web`) | `npm ci`, `npm run lint` (ESLint + `prettier --check`), `npm run typecheck` (`tsc --noEmit`), types fresh: `npm run gen:api` then `git diff --exit-code src/lib/api/schema.d.ts` | — |
| `web-test` | same Node setup | `npm ci`, `npm test` (Vitest) | — |
| `web-build` | same Node setup | `npm ci`, `npm run build` (`next build`, no env baked) | — |
| `smoke` (package.yml) | ubuntu-latest | `cp .env.example .env` (+ runner `UID`/`GID`), `docker compose build`, `composer install` + `key:generate` in a one-off app container, `docker compose up -d --wait --scale app=2 --scale web=2` (all healthchecks incl. web, keycloak, rustfs, s3-init), `docker compose run --rm migrate` + `DemoSeeder`, `docker compose run --rm web node scripts/auth-migrate.mjs`, `curl -fsS localhost:8080/healthz`, `curl -fsS localhost:8080/readyz`, `curl -fsS localhost:8080/api/v1/branding`, `curl -fsS localhost:8081/realms/lms/.well-known/openid-configuration`, Playwright e2e (`web/e2e`, pinned `mcr.microsoft.com/playwright` image, `--network host`, report uploaded as artifact), then always `docker compose down -v`. Dumps `docker compose logs` on failure. | `ci` |
| `push` (package.yml) | ubuntu-latest, `permissions: {contents: read, packages: write}`, matrix `app`/`nginx`/`web` | `docker/setup-qemu-action` (tags only), `docker/setup-buildx-action`, `docker/login-action` (ghcr.io, `${{ github.actor }}`, `GITHUB_TOKEN`), `docker/metadata-action` (tags `type=sha`, `type=ref,event=branch`, `type=semver` `{{version}}` + `{{major}}.{{minor}}`, `latest` on the default branch; label `org.opencontainers.image.licenses=MIT`), `docker/build-push-action` ×3 (`target: app-prod` → `ghcr.io/${{ github.repository }}/app`, `target: nginx` → `ghcr.io/${{ github.repository }}/nginx`, context `web/` target `runner` → `ghcr.io/${{ github.repository }}/web`). **Platforms:** `linux/amd64` on `main`; `linux/amd64,linux/arm64` on `v*` tags. `cache-from/to: type=gha` (scope per image). | `ci, smoke` |

Image names are derived from `github.repository` and lowercased (`${GITHUB_REPOSITORY,,}`), which gives `ghcr.io/heyadityak/lms/app`, `/nginx` and `/web` for the `heyadityak/lms` repository. The test jobs use setup-php instead of the image for speed; the image itself is validated by `smoke` and `package`, and parity is kept on PHP 8.4, Postgres 17 and Redis 7 (D-10, D-25). No Keycloak image is built or published: customers run upstream `quay.io/keycloak/keycloak` and import/configure the documented realm (D-33).

## 14. Sources
- Laravel 12 release notes (PHP 8.2–8.5 support): https://laravel.com/docs/12.x/releases
- Laravel deployment/optimisation, queues, scheduling, Octane, filesystem, logging: https://laravel.com/docs/12.x/deployment , https://laravel.com/docs/12.x/queues , https://laravel.com/docs/12.x/scheduling , https://laravel.com/docs/12.x/octane , https://laravel.com/docs/12.x/filesystem , https://laravel.com/docs/12.x/logging
- Twelve-Factor App: https://12factor.net/
- FrankenPHP (worker mode, Docker images, `install-php-extensions`): https://frankenphp.dev/docs/ , https://frankenphp.dev/docs/docker/ , https://frankenphp.dev/docs/laravel/
- Laravel Redis / cache locks / scheduling `onOneServer`: https://laravel.com/docs/12.x/redis , https://laravel.com/docs/12.x/cache#atomic-locks , https://laravel.com/docs/12.x/scheduling#running-tasks-on-one-server
- RustFS (S3-compatible object storage): https://rustfs.com/ , https://github.com/rustfs/rustfs
- Multi-platform builds: https://docs.docker.com/build/ci/github-actions/multi-platform/
- Keycloak 26 server docs (hostname v2, clustering/caching, OIDC clients, user federation, themes): https://www.keycloak.org/documentation , https://www.keycloak.org/server/hostname , https://www.keycloak.org/server/caching
- JWT validation: https://github.com/firebase/php-jwt
- OAuth 2.0 for Browser-Based Applications (BFF pattern): https://datatracker.ietf.org/doc/draft-ietf-oauth-browser-based-apps/
- Next.js (App Router, standalone output, `generateMetadata`, caching): https://nextjs.org/docs ; Better Auth 1.7 (generic OAuth plugin, session management, database, cookies): https://better-auth.com/docs/plugins/generic-oauth , https://better-auth.com/docs/concepts/session-management , https://better-auth.com/docs/concepts/database ; Ant Design v6 migration and Next.js: https://ant.design/docs/react/migration-v6 , https://ant.design/docs/react/use-with-next
- openapi-typescript / openapi-fetch: https://openapi-ts.dev/ ; TanStack Query: https://tanstack.com/query ; Ant Design: https://ant.design/docs/react/introduce , https://ant.design/docs/react/use-with-next , https://ant.design/docs/react/css-variables ; Figma reference: https://www.figma.com/community/file/1473575090164873432/employee-leave-management ; Playwright + axe: https://playwright.dev/docs/accessibility-testing
- RFC 9457 Problem Details: https://www.rfc-editor.org/rfc/rfc9457 ; OpenAPI 3.1: https://spec.openapis.org/oas/v3.1.0 ; IETF Idempotency-Key draft: https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/
- Leave ledger/LLD reference: https://techinterview.org/lld-employee-leave-management/
- Leave policy reference: https://udyamo-hrms-book.pages.dev/en/part-5/ch22-leave-policies
- PostgreSQL exclusion constraints / range types: https://www.postgresql.org/docs/17/rangetypes.html#RANGETYPES-CONSTRAINT
- NGINX docker image templates (envsubst): https://hub.docker.com/_/nginx
- GHCR publishing via Actions: https://docs.github.com/en/actions/use-cases-and-examples/publishing-packages/publishing-docker-images
- CSS custom properties: https://developer.mozilla.org/en-US/docs/Web/CSS/Using_CSS_custom_properties

## 15. Diagrams

Mermaid diagrams (render on GitHub; SVG exports in `docs/diagrams/svg/`). Index: [docs/diagrams/README.md](diagrams/README.md).

| Diagram | Link |
|---|---|
| System context, containers, deployment per customer, horizontal scaling | [01-system-design.md](diagrams/01-system-design.md) |
| Data flow (DFD level 0 and 1, stores D1–D14) | [02-data-flow.md](diagrams/02-data-flow.md) |
| Class diagrams (models/enums, domain logic, identity, HTTP) + class names to adopt | [03-class-diagram.md](diagrams/03-class-diagram.md) |
| Object diagram (snapshot with balances) | [04-object-diagram.md](diagrams/04-object-diagram.md) |
| Activity diagrams (apply, approve/reject, cancel, accrual, settings, first login, role guardrails) | [05-activity-diagrams.md](diagrams/05-activity-diagrams.md) |
| Sequence diagrams (OIDC login/logout via Next BFF, API JWT, leave flows, accrual, readiness, branding, API errors + idempotency, user creation + compensation) | [06-sequence-diagrams.md](diagrams/06-sequence-diagrams.md) |
| Leave request state machine | [07-state-diagram.md](diagrams/07-state-diagram.md) |
