# 2026-11-19 — platform — phase 8: Laravel API-only cleanup (D-43)

## Goal
BUILD_PLAN phase 8: remove the Blade/Socialite web layer, make Laravel session-free, add the public `GET /api/v1/branding`
(+ `public` limiter, `URL::forceRootUrl`), the `module:api` first-party bypass, `/branding/{asset}` with bundled defaults, and point
the realm `lms-web` client at the Next.js BFF. No `web/` work.

## What I did (files)
Deleted:
- `app/Http/Controllers/Web/**` (Dashboard, LeaveRequest, Approval, Holiday, `Auth/KeycloakLoginController`), `routes/auth.php`
- `app/Auth/KeycloakProvider.php` (Socialite), `app/View/Composers/BrandingComposer.php`, `app/Exceptions/RendersBackOnWeb.php`
- `resources/views/{dashboard,welcome}.blade.php`, `resources/views/{layouts,leave-requests,approvals,holidays}/**` (kept `resources/views/.gitkeep` for mail/views later), `public/css/app.css`
- tests: `tests/Feature/Auth/KeycloakLoginTest.php`, `tests/Feature/Leave/LeaveWebTest.php`; Blade tests in `SettingsModulesBrandingTest` (`@module`, CSS-variable page, colour fallback on page)
- composer: `laravel/socialite`, `socialiteproviders/keycloak` (+ transitive `socialiteproviders/manager`, `league/oauth1-client`, `phpseclib/phpseclib`, `paragonie/constant_time_encoding`, `symfony/polyfill-php82`)

Changed / added:
- `bootstrap/app.php` (shared): no `web:` routing; `routes/web.php` loaded in `then:` outside the `web` group; `redirectGuestsTo(null)` (no login route → always 401 problem); `shouldRenderJsonWhen(true)`.
- `app/Exceptions/ProblemJsonRenderer.php`: renders problem+json for **every** path (Laravel renders no HTML pages); InvalidTransition/LeaveRuleViolation drop the web trait.
- `routes/web.php`: only `/healthz`, `/readyz`, `/branding/{logo|favicon}`.
- `app/Http/Controllers/BrandingAssetController.php` (new): override from `branding.disk`, else bundled `public/img/default-logo.png` / `public/favicon.ico`; dangling override → default; `Cache-Control: public, max-age=300`, ETag, 304.
- `app/Http/Controllers/Api/V1/BrandingController.php` (new) + `routes/api.php` `GET /api/v1/branding` (`throttle:public`, no auth, no `module:api`), `Cache-Control: public, max-age=30`, `meta`/`links` as `{}`.
- `app/Support/Brand.php`: `logo_url`/`favicon_url` always `${APP_URL}/branding/{asset}?v=<12-hex sha1 of stored path|default>`.
- `app/Providers/AppServiceProvider.php` (shared): removed Socialite listener, `@module`, `View::composer`, `login` limiter; added `URL::forceRootUrl(config('app.url'))` + `forceScheme`, limiter `public` (300/min per IP).
- `app/Http/Middleware/EnsureModuleEnabled.php`: `api` off → passes `/api/v1/*` requests whose **unverified** token `azp` = `keycloak.client_id`; `auth:keycloak-jwt` still verifies (forged → 401); `/api/docs` and `/api/openapi.yaml` never bypassed.
- `app/Http/Middleware/EnsureUserIsActive.php`: session logout branch removed (always 401).
- config: `session.php` driver default `array`, no `session` connection; `database.php` Redis `session` connection removed; `keycloak.php` drops `client_secret`/`redirect_uri`; `services.php` drops the Socialite `keycloak` block; `filesystems.php` local disk `serve => false` (no `/storage/{path}` routes); `auth.php` comment on the unused `web` guard (see D proposal).
- `.env.example` (and local `.env`): `SESSION_DRIVER=array`, removed `SESSION_*` cookie vars, `REDIS_SESSION_DB`, `KEYCLOAK_CLIENT_SECRET`, `KEYCLOAK_REDIRECT_URI`. `phpunit.xml`: removed `REDIS_SESSION_DB`.
- `public/favicon.ico`: was the 0-byte skeleton file; now a 126-byte ICO wrapping the bundled 32×32 PNG logo.
- `docker/keycloak/realm-lms.json`: `lms-web` redirect `http://localhost:8080/auth/*`, web origin `http://localhost:8080`, post-logout `http://localhost:8080/` (all DEV ONLY, stated in the client description, ≤255 chars); realm `revokeRefreshToken: true`, `refreshTokenMaxReuse: 1`. Same values applied to the running dev Keycloak through the Admin API (an existing realm is not re-imported).
- Tests: `KeycloakJwtTest` +6 (verified-email link, stale-sub relink, unverified email never links — ported from the web login test to the API path; `api` off + third-party azp → 404; `api` off + `lms-web` azp → 200 and docs still 404; forged `lms-web` token → 401). New `PublicBrandingTest` (7: spec validation, cache + rate-limit headers, overrides/enabled modules only, colour fallback, APP_URL via internal host, per-IP `public` limiter → 429, default/override/dangling assets + ETag/304, no cookies). `ProblemJsonTest`: old "web keeps HTML renderer" → "old web paths are problem+json 404, no login redirect". Octane isolation tests now use `/api/v1/branding` instead of `/`.

