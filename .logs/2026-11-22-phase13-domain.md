# 2026-11-22 — phase 13 (domain): HR admin API

## Goal
Finish BUILD_PLAN phase 13 and prove it. HR admin API only (no pages): departments, leave types, holidays, users' LMS fields + deactivate/activate, balance adjustments, reports JSON/CSV, notification deep links (`config('app.url')` + Next paths), year-start accrual command + schedule. Match `docs/api/openapi.yaml`. Use cases UC-H1, UC-H2, UC-H3, UC-H4, UC-H5, UC-H7, UC-H8, UC-H9, UC-S1, UC-S2. Out of scope: UC-H16, branding, modules, `/admin/users`.

## What I did (files)
Audited the phase-13 code already in `334a81e` (routes, controllers, policies, Form Requests, resources, actions, notifications, `lms:accrue-year` + schedule) against the OpenAPI operations and USE_CASES §3.6 / §3.7. Happy paths already matched: `public_id` as `id`, envelopes, hr_admin writes via policies, `module:holidays` / `module:reports` + `role:hr_admin`, pagination, `Accept: text/csv`, `DEPARTMENT_NOT_EMPTY`, `ROLE_READ_ONLY`, append-only `adjustment` rows, prorated grant on the first `date_of_joining`, idempotent accrual (`insertOrIgnore` + yearly unique index), mail URLs built in `LeaveRequestNotification` (decided/cancelled → `/leave-requests/{public_id}`, submitted → `/approvals`).

One code fix:

- `app/Http/Controllers/Api/V1/LedgerEntryController.php`: `GET /me/ledger-entries` and `GET /users/{id}/ledger-entries` ordered `id` descending (newest first). `LedgerEntryList` says oldest first. Now `orderBy('id')`.

Tests (new):

- `tests/Feature/Hr/HrAdminApiTest.php` — 8 tests, `ValidatesOpenApi::assertMatchesSpec` (same helper as the other feature tests). CSV is buffered in the test only so the validator can read a `StreamedResponse` body; the controller still streams.
  - UC-H1 department create/list/show/update/delete, then delete of a department that has a user → 409 `DEPARTMENT_NOT_EMPTY`, `users_count` 1.
  - UC-H3 leave type create; `code` on PATCH → 422 `VALIDATION_FAILED` and the code is unchanged; name update keeps the code.
  - UC-H4 holiday create/list/show/update/delete; `holidays` module off → 404 `NOT_FOUND` on list and create.
  - UC-H2 patch department, manager, joining date, employee code, designation, employment type; first joining date writes the prorated CL grant; `role` → 422 `ROLE_READ_ONLY`; deactivate then activate.
  - UC-H5 `adjustment` ledger row (201, Location = the user's ledger); list is oldest-first (grant, then the new adjustment).
  - UC-H7 / UC-H8 JSON + `Accept: text/csv` for `hr_admin`; employee → 403 `FORBIDDEN`.
  - UC-H9 / UC-S1 `lms:accrue-year 2027` is scheduled (`10 0 1 {LEAVE_YEAR_START_MONTH} *`, `onOneServer`, `withoutOverlapping`); re-run writes 0 rows.
  - UC-S2 approve mail: action URL is `config('app.url')` + `/leave-requests/{public_id}` in both HTML and text views.

Not touched: `web/**`, Dockerfile, compose, CI, `docs/**`, `.env`, `.logs/MEMORY.md`, `.logs/BUILD_PLAN.md`, `.logs/decisions.md`. Other agents' uncommitted web/docs edits were left as they were.

## Commands run + results
- `docker compose run --rm app php artisan test tests/Feature/Hr/HrAdminApiTest.php` → 8 passed (150 assertions).
- `docker compose run --rm app vendor/bin/pint` → PASS, 179 files.
- `docker compose run --rm app vendor/bin/pint --test` → PASS, 179 files.
- `docker compose run --rm app php artisan test` → 180 passed (1861 assertions), 19.91s.
- `KEYCLOAK_CLIENT_SECRET` was already set in the local `.env` (from the example placeholder). Not printed. Not committed.

## Decisions
No new ADR. The ledger sort change follows the existing `LedgerEntryList` description ("oldest first"). It does not change a decided design.

## Bugs found / open issues
- Fixed: ledger lists were newest-first; spec says oldest first (`LedgerEntryController` list, shared by `/me/ledger-entries` and `/users/{id}/ledger-entries`).
- Spec gap, not changed (domain does not edit `docs/**`): `GET /leave-types` validates `filter` and returns 422 `VALIDATION_FAILED` for a bad filter (API_GUIDELINES §3). `listLeaveTypes` documents 200/401/404/429 only. Already called out in `tests/Feature/Api/LeaveApiContractTest`. Happy path (no bad filter) matches the spec.
- Accrual happy path proves a 2027 grant is written and a re-run adds no rows. It does not assert the CL-lapse / SL-carry-all / EL-`max_balance` clip. That maths is in `RunYearStartAccrual` + `AccrualCalculator` and matches USE_CASES §3.7 on audit; the full check is QA scenario 9.
- Not in this phase, left alone: UC-H16 working week, branding PATCH/upload, module admin, `/admin/users`.

## Next steps
Finished for QA (phase 17) and frontend (phase 14): **UC-H1, UC-H2, UC-H3, UC-H4, UC-H5, UC-H7, UC-H8, UC-H9, UC-S1, UC-S2**.

QA still owns the edge matrix (406 on reports, manager cycle, self-deactivate, adjustment below zero, `reports` module off, accrual CL/SL/EL amounts, and the rest of AGENTS §6). Frontend can build the HR screens on these `/api/v1` routes; ids in bodies are ULIDs.
