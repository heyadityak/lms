# AGENTS.md — Builder contract

Every agent (human or AI) working in this repo follows this file. Read [docs/USE_CASES.md](docs/USE_CASES.md) (what) and [docs/SYSTEM_DESIGN.md](docs/SYSTEM_DESIGN.md) (how) before writing code. Decisions are in [.logs/decisions.md](.logs/decisions.md). Do not re-decide them silently. Propose a new ADR instead.

## 1. Project overview
A greenfield rewrite of a 2016 PHP college project (Leave Management System) as a **white-label, single-tenant** product: one deployment per customer (SMB/enterprise), each with its own branding (name, colours, logo, favicon) and module set (holidays, reports, api, notifications, …).

Core features: employees apply for leave, managers approve/reject, HR admins (`hr_admin`) run HR operations (departments, leave types, holidays, working week, balance adjustments, reports, users' HR fields), admins (`admin`, additive realm role, D-29) manage branding, modules and Keycloak users. Balances are kept in an append-only ledger, with year-start accrual and carry-forward.

## 2. Stack (fixed)

| Component | Version |
|---|---|
| PHP runtime | **FrankenPHP** 1.x with PHP 8.4 (`dunglas/frankenphp:<pinned 1.x>-php8.4-alpine`), **Laravel Octane** worker mode on `:8000` (`OCTANE_SERVER=frankenphp`); `APP_SERVER_MODE=classic` = non-worker fallback for debugging (D-27). No php-fpm, no FastCGI. |
| Laravel | 12.x (`laravel/laravel:^12.0`) |
| PostgreSQL | 17 (`postgres:17-alpine`) |
| NGINX | `nginx:stable-alpine`: edge reverse proxy + load balancer in front of N app replicas (`proxy_pass` HTTP), static assets, security headers, TLS in prod |
| Identity / Auth | **Keycloak 26**, upstream image, no custom image published (D-33) (OIDC, realm `lms`, realm roles `employee|manager|hr_admin` + additive `admin` = RBAC authority, D-20, D-29). Web: `laravel/socialite` + `socialiteproviders/keycloak` → Laravel session. API: Bearer JWT guard `keycloak-jwt` (`firebase/php-jwt`, JWKS cached in Redis). **No Sanctum, no passwords in the app.** |
| API contract | [`docs/api/openapi.yaml`](docs/api/openapi.yaml) (OpenAPI 3.1) is the **source of truth**; conventions in [`docs/API_GUIDELINES.md`](docs/API_GUIDELINES.md) (D-21). |
| Queue / cache / session / locks / rate limits | **Redis 7** (`redis:7-alpine`, `phpredis` ext), separate DBs: default 0, cache 1, session 2, queue 3, prefix `lms-` (D-23). `failed_jobs` in Postgres. |
| Object storage | S3 API: **RustFS** in dev (`rustfs/rustfs`, bucket created by the `s3-init` container), any S3-compatible store in prod (D-24). |
| Scaling | N replicas of nginx/app/worker/keycloak, no sticky sessions, `onOneServer()` on scheduled tasks, migrations as a one-off job, `/readyz` = DB + Redis (D-24). |
| Mail | SMTP via env. Mailpit in dev (`axllent/mailpit`) |
| Tests | PHPUnit via `php artisan test` |
| Style | Laravel Pint (preset `laravel`) |
| Frontend | Blade + plain CSS with custom properties. **No Node/Vite.** |
| Extra composer deps allowed | `league/flysystem-aws-s3-v3`, `laravel/socialite`, `socialiteproviders/keycloak`, `firebase/php-jwt` (D-20), `laravel/octane` (D-27); dev: `osteel/openapi-httpfoundation-testing` (D-21). `laravel/sanctum` is **not** used. Anything else needs an ADR. |

## 3. Directory layout

```
.
├── AGENTS.md / CLAUDE.md
├── Dockerfile                    # targets: app-base (FrankenPHP), app-dev, vendor, app-prod, nginx
├── compose.yaml                  # nginx, app, worker, scheduler, postgres, redis, rustfs, s3-init, keycloak, mailpit
├── .dockerignore  .env.example
├── docker/
│   ├── nginx/default.conf.template
│   ├── php/php.ini  php/entrypoint.sh                  # entrypoint: caches, then Octane or classic FrankenPHP
│   ├── keycloak/realm-lms.json                        # DEV realm import (clients, roles, alice/bob/carol/dave); prod = upstream Keycloak (D-33)
│   └── postgres/init/01-test-db.sql                     # creates lms_test + keycloak DBs
├── .github/workflows/{ci,package,wiki}.yml
├── app/
│   ├── Actions/{Leave,Accrual,Users,Settings}/   # one public handle() per class, domain writes
│   ├── Auth/                     # KeycloakUserProvisioner, KeycloakJwtGuard, JwksCache (D-20)
│   ├── Console/Commands/AccrueYear.php
│   ├── Enums/                    # Role, LeaveStatus, DayPart, LedgerKind, LeaveAction, Module
│   ├── Exceptions/InvalidTransition.php
│   ├── Http/
│   │   ├── Controllers/{Web,Web/Admin,Api/V1}/  HealthController.php
│   │   ├── Middleware/           # EnsureModuleEnabled, EnsureUserIsActive, RequestId
│   │   ├── Requests/             # Form Requests (one per write endpoint)
│   │   └── Resources/            # API resources
│   ├── Models/
│   ├── Notifications/
│   ├── Policies/
│   ├── Services/                 # WorkingDayCalculator, LeaveBalanceService
│   ├── Support/                  # Settings, Modules
│   └── View/Composers/BrandingComposer.php
├── config/{branding,modules,lms}.php    # lms.php: weekend_days
├── database/{migrations,seeders,factories}
├── public/css/app.css  public/img/default-logo.png  public/favicon.ico
├── resources/views/{layouts,auth,leave-requests,approvals,team,holidays,admin,emails}
├── routes/{web,api,console}.php
├── tests/{Unit,Feature}
├── docs/
└── .logs/                        # project memory, see §10
```

## 4. Coding conventions
- `declare(strict_types=1);` at the top of every PHP file in `app/`, `database/`, `tests/`, `config/` (config may skip if Pint rejects it).
- PSR-12 via **Pint**. Run `docker compose run --rm app vendor/bin/pint` before finishing. CI runs `pint --test`.
- Typed properties, parameter and return types everywhere. `final` classes for Actions, Services, Controllers.
- **Controllers are thin:** authorize → validate (Form Request) → call an Action/Service → return a view, redirect or Resource. No business logic, no queries beyond simple reads.
- **Form Requests** for every POST/PUT/PATCH/DELETE with input. `authorize()` delegates to policies or returns true when the route already authorized.
- **Policies** for every model action (USE_CASES §3.6 actor table). No role checks in Blade except via `@can`. No `Gate::before` super-admin bypass.
- **Actions** (`app/Actions`) hold every state change. They wrap work in `DB::transaction`, use `lockForUpdate` where USE_CASES requires it, and dispatch notifications with `afterCommit()`.
- **Enums** (string-backed) for role, status, day part, ledger kind, action, module. Cast them on models. Never compare raw strings.
- State transitions only via `LeaveStatus::canTransitionTo()` + the Actions. Throw `InvalidTransition` → 409.
- Ledger is append-only. `LeaveLedgerEntry` overrides `update`/`delete` to throw `LogicException`.
- **Octane worker-mode safety (D-27).** The app process lives across requests:
  - No request state in `static` properties, class constants built at runtime, or container **singletons**. Per-request services (`Settings`, `Modules`, branding, the current user/brand, anything holding the request) are `scoped()` bindings.
  - Never mutate `config()` at runtime (no `config([...])` in request code). Read env-derived config, and DB-backed overrides through `Settings`.
  - Branding and settings are resolved **per request** (`BrandingComposer` reads `Settings` each request); never cache them in a property of a long-lived object.
  - Don't inject `Request`, `Auth`, or the container into singleton constructors; resolve them inside the method.
  - Use `Octane::concurrently` only for independent read work. Long tasks go to the queue.
  - Workers recycle after `OCTANE_MAX_REQUESTS`; that is a safety net, not a fix for leaks.
- Dates: `CarbonImmutable` (`Date::use(CarbonImmutable::class)` in AppServiceProvider). "today" = `now()->toDateString()` in `APP_TIMEZONE`.
- Config: read env **only** in `config/*.php`. Code calls `config()`/`Settings`, never `env()`.
- Module checks: routes `->middleware('module:<key>')`, views `@module('<key>') … @endmodule`, domain `app(Modules::class)->enabled(Module::X)`.
- No raw SQL with interpolated input. Postgres-specific DDL via `DB::statement` in migrations is fine.
- Mail: Notifications only (`ShouldQueue`), Blade markdown mail, subject prefixed with the brand name.
- Naming: routes kebab-case plural, route names dotted (`leave-requests.approve`), tables snake plural, factories for every model.
- **Auth (D-20):** never store or check passwords. Web login only through `KeycloakLoginController` (Socialite). API routes use `auth:keycloak-jwt`. Roles come from Keycloak realm roles via `KeycloakUserProvisioner`. The only in-app way to change identity data or roles is the `user_management` module (D-22), usable by role `admin` only (D-29): `CreateUserAction`, `UpdateUserAction`, `AssignRolesAction`, `SetUserEnabledAction`, `SendPasswordResetAction`, which write **Keycloak first** via the `KeycloakAdminClient` interface, then mirror locally, and audit to `user_admin_audits`. Never call the Keycloak Admin API outside that interface; never set passwords. Resource rules (direct manager, not own request) stay in Policies.
- **API contract (D-21):** `docs/api/openapi.yaml` is the source of truth. Domain implements `/api/v1` routes, Resources, status codes and problem `code`s to match it exactly; change the spec first (architect/coordinator) if behaviour must differ. Expose `public_id` (ULID) as `id`, never the bigint key. Errors are `application/problem+json` (RFC 9457) via the exception handler. Swagger UI/Redoc is served at `/api/docs` when the `api` module is enabled (route added by platform).

## 5. Running things — Docker only
The host has **no PHP/Composer**. Everything runs through compose. Install Task: https://taskfile.dev/installation/ (e.g. `brew install go-task`); `task --list` shows the wrappers (`up`, `down`, `build`, `test`, `test:unit`, `test:feature`, `lint`, `migrate`, `shell`, `logs`).

```bash
cp .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose up -d postgres redis rustfs s3-init keycloak mailpit
docker compose run --rm app php artisan migrate --seed          # DemoSeeder only in local (alice/bob/carol/dave)
docker compose up -d                                             # nginx :8080 → app (FrankenPHP/Octane :8000), keycloak :8081, rustfs console :9001, mailpit UI :8025
docker compose exec app php artisan octane:reload                # reload Octane workers after code changes (or run app with --watch)
APP_SERVER_MODE=classic docker compose up -d app                 # non-worker FrankenPHP for debugging state issues
# Dev logins (Keycloak realm import, DEV ONLY): alice (employee), bob (manager), carol (hr_admin), dave (employee + admin)

docker compose run --rm app php artisan test                     # all suites
docker compose run --rm app php artisan test --testsuite=Unit
docker compose run --rm app php artisan test --testsuite=Feature # uses DB lms_test on compose postgres
docker compose run --rm app vendor/bin/pint
docker compose run --rm app php artisan lms:accrue-year 2027
docker compose logs -f app worker
```

Bootstrap (platform agent, once): build `app-dev` first, then scaffold into a temp dir and move it in, because the repo is not empty:
`docker compose run --rm app composer create-project laravel/laravel:^12.0 /tmp/skeleton && cp -a /tmp/skeleton/. .` (run inside the same container invocation, e.g. `sh -c '…'`). Do **not** run `php artisan install:api` (it installs Sanctum). Create `routes/api.php` by hand and register it in `bootstrap/app.php` (`withRouting(api: …)`, prefix `api`).

## 6. Testing strategy

| Suite | Location | Base class | DB | Covers |
|---|---|---|---|---|
| Unit | `tests/Unit` | `PHPUnit\Framework\TestCase` (**not** Laravel's, so no app boot and no DB) | none | `WorkingDayCalculator` (weekends, holidays, half-day, zero-day), `LeaveStatus::canTransitionTo`, proration/carry-forward math (pure helpers), hex colour validation/contrast helper, module resolution logic (pure function over arrays) |
| Feature | `tests/Feature` | `Tests\TestCase` + `RefreshDatabase` | **Postgres** `lms_test` | HTTP flows web+API, policies (403s), validation (422s), transitions (409), overlap exclusion constraint, ledger effects, accrual idempotency, module toggles (404 + hidden menu), branding upload (`Storage::fake`), notifications (`Notification::fake`), health endpoints |

`phpunit.xml`: `DB_CONNECTION=pgsql`, `DB_DATABASE=lms_test`, (Redis not required by default; tests that need real Redis, e.g. `/readyz`, use the compose/CI Redis service) `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `BCRYPT_ROUNDS=4`, `MODULES_ENABLED=holidays,reports,api,notifications,user_management`. **Never SQLite**: the exclusion constraint and partial indexes are Postgres-only.

Minimum feature scenarios, owned by QA:
1. Submit happy path → pending, approver notified, available balance drops by pending amount.
2. Overlap rejected (app check, and constraint when bypassing the app).
3. Insufficient balance rejected. LWP bypasses the balance check.
4. Weekend/holiday-only range rejected. Half-day on a multi-day range rejected. Weekend override (`PATCH /settings/working-week`, hr_admin): new requests use it, existing `days` unchanged; invalid sets (out of 1–7, duplicates, all 7 days) → 422; `null` resets to `LMS_WEEKEND_DAYS` (D-30).
5. Manager approves report → debit ledger row. Manager cannot approve a non-report (403). Nobody approves own (403).
6. Reject without comment → 422.
7. Cancel approved future leave → reversal. Employee cannot cancel started leave (403). HR can.
8. Double approve → 409.
9. Year-start accrual: CL lapses, SL carries all, EL capped at max_balance. Re-run creates no new rows.
10. Proration when `date_of_joining` is first set (HR edit or token claim at first login).
11. Each module off → its routes 404, menu link absent, `holidays` off → holidays not excluded from day count, `notifications` off → no leave notifications sent. Admin cannot enable an unlicensed module (422).
12. Branding (role `admin`; hr_admin → 403): colour validation, logo upload stored on the fake disk and served by `/branding/logo`, SVG rejected, reset to defaults.
13. Deactivated user: callback refuses login, API JWT → 401, existing session logged out, pending requests cancelled.
14. `/healthz` 200. `/readyz` 200 with `db` + `redis` checks (and 503 when the DB or Redis connection is broken).
15. API: 401 for missing/expired/wrong-`aud`/wrong-`iss`/bad-signature JWT, 404 problem when `api` off, `Idempotency-Key` replay, `X-RateLimit-*` headers, and **every API response validated against `docs/api/openapi.yaml`** (`osteel/openapi-httpfoundation-testing`). Tests never call Keycloak: they sign JWTs with a test RSA key and serve its JWKS via `Http::fake` (`tests/Support/FakeKeycloak`); web tests use `actingAs()` or fake Socialite.
16. First SSO login provisions the user (`keycloak_sub`), base role and `is_admin` synced from realm roles on every login (highest base role wins; none → refused, `admin` alone → refused); `PATCH /users/{id}` with `role` → 422 `ROLE_READ_ONLY`.
17. User admin, role `admin` only (hr_admin → 403) (bind `FakeKeycloakAdminClient`, never a real Keycloak): create → Keycloak user + local row + actions email; local insert failure → Keycloak user deleted (compensation) and audit row; duplicate → 409 `IDP_CONFLICT`; Keycloak down → 503 `IDP_UNAVAILABLE` and no local row, also on list/detail (no local fallback) while `/users` keeps working; self role change/self-disable → 403; last active `admin` demoted/disabled → 409 `LAST_ADMIN`; federated profile edit → 422 `IDP_READ_ONLY`; role outside allow-list → 422; `user_management` off → 404; every action writes `user_admin_audits`.
18. Octane state leaks: on one worker (`Octane` test helper / `APP_SERVER_MODE=octane` in a dedicated feature test), run request A as alice under brand override X, then request B as bob with no override; assert B sees bob's identity, default branding, its own locale/settings and no leftover auth, scoped bindings or config from A.

## 7. CI
`.github/workflows/ci.yml` (SYSTEM_DESIGN §13, D-25, D-38) on every PR, push to `main` and manual dispatch: `lint` (pint --test), `openapi` (Redocly lint of `docs/api/openapi.yaml`), `unit`, `feature` (Postgres 17 + Redis 7 service containers; API responses validated against the spec). `.github/workflows/package.yml` runs on push to `main`, `v*` tags and manual dispatch: it calls `ci.yml` (`workflow_call`), then `smoke` (`docker compose up --wait --scale app=2`, one-off migrate, curl `/healthz`, `/readyz`, Keycloak discovery, `down -v`), then pushes `ghcr.io/${{ github.repository }}/app` (target `app-prod`) and `/nginx` (target `nginx`), lowercased = `ghcr.io/heyadityak/lms/{app,nginx}`: `linux/amd64` on `main`, `linux/amd64,linux/arm64` on `v*` tags.

## 8. Definition of done (per task)
- [ ] Behaviour matches USE_CASES/SYSTEM_DESIGN. Any deviation is recorded as an ADR in `.logs/decisions.md`.
- [ ] `docker compose run --rm app php artisan test` green. `vendor/bin/pint --test` clean.
- [ ] New logic has tests (domain: QA writes them or the domain agent adds at least a happy-path feature test).
- [ ] No `env()` outside config. No secrets committed. `.env.example` updated for new vars.
- [ ] Migrations run from scratch (`migrate:fresh --seed`) on Postgres 17.
- [ ] API changes: `docs/api/openapi.yaml` updated first and Redocly lint passes; diagrams in `docs/diagrams/` still match.
- [ ] Run log appended under `.logs/` and `.logs/MEMORY.md` index updated.
- [ ] Do not commit unless the coordinator/user asks. Never push.

## 9. Agent roster & ownership

Subagent definitions live in `.claude/agents/{architect,platform,domain,qa}.md`. The main session orchestrates only and delegates to them.

| Agent | Owns (may create/edit) | Must not touch | Hand-off |
|---|---|---|---|
| **platform** | Laravel skeleton bootstrap, `composer.json` (infra deps), `Dockerfile`, `docker/**`, `compose.yaml`, `.dockerignore`, `.env.example`, `phpunit.xml`, `config/*.php` infra (`app`, `database`, `logging`, `queue`, `session`, `filesystems` incl. `branding` + `s3` disks, `trustedproxy`), `bootstrap/app.php` (middleware aliases, health routes exclusion), `HealthController` + `/healthz` `/readyz`, `RequestId` middleware, `.github/workflows/**`, `AppServiceProvider` infra bits (CarbonImmutable, JSON logging context), Keycloak service in compose + `docker/keycloak/realm-lms.json` dev realm import (D-20), `/api/docs` route serving the spec (D-21) | Domain migrations, models, business logic, views beyond a bare layout | Publishes a working `docker compose up` + green empty test suites + CI. Tells domain which middleware aliases exist. |
| **architect** | `docs/**` incl. `docs/api/openapi.yaml`, `docs/API_GUIDELINES.md`, `docs/diagrams/**`, ADRs | Code | Spec changes are announced in its run log; domain/qa follow. |
| **domain** | `database/migrations` (domain tables + `settings`), models, factories, seeders, `app/Enums`, `app/Actions`, `app/Services`, `app/Support/{Settings,Modules}`, `config/{branding,modules,lms}.php`, `EnsureModuleEnabled`, `EnsureUserIsActive`, `@module` Blade directive, `BrandingComposer`, policies, Form Requests, controllers, `routes/web.php` & `routes/api.php`, Blade views, `public/css/app.css` (theming via CSS vars), notifications, `AccrueYear` command + schedule, branding upload/serve, modules admin UI, `app/Auth/*` + `KeycloakLoginController` (D-20), API controllers/Resources/problem rendering matching `docs/api/openapi.yaml` | Dockerfile/compose/CI (ask platform), test files except minimal smoke tests | Notifies QA with a list of finished use cases (IDs). |
| **qa** | `tests/**` (API tests assert responses against `docs/api/openapi.yaml`), test factories states (may add factory states), `phpunit.xml` test env vars (coordinate with platform) | Production code in `app/`, `routes/`, `database/migrations`, `resources/`. Exception: trivial typo fixes that block tests, which must be logged. | Reports bugs in its run log under "Bugs found" (file:line, expected vs actual, failing test name) and marks the test `#[Group('known-bug')]` rather than weakening it. Domain fixes. |

Shared files (`composer.json`, `bootstrap/app.php`, `AppServiceProvider`, `phpunit.xml`): make an additive edit, keep other agents' lines, and note it in your run log.

## 10. Memory / logs
Project memory is committed under `.logs/`:

| File | Purpose | Rule |
|---|---|---|
| `.logs/MEMORY.md` | Index: one line per run log + "current state" summary + open questions | Update at the end of every run |
| `.logs/decisions.md` | ADR-lite list `D-NN: title — decision — rationale — date — author` | Append-only. Supersede with a new entry, never edit old ones (except to mark them "Superseded by D-xx"). |
| `.logs/YYYY-MM-DD-<agent>[-n].md` | One file per agent run | Sections: Goal, What I did (files), Commands run + results, Decisions (link D-NN), Bugs found / open issues, Next steps |

Every agent **must** write its run log before finishing and add it to `MEMORY.md`. Read `MEMORY.md` + `decisions.md` at the start of each run.

**Wiki:** the [GitHub wiki](https://github.com/heyadityak/lms/wiki) is a generated mirror of `docs/` + `AGENTS.md` (`scripts/docs-to-wiki.sh`, workflow `.github/workflows/wiki.yml` on push to `main`). Edit `docs/`, never the wiki.
