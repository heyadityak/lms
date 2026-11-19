# 2026-11-19 — platform, phase 2 (GitHub Actions CI + packaging)

## Goal
BUILD_PLAN phase 2: `ci.yml` (pint, OpenAPI lint, Unit, Feature with Postgres 17 + Redis 7) and `package.yml` (compose smoke, GHCR push of `app-prod` + `nginx`), per D-25, D-33, D-36, D-37.

## What I did (files)
- `.github/workflows/ci.yml` (new): jobs `lint`, `openapi`, `unit`, `feature`. setup-php@v2 (8.4; pdo_pgsql, redis, intl, zip, pcntl, bcmath), composer cache via actions/cache@v6 on `composer config cache-files-dir`. Feature: services `postgres:17-alpine` (db `lms_test`, user `lms` = superuser, so migrations can create `btree_gist`) and `redis:7-alpine`, env `DB_HOST`/`REDIS_HOST=127.0.0.1`. `permissions: contents: read`; concurrency `ci-${{ github.workflow }}-${{ github.ref }}`, cancel only for PRs (the `ci-` prefix keeps the group distinct from the caller's when run via `workflow_call`, which would otherwise deadlock).
- `.github/workflows/package.yml` (new): `ci` (uses ci.yml) → `smoke` (cp .env.example + runner UID/GID, compose build, composer install + key:generate in a one-off container, `up -d --wait --scale app=2`, `run --rm migrate`, curl healthz/readyz/Keycloak discovery, logs on failure, always `down -v`) → `push` (matrix app/app-prod, nginx/nginx; repo lowercased with `${GITHUB_REPOSITORY,,}`; QEMU only on `v*` tags; buildx, login (GITHUB_TOKEN), metadata (sha, branch, semver, latest on default branch, OCI title + licenses=MIT), build-push with gha cache per image). Workflow concurrency `package-${{ github.ref }}`, no cancel.
- `wiki.yml`: untouched.
- `AGENTS.md` §3 tree + §7 CI, `docs/SYSTEM_DESIGN.md` §13: surgical edits to describe the two-workflow split (D-38).
- `.logs/decisions.md`: D-38.

## Commands run + results
- actionlint 1.7.12 (`docker run rhysd/actionlint`, incl. shellcheck) on all workflows → exit 0, no findings.
- `docker compose run --rm app vendor/bin/pint --test` → PASS 50 files.
- `... php artisan test --testsuite=Unit` → 1 passed; `--testsuite=Feature` → 35 passed.
- CI-like env: fresh `.env` from `.env.example` mounted over `/app/.env`, `key:generate`, Unit + Feature → 1 + 35 passed.
- `docker run node:22-alpine npx --yes @redocly/cli@2.80.0 lint docs/api/openapi.yaml` → valid, 2 warnings, exit 0.
- Smoke sequence in an isolated project (`COMPOSE_PROJECT_NAME=lms-smoke`, so `down -v` did not touch dev volumes): `up -d --wait --wait-timeout 300 --scale app=2` → all healthy (app×2, nginx, keycloak, rustfs, s3-init exit 0); migrate → 3 stock migrations DONE; `/healthz` `{"status":"ok"}`, `/readyz` ready db+redis ok, Keycloak discovery 200; `down -v` clean. Local run used the existing `.env` (not overwritten by cp).
- `docker build --target app-prod` and `--target nginx` → both build.

## Decisions
- D-38 (new): ci.yml + package.yml split, `workflow_call` instead of `workflow_run`, smoke main/tags only, no `--seed` until DemoSeeder.

## Bugs found / open issues
- On push to `main` CI runs twice (standalone + inside package). Accepted cost of the simpler gate.
- Not verified on real GitHub runners (orchestrator pushes and watches). arm64 build (QEMU) only exercised on `v*` tags.
- Repo settings: GITHUB_TOKEN default permission can stay read-only (workflows declare `packages: write` themselves, `wiki.yml` declares `contents: write`). After the first push, GHCR packages `lms/app` and `lms/nginx` are private by default: set visibility public if wanted, and check "Manage Actions access" links them to the repo (automatic when first pushed by this repo's workflow).

## Next steps
- Add `--seed` to the smoke migrate once `DemoSeeder` lands (phase 5).
- Phase 3 (Keycloak).
