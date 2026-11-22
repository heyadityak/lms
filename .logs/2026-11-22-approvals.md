# 2026-11-22 — approvals page

## Goal
Find why `/approvals` fails for a manager or hr_admin, and fix it. Do not touch audit, departments, or locations files. Do not commit.

## What I did
No product code change. The page, the leave-request resource, and the approve/reject routes already match the contract.

- `web/src/app/(app)/approvals/page.tsx` renders `ApprovalsView` for `manager` and `hr_admin`.
- `ApprovalsView` reads `GET /approvals` (`user.name`, `leave_type.name`, dates, `days`, `reason`, `created_at`). `hours: null` is ignored. The delegation link is a normal `Link` to `/approvals/delegation` and does not throw before the table.
- A manager inbox calls `LeaveRequestQuery::approvableBy`, which reads `approval_delegations`. That table is created by `2026_11_22_190000_phase19_leave_extensions`. App logs from before that migration showed missing phase 19 relations (`department_leave_quotas`, `holidays.location_id`, `audit_logs`). The same migration creates `approval_delegations`. `hr_admin` returns before that query, so HR was not blocked by the missing table.
- The departments/locations fix applied that migration. After it, both roles get 200 and the inbox renders.

## Commands run + results
- Bob and Carol `GET /api/v1/approvals` — 200, one pending row (Alice, Earned Leave, `hours: null`), envelope `data`/`meta`/`links`.
- Bob session: `/approvals` and `/approvals/delegation` HTML 200. BFF `GET /bff/api/approvals` 200. Delegation API 404 when none exists (the form treats that as empty).
- Browser as Carol: inbox row, detail drawer (history, no attachment), delegation form. No error overlay.
- Browser as Bob: client navigation from View approvals lands on `/approvals` with the same row, Approve/Reject, and the delegation link.
- `php artisan test --filter=test_manager_lists_a_direct_reports_pending_request_and_approves_it` — 1 passed (19 assertions).

## Decisions
None.

## Bugs found / open issues
None left on this page. No code change.

## Next steps
None for approvals.
