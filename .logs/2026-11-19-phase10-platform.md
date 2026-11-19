# 2026-11-19 — platform — phase 10: web wiring (compose, NGINX, CI)

## Goal
BUILD_PLAN phase 10: run the Next.js `web` app (phase 9) behind NGINX next to Laravel, with `lms_auth` DB, one-off auth migrations, Node tooling, CI web job, GHCR `web` image and a smoke that logs in through nginx. Plus two coordinator requests: dev `.env` must work out of the box, and a cheap way to reload Octane workers.

## What I did
- `docker/postgres/init/01-test-db.sql`: `CREATE DATABASE lms_auth OWNER lms` (fresh volumes, dev + smoke). CI `feature` job needs no `lms_auth` (no web there); the `web` CI job needs no DB.
- `compose.yaml`:
  - `x-web` anchor: build `web/` → image `lms-web`, env per the phase-9 table via compose interpolation from `.env` (secrets `BETTER_AUTH_SECRET`, `KEYCLOAK_CLIENT_SECRET` use `:?` with a pointer to `.env.example`; non-secrets have `:-dev` defaults), `depends_on` postgres.
  - `web` service (scalable; healthcheck = image `HEALTHCHECK` on `/bff/healthz`).
  - `web-migrate` (profile `tools`, `node scripts/auth-migrate.mjs`, mirrors `migrate`).
  - `web-dev` (profile `tools`): `dockerfile_inline` on `node:24.22.3-alpine` that pre-creates the volume mount points owned by host `UID/GID`; repo root at `/repo`, workdir `/repo/web`, `node_modules` + npm cache in named volumes (host `web/node_modules` has darwin binaries).
  - nginx: `WEB_UPSTREAM`, depends on `web` healthy, `./public` mount removed.
