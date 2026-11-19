# Project memory — index

Read this first, then `decisions.md`, then the docs it points to.

## Current state
- 2026-11-19: Design phase done. No application code yet. Docs: `docs/USE_CASES.md`, `docs/SYSTEM_DESIGN.md`, contract `AGENTS.md`.
- Next owner: **platform** (scaffold, Docker, compose, CI, health), then **domain**, with **qa** in parallel.
- 2026-11-19 (later): **Build paused for user review of docs.** Design now includes Keycloak (D-20, D-22), contract-first API `docs/api/openapi.yaml` (D-21), Redis (D-23), scaling + RustFS (D-24), CI (D-25), configurable leave year (D-26), FrankenPHP + Octane (D-27), wiki mirror (D-28). Diagrams in `docs/diagrams/`. Existing platform code still uses php-fpm and must be redone per D-27.
- 2026-11-19 (review): User answered Q3–Q10 → D-29…D-34; MIT license D-35. Platform follow-ups: remove `docker/keycloak/Dockerfile` (D-33), add realm role `admin` + user `dave` to `realm-lms.json` (D-29), comment `user_management` in `.env.example` (D-34).

- 2026-11-19 (phase 1): FrankenPHP + Octane containers verified (scaled compose, S3 roundtrip, suites green). Use `task` (Taskfile) instead of make.
- 2026-11-19 (phase 2): `ci.yml` (lint, openapi, unit, feature) + `package.yml` (ci → compose smoke → GHCR app/nginx) written, actionlint clean, commands verified locally; not yet run on GitHub (D-38).
- 2026-11-19 (phase 3): Keycloak auth done: realm import (4 roles, dave, kc-admin-api for lms-admin), OIDC login/logout verified against real Keycloak, `auth:keycloak-jwt` guard + Redis JWKS cache, provisioning/role sync; 50 tests green (D-39). Open: Octane swallows app JSON logs ("unknown error").
- 2026-11-19 (phase 4): API plumbing done: problem+json (spec UPPERCASE codes), `EnsureIdempotency`, cache/Redis `throttle:api`, module-before-auth order, `/api/docs` (Redoc + SRI), Octane log passthrough fixed; 66 tests green, live stack verified (D-40). Domain seam: `EnsureModuleEnabled` → `Modules` service in phase 5.
- 2026-11-19 (phase 5): Schema done (9 domain migrations incl. btree_gist EXCLUDE, partial uniques, CHECKs), enums/models/factories, LeaveTypeSeeder + DemoSeeder (alice/bob/carol/dave), scoped `Settings`/`Modules`, `@module`, `BrandingComposer` + base layout; 82 tests green incl. Octane FakeWorker isolation (D-41).
- 2026-11-19 (phase 6): Leave core done: WorkingDayCalculator/LeaveBalanceService/ApproverResolver, Submit/Approve/Reject/Cancel (row locks, 23P01 backstop, ledger debit/reversal), LeaveRequestPolicy, `/api/v1` me/balances/leave-requests/approvals/leave-types/holidays, Blade dashboard/apply/my requests/approvals/holidays, `active` middleware, provisioner last_login_at + prorated initial grant; 110 tests green, live API + web flow verified (D-42).
- 2026-11-19 (phase 7): QA done: 164 tests green, 8 known bugs open (`task test:known-bugs`), see phase-7 log "Bugs found" for domain (D-44).
- 2026-11-19 (D-43): **Redesign: Next.js frontend (`web/`, antd v5, Auth.js BFF, Figma "Employee Leave Management") + API-only Laravel.** New endpoints `GET /api/v1/branding` (public) and `POST /api/v1/leave-requests/preview`. BUILD_PLAN phases 8–17 rewritten. Open user decisions: Auth.js beta, antd v5 vs v6, Figma license.

