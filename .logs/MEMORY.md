# Project memory — index

Read this first, then `decisions.md`, then the docs it points to.

## Current state
- 2026-11-19: Design phase done. No application code yet. Docs: `docs/USE_CASES.md`, `docs/SYSTEM_DESIGN.md`, contract `AGENTS.md`.
- Next owner: **platform** (scaffold, Docker, compose, CI, health), then **domain**, with **qa** in parallel.
- 2026-11-19 (later): **Build paused for user review of docs.** Design now includes Keycloak (D-20, D-22), contract-first API `docs/api/openapi.yaml` (D-21), Redis (D-23), scaling + RustFS (D-24), CI (D-25), configurable leave year (D-26), FrankenPHP + Octane (D-27), wiki mirror (D-28). Diagrams in `docs/diagrams/`. Existing platform code still uses php-fpm and must be redone per D-27.

## Run logs
| Date | Agent | File | Summary |
|---|---|---|---|
| 2026-11-19 | architect | [2026-11-19-architect.md](2026-11-19-architect.md) | Researched the original project + LMS practices. Wrote use cases, system design, AGENTS.md, ADRs D-01…D-19 (incl. white-label branding/modules). |
| 2026-11-19 | architect | [2026-11-19-architect-diagrams.md](2026-11-19-architect-diagrams.md) | Mermaid diagrams (01–07 + 31 SVGs, all rendered), API guidelines + OpenAPI 3.1 (Redocly-valid), Keycloak / user admin / Redis / RustFS / scaling / CI / FrankenPHP doc updates, ADRs D-20…D-27. |
| 2026-11-19 | platform | [2026-11-19-platform.md](2026-11-19-platform.md) | Scaffold/compose drafted, unverified; build paused for review (pre-FrankenPHP: php-fpm). |
| 2026-11-19 | platform | [2026-11-19-platform-wiki.md](2026-11-19-platform-wiki.md) | docs/ → GitHub wiki mirror script + workflow (D-28); actionlint clean; not pushed. |

## Open questions (awaiting user)
1. ~~Leave year~~ Resolved by D-26 (`LEAVE_YEAR_START_MONTH`, default 1). Confirm env-only (not admin-editable) is acceptable.
2. ~~GHCR names~~ Resolved by D-25: `ghcr.io/heyadityak/lms/{app,nginx}` (repo `heyadityak/lms`).
3. Should a separate `admin` (IT/customer-admin) role own branding/modules and identity user admin instead of `hr_admin` (D-18, D-22, UC-U7)?
4. Weekend default Sat+Sun. Should it be per deployment only (env, as designed) or admin-editable via `settings`?
5. Is backdated sick leave (7 days default for SL) acceptable as the default policy?
6. ~~Multi-arch~~ Resolved by D-25: amd64 on `main`, amd64+arm64 on `v*` tags.
7. Should the prod Keycloak image (`docker/keycloak/Dockerfile`) be published to GHCR too, and should a custom login theme ship in it?
8. User admin: when Keycloak is down, should the HR UI show a read-only local user list (current design) or block the page?
9. `user_management` on by default in `MODULES_ENABLED`: OK for SMB, but should enterprise deployments with AD/Entra default it off?
10. Accept the two Redocly warnings (no 4xx on `/healthz`, `/readyz`) or add a `redocly.yaml` to silence them?
