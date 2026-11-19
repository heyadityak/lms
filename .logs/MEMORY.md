# Project memory — index

Read this first, then `decisions.md`, then the docs it points to.

## Current state
- 2026-11-19: Design phase done. No application code yet. Docs: `docs/USE_CASES.md`, `docs/SYSTEM_DESIGN.md`, contract `AGENTS.md`.
- Next owner: **platform** (scaffold, Docker, compose, CI, health), then **domain**, with **qa** in parallel.
- 2026-11-19 (later): **Build paused for user review of docs.** Design now includes Keycloak (D-20, D-22), contract-first API `docs/api/openapi.yaml` (D-21), Redis (D-23), scaling + RustFS (D-24), CI (D-25), configurable leave year (D-26), FrankenPHP + Octane (D-27), wiki mirror (D-28). Diagrams in `docs/diagrams/`. Existing platform code still uses php-fpm and must be redone per D-27.
- 2026-11-19 (review): User answered Q3–Q10 → D-29…D-34; MIT license D-35. Platform follow-ups: remove `docker/keycloak/Dockerfile` (D-33), add realm role `admin` + user `dave` to `realm-lms.json` (D-29), comment `user_management` in `.env.example` (D-34).

- 2026-11-19 (phase 1): FrankenPHP + Octane containers verified (scaled compose, S3 roundtrip, suites green). Use `task` (Taskfile) instead of make.

## Run logs
| Date | Agent | File | Summary |
|---|---|---|---|
| 2026-11-19 | architect | [2026-11-19-architect.md](2026-11-19-architect.md) | Researched the original project + LMS practices. Wrote use cases, system design, AGENTS.md, ADRs D-01…D-19 (incl. white-label branding/modules). |
| 2026-11-19 | architect | [2026-11-19-architect-diagrams.md](2026-11-19-architect-diagrams.md) | Mermaid diagrams (01–07 + 31 SVGs, all rendered), API guidelines + OpenAPI 3.1 (Redocly-valid), Keycloak / user admin / Redis / RustFS / scaling / CI / FrankenPHP doc updates, ADRs D-20…D-27. |
| 2026-11-19 | platform | [2026-11-19-platform.md](2026-11-19-platform.md) | Scaffold/compose drafted, unverified; build paused for review (pre-FrankenPHP: php-fpm). |
| 2026-11-19 | platform | [2026-11-19-platform-wiki.md](2026-11-19-platform-wiki.md) | docs/ → GitHub wiki mirror script + workflow (D-28); actionlint clean; not pushed. |
| 2026-11-19 | architect | [2026-11-19-architect-review.md](2026-11-19-architect-review.md) | Applied user answers Q3–Q10: additive `admin` role (D-29), editable weekend (D-30), SL backdate (D-31), IdP-down blocks identity admin (D-32), no Keycloak image (D-33), `user_management` checklist (D-34); MIT license (D-35); root README. Spec + diagrams re-validated. |
| 2026-11-19 | platform | [2026-11-19-phase1-platform.md](2026-11-19-phase1-platform.md) | Phase 1: FrankenPHP 1.13.5/PHP 8.4 + Octane image, nginx upstream LB, compose (app×3, worker×2 verified), /healthz /readyz, Taskfile; 36 tests green, pint clean (D-37). |

## Open questions (awaiting user)
1. ~~Leave year~~ Resolved by D-26 (`LEAVE_YEAR_START_MONTH`, default 1). Confirm env-only (not admin-editable) is acceptable.
