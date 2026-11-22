# 2026-11-22 — audit logs

## Goal
Check whether `GET /api/v1/audit-logs` and `/admin/audit-logs` are broken (UC-H15 / D-58). Fix a product bug if there is one. Do not commit.

## What I did
No product code change.

- List: `routes/api.php` registers `GET /audit-logs`. `AuditLogController::index` paginates with `page[number]` / `page[size]`, newest `id` first, and `AuditLogResource` returns `id`, `actor`, `action`, `subject_type`, `subject_id`, `changes`, `created_at`. `AuditLogPolicy::viewAny` allows `hr_admin` only.
- Writes: `RecordAuditLog::write` from department, leave type, holiday, and location create/update/delete; quota replace; encashment; user LMS update; activate and deactivate.
- Page: `web/src/app/(app)/admin/audit-logs/page.tsx` returns 404 unless `hr_admin`. `AuditLogsView` calls `GET /audit-logs` with `page[number]` and `page[size]` and reads `data`, `actor.name`, and `changes`.
- App logs from before the phase 19 migration: `relation "audit_logs" does not exist` on `select count(*) from audit_logs`. That migration is applied now (`migrate:status` has nothing pending). The table has rows.

## Commands run + results
- `docker compose run --rm app php artisan test --filter=test_hr_can_read_the_audit_log` — **1 passed** (6 assertions). Carol 200 matches the OpenAPI document; Alice 403.
- Carol through nginx: `GET /api/v1/audit-logs` 200, envelope `data`/`meta`/`links`, two rows. `page[number]=1&page[size]=10` keeps `per_page` 10. `page[number]=2&page[size]=1` returns the older row.
- Browser as Carol: `/admin/audit-logs` shows the Audit log heading and both location rows (created, then deleted). Pagination `1–2 of 2`.

## Decisions
None.

## Bugs found / open issues
None in the current code. The earlier 500 was the unmigrated `audit_logs` table, already created when phase 19 was migrated for departments and locations.

## Next steps
None.
