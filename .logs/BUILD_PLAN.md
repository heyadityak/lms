# Build plan (orchestrator)

Small phases. Each phase: one agent run → verified (tests green in Docker) → one commit (+ push) → status ticked here.

| # | Agent | Scope | Status |
|---|---|---|---|
| 1 | platform | FrankenPHP + Octane rework: Dockerfile (app), nginx edge proxy image, compose (app×N, worker, scheduler, postgres, redis, rustfs + s3-init, keycloak, mailpit), `.env.example`, `/healthz` `/readyz`, Unit/Feature suites green | done |
| 2 | platform | CI: `ci.yml` (pint, unit, feature w/ Postgres+Redis, OpenAPI lint), `package.yml` (GHCR app+nginx, compose smoke) | done |
| 3 | platform | Keycloak: realm import (roles employee/manager/hr_admin/admin, clients lms-web/lms-api-cli/lms-admin, dev users), OIDC web login/logout, JWT API guard + JWKS cache, user provisioning/role sync | done |
| 4 | platform | API plumbing: `/api/v1` group, problem+json renderer, Idempotency-Key, Redis throttle headers, `/api/docs` + `/api/openapi.yaml` | done |
| 5 | domain | Schema: migrations, models, enums, factories, seeders; Settings/Modules/Branding foundation (`module:` middleware, `@module`) | todo |
| 6 | domain | Leave core: WorkingDayCalculator, LeaveYear, ledger/balances, submit/approve/reject/cancel (web + API) | todo |
| 7 | qa | Tests for phases 1–6 incl. OpenAPI response validation, Octane state-leak; bug list → domain fixes | todo |
| 8 | domain | HR admin: departments, leave types, holidays, balance adjustments, reports CSV, notifications, yearly accrual | todo |
| 9 | domain | Admin: branding/theme/logo, modules, weekend days, Keycloak user management (guardrails, audit, compensation) | todo |
| 10 | qa | Tests for phases 8–9 + full regression; bug fixes loop until green | todo |
