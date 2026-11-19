# 2026-11-19 — platform — phase 4: API plumbing

## Goal
BUILD_PLAN phase 4: `/api/v1` group, RFC 9457 problem+json for `/api/*`, `EnsureIdempotency`, Redis throttle headers, `/api/docs` + `/api/openapi.yaml`, Octane log fix, AGENTS §9 ownership move. Review the unreviewed draft (renderer, `Idempotency`, `ApiDocsController`, `EnsureModuleEnabled` stub, tests).

## What I did (files)
Draft review findings (all fixed): lowercase codes and `_` in `type` (spec: UPPERCASE code, kebab URN); codes outside the spec enum (`conflict`, `method_not_allowed`, `too_many_requests`, `idempotency_key_invalid`); 500 leaked the message with APP_DEBUG; 422 from domain exceptions had no `errors`; key-reused was 409 (spec 422); middleware named `Idempotency` (diagram: `EnsureIdempotency`); idempotency on the whole v1 group (spec: two POSTs only); stored 4xx/any <500 (spec: 2xx + 422); replay kept only 2 headers; module stub read `config('modules.licensed')`, which did not exist, so it never 404'd; and auth ran before `module:` (priority list), so a disabled module gave 401.

- `app/Exceptions/ProblemJsonRenderer.php`: rewritten per API_GUIDELINES §4 / D-40 (a–c).
- `app/Exceptions/DomainException.php` (base: `problemCode`, `status`, `errors`, `headers`), new `LeaveRuleViolation` (422 + field), `InvalidTransition` (409), `IdpConflict` (409), `IdpUnavailable` (503 + Retry-After), `IdpReadOnly` (422). Subclasses are domain-owned from now on; created as the seam.
- `app/Http/Middleware/EnsureIdempotency.php` (replaces `Idempotency.php`, alias `idempotent`), D-40 (e).
- `app/Http/Middleware/EnsureModuleEnabled.php`: stub = `MODULES_ENABLED` licence + registry `available`. **Seam for domain (phase 5):** replace the `abort_unless` condition with `app(Modules::class)->enabled(Module::from($module))` to add the DB `modules.disabled` override; keep the alias and its priority entry.
- `config/modules.php` (new, **domain-owned**, copied verbatim from SYSTEM_DESIGN §8.3 because the stub needs it).
- `app/Http/Controllers/ApiDocsController.php`: SRI + `crossorigin` on the pinned Redoc 2.5.0 bundle.
- `app/Auth/KeycloakJwtGuard.php`: JWKS fetch failure → 503 (D-40 d).
- `routes/api.php`: v1 group = `module:api` → `auth:keycloak-jwt` → `throttle:api` (no `idempotent`; domain adds it to `POST /leave-requests` and `POST /admin/users`). `/me` stub unchanged. Docs routes public behind `module:api` (SYSTEM_DESIGN §6.2: no auth).
- `bootstrap/app.php` (**shared, additive**): alias `idempotent` → `EnsureIdempotency`, priority entry for `EnsureModuleEnabled`, dropped `throttleWithRedis()` (D-40 f), `api` itself also gets problem+json.
- `docker/php/entrypoint.sh`, `compose.yaml`, `.env.example`: `CADDY_LOG_LEVEL` (default WARN) → `--log-level` (D-40 h).
- `AGENTS.md` §9: `app/Auth/*` + `KeycloakLoginController` and the API plumbing moved to platform; domain row now says "`DomainException` subclasses (problem codes)" instead of "problem rendering".
- Tests: `tests/Support/ValidatesOpenApi.php` (osteel, already a dev dep), `tests/Feature/Api/ProblemJsonTest.php` (401 ×2, 403, 404 ×2 incl. module off for `/api/v1/me`, `/api/docs`, `/api/openapi.yaml`, 405, 409 ×2, 422 ×2, 500, 503, web untouched), `IdempotencyTest.php` (replay, mismatch, concurrent in-flight via a nested request, 422 stored, 5xx/409 release, per-user scope, no key, invalid key; real Redis store), `ThrottleTest.php` (headers, 429 with Retry-After + Reset, limiter key user id → IP, JWKS down 503), `ApiDocsTest.php`. Spec validation: `/me` 401/404/429, `POST /leave-requests` 403/409/422 shapes, `/admin/users` 409/503. `KeycloakJwtTest` updated for the `invalid_token` challenge.

## Commands run + results
- `task test` → 66 passed (460 assertions), run 3× back to back, no flakes. `task lint` → PASS 61 files (pint fixed 2 style issues first).
- `docker compose build app && docker compose up -d --scale app=3 --wait` → all healthy. Through nginx `:8080`:
  - `GET /api/v1/me` no token → 401 `application/problem+json`, `WWW-Authenticate: Bearer`, code `UNAUTHENTICATED`, `request_id` = `X-Request-Id`.
  - alice password grant (`lms-dev-cli`) → `/me` 200, `X-RateLimit-Limit: 60`, `Remaining: 59`; bob ×61 → 429 `RATE_LIMITED`, `Retry-After: 59`, `X-RateLimit-Reset`.
  - `/api/docs` 200 HTML with pinned Redoc + SRI; `/api/openapi.yaml` 200 `application/yaml`; `POST /api/v1/me` → 405 `BAD_REQUEST`, `Allow`.
  - Idempotency via a **temporary** `POST /api/v1/_idem-demo` route (removed afterwards, file restored from a backup): 201 → same key/body 201 `Idempotent-Replayed: true` with identical random body (across load-balanced replicas) → different body 422 `IDEMPOTENCY_KEY_REUSED`.
  - Logs: `docker compose logs app` shows `{"message":"auth.jwt_rejected",...,"level_name":"NOTICE",...}` raw; 0 "unknown error" lines.
- Test idem keys removed from dev Redis DB 1; `docker compose down` (volumes kept).
- actionlint not run: no workflow changed.

## Decisions
- D-40 (phase-4 API plumbing details).

## Bugs found / open issues
- Spec gap (architect): `ProblemCode` has no `METHOD_NOT_ALLOWED`; 405 uses `BAD_REQUEST` (D-40 a).
- Brief vs spec, spec followed: idempotency key scope without route, only 2xx/422 stored, key-reused = 422, 500 never shows detail with APP_DEBUG (D-40).
- `/me` 200 is not spec-validated: the stub returns the bigint id and no HR fields; domain's `UserResource` (phase 5) must make `assertMatchesSpec($r, '/me')` pass.
- `IdempotencyTest` uses the real Redis cache store, which in `docker compose run` is the dev Redis DB 1 (uuid keys, 24 h TTL). Harmless, but a separate test Redis DB would be cleaner.
- Cache keys are double-prefixed (`lms-lms-cache-…`: Redis prefix + cache prefix). Pre-existing, cosmetic.
- Unauthenticated API calls are not rate limited (auth runs before `throttle:api` per SYSTEM_DESIGN §6.2); nginx is the place if that matters.

## Next steps
- Domain (phase 5): `Modules` service into `EnsureModuleEnabled` (seam above), `active` alias after auth, `UserResource` for `/me`, `idempotent` on the two POSTs, throw `LeaveRuleViolation`/`InvalidTransition`/`Idp*` with spec codes; use `Tests\Support\ValidatesOpenApi` in API tests.
- Architect: decide on `METHOD_NOT_ALLOWED`.
