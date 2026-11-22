# 2026-11-22 — phase 18 (domain): UC-S4 pending-approval reminder

## Goal
UC-S4: a daily reminder to active managers and HR admins who have pending leave requests they can approve. Matched `docs/USE_CASES.md` §3.12 and `docs/SYSTEM_DESIGN.md` §5 / §5.1 (the architect doc landed before this run finished). No new HTTP route, no OpenAPI change.

## What I did (files)
- `lms:remind-pending-approvals` (`RemindPendingApprovals`) calls `App\Actions\Leave\SendPendingApprovalReminders::handle(): PendingApprovalReminderSummary`. Exit code is always `SUCCESS`.
- Schedule in `routes/console.php`: `dailyAt('08:00')->onOneServer()->withoutOverlapping()`. No `->timezone()`; 08:00 is the scheduler default (`APP_TIMEZONE`).
- Recipients: active `manager` and `hr_admin`, ascending `users.id`. Pending set is `LeaveRequestQuery::approvableBy` (the approvals inbox). Count 0 writes nothing. Employees, inactive users, and additive `admin` are not extra recipients.
- Idempotency: table `approval_reminders` (`id`, `user_id` FK restrict, `reminded_on` date, `created_at`). Unique `(user_id, reminded_on)`. One transaction per candidate with count > 0: `insertOrIgnore`, and only if a row was inserted, `notify(new PendingApprovalsReminder($count))` (`ShouldQueue`, `afterCommit()`).
- `notifications` off: the action returns a zero summary immediately (no scan, no rows, no jobs). The command prints that the module is off.
- Mail reuses `mail.leave-request` and `mail.leave-request-text`. Subject `[{brand}] 1 leave request awaiting your decision` (plural for n > 1). Body is the inbox count sentence. Link is `config('app.url')` + `/approvals`. Brand is resolved when the worker renders.
- Happy-path feature test `tests/Feature/Leave/PendingApprovalReminderTest.php`.

Not touched: `web/**`, `.github/**`, `docs/**`, `.logs/MEMORY.md`, `.logs/BUILD_PLAN.md`, `.logs/decisions.md`. Not committed.

## Commands run + results
- `docker compose run --rm app vendor/bin/pint` → PASS, 222 files.
- `docker compose run --rm app vendor/bin/pint --test` → PASS, 222 files.
- `docker compose run --rm app php artisan test --filter=PendingApprovalReminderTest` → 3 passed.
- `docker compose run --rm app php artisan test` → **214 passed** (2179 assertions), 21.81s. No secrets printed.

## Decisions
No new ADR (`decisions.md` was not edited, per the phase brief). Behaviour follows USE_CASES §3.12. The first draft used a `pending_count` column, a custom markdown view, and `->timezone()`; those were removed once §3.12 was in the tree.

## Bugs found / open issues
None.

## Next steps
- QA: UC-S4 is done. Command `lms:remind-pending-approvals`. Idempotency is unique `(user_id, reminded_on)` on `approval_reminders`. Feature test covers one pending request → one mail to the manager and one to HR, a same-day re-run sends nothing, the employee is not mailed, and `notifications` off sends nothing and writes no row.
- Orchestrator: index this file in `.logs/MEMORY.md` (this run was told not to edit it).
