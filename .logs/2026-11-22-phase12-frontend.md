# 2026-11-22 — frontend — phase 12: leave screens

## Goal
BUILD_PLAN phase 12 only (UI_GUIDELINES §2 S2–S7, UC-E4–E8, UC-M1–M3). Wire the existing leave views, add Dashboard and Holidays, and open Apply Leave from `/leave-requests/new`. Do not start phases 14 or 16. Do not call `GET /settings/working-week`.

## What I did (files)
All under `web/` unless noted.

Wired (pages no longer render `Placeholder`):
- `src/app/(app)/page.tsx` → `DashboardView`
- `src/app/(app)/leave-requests/page.tsx` → `LeavesView`
- `src/app/(app)/leave-requests/new/page.tsx` → `LeavesView applyOpen` (S4 deep link)
- `src/app/(app)/approvals/page.tsx` → `ApprovalsView`, `notFound()` unless role is `manager` or `hr_admin`
- `src/app/(app)/holidays/page.tsx` → `HolidaysView`, existing `notFound()` when `holidays` is off

New:
- `src/components/DashboardView.tsx` — Available Leaves + Apply (`/leave-requests/new`); Pending approvals + Team Time Off only for manager/hr_admin; Next Holidays only when the module is on. No absent alert. No employee-wide calendar.
- `src/components/HolidaysView.tsx` — leave year 2000–2100, month `Calendar` (`cellRender`) and `Listy`. No weekend shading.
- `src/components/ApplyLeaveModal.test.tsx` — preview body, `Idempotency-Key`, problem+json field errors
- `src/components/CommentModal.test.tsx` — reject comment required (and at least 3 characters)
- `src/components/DashboardView.test.tsx` — employee hides Team Time Off and pending stat; holidays module gates Next Holidays; manager query window

Edited:
- `src/components/LeavesView.tsx` — Apply on this page stays local (filters kept). `/leave-requests/new` opens the modal; closing it `router.replace`s `/leave-requests`. Detail drawer unchanged (`include=actions`).
- `src/components/ApplyLeaveModal.tsx` — `labelAlign="start"` (antd v6; `left` warns).
- `src/components/CommentModal.tsx` — validation failure stays on the field and does not reject the Modal `onOk` promise.
- `src/components/ui.tsx` — initials avatar: `role="img"` on a span. antd 6 `Avatar` does not accept `role`.
- `src/lib/queries.ts` — `useHolidays(enabled, year?)`. Comment on `useWorkingWeek`: phase 12 must not call it.
- `src/lib/timeoff.ts` — `DEFAULT_WEEKEND_DAYS` `[6, 7]`, `TEAM_TIME_OFF_DAYS` 14.
- `src/components/AppShell.test.tsx` — fixtures match `ShellUser.id` and brand colour props.
- `vitest.setup.ts` — `ResizeObserver` stub; `matchMedia` treats `min-width` as matching so jsdom gets the desktop shell.
- Prettier wrapped long lines in existing phase-12 components (`ApprovalsView`, `AppShell`, `BalanceBreakdown`, `CarouselCard`, `LeaveDetailDrawer`, `table.ts`, `timeoff.test.ts`) so `prettier --check` is clean.

Not mounted as its own URL: S5 stays the existing drawer from Leaves and Approvals (`GET /leave-requests/{id}?include=actions`).

`Placeholder.tsx` is unused. Left in place.

Follow-up: Leaves stat cards are one available-balance card per tracked leave type (title = type name, value = `available`; a type with `tracks_balance === false` is omitted) plus the existing Pending leave requests card. Summed Available / Entitled / Used cards are gone. Dashboard still has the single Available Leaves stat. `LeavesView.test.tsx` covers this. Re-ran `npm test` (10 files, 30 passed), `npm run lint`, and `npm run typecheck`, all clean.

## Commands run + results
Host Node, from `web/`:

- `npm test` — 9 files, **29 passed**, 0 failed.
- `npm run lint` — ESLint + Prettier clean.
- `npm run typecheck` — `tsc --noEmit` clean.
- `npm run build` — Next.js 16.4.6 compiled. Routes `/`, `/leave-requests`, `/leave-requests/new`, `/approvals`, `/holidays` are dynamic.

No browser pass (orchestrator). No live Laravel calls.

## Decisions
Propose, do not edit `decisions.md`:

**D-51 — Phase-12 Team Time Off uses the default weekend (amends UI_GUIDELINES §3 until phase 15).** Dashboard Team Time Off groups people with `groupTimeOff` and weekend ISO days `[6, 7]` (Saturday and Sunday, the `LMS_WEEKEND_DAYS` default). It does not call `GET /api/v1/settings/working-week`. That route and UC-H16 are phase 15. Until then, a customer who overrode the working week still has Saturday and Sunday omitted from Team Time Off, and a Friday-off weekend still lists Friday. The holidays calendar does not shade weekends. Apply Leave does not count days in the browser: `POST /leave-requests/preview` already applies the override. Phase 15 should pass `useWorkingWeek().weekend_days` into `groupTimeOff` and, if we shade the holiday calendar, into that view too. (2026-11-22, frontend)

D-51's dashboard half is now done. Team Time Off passes `useWorkingWeek().weekend_days` into `groupTimeOff` after that query succeeds, and keeps `[6, 7]` while the query is loading or if it fails. Holidays are still not shaded, and Apply Leave still uses the preview endpoint.

## Bugs found / open issues
- No Laravel bug blocked a screen. `LeaveRequestQuery::visibleTo` matches the dashboard call (employee: own; manager: own + direct reports; hr_admin: all). Holidays `filter[year]` is 2000–2100. Not executed against a running API.
- "Today" on the dashboard is the browser's local date, not `APP_TIMEZONE`.
- Holiday year control sends `filter[year]` (leave year). The month grid is a calendar year. They match when `LEAVE_YEAR_START_MONTH` is 1 (the default). A fiscal year will show calendar months that are not that leave year.
- Team Time Off requests one page of 100 (API max). If `meta.total` is higher, the card says how many were omitted.
- ~~S3 keeps the existing summed Available / Entitled / Used cards plus the per-type popover, not one card per leave type. Say if that should change.~~ Done: one available card per tracked type, plus Pending.
- `Placeholder.tsx` has no callers.
- Holiday calendar selected the 1st because `cursor` started at `dayjs().startOf('month')`. Fixed: initial value is today. `HolidaysView.test.tsx` asserts the selected cell title is today. Live check on `/holidays` (2026-11-22): selected cell `2026-11-22`, 1 Nov not selected.

## Next steps
Screens done for phase 12: Dashboard, Leaves, Apply (modal + `/leave-requests/new`), request detail drawer, Approvals, Holidays. Approvals and Holidays still 404 for the wrong role / module off.

Not started: phases 14 and 16, admin screens, weekend override (phase 15). `useWorkingWeek` is still defined and unused by these screens.
