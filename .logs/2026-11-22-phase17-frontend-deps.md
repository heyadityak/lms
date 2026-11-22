# 2026-11-22 — frontend — phase 17: Playwright devDependencies

## Goal
Make `npx playwright test --config=e2e/playwright.config.ts` installable from `web/` after `npm ci`. `@playwright/test` was only an optional peer of Next, and `@axe-core/playwright` was absent. Both are already on the allowed devDependency list. No new ADR. No e2e specs or `playwright.config.ts` (QA owns `web/e2e/**`).

## What I did (files)
- `web/package.json` — exact devDependencies `@playwright/test` 1.65.1 and `@axe-core/playwright` 4.14.0 (versions npm resolved; `--save-exact` so they match the rest of this file).
- `web/package-lock.json` — lockfile changed (+63 lines). Direct dev entries plus transitive `playwright` 1.65.1 and `playwright-core` 1.65.1. `axe-core` 4.14.1 was already locked. No other top-level package.

Not edited: PHP, CI, `.logs/MEMORY.md`, `.logs/BUILD_PLAN.md`, `.logs/decisions.md`, `web/e2e/**`. Not committed.

## Commands run + results
From `web/`, host npm 11.19.0 / Node 26.7.0:

- `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install -D --save-exact @playwright/test @axe-core/playwright` — added 4 packages, 0 vulnerabilities.
- `npm ls @playwright/test @axe-core/playwright` — both are direct dependencies of `lms-web`; `@playwright/test@1.65.1` is deduped under `next@16.4.6`. `npm ls --include=dev --depth=0` lists both; `npm ls --omit=dev` is empty.
- `./node_modules/.bin/playwright --version` — Version 1.65.1.

## Decisions
None. No ADR.

## Bugs / open issues
None. Browsers were not downloaded (`PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`). QA still owns the e2e specs and config.

## Next steps
QA writes `web/e2e/**`. CI `npm ci` in `web/` now installs the Playwright CLI.