## Run logs
| Date | Agent | File | Summary |
|---|---|---|---|
| 2026-11-19 | architect | [2026-11-19-architect.md](2026-11-19-architect.md) | Researched the original project + LMS practices. Wrote use cases, system design, AGENTS.md, ADRs D-01…D-19 (incl. white-label branding/modules). |
| 2026-11-19 | architect | [2026-11-19-architect-diagrams.md](2026-11-19-architect-diagrams.md) | Mermaid diagrams (01–07 + 31 SVGs, all rendered), API guidelines + OpenAPI 3.1 (Redocly-valid), Keycloak / user admin / Redis / RustFS / scaling / CI / FrankenPHP doc updates, ADRs D-20…D-27. |
| 2026-11-19 | platform | [2026-11-19-platform.md](2026-11-19-platform.md) | Scaffold/compose drafted, unverified; build paused for review (pre-FrankenPHP: php-fpm). |
| 2026-11-19 | platform | [2026-11-19-platform-wiki.md](2026-11-19-platform-wiki.md) | docs/ → GitHub wiki mirror script + workflow (D-28); actionlint clean; not pushed. |
| 2026-11-19 | architect | [2026-11-19-architect-review.md](2026-11-19-architect-review.md) | Applied user answers Q3–Q10: additive `admin` role (D-29), editable weekend (D-30), SL backdate (D-31), IdP-down blocks identity admin (D-32), no Keycloak image (D-33), `user_management` checklist (D-34); MIT license (D-35); root README. Spec + diagrams re-validated. |
| 2026-11-19 | platform | [2026-11-19-phase1-platform.md](2026-11-19-phase1-platform.md) | Phase 1: FrankenPHP 1.13.5/PHP 8.4 + Octane image, nginx upstream LB, compose (app×3, worker×2 verified), /healthz /readyz, Taskfile; 36 tests green, pint clean (D-37). |
| 2026-11-19 | platform | [2026-11-19-phase2-platform.md](2026-11-19-phase2-platform.md) | Phase 2: ci.yml + package.yml (workflow_call gate, smoke scale app=2, GHCR multi-arch on tags); actionlint clean; local pint/unit/feature/redocly/smoke green (D-38). |
| 2026-11-19 | platform | [2026-11-19-phase4-platform.md](2026-11-19-phase4-platform.md) | Phase 4: problem+json renderer (spec codes), DomainException + LeaveRuleViolation/InvalidTransition/Idp*, EnsureIdempotency, throttle headers, module 404 before auth, /api/docs + spec, Octane raw log passthrough; 66 tests green, live curl through nginx (D-40). |
| 2026-11-19 | domain | [2026-11-19-phase5-domain.md](2026-11-19-phase5-domain.md) | Phase 5: domain schema + constraints, enums/models/factories, seeders, Settings/Modules/branding foundation, `@module`, layout; 82 tests green, live override verified (D-41). |
| 2026-11-19 | domain | [2026-11-19-phase6-domain.md](2026-11-19-phase6-domain.md) | Phase 6: leave core (services, 4 actions, policy, API per spec, minimal web UI, `active`, provisioner leftovers); 110 tests green, live alice→bob flow verified (D-42). |
| 2026-11-19 | architect | [2026-11-19-architect-frontend.md](2026-11-19-architect-frontend.md) | D-43: Next.js + BFF, Laravel API-only, antd + Figma UI_GUIDELINES, public branding + leave preview endpoints, docs/diagrams/AGENTS/BUILD_PLAN updated; spec + Mermaid validated. |
| 2026-11-19 | platform | [2026-11-19-phase3-platform.md](2026-11-19-phase3-platform.md) | Phase 3: Keycloak realm, web OIDC login + RP logout, JWT guard `keycloak-jwt` + JWKS cache (rate-limited refetch), provisioning/role sync, users identity-column migration; live stack verified (scale app=3, Admin API create/delete); 50 tests green (D-39). |
| 2026-11-19 | qa | [2026-11-19-phase7-qa.md](2026-11-19-phase7-qa.md) | Phase 7: 54 new tests (rules edges, full 409 matrix, RBAC, openapi contract incl. errors/pagination, real idempotency, process-based races, Octane identity, JWT/DOJ probes); 164 green + 8 known-bug excluded; Redis test DBs 10–13 (D-44). |

## Open questions (awaiting user)
1. ~~Leave year~~ Resolved by D-26 (`LEAVE_YEAR_START_MONTH`, default 1). Confirm env-only (not admin-editable) is acceptable.
