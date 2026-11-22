# 2026-11-22 frontend — phase 19 screens

## Goal

Phase 19 UI: hourly leave and one attachment, leave PDF and attachment on the detail drawer, avatar in the user menu, team calendar, approval delegation, sandwich and accrual frequency, location and encashment, department quota overrides, locations, holiday location, audit log.

## What I did

- `npm run gen:api` after `docs/api/openapi.yaml` gained the phase 19 paths. Screens call `browserApi` with those generated types. No hand-written `schema.d.ts` entries.
- Apply leave: optional hours 1–8, shown only on a single day and hidden for a half day. One PNG/JPEG/PDF (max 5 MB) is posted to `POST /leave-requests/{id}/attachments` after the request is created.
- Leave detail: download link `/bff/api/leave-requests/{id}/pdf`. One attachment (GET bytes, 404 means none). Delete for the owner while pending.
- User menu: `POST`/`DELETE /me/avatar`. Image from `/bff/api/users/{id}/avatar` when `avatar_url` is set, otherwise initials. Profile link kept because `/profile` is in the app.
- `/team-calendar` for every role. `GET /team-calendar?from&to`. No reason column. Nav item for all roles.
- `/approvals/delegation` for manager and `hr_admin` (`notFound()` otherwise). One delegation: form when none, delete via `DELETE /me/approval-delegations`. Linked from Approvals, not a sider item.
- Leave type form: Sandwich switch and accrual frequency yearly or monthly (`sandwich`, `accrual_frequency`).
- HR user drawer: location select. Encash opens a form (leave type, days, note) posting `POST /users/{id}/encashments`.
- Department drawer: quota override per leave type. Changed rows `PUT /departments/{id}/leave-quotas` (`annual_quota: null` clears).
- `/admin/locations` CRUD `/locations`. Holiday admin: optional location.
- `/admin/audit-logs` table from `GET /audit-logs`. Locations and Audit log are hr_admin admin-subnav items.
- `ModulesView` test fixture key `delegation` → `multi_level` so it matches the regenerated `ModuleKey`.

## Commands + results

- `npm run gen:api` — wrote `web/src/lib/api/schema.d.ts`.
- `npm run typecheck` — `tsc --noEmit` passed.
- `npm test` — Vitest **61 passed** (22 files).

## Decisions

No new ADR. Field names follow the updated OpenAPI contract (singular delegation, one attachment, `sandwich`, `from`/`to` on the team calendar, avatar image at `GET /users/{id}/avatar`).

## Bugs / open issues

- Browser not checked.
- `schema.d.ts` is regenerated; `check:api` will fail until that file is committed with the spec.

## Next steps

- QA can cover hourly leave, the single attachment, encashment, and the new HR pages against the live API.
