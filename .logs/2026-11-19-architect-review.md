# 2026-11-19 — architect — apply user review answers (Q3–Q10), README, MIT license

## Goal
Apply the user's answers to open questions Q3–Q10 across AGENTS.md, USE_CASES, SYSTEM_DESIGN, API_GUIDELINES, `docs/api/openapi.yaml` and the diagrams. Record decisions, then clear the answered questions from MEMORY.md. Coordinator extras: a root `README.md` and the MIT license (holder Aditya Krishnakumar) across the docs.

## What I did (files)
- `docs/api/openapi.yaml` (spec first):
  - `admin` realm role: `x-roles: [admin]` on all `/admin/users*`, branding writes and `/modules*`.
  - New `AssignableRole` enum (`employee|manager|hr_admin|admin`), used by `RoleAssignment` (1 base role + optional `admin`, max 2, unique) and by the `filter[role]` of `/admin/users`.
  - `is_admin` added to `User`, `IdentityUser` and `IdentityUserCreate`.
  - `LAST_HR_ADMIN` renamed to `LAST_ADMIN`.
  - New `GET/PATCH /settings/working-week` with `WorkingWeek` / `WorkingWeekUpdate` and the `WorkingWeekOk` response.
  - `/admin/users` notes that there is no stale fallback (503).
  - `info.license` changed to MIT.
- `docs/API_GUIDELINES.md`: error table (`SELF_MODIFICATION_FORBIDDEN`, `LAST_ADMIN`, `IDP_UNAVAILABLE` scope), §5 base vs additive roles, §9 identity admin is `admin`-only plus the 503 rule.
- `docs/USE_CASES.md` (v1.3):
  - New Admin actor; role paragraph; Customer Admin = `admin`.
  - New UC-H16 (working week). UC-U* moved to `admin`. UC-U1 503 rule. UC-U6 allow-list. UC-U7 marked done. UC-B9 is now a customer/ops task (no image or theme).
  - SL backdate default confirmed. §3.3 weekend source, plus no recalculation of existing requests. §3.8 `user_management` row. §3.9 and §3.10 sync `is_admin`. G1–G4 rewritten.
- `docs/SYSTEM_DESIGN.md` (v1.4):
  - Realm roles; Keycloak container row is now upstream image only; ERD `users.is_admin`; enums; components (`UpdateWorkingWeek`, `AssignRolesAction`, provisioner).
  - Web routes: branding/modules/user admin → `admin`, new `/admin/settings/working-week` (hr_admin), and new `/admin/identity-users` (admin) for the Keycloak list/detail pages.
  - API table; `work.weekend_days` setting; deployment checklist (D-34); env notes; security roles/policies; §10.1 realm + availability (D-32); seed user `dave`; §13 Keycloak image sentence.
- `AGENTS.md`: overview, stack row, directory layout (no `keycloak/Dockerfile`), Auth rule, dev logins (dave), QA scenarios 4/12/16/17.
- Diagrams:
  - `01-system-design.md`: Admin actor; Keycloak A is the upstream image.
  - `02-data-flow.md`: Admin entity, `work.weekend_days`, `is_admin`, P11 503 path.
  - `03-class-diagram.md`: `User.is_admin`, `Role::Admin`/`isBase()`, `UpdateWorkingWeek`, `WorkingWeekController`, `UpdateWorkingWeekRequest`, `assertNotLastAdmin`, policies, names table.
  - `04-object-diagram.md`: dave (admin) is now the audit actor.
  - `05-activity-diagrams.md`: weekend source, admin + working-week lanes, provisioning sync, G1–G3 for admin.
  - `06-sequence-diagrams.md`: branding and user creation by the admin dave; upstream Keycloak login.
  - `README.md` index text.
  - SVGs for 01–06 regenerated.
- `README.md` (new, root): summary, stack, wiki links, note that docs are edited in `docs/`, placeholder quickstart, MIT license section and badge.
- `.logs/decisions.md`: D-29…D-35 added. D-18 marked superseded by D-29; D-22 marked partly superseded by D-29/D-32/D-34.
- `.logs/MEMORY.md`: Q2–Q10 removed (answered/resolved). Q1 kept (its confirmation is still pending). Current state, platform follow-ups and this log added to the index.

## Commands run + results
- `docker run --rm -v "$PWD":/work redocly/cli lint /work/docs/api/openapi.yaml`: valid, 2 warnings. Both are `operation-4xx-response` on `/healthz` and `/readyz`, which have no 4xx by design. **Accepted per user answer Q10**, no `redocly.yaml`.
- `docker run --rm -v "$PWD/docs/diagrams":/data minlag/mermaid-cli -i /data/<file>.md -o /data/svg/<file>.md -e svg` for 01–06: all 30 blocks rendered, no "Syntax error" in any SVG. Generated `.md` copies in `svg/` deleted. 07 is unchanged. The Mermaid blocks in USE_CASES/SYSTEM_DESIGN were not changed.

## Decisions
- D-29: additive `admin` realm role, `users.is_admin`, allow-list, `LAST_ADMIN`.
- D-30: editable weekend days (`work.weekend_days`, hr_admin, no recalculation).
- D-31: SL backdate 7 days confirmed.
- D-32: Keycloak down ⇒ 503 on all identity admin, no stale fallback.
- D-33: no Keycloak image or theme published; upstream Keycloak.
- D-34: `user_management` left out for directory realms via the checklist.
- D-35: MIT license, copyright Aditya Krishnakumar.

Judgement calls (flag to the user if wrong):
- `admin` is **additive** to a single base role, not a fourth exclusive rank. This lets SMBs combine hr_admin and admin in one person.
- The working week is edited by **hr_admin** (an HR policy, like holidays), not admin.
- `LAST_HR_ADMIN` was replaced rather than kept next to `LAST_ADMIN`. An admin can always re-assign hr_admin.
- Added the web route `/admin/identity-users` because `/admin/users` (web) is the hr_admin local-data list.

## Bugs found / open issues
- Outside my ownership. Platform must:
  - delete `docker/keycloak/Dockerfile` (D-33);
  - add realm role `admin` and dev user `dave` (`dave@example.test`, roles employee + admin) to `docker/keycloak/realm-lms.json` (D-29);
  - add a comment next to `MODULES_ENABLED` in `.env.example`: "omit user_management for LDAP/AD/Entra-backed realms" (D-34).
- Domain must:
  - add `users.is_admin`;
  - add `Role::Admin`;
  - seed `dave`;
  - implement `work.weekend_days` / UC-H16.
- Open question 1 (leave year env-only) still awaits user confirmation.

## Next steps
- Platform: the realm/env/Dockerfile follow-ups above. Domain/QA: follow the spec changes (x-roles, `is_admin`, `LAST_ADMIN`, working-week endpoints, 503 on identity list/detail).
