# 2026-11-19 — platform, phase 1 (FrankenPHP + Octane containers)

## Goal
BUILD_PLAN phase 1: replace the unverified php-fpm draft with FrankenPHP + Octane (D-27), nginx as the edge proxy/LB, full compose stack, `.env.example`, `/healthz` + `/readyz`, green suites. Mid-run coordinator change: Taskfile (go-task) replaces Makefile.

## What I did (files)
- `Dockerfile`: rewritten. `app-base` = `dunglas/frankenphp:1.13.5-php8.4-alpine` + `pdo_pgsql redis intl zip opcache pcntl bcmath`, non-root `app` user (UID/GID build args), `/app /data /config` owned by it (psysh/Caddy write under XDG dirs), `SERVER_NAME=:8000`, HEALTHCHECK curl `:8000/healthz`. `app-dev` (+composer), `app-prod` (`composer install --no-dev -o`, composer bind-mounted, `opcache.validate_timestamps=0`), `nginx` (`nginx:1.30-alpine`, `public/` baked in, PHP files removed).
- `docker/php/entrypoint.sh`: `php artisan optimize` when APP_ENV is non-dev; no command → Octane (`--workers`/`--max-requests` from env) or `APP_SERVER_MODE=classic` → `frankenphp php-server --listen :8000 --root public/`.
- `docker/nginx/default.conf.template`: `upstream app { zone; server ${APP_UPSTREAM} resolve; keepalive }` + `resolver ${NGINX_RESOLVER}`, static `try_files $uri @app`, `.php` → 404, security headers, `X-Forwarded-*` (`$http_host` keeps the :8080 port), `X-Request-Id`, `/nginx-health`. All FastCGI removed.
- Deleted `docker/php/zz-fpm.conf`, `docker/keycloak/Dockerfile` (D-33), `Makefile`.
- `compose.yaml`: nginx, app, worker (`queue:work redis`), scheduler, `migrate` (profile `tools`), postgres 17, redis 7, rustfs 1.0.6 + s3-init (aws-cli 2.41.1), keycloak 26.8.2 upstream, mailpit v1.34.0 (healthcheck added). Realm JSON untouched.
- `.env.example` (+ local `.env`): removed `PHP_FPM_HOST`, added `APP_SERVER_MODE`, `OCTANE_SERVER/WORKERS/MAX_REQUESTS`, `SERVER_NAME`, `APP_UPSTREAM`; D-34 comment next to `MODULES_ENABLED`. Every var in the SYSTEM_DESIGN §9 table is present (checked by script).
- `composer.json/lock`: `laravel/octane ^2.25`; `octane:install --server=frankenphp` → `config/octane.php` (default server `frankenphp`, Pint-formatted), `.gitignore` entries. `pint.json`: excludes the generated `public/frankenphp-worker.php`.
- `config/database.php` (Redis prefix default `lms-`), `config/cache.php` (lock connection `cache`, D-23).
- `app/Http/Controllers/HealthController.php`: `/readyz` always checks DB + Redis; `ok|fail`, `ready|unavailable` (OpenAPI `Readiness`). `tests/Feature/HealthTest.php` updated (5 tests incl. 503 on DB down and on Redis down).
- `Taskfile.yml` (go-task v3): up, down, build, test, test:unit, test:feature, lint, migrate, shell, logs. README quickstart → `task up` + install line; AGENTS §5 install line.

## Commands run + results
- `docker compose build` → lms-app-dev, lms-nginx-dev built.
- `docker compose up -d --scale app=3 --scale worker=2 --wait` → all 12 services healthy/up, s3-init exited 0 (worker/scheduler have healthcheck disabled by design).
- `docker compose run --rm migrate` → "Nothing to migrate" (existing `pgdata` volume; `migrate:status` shows the 3 stock migrations ran). DBs `lms`, `lms_test`, `keycloak` present; `btree_gist` creatable in `lms_test`.
- `curl :8080/healthz` → 200 `{"status":"ok"}`; `/readyz` → 200 `{"status":"ready","checks":{"db":"ok","redis":"ok"}}`. 10x each → nginx log `upstream=` spread 7/8/7 over the 3 app IPs (round-robin).
- tinker `Storage::disk('s3')` put/get/delete → true / "hello rustfs" / true (endpoint `http://rustfs:9000`, path-style).
- `php artisan test` → 36 passed (Unit 1, Feature 35); `pint --test` → PASS 50 files. Same via `task test` / `task lint` / `task migrate`; `task --list` shows all 10 tasks.
- Classic mode: `APP_SERVER_MODE=classic` → `frankenphp php-server`, `/healthz` + `/readyz` 200.
- `app-prod` image run against the compose network: uid 1000 (`app`), no dev deps, `bootstrap/cache` built at start by `optimize`, `/readyz` ready.
- Keycloak discovery `localhost:8081/realms/lms` → issuer ok (realm import still works).
- actionlint 1.7.12 (Docker) on `.github/workflows` → clean.
- `docker compose down` → removed.

## Decisions
- D-37 (recorded): no vendor stage, nginx `upstream … resolve`, entrypoint cache rule, readyz shape, lock connection, `migrate` service, image pins.
- Taskfile replaces Makefile: decision line handed to the orchestrator to record as D-36.

## Bugs found / open issues
- Octane's per-request console lines (`200 GET /healthz … ms`) on stdout are Octane's own CLI format, not JSON. Laravel logs are JSON on stderr. nginx access logs cover request logging; revisit if JSON-only output is required.
- Phase 3/4 drafts from the paused run (Keycloak auth, problem+json, Idempotency, ApiDocs) were left untouched. Their tests pass, but they are still unreviewed and need re-verification in phases 3/4.
- `.claude/agents/*.md` still say `make test`. Those are agent config, so I did not edit them; the orchestrator should update them.
- No dev `--watch` (would need Node/chokidar). After code changes, run `docker compose exec app php artisan octane:reload`.
- `BUILD_PLAN.md` status was not updated (orchestrator file).

## Next steps
Phase 2 (CI): build `app-prod` + `nginx` targets, feature job with Postgres 17 + Redis 7, and a smoke job on `docker compose up --wait`.
