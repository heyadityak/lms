# 2026-11-22 phase 19 domain

## Goal
Implement the phase 19 API: attachments, avatars, leave PDF, team calendar, hourly leave, approval delegation, multi-level approval, stale-approval escalation, department quotas, encashment, sandwich leave, monthly accrual, locations, and the HR audit log.

## What I did
- Migration `database/migrations/2026_11_22_190000_phase19_leave_extensions.php`: locations, user location/avatar, leave-type sandwich and accrual frequency, holiday location (unique on date + location, nulls not distinct), hourly `hours` and eighth-day `days`, `escalated_at`, ledger kind `encashment`, ledger `accrual_month`, one attachment per request, one approval delegation per user, department leave quotas, append-only `audit_logs`.
- Employee: `POST/GET/DELETE /api/v1/leave-requests/{id}/attachments` (one PNG/JPEG/PDF, 5 MB, no SVG, S3 disk), `POST/DELETE /api/v1/me/avatar` and `GET /api/v1/users/{id}/avatar`, `GET /api/v1/leave-requests/{id}/pdf` via `barryvdh/laravel-dompdf`, `GET /api/v1/team-calendar`, optional `hours` 1–8 (`days = hours/8`).
- Manager: `POST/GET/DELETE /api/v1/me/approval-delegations` (one row; delegate replaces the approver while today is inside the window). Module key `multi_level` (`available: true`); when licensed and enabled, `days >= lms.hr_approval_days` goes to HR. `lms:escalate-stale-approvals` daily at 09:00.
- HR: `PUT /api/v1/departments/{id}/leave-quotas`, `POST /api/v1/users/{id}/encashments`, sandwich and monthly accrual (`lms:accrue-month` on the 1st at 00:15), locations CRUD, `GET /api/v1/audit-logs`.
- Composer: `barryvdh/laravel-dompdf` ^3.1. Env examples: `LEAVE_HR_APPROVAL_DAYS`, `LEAVE_ESCALATION_DAYS`.

## Commands and results
- `docker compose run --rm app composer require barryvdh/laravel-dompdf:^3.1` — installed v3.1.2.
- `docker compose run --rm app vendor/bin/pint --test` — pass (268 files).
- `docker compose run --rm app php artisan test` — **230 passed** (2324 assertions).

## Decisions
No ADR written here. The architect is recording the dompdf ADR. Behaviour follows the phase 19 use cases that landed in `docs/USE_CASES.md` while this was in progress (module key `multi_level`, one attachment, one delegation, quota body, escalation when notifications are off).

## Bugs / open issues
- `LeaveRequestPreview.available_before` / `available_after` still reference `Days` (`multipleOf: 0.5`). Hourly leave returns `days` as an eighth (`RequestDays`). A 2-hour preview can report `available_after` of 29.75, which does not match `Days`. `POST /leave-requests` itself validates.
- `LeaveRequestReportRow.days` still references `Days`, so a report row for an hourly request would not match the schema.
- The task brief said monthly accrual at 00:20 and that escalation may still set `escalated_at` when notifications are off. The published use case schedules accrual at 00:15 and writes nothing when notifications are off. This implementation follows the use case.

## Next steps
- QA: UC-E10, UC-E11, UC-E12, UC-E13, UC-E14, UC-M5, UC-M6, UC-M7, UC-H10, UC-H11, UC-H12, UC-H13, UC-H14, UC-H15.
- Architect: preview/report day schemas if hourly eighths should appear there.
