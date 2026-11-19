# 2026-11-19 — platform — phase 3: Keycloak authentication

## Goal
BUILD_PLAN phase 3: realm import, OIDC web login/logout, JWT API guard + JWKS cache, first-login provisioning + role sync (D-20, D-22, D-29). Review the unreviewed draft auth code from the paused run critically.

## What I did (files)
Draft review: kept the Socialite provider split (public vs back-channel URL) and the PKCE flag, `EnsureRole`, the login/logout controller shape and the config. Replaced the verifier/guard/sync trio, the realm roles/mappers, the users migration and both test files (details below).

- `app/Auth/JwksCache.php` (new, replaces the JWKS half of `KeycloakTokenVerifier`): `keys()` = `Cache::remember('keycloak:jwks', TTL)`, `refresh()` behind lock `keycloak:jwks-refresh` (10 s, never released = cross-replica rate limit). Caches raw JWK arrays (serialisable), RS256 `sig` keys only.
- `app/Auth/KeycloakJwtGuard.php` (new, replaces `KeycloakTokenGuard` + `KeycloakTokenVerifier`): real `Guard`, `setRequest()` clears the cached user; `decode()` pins `alg=RS256` + requires `kid` before key lookup, checks signature, exp (required) /nbf with leeway, `typ=Bearer`, iss, aud/azp allow-list. Inactive → null (401).
- `app/Auth/KeycloakUserProvisioner.php` (new, replaces `SyncKeycloakUser`): `realm_access.roles` (draft read a custom top-level `roles` claim), highest base role, `is_admin`, no base role / admin-only → `AuthorizationException`; link by verified email; unverified collision → 403; race on first insert retried once.
- `app/Http/Controllers/Web/Auth/KeycloakLoginController.php` (moved from `Controllers/Auth` to the documented namespace): the callback verifies the access token with the guard and refuses inactive users (the draft trusted userinfo, had no active/admin checks and no `is_admin`); logout uses `post_logout_redirect_uri = APP_URL/` + `client_id` + `id_token_hint`.
- `app/Http/Middleware/EnsureAdmin.php` (new, alias `admin`); `EnsureRole` docblock.
- `app/Providers/AppServiceProvider.php`: `Auth::extend('keycloak-jwt')` (+ `refresh('request')`), `RateLimiter::for('login')` (10/min/IP on `/auth/callback`). **Shared file, additive.**
- `bootstrap/app.php`: alias `admin`. **Shared file, additive.**
- `config/auth.php`: guard `keycloak-jwt`, removed password brokers/timeout. `config/keycloak.php`: dropped `roles` list.
- `routes/auth.php` (controller namespace, `throttle:login`), `routes/api.php` (`module:api` → `auth:keycloak-jwt` order; `/api/v1/me` stub now also returns `is_admin`).
- `app/Models/User.php` (casts, attribute defaults; identity columns not fillable), `database/factories/UserFactory.php` (`is_admin`, `is_active`). **Domain-owned files, minimal additive edit.**
- Migrations, **coordination note for domain**: `0001_01_01_000000_create_users_table.php` restored to stock (the draft had edited it in place; still no `password_reset_tokens`); new `2026_11_19_000000_add_keycloak_columns_to_users_table.php` adds only `keycloak_sub` (nullable unique), `role` (+ CHECK), `is_admin`, `is_active` and drops `password`. Domain adds `public_id`, `last_login_at`, department/manager/HR fields in phase 5 and may switch the provisioner's role strings to `App\Enums\Role`.
- `docker/keycloak/realm-lms.json`: roles `employee|manager|hr_admin|admin` + explicit `default-roles-lms` (with `employee`), `realm_access.roles` mapper on lms-web / lms-api-cli / lms-dev-cli, lms-dev-cli audience mapper → `lms-api-cli` (DEV ONLY), user `dave` (employee + admin), service account with no realm roles and default scopes `basic` + `kc-admin-api`.
- `compose.yaml`: `KC_LOG_LEVEL` passthrough (default `info`). `.env.example`: `KC_LOG_LEVEL`, comment fix.
- Tests: `tests/Support/FakeKeycloak.php` (test RSA key, `Http::fake` JWKS that tests can rotate, forged tokens), `tests/Feature/Auth/KeycloakJwtTest.php` (21 cases), `tests/Feature/Auth/KeycloakLoginTest.php` (11 cases).
- Docs: `docs/SYSTEM_DESIGN.md` §10.1 clients + JwksCache row; `AGENTS.md` §6 item 15 (Http::fake instead of a fake JwksCache binding).

