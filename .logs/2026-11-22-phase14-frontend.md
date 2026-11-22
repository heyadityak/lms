# 2026-11-22 — frontend — phase 14: HR screens

## Goal
BUILD_PLAN phase 14 only. HR screens for users, departments, leave types, holiday calendar, reports (CSV through the BFF), and working week. Make the Admin children in `nav.ts` navigable from AppShell. Do not build branding, modules, or identity users (phase 16). No separate team page.

## What I did (files)
All under `web/` unless noted.

Pages (server gate, then a client view). Wrong role → `notFound()`. Holidays and reports also `notFound()` when that module is off.

- `src/app/(app)/admin/users/page.tsx` → `UsersView` (hr_admin)
- `src/app/(app)/admin/departments/page.tsx` → `DepartmentsView` (hr_admin)
- `src/app/(app)/admin/leave-types/page.tsx` → `LeaveTypesView` (hr_admin)
- `src/app/(app)/admin/holidays/page.tsx` → `HolidaysAdminView` (hr_admin + `holidays`)
- `src/app/(app)/admin/reports/page.tsx` → `ReportsView` (hr_admin + `reports`)
- `src/app/(app)/admin/settings/working-week/page.tsx` → `WorkingWeekView` (hr_admin)

Views:

- `src/components/admin/UsersView.tsx` — search/filter, HR fields (`UserUpdate` only: department, manager, joining date, code, designation, employment type). Name, email, and role are read-only text. PATCH never includes `role`. Adjust balance (`POST /users/{id}/ledger-entries`). Activate/deactivate. Not your own account.
- `src/components/admin/DepartmentsView.tsx` — create/update. Delete of a non-empty department shows the problem+json detail (`DEPARTMENT_NOT_EMPTY`).
- `src/components/admin/LeaveTypesView.tsx` — create and update. Code is a disabled labelled field on edit and is not in the PATCH body (`LeaveTypeUpdate`). No delete. Active and inactive types are both listed.
- `src/components/admin/HolidaysAdminView.tsx` — year list, create/update/delete.
- `src/components/admin/ReportsView.tsx` — leave-requests and balances tables. CSV is `fetch` to `/bff/api/reports/...` with `Accept: text/csv` (same filters, no page params).
- `src/components/admin/WorkingWeekView.tsx` — weekend checkboxes. Empty, duplicates, and all 7 days fail in the form before PATCH. Reset sends `{ weekend_days: null }`. A failed GET shows `ProblemAlert` and does not throw.

Shell:

- `src/components/AppShell.tsx` — Admin is a menu of its children (no `/admin` index link). On `/admin/*`, a second nav lists those same links.

Support:

- `src/lib/fields.ts` — problem+json `errors` → `form.setFields`
- `src/lib/weekend.ts` — weekday labels and the selection rule
- `src/lib/csv.ts` — CSV download through the BFF
- `src/lib/api/client.ts` — `unwrapEmpty` for 204
- `src/lib/queries.ts` — `useDepartments`, `useAllLeaveTypes`. `useWorkingWeek` is used by the working-week screen. Team Time Off still uses the default weekend.
- `src/lib/table.ts` — `usePager`
- `src/components/ui.tsx` — `ROLE_LABEL`, `ActiveTag` (text, not colour alone)
- `src/app/globals.css` — focus ring on nav buttons

Tests:

- `src/components/admin/WorkingWeekView.test.tsx` — empty, all 7, duplicates (no PATCH); valid PATCH body; GET problem+json does not render Save
- `src/components/admin/DepartmentsView.test.tsx` — 422 `name` mapped onto the field; 409 delete detail
- `src/components/AppShell.test.tsx` — admin subnav hrefs; Admin menu opens Departments

Not built (phase 16): `/admin/settings/branding`, `/admin/settings/modules`, `/admin/identity-users`. Those nav links are present for an `admin` and 404 until phase 16.

## Commands run + results
Host Node, from `web/`:

- `npm test` — 12 files, **39 passed**, 0 failed.
- `npm run lint` — ESLint + Prettier clean.
- `npm run typecheck` — `tsc --noEmit` clean.

`http://localhost:8080/healthz` did not connect. No browser pass. No live Laravel calls.

## Decisions
Propose, do not edit `decisions.md`:

**Empty weekend is rejected in the UI.** The task requires the form to refuse an empty set, duplicates, and all 7 days before submit. OpenAPI `WorkingWeekUpdate` has `maxItems: 6` and `uniqueItems` but no `minItems`, and `UpdateWorkingWeek::assertValid` allows `[]` (every day a working day). This screen will not send `[]`. Reset is `null` (deployment default), which the action already accepts. If a 7-day working week should be settable, drop the empty check.

Follow-up: an empty selection is valid under D-30 and `WorkingWeekUpdate` (no `minItems`); the form now PATCHes `{weekend_days: []}` for a 7-day working week. Duplicates and more than 6 days are still rejected before submit, and reset stays `null`.

**Admin has no index route.** The sider Admin control opens the child links. A subnav on `/admin/*` repeats them. There is still no `/admin` page.

## Bugs found / open issues
- **API gap:** `GET`/`PATCH /api/v1/settings/working-week` are in `docs/api/openapi.yaml`. `app/Actions/Settings/UpdateWorkingWeek.php` and `SettingsPolicy` exist. `routes/api.php` has no working-week route and no controller. The screen calls the spec path. A 404 (or any problem+json) stays on the page; the shell does not crash. Phase 15 needs to register the route for the screen to load data.
- Departments, leave types, holidays, users (HR fields, activate/deactivate, ledger adjustment), and both reports including `Accept: text/csv` are already routed (phase 13). No other missing endpoint for these screens.
- Browser not checked (compose stack not listening on :8080).
- `.logs/MEMORY.md` was not updated: this run was told not to touch it.

## Next steps
Phase 15: add the working-week route matching `WorkingWeek` / `WorkingWeekUpdate`, then this screen loads. Phase 16: branding, modules, identity users (nav links already point at those paths). Team Time Off still uses Saturday/Sunday until something passes `useWorkingWeek` into `groupTimeOff`.