- `Dockerfile` nginx target: no `COPY public/`, `ENV WEB_UPSTREAM=web:3000`.
- `docker/nginx/default.conf.template`: upstreams `app` + `web` (both `resolve`, zone, keepalive); `^~ /api/`, `= /healthz`, `= /readyz`, `^~ /branding/` → app; `/` → web (pages, `/auth/*`, `/bff/*`, `/_next/*`). Proxy headers (`Host $http_host`, `X-Forwarded-For/-Proto/-Host`, `X-Real-IP`, `X-Request-Id`) at server level. `large_client_header_buffers 4 16k`; `proxy_buffer_size 16k` for web (Better Auth `Set-Cookie`). Static file serving, `root`, `.php`/dotfile regex locations removed. CSP on web responses only (see D-49 below).
- `.env.example`: `KEYCLOAK_CLIENT_SECRET` (dev value = realm), `BETTER_AUTH_SECRET` (dev-only placeholder ≥ 32 chars + `openssl rand -base64 32` hint), `AUTH_DATABASE_URL`, `LMS_API_URL`, `BRANDING_REVALIDATE_SECONDS`, `AUTH_SESSION_MAX_AGE`, `WEB_DEV_PORT`, `WEB_UPSTREAM`. So `cp .env.example .env` works for compose, CI smoke and the feature job. Local `.env` (gitignored) got the missing vars appended.
- `Taskfile.yml`: `migrate` runs `migrate` then `web-migrate`; new `reload` (POST `localhost:2019/frankenphp/workers/restart` in every `app` replica + `queue:restart`), `web:install`, `web:test` (lint, typecheck, vitest, check:api), `web:build`, `web:gen-api`; `logs` includes web. `up` unchanged (migrations stay explicit, D-24).
- `scripts/smoke-login.sh`: curl-only Keycloak login through nginx (sign-in/social → Keycloak form → callback 302 `/` → `/bff/api/me` 200 with the user's email).
- `.github/workflows/ci.yml`: job `web` (setup-node@v7, Node 24, npm cache on `web/package-lock.json`, `npm ci`, lint, typecheck, test, build, check:api); same triggers as the other jobs.
- `.github/workflows/package.yml`: smoke `--scale app=2 --scale web=2`, DemoSeeder, `web-migrate`, edge checks (`/` 307 → `/signin`, `/signin` 200, `/bff/healthz` 200, `/api/v1/branding` 200, `/api/v1/me` 401), `scripts/smoke-login.sh alice`, Playwright TODO (phase 17); push matrix `web` (context `web`, target `runtime`), OCI description label added (MIT label already there).
- `docker/keycloak/realm-lms.json`: `lms-web.webOrigins` → `[]` (D-43(j)); verified login works without it.
- Docs (surgical): `docs/SYSTEM_DESIGN.md` (web stage name `runtime`, web-dev/web-migrate, nginx CSP in §3/§10, CI table: one `web` job, smoke steps); `AGENTS.md` §5 (web-migrate, `task reload` replaces `octane:reload`, web-dev `npm ci` + `task web:test`, task list).

## Commands + results
- `docker compose build nginx web` OK; `docker compose up -d --wait --scale app=2 --scale web=2` → all healthy.
- `docker compose run --rm migrate` / `web-migrate` / `db:seed --class=DemoSeeder` → OK.
- Through `http://localhost:8080`: `/` → 307 `http://localhost:8080/signin`; `/signin` 200 with CSP + XFO DENY + nosniff + Referrer-Policy; 17 `<script>` tags, all carry the request nonce; `/bff/healthz` 200; `/api/v1/me` → 401 `application/problem+json` `UNAUTHENTICATED`; `/api/docs` 200; `/api/v1/branding` 200 (no CSP); `/healthz`, `/readyz`, `/branding/logo` 200; `/_next/static/chunks/*.js` 200 `Cache-Control: public, max-age=31536000, immutable` (from Next).
- `scripts/smoke-login.sh alice` and `bob` → signed in, `/bff/api/me` 200.
- Spread: 20× each → `/bff/healthz` 10/11 over the two web IPs, `/healthz` 11/10 over the two app IPs.
- Fresh volumes (isolated project `lmsp10smoke`, the smoke job sequence): init created `lms_auth`, migrations from scratch, auth-migrate created `user, session, account, verification, rateLimit`, `/readyz` ready, alice login OK, Keycloak `lms-web` `webOrigins: []`; project then `down -v` (only its own volumes). Dev stack volumes untouched.
- `task web:install && task web:test` → lint + typecheck clean, 22 Vitest tests, types fresh.
- `task test` → 172 passed (incl. the 7 `LeavePreview` tests from phase 11); `task lint` → Pint pass.
- `task reload` → workers restarted on both app replicas, queue restart broadcast.
- `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest` → clean.

## Decisions (proposed, for the orchestrator to merge)
**D-49 — Phase-10 web wiring details (amends D-43(b)(j), D-47(g)).**
(a) CSP on web responses only: `default-src 'self'; script-src 'self' 'nonce-<$request_id>'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'`. Nonce = nginx `$request_id` (128 bit from `RAND_bytes`), also sent to Next as a **request** `Content-Security-Policy` header; Next 16 reads the nonce from it and stamps its inline scripts (no web code change; works because the root layout is `force-dynamic`). Styles use `'unsafe-inline'` (antd v6 runtime CSS-in-JS + style attributes), not a nonce. No `form-action` (Chrome applies it to redirects; logout/sign-in end at Keycloak's origin). Laravel responses get no CSP (Redoc at `/api/docs` loads a CDN script).
(b) `lms-web` has no web origins (D-43(j) restored; D-47(g) reversed). Login verified without them.
(c) `web` env comes from `.env` by compose interpolation; secrets are `:?` (fail fast), others have dev defaults. `.env.example` carries DEV-ONLY values so `cp .env.example .env` works.
(d) One CI `web` job instead of three (shared `npm ci`); smoke adds edge-routing checks and a curl Keycloak login (`scripts/smoke-login.sh`); Playwright stays phase 17.
(e) `web-dev` uses a `dockerfile_inline` image only to give the `node_modules` volume the host UID; no extra Dockerfile target.
(f) Dev reload: `task reload` (FrankenPHP admin API `POST :2019/frankenphp/workers/restart` per replica + `queue:restart`); no `--watch` (no Node in the PHP image).
(g) `proxy_next_upstream` retries (error/timeout/502/503) only on `/api/` and `/branding/`; web requests are not retried by nginx.

## Bugs / open issues
- The dev Keycloak volume still has the old `lms-web` webOrigins (realm import skips existing realms); fresh volumes and CI get `[]`. Harmless.
- Existing dev `.env` files without `BETTER_AUTH_SECRET`/`KEYCLOAK_CLIENT_SECRET` make every compose command fail with the `:?` message; fix = copy those lines from `.env.example`.
- `/api` (no trailing slash) goes to Next (404 from Next). Acceptable.
- Not checked in a real browser (CSP violations would show only there); Playwright in phase 17 should assert no CSP console errors.
- `AGENTS.md` §3 comment says `01-test-db.sql` creates the keycloak DB; that is `02-keycloak-db.sql`. Cosmetic, left.

## Repo settings needed
- After the first `package` run on `main`, GHCR package `lms/web` is created private by default: set its visibility (public, like `app`/`nginx` if those are public) and link it to the repo (Package settings → "Manage Actions access" → the repo with write), otherwise later pushes from `GITHUB_TOKEN` can fail.

## Next steps
- Orchestrator: merge D-49, update MEMORY index, mark BUILD_PLAN phase 10 done after the real GitHub run.
- Phase 17 (qa): Playwright in smoke (replace the TODO step), add a "no CSP violations" assertion.
