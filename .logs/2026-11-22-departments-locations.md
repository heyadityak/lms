# 2026-11-22 — departments and locations

## Goal
Find why `/admin/departments` and `/admin/locations` fail, and fix it. Do not touch audit-log files. Do not commit.

## What I did
No product code change. Both pages 500'd because `2026_11_22_190000_phase19_leave_extensions` (and the earlier `approval_reminders` migration) had not been applied to the running `lms` database.

- Departments: `DepartmentController::index` eager-loads `leaveQuotas` (`app/Http/Controllers/Api/V1/DepartmentController.php:24`). Postgres: `relation "department_leave_quotas" does not exist`. `GET /api/v1/departments` → 500.
- Locations: `LocationController::index` reads `locations` (`app/Http/Controllers/Api/V1/LocationController.php:24`). The table did not exist. `GET /api/v1/locations` → 500.
- UI already matches the contract: public `id`, `{data, meta, links}`, `location_id` on writes, `leave_quotas` on the department resource. `web/src/app/(app)/admin/departments/page.tsx:9` and `web/src/app/(app)/admin/locations/page.tsx:9` only gate `hr_admin`. `useDepartments` / `useLocations` in `web/src/lib/queries.ts:34` and `:41` read `.data`.

Ran `php artisan migrate --force`. Both migrations succeeded.

## Commands run + results
- `php artisan migrate:status` — phase 19 and approval reminders Pending.
- `php artisan migrate --force` — both Done.
- Carol token through nginx: `GET /api/v1/departments` 200, two rows (`HR`, `IT`), `leave_quotas: []`, envelope `data`/`meta`/`links`. `GET /api/v1/locations` 200, `data: []`.
- `php artisan test --filter='test_department_crud_and_delete_blocked_when_users_exist|test_department_quota_is_what_the_next_grant_uses|test_location_holiday_applies_only_to_that_location'` — 3 passed (49 assertions).
- Browser as carol: departments lists HR (1) and IT (3). Locations empty state, then add HQ / Headquarters and delete it. Empty again.

## Decisions
None.

## Bugs found / open issues
None left on these two pages. Audit log was out of scope.

## Next steps
None for this bug.