## Commands run + results
- Baseline `task test`: **172 passed**.
- `docker compose run --rm --no-deps app composer remove laravel/socialite socialiteproviders/keycloak` (first try: packagist timeout; lock finished with `composer update … -w`).
- `task test`: **165 passed** (1608 assertions), 0 known-bug. Delta: −20 (11 KeycloakLogin, 6 LeaveWeb, 3 Blade) +13 new.
- `task lint`: PASS (144 files).
- `php artisan optimize` + `route:list`: OK; 18 app routes, none at `/`.
- `php artisan migrate:fresh --seed --force` + `db:seed --class=DemoSeeder`: OK.
- `docker compose up -d --wait --scale app=3`: 3 healthy replicas; nginx log shows round robin (7/7/7).
- Live (`http://localhost:8080`): `/api/v1/branding` 200, `Cache-Control: max-age=30, public`, `X-RateLimit-Limit: 300`, absolute `http://localhost:8080/branding/...?v=default`; `/branding/logo` 200 `image/png`, byte-identical to the bundled default, `max-age=300, public`, ETag; `/branding/favicon` 200 `image/vnd.microsoft.icon`; `/api/v1/me` with alice and bob `lms-dev-cli` tokens 200, no `Set-Cookie`; `/healthz`, `/readyz` OK; `/`, `/leave-requests`, `/approvals`, `/holidays`, `/login`, `/auth/callback`, `/logout`, `/storage/x` → 404 problem+json; `api` disabled in settings: third-party token 404, tampered token with `azp=lms-web` 401, `/api/v1/branding` still 200 with `api` missing from `modules`.
- Throwaway Keycloak 26.8.2 `start-dev --import-realm` of the edited realm file: first run **failed** (client description 256 chars > 255 column) → shortened → import OK; `lms-web` auth endpoint accepts `/auth/oauth2/callback/keycloak` and `/auth/callback/keycloak` (200), rejects `/api/x` (400); realm `revokeRefreshToken=true`, `refreshTokenMaxReuse=1`.
- actionlint: not run (Docker `rhysd/actionlint` blocked by the permission classifier; host has no actionlint). No workflow file changed this phase.

## Decisions (proposed; architect/orchestrator to record, decisions.md not edited while the architect runs)
- **D-47 (proposed) — Phase-8 API-only details (amends D-43(f)/(j), D-40).**
  (a) Every Laravel error is `application/problem+json`, also outside `/api` (`shouldRenderJsonWhen(true)`, renderer no longer path-gated); guests are never redirected (`redirectGuestsTo(null)`).
  (b) `module:api` bypass applies only to `/api/v1/*` (all behind `auth:keycloak-jwt`); `/api/docs` and `/api/openapi.yaml` stay 404 for any token when `api` is off.
  (c) Asset cache-buster `v` = first 12 hex of `sha1(stored path)` (paths are content-hashed `logo-{sha1}.{ext}`), `default` otherwise; `/branding/{asset}` falls back to the bundled default when the stored file is missing (no 500).
  (d) `config/auth.php` keeps the session-driver `web` guard as the **default guard** only so feature tests can `actingAs()`; no route uses it and `SESSION_DRIVER=array`. Switching the default to `keycloak-jwt` breaks `IdempotencyTest::test_keys_are_scoped_per_user` because the JWT guard drops its user on request rebinding (Octane safety). Revisit if QA moves tests to bearer tokens.
  (e) Local disk `serve => false`: no `/storage/{path}` routes; public files only via `/branding/{asset}`.
  (f) `public/favicon.ico` is now a real 32×32 ICO (PNG-in-ICO of the default logo); it was 0 bytes.
  (g) Realm `lms-web`: redirect `${APP_URL}/auth/*` (wildcard, works for Auth.js `/auth/callback/keycloak` and Better Auth `/auth/oauth2/callback/keycloak`), **web origin `${APP_URL}`** (orchestrator instruction; D-43(j) said none), post-logout exact `${APP_URL}/`. Refresh rotation is realm-wide in Keycloak (`revokeRefreshToken`, `refreshTokenMaxReuse=1`), so it also applies to `lms-api-cli`/`lms-dev-cli`.

## Bugs found / open issues
- Fixed before shipping: realm client `description` > 255 chars breaks `--import-realm` (Keycloak column limit).
- `web` env vars are **not** added to `.env.example`: the Auth.js → Better Auth switch (D-45, `lms_auth` DB) is still being written; add them with the compose `web` service in phase 10.
- NGINX still serves `public/` statically (`try_files $uri @app`) and the nginx image still copies `public/`; phase 10 removes both with the `/`→web routing. Until then `/favicon.ico` is answered by nginx, `/branding/*` by Laravel.
- Running dev Keycloak was patched via the Admin API; a fresh `keycloak` DB gets the same values from the import.
- AGENTS.md §3 layout lists `Controllers/Api/V1/ HealthController.php ApiDocsController.php BrandingAssetController.php`; actual: `Health`, `ApiDocs` and `BrandingAsset` controllers live in `app/Http/Controllers/`, `BrandingController` (public branding) in `Api/V1/`. Ownership did not move; left for the architect (concurrent AGENTS edits).
- Domain-owned files touched under D-43 authority (logged): `routes/api.php`, `app/Support/Brand.php`, `app/Http/Middleware/EnsureModuleEnabled.php`, `EnsureUserIsActive.php`, the two exception classes, branding controllers. QA-owned tests touched: listed above.

## Next steps
- Orchestrator: commit phase 8; architect records D-47 (or renumbers).
- Phase 10 (platform): compose `web`, nginx two upstreams + drop `public/` from the nginx image, `.env.example` web vars per D-45, CI web jobs.
- QA (phase 17): consider bearer-token tests instead of `actingAs` so the `web` guard can go.
