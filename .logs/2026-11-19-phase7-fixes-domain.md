# 2026-11-19 — phase 7 fixes (domain)

## Goal
Fix QA bugs 3, 4, 5, 6, 8 from `.logs/2026-11-19-phase7-qa.md` (domain-owned code); un-group their `known-bug` tests.

## What I did
- **Bug 3 (invisible request → 404):** `LeaveRequestPolicy::decide` and `::cancel` first run `view`; its `denyAsNotFound` is returned as-is. The three action Form Requests (`Approve/Reject/CancelLeaveRequestRequest`) now return the policy `Response` from `authorize()` (via `Gate::inspect`) instead of `bool`, because a `false` collapses to 403. Self-approval on a visible request stays 403. `LeaveApiTest::test_self_approval_and_non_report_are_forbidden_hr_can_approve` now expects 404 `NOT_FOUND` for the non-report manager.
- **Bug 4 (cancel TOCTOU):** `CancelLeaveRequest` re-runs `Gate::forUser($actor)->authorize('cancel', $locked)` right after `lockForUpdate`, so the "already started" rule is evaluated on the locked row (403, nothing written).
- **Bug 5 (employee-role manager):** `ApproverResolver` only accepts actors/direct managers whose base role is `manager` or `hr_admin`. `approversFor` falls back to active hr_admins when the direct manager has role `employee`; `canDecide` refuses employee-role actors.
- **Bug 6 (control chars):** new rule `App\Rules\PlainText` (rejects Unicode `Cc` except `\t \n \r`, and invalid UTF-8) added to every free-text Form Request field: `reason`, `handover_note`, `comment` (approve/reject/cancel). 422 `VALIDATION_FAILED`.
- **Bug 8 (page wrap):** `page.number` is now `between:1,10000` in `ListLeaveRequestsRequest` (all leave-request lists + approvals) → 422.
- Removed `#[Group('known-bug')]` from: `LeaveApiContractTest::test_nul_byte_in_reason_is_refused_not_silently_truncated`, `::test_huge_page_number_does_not_wrap_around_to_the_first_page`, `LeaveRulesEdgeTest::test_owner_cancel_racing_an_approval_cannot_cancel_started_leave`, `::test_actions_on_an_invisible_request_are_404_not_403`, `::test_employee_role_user_set_as_manager_cannot_approve`. Assertions unchanged. Pint dropped the now-unused `Group` import.

## Commands + results
- `task lint` → PASS (152 files).
- `task test` → 172 passed (1579 assertions). Earlier runs showed random `QueryException`/`UniqueConstraintViolation` failures in unrelated tests. They came from a parallel agent using the shared `lms_test` DB, and every one passed when re-run alone.
- The 6 touched tests, filtered → all pass.

## Decisions (for the architect to number)
- **Approver role (orchestrator decision, bug 5):** only users whose base role is `manager` or `hr_admin` may approve/reject, matching openapi `x-roles`. A direct manager with base role `employee` is not an approver; their reports' requests route to active hr_admins. HR must grant the `manager` role to anyone who manages people.
- **`page[number]` max 10000** (422 above it). The spec's `PageNumber` schema has no `maximum`, so it needs `maximum: 10000` added (architect).
- **Free-text rule:** `PlainText` on all free-text fields. Consider documenting it in API_GUIDELINES (validation section).

## Bugs / open issues
- `LeaveRulesEdgeTest::test_manager_sees_and_decides_direct_reports_only` (line ~274) still accepts `[403, 404]` and has a stale "see known-bug test" comment. It could be tightened to 404 (QA).
- Feature tests share the `lms_test` DB, so concurrent agent runs break each other.

## Next steps
- Architect: number the bug-5 decision, add `maximum: 10000` to `PageNumber` in openapi.yaml.
- QA: re-verify bugs 3, 4, 5, 6, 8.
