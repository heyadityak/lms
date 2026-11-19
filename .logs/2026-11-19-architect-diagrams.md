# 2026-11-19 — architect — diagrams, Keycloak, API contract, user admin, Redis/RustFS/scaling, CI, FrankenPHP

## Goal
Started as "write Mermaid diagrams consistent with the docs". During the run the coordinator added requirements, all handled here: Redis + horizontal scaling, RustFS instead of MinIO, Keycloak as IdP/RBAC, a contract-first API (guidelines + OpenAPI 3.1), in-app Keycloak user administration, finishing the platform agent's doc work (Redis, scaling, RustFS init, Keycloak env, API plumbing, CI, dev seed users) after it was stopped, and FrankenPHP + Octane instead of php-fpm. Docs only; no code touched; no git add/commit.

## What I did (files)
- `docs/diagrams/` (new): `README.md`, `01-system-design.md` (context, containers, per-customer deployment, scaling), `02-data-flow.md` (DFD L0, L1a, L1b; stores D1–D14), `03-class-diagram.md` (4 class diagrams + class-name list), `04-object-diagram.md`, `05-activity-diagrams.md` (7 activities incl. first-login provisioning and role guardrails), `06-sequence-diagrams.md` (11 sequences incl. OIDC login/logout, JWT API, idempotency/errors, user creation with compensation), `07-state-diagram.md`; `svg/` with 31 exported SVGs.
- `docs/API_GUIDELINES.md` (new), `docs/api/openapi.yaml` (new, OpenAPI 3.1).
- `docs/USE_CASES.md`: Keycloak actor/§3.10, removed in-app password use cases, UC-U1…U7 + guardrails §3.11 (G1–G7), `user_management` module, UC-B9/B10, configurable leave year (§3.2, §3.7).
- `docs/SYSTEM_DESIGN.md`: §1 context (FrankenPHP, Keycloak, Redis, RustFS, scaling, delivery), §2 architecture diagram, §3 topology (+ redis, rustfs, s3-init, keycloak; §3.1 scaling rules; FrankenPHP Dockerfile targets), §4 ERD (`public_id`, `keycloak_sub`, no password columns, `user_admin_audits`), §5 components (Auth, user-admin actions, scoped Settings/Modules), §6 routes + API section pointing at the spec, §8 Redis/S3, §9 12-factor + full env table aligned with `.env.example`, §10 + §10.1 identity, §11 readyz DB+Redis, §12 seeders (alice/bob/carol), §13 CI, §15 Diagrams links.
- `AGENTS.md`: stack (FrankenPHP/Octane, Keycloak, Redis, RustFS, scaling, API contract, deps), layout, Octane worker-mode safety rules, auth/user-admin/API conventions, run commands, QA scenarios 13–18, CI, DoD, architect row in ownership.
- `.logs/decisions.md`: D-20 … D-27 appended; D-03 marked superseded; D-04, D-09, D-10, D-15 marked amended; D-20 marked partly superseded by D-22. (D-28 was added by the platform-wiki run.)

## Commands run + results
- `docker run --rm minlag/mermaid-cli` (mmdc 12.0.0) on every extracted block of `docs/diagrams/0*.md` → 31/31 render, SVGs in `docs/diagrams/svg/` (`--no-font-embed`, 3.4 MB). Also validated the Mermaid blocks in `SYSTEM_DESIGN.md` (3) and `USE_CASES.md` (1). PNG spot checks of class, object, state, activity and sequence diagrams. Fixed one issue found: one-line `class X { <<stereotype>> text }` renders the stereotype wrongly → converted to multi-line.
- `docker run --rm -v "$PWD":/work redocly/cli lint /work/docs/api/openapi.yaml` → valid, 2 accepted warnings (`operation-4xx-response` on `/healthz` and `/readyz`). Fixed: an unquoted comma in a flow-mapping description, missing tag descriptions.

## Decisions
D-20 Keycloak · D-21 contract-first API · D-22 in-app Keycloak user admin · D-23 Redis · D-24 horizontal scaling + RustFS · D-25 CI · D-26 configurable leave year · D-27 FrankenPHP + Octane.

## Bugs found / open issues
- Aligned docs to the platform's implemented env names: `KEYCLOAK_BASE_URL` = back-channel, `KEYCLOAK_PUBLIC_URL` = issuer/browser, `KEYCLOAK_ALLOWED_AUDIENCES=lms-web,lms-api-cli` (checked against `aud` or `azp`), `KEYCLOAK_JWT_LEEWAY`. The realm import has no audience mappers, so the guard must accept `azp`.
- Code/config written by the platform agent before the FrankenPHP switch still uses php-fpm (Dockerfile `php-base`, `docker/php/zz-fpm.conf`, nginx FastCGI template, `PHP_FPM_HOST`) → must be redone to match D-27 (`APP_UPSTREAM`, `app-base` FrankenPHP, Octane). `.env.example` lacks `APP_SERVER_MODE`, `OCTANE_*`, `SERVER_NAME`, `APP_UPSTREAM`.
- `.github/workflows/ci.yml` does not exist yet (only `wiki.yml`); D-25 describes the target.
- `laravel/octane` must be added to `composer.json`; Sanctum must not be installed.
- The dev realm has an extra `lms-dev-cli` client (direct grants) — documented as dev-only.

## Next steps
1. User reviews docs (checklist in the coordinator report) and answers open questions in `MEMORY.md`.
2. platform: redo Dockerfile/compose/nginx for FrankenPHP (D-27), CI per D-25, `/api/docs` route.
3. domain: adopt class names in `docs/diagrams/03-class-diagram.md`; implement routes per `docs/api/openapi.yaml`.
4. qa: spec validation in API tests, Octane state-leak test, `FakeKeycloakAdminClient`.
