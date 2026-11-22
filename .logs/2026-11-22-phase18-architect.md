# 2026-11-22 — phase 18 (architect): UC-S4 daily pending-approval reminder

## Goal
Promote UC-S4 from Later to MVP and write the behaviour so domain can implement it without inventing rules. Docs only. No application code. No commit.

## What I did (files)
- `docs/USE_CASES.md`: UC-S4 scope is MVP. `notifications` module row includes the reminder. New §3.12: 08:00 schedule, recipients, inbox visibility, idempotency, mail copy, classes, minimum feature test.
- `docs/SYSTEM_DESIGN.md`: `approval_reminders` on the ERD and in the constraint table. Component rows for `ApprovalReminder`, `SendPendingApprovalReminders`, `RemindPendingApprovals`, `PendingApprovalsReminder`. New §5.1 scheduled tasks (accrual 00:10 and reminder 08:00).
- `.logs/BUILD_PLAN.md`: phase 18 row, domain, status `not started` in this file (domain's own run log says the code is already written).
- Diagrams kept in sync: `docs/diagrams/01-system-design.md` (scheduler idempotency), `02-data-flow.md` (P12, D15), `03-class-diagram.md` (model, command, action, notification, `LeaveRequestQuery::approvableBy`), `05-activity-diagrams.md` §8, `06-sequence-diagrams.md` §7a, `docs/diagrams/README.md`. SVGs re-rendered for the blocks whose source changed. Unchanged activity SVGs 1–7 and class-diagram 3 were restored so a newer mermaid-cli image did not rewrite them.
- No OpenAPI change. No new module key. No env var. No admin setting.

Not edited: PHP (`routes/console.php` and the new action/notification/migration were written by the parallel domain run, not by this one), `.logs/MEMORY.md`, `.logs/decisions.md`.

## Commands run + results
- `docker run --rm minlag/mermaid-cli` on `docs/diagrams/02-data-flow.md` (3 charts), `03-class-diagram.md` (4), `05-activity-diagrams.md` (8), `06-sequence-diagrams.md` (12), and `docs/SYSTEM_DESIGN.md` (3). All exit 0. No `Syntax error` in the SVGs. Generated `.md` copies and the SYSTEM_DESIGN check SVGs were deleted.
- Redocly was not run. `docs/api/openapi.yaml` was not changed.

## Decisions
No ADR. This follows existing decisions rather than contradicting them:
- D-24 already requires `onOneServer()` + `withoutOverlapping()` on every scheduled task. The Redis lock is concurrency only.
- D-26 fixes the accrual clock time (00:10) in the schedule and leaves the month in env. 08:00 is the same kind of fixed clock time. It is not a setting and not an env var.
- D-17 / the `notifications` module already mean "do not dispatch mail when the module is off".
- D-43 deep links are `config('app.url')` + the Next path. The path is `/approvals`, matching `LeaveRequestSubmitted`.

`.logs/decisions.md` was not edited.

## Bugs found / open issues
None in the docs. A parallel domain run (`2026-11-22-phase18-domain.md`) reports it aligned to §3.12 after dropping an earlier `pending_count` column, a custom mail view, and `->timezone()`. This architect run did not re-test that code.

## Next steps
- Domain implements §3.12 if anything in that run still disagrees with it. The contract is the doc, including: no second visibility rule besides `LeaveRequestQuery::approvableBy`, no row when the count is 0, no row when `notifications` is off, count frozen at queue time, mail views `mail.leave-request` / `mail.leave-request-text`.
- QA can extend the happy-path test. Minimum is already named in §3.12.
- Orchestrator indexes this log in `.logs/MEMORY.md`. This run did not edit that file.
