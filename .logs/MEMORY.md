# Project memory — index

Read this first, then `decisions.md`, then the docs it points to.

## Current state
- 2026-11-19: Design phase done. No application code yet. Docs: `docs/USE_CASES.md`, `docs/SYSTEM_DESIGN.md`, contract `AGENTS.md`.
- Next owner: **platform** (scaffold, Docker, compose, CI, health), then **domain**, with **qa** in parallel.

## Run logs
| Date | Agent | File | Summary |
|---|---|---|---|
| 2026-11-19 | architect | [2026-11-19-architect.md](2026-11-19-architect.md) | Researched the original project + LMS practices. Wrote use cases, system design, AGENTS.md, ADRs D-01…D-19 (incl. white-label branding/modules). |

## Open questions (awaiting user)
1. Leave year: calendar year assumed (D-04). Do some customers need a fiscal year (e.g. Apr–Mar, common in India)? If yes, add `LMS_LEAVE_YEAR_START_MONTH` env before domain starts.
2. GHCR image names: `ghcr.io/heyadityak/<repo>/app` and `/nginx` assumed. Is the repo name `laravel-lms-rewrite`?
3. Should a separate `admin` (IT/customer-admin) role own branding/modules instead of `hr_admin` (D-18)?
4. Weekend default Sat+Sun. Should it be per deployment only (env, as designed) or admin-editable via `settings`?
5. Is backdated sick leave (7 days default for SL) acceptable as the default policy?
6. Multi-arch images (arm64) needed for customer deployments? Currently amd64 only.
- [2026-11-19 platform (paused, partial)](2026-11-19-platform.md) — scaffold/compose drafted, unverified; build paused for review