## Commands run + results
- `docker compose run --rm app php artisan test` → 50 passed (193 assertions). `task lint` → PASS 52 files.
- Mutation check (temporarily disabling the refresh lock, iss check, is_active check, admin sync): each makes the matching tests fail; originals restored.
- `migrate:fresh`, then rollback 1 step and migrate again on Postgres 17 → OK.
- Dev `keycloak` DB dropped and recreated so the realm re-imports (`Realm 'lms' imported`).
- `docker compose up -d --scale app=3 --wait` → all healthy. Inside the network (`docker compose exec app sh`):
  - discovery: `issuer http://localhost:8081/realms/lms`, `end_session_endpoint …/logout`.
  - password grant on `lms-dev-cli` for alice/bob/carol/dave → `aud [lms-api-cli, account]`, `azp lms-dev-cli`; `GET http://nginx/api/v1/me` → alice employee, bob manager, carol hr_admin, dave employee + `is_admin:true`. No token / garbage token → 401.
  - `lms-admin` client credentials: POST user → 201, lookup id, DELETE → 204, lookup → `[]`; `GET /clients` → 403, `GET /roles` → 403; service-account role mappings: realm `[]`, realm-management `[manage-users, view-users, query-users]`.
- Host curl browser simulation: `/login` → Keycloak auth URL (PKCE S256, scope openid profile email) → form login as dave → `/auth/callback` 302 `/` → `/` 200; session keys in Redis DB 2, `keycloak:jwks` in DB 1 (TTL ~3600). `POST /logout` (X-XSRF-TOKEN) → 302 to `…/logout?post_logout_redirect_uri=http%3A%2F%2Flocalhost%3A8080%2F&client_id=lms-web&id_token_hint=…` → Keycloak 302 back to APP_URL; a new `/login` then shows the Keycloak form (200), so the SSO session ended.
- `docker compose down` at the end (volumes kept).

## Decisions
- D-39 (phase-3 auth details: guard name, access-token provisioning, refetch lock, verified-email linking, realm fixes, migration split).

## Bugs found / open issues
- **Found and fixed during the live run:** (1) Keycloak 26.8 Admin API returned 401 to `lms-admin` ("Expected audience not available in the token") → `kc-admin-api` scope. (2) `defaultRole.composites` is ignored on import, so new users had no `employee` → explicit `default-roles-lms` role. (3) A re-imported realm gives users new subs → unique-email 500 → verified-email re-link (D-39 d).
- **Open (platform, pre-existing from phase 1):** under Octane/FrankenPHP, app log lines never reach `docker logs`. `vendor/laravel/octane/src/Commands/StartFrankenPhpCommand.php:466-470` parses each worker stderr line as Caddy JSON and prints `$debug['msg'] ?? 'unknown error'`; Monolog's JsonFormatter writes `message`, so every app log shows as "ERROR unknown error." (12-factor logs broken in octane mode). Fix candidates: a formatter that also emits `msg`, or a different stream. Not fixed here (out of phase-3 scope).
- Problem `code`s are lowercase (`unauthenticated`) in the draft `ProblemJsonRenderer`, while openapi.yaml uses `UNAUTHENTICATED`. Phase 4.
- `lms-dev-cli` and `lms-admin` log Keycloak's "Full scope allowed is deprecated" warning; left as is (turning it off on `lms-admin` needs explicit scope mappings).
- Draft phase-4 code (`ProblemJsonRenderer`, `Idempotency`, `ApiDocsController`, `EnsureModuleEnabled` stub and their tests) is still unreviewed. Phase 4 should review it.
- Ownership: AGENTS §9 lists `app/Auth/*` + `KeycloakLoginController` under domain; BUILD_PLAN gave phase 3 to platform. Domain owns them from now on.

## Next steps
- Phase 4 (platform): API plumbing review (problem codes vs spec, module/auth/throttle order, `/me` → UserResource by domain), fix the Octane log passthrough.
- Domain (phase 5): rest of the users columns, `Role` enum, `EnsureUserIsActive` (`active` alias), `DemoSeeder` users matching the realm (alice/bob/carol/dave, verified emails link on first login), `date_of_joining` claim → `GrantInitialEntitlement` in the provisioner.
