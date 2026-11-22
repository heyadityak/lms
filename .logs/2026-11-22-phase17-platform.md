# 2026-11-22 — platform — phase 17: Playwright in package smoke

## Goal
BUILD_PLAN phase 17 CI slice only: replace the Playwright placeholder in `.github/workflows/package.yml` so the smoke job runs the e2e suite QA is placing at `web/e2e`. No specs, no `tests/**`, no `web/src`. No commit. `MEMORY.md`, `BUILD_PLAN.md`, and `decisions.md` left for the orchestrator.

## What I did
`.github/workflows/package.yml` smoke job only. Push job and `ci.yml` untouched.

Removed the phase-10 TODO (`mcr.microsoft.com/playwright`, `--network host`). After the existing Keycloak login check, and before log dump / `down -v`:

- `actions/setup-node@v7`, Node 24, npm cache on `web/package-lock.json` (same as the CI `web` job). The smoke runner had no Node setup.
- `npm ci --no-audit --no-fund` in `web/`.
- `npx playwright install --with-deps chromium` in `web/`.
- `npx playwright test --config=e2e/playwright.config.ts` in `web/`, `PLAYWRIGHT_BASE_URL=http://localhost:8080` (compose nginx on the runner).
- On failure, `actions/upload-artifact@v4` uploads `web/playwright-report/` and `web/test-results/` (`if-no-files-found: ignore`). Smoke job permissions are now `contents: read` plus `actions: write` so the upload is allowed under the workflow's restrictive token.
- Smoke `timeout-minutes` 20 → 40. Compose build was already the long pole; Chromium install plus the suite needs the extra room.

There is no `test:e2e` script in `web/package.json`. The job calls `npx playwright test` directly.

Config contract for QA: file must be `web/e2e/playwright.config.ts`. Playwright does not read `PLAYWRIGHT_BASE_URL` on its own. The config must set `use.baseURL` from that env (value in this job: `http://localhost:8080`).

## Commands + results
- `command -v actionlint` → not installed on the host.
- `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest -color .github/workflows/package.yml` → exit 0, no findings (image pulled `sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667`).

No compose stack and no Playwright run: `web/e2e` does not exist yet, and `@playwright/test` is not installed.

## Decisions
Not written to `decisions.md` (orchestrator asked this slice not to). Proposed, for the orchestrator:

**D-43(l) image line is not what this job does.** D-43(l) and `docs/SYSTEM_DESIGN.md` still say a pinned `mcr.microsoft.com/playwright` image with `--network host`. This slice follows the phase-17 task instead: Node 24 on the `ubuntu-latest` runner, `@playwright/test` from `web/`, Chromium via `npx playwright install --with-deps`. Host network is unnecessary because compose already publishes nginx on `localhost:8080`, which is where the runner's browser connects. `SYSTEM_DESIGN.md` was not edited (architect owns `docs/**`).

No new dependency and no ADR-worthy package added. `web/package.json` is frontend-owned and was not modified.

## Bugs
- `@playwright/test` is not a direct dependency. `web/package.json` has no `test:e2e` script and no `@playwright/test` / `@axe-core/playwright`. The lockfile only mentions `@playwright/test` as an optional peer of `next`. `npm ci` will not install a `playwright` binary. Until frontend adds the devDependency and refreshes the lockfile, `npx playwright` on a `CI=true` runner can fetch an unpinned `playwright` instead of the locked one, then fail on the missing config.
- `web/e2e/` is absent (QA writing it in parallel). The smoke step fails until `web/e2e/playwright.config.ts` exists.
- Report upload only helps if the config emits `playwright-report/` or `test-results/`. Defaults under `CI=true` may not write an HTML report. QA can turn the HTML reporter on; the upload step already ignores a missing directory.

## Next steps
- Frontend: add `@playwright/test` and `@axe-core/playwright` to `web` devDependencies and update `web/package-lock.json`. QA must not edit `package.json` (ownership). Do not add a `test:e2e` script unless the workflow is switched to it; the job calls `npx playwright test`.
- QA: add `web/e2e/playwright.config.ts` with `use.baseURL: process.env.PLAYWRIGHT_BASE_URL` and specs under `web/e2e`. Chromium only is installed.
- Orchestrator: keep this workflow off a real `package` run until the dependency and the config exist. Merge an ADR only if D-43(l)'s Playwright-image sentence should be superseded. Index this log in `.logs/MEMORY.md`. `docs/SYSTEM_DESIGN.md` smoke row still describes the container image.
