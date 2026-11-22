# 2026-11-22 — phase 19 (architect): promote Later use cases

## Goal
Promote UC-E10–E14, UC-M5–M7 and UC-H10–H15 from Later to MVP. Docs only. No application code. No commit. Do not edit `.logs/MEMORY.md`.

## What I did (files)
- `docs/USE_CASES.md`: those use cases are MVP. Day counting, the approver set, accrual, and the module table match the opt-in rules. New §3.13–§3.22 are the contract domain and frontend are implementing.
- `docs/SYSTEM_DESIGN.md`: ERD, constraints, enums, commands, schedule (00:15 monthly, 09:00 escalation), HTTP table, module registry (`multi_level`, `delegation` removed), env `LEAVE_HR_APPROVAL_DAYS` and `LEAVE_ESCALATION_DAYS`. Composer dep `barryvdh/laravel-dompdf` (D-58).
- `docs/api/openapi.yaml`: the paths below. No other new paths. `hours`, `sandwich`, `accrual_frequency`, `location`, `avatar_url`, `leave_quotas`, ledger kind `encashment`, module key `multi_level`. Problem code `LOCATION_NOT_EMPTY`.
- `docs/API_GUIDELINES.md`: day-amount rule and `LOCATION_NOT_EMPTY`.
- `docs/UI_GUIDELINES.md`: Team Time Off is `GET /team-calendar` for any role.
- Diagrams that would have stayed wrong: class enums and calculator/resolver signatures, apply and approve and accrual activities, approve sequence, state table, object snapshot, data-flow store D10 and the ledger note, scheduler idempotency row. SVGs re-rendered for the changed charts. Unchanged charts were restored so a newer mermaid-cli image did not rewrite them.
- `.logs/BUILD_PLAN.md`: phase 19 row, domain + frontend, status `in progress`.
- `.logs/decisions.md`: D-53, D-54, D-55, D-56, D-58. D-04, D-08, D-13 and D-19 only gained a "Superseded by" mark. D-52 and D-57 (welcome-page sign-in, written by a parallel run) were left as they are.

Not edited: PHP, `web/**`, `.logs/MEMORY.md`.

## Commands run + results
- `docker run --rm redocly/cli lint docs/api/openapi.yaml`: valid. The only warnings are the two accepted ones on `/healthz` and `/readyz` (`operation-4xx-response`).
- `docker run --rm minlag/mermaid-cli` on `docs/SYSTEM_DESIGN.md` (3 charts, check SVGs not kept) and on diagrams 02, 03, 04, 05, 06, 07. All exit 0. No `Syntax error` in the SVGs. The first ERD render failed on `FK UK` in one attribute; that attribute is now `UK` only and the re-render passed.

## Decisions
- **D-53** supersedes the yearly-only sentence of D-04. `yearly` stays the default. Monthly is per leave type. D-26 is unchanged.
- **D-54** supersedes D-08 only when a delegation covers today or module `multi_level` applies. Otherwise the old approver rule stands. Module key is `multi_level` (not `multi_level_approval`). Delegation is core, not a module.
- **D-55** supersedes D-13 only when a department quota row exists.
- **D-56** supersedes D-19 only when `sandwich` is true. Default remains false.
- **D-58** allows `barryvdh/laravel-dompdf` and records attachments, avatars, hourly `days`, the team calendar, escalation, encashment, locations, and the HR audit log. D-57 was already the welcome-page sign-in decision, so this bundle is D-58.

## Bugs found / open issues
None in the docs. A parallel run added D-52 and D-57 for welcome-page password grant and edited the login sentences in USE_CASES and SYSTEM_DESIGN. This run did not redo that decision.

## Next steps
- Domain implements USE_CASES §3.13–§3.22 and the OpenAPI paths. Platform adds `LEAVE_HR_APPROVAL_DAYS=5` and `LEAVE_ESCALATION_DAYS=3` to `.env.example` and `config/lms.php` is domain-owned.
- Frontend calls only those paths. Team Time Off uses `GET /team-calendar`.
- Orchestrator indexes this log in `.logs/MEMORY.md`. This run did not edit that file.

Follow-up: `LeaveRequestReportRow.days` now uses `RequestDays` (multiples of 0.125); `LeaveRequestPreview.available_before` and `available_after` use new `BalanceAmount` (minimum 0, no `multipleOf`) because a 2-hour debit yields eighths such as 29.75, a preview that uses the whole balance yields 0, and a monthly grant or encashment yields up to 3 decimal places, so neither `Days` nor `RequestDays` fits. `Balance` and `BalanceReportRow` amounts were already plain numbers and already allow those values. Quotas, caps, and adjustments stay multiples of 0.5. Redocly lint is valid with the two accepted warnings on `/healthz` and `/readyz`.
