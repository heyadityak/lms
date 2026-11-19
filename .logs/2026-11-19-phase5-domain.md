# 2026-11-19 — domain — phase 5: schema + settings/modules/branding foundation

## Goal
BUILD_PLAN phase 5: every ERD table (migrations, Postgres constraints), models/enums/factories, reference + demo seeders, `Settings`/`Modules`/branding foundation (`module:` via `Modules`, `@module`, `BrandingComposer`, base layout). No leave workflows (phase 6), no admin UIs (8–9).

## What I did (files)
- Migrations `database/migrations/2026_11_19_1000{00..08}_*`: `departments`; `add_hr_columns_to_users_table` (public_id ULID backfilled → NOT NULL UNIQUE, department_id RESTRICT, manager_id SET NULL, employee_code UNIQUE, designation, employment_type, date_of_joining, last_login_at timestamptz, `CHECK (manager_id <> id)`, drops email_verified_at/remember_token); `leave_types` (+ amounts CHECK); `holidays` (UNIQUE date); `leave_requests` (CHECKs dates/days/day_part/half-day/status, indexes `(user_id,status)` `(status,start_date)`, `btree_gist` + `leave_requests_no_overlap` EXCLUDE for pending/approved); `leave_request_actions` (cascade, `(leave_request_id,created_at)`); `leave_ledger_entries` (kind/days CHECKs, `(user_id,leave_type_id,year)`, partial UNIQUE yearly kinds, partial UNIQUE `(leave_request_id,kind)`); `settings` (key PK, jsonb); `user_admin_audits` (action/outcome CHECKs). Phase-3 users migration untouched.
- Enums `app/Enums/{Role,LeaveStatus,DayPart,LedgerKind,LeaveAction,Module}.php` (`Role::fromKeycloakRoles/isBase/rank`, `LeaveStatus::canTransitionTo/isOpen`, `DayPart::isHalf`, `LedgerKind::isYearly`).
- Models: `User` (role → `Role` cast, HR fillables, relations department/manager/reports/leaveRequests/ledgerEntries), `Department`, `LeaveType`, `Holiday`, `LeaveRequest` (`isTaken()`, `leaveYear()`), `LeaveRequestAction`, `LeaveLedgerEntry`, `Setting`, `UserAdminAudit`; concerns `Models/Concerns/HasPublicId.php` (uppercase ULID, route key) and `AppendOnly.php`.
- Factories for every model; states: User `manager/hrAdmin/admin/inactive/unlinked/managedBy/inDepartment`; LeaveType `untracked/inactive/fullDayOnly`; LeaveRequest `between/halfDay/approved/rejected/cancelled`; LedgerEntry `grant/debitFor/adjustment`; Audit `failed`.
- Seeders: `LeaveTypeSeeder` (CL/SL/EL/LWP, SL backdate from `LMS_SL_MAX_BACKDATE_DAYS`=7), `DatabaseSeeder` → LeaveTypeSeeder only, `DemoSeeder` (local/testing only).
- Support: `app/Support/Settings.php` (scoped; `get/set/forget/default`, Redis `settings:all`, bust after commit), `Modules.php` (scoped; `enabled/licensed/all/resolve`), `LeaveYear.php` (`of/start/end/current`, D-26), `BrandColor.php`, `Brand.php` (DTO + `fromSettings`).
- Config: new `config/lms.php` (weekend_days, leave_year_start_month, sl_max_backdate_days), `config/branding.php` (name, colours, disk); `config/modules.php` reviewed (matches §8.3), header comment only.
- `EnsureModuleEnabled`: condition → `app(Modules::class)->enabled(Module::from($module))`; alias + priority entry unchanged.
- `AppServiceProvider` (**shared, additive**): `scoped(Settings)`, `scoped(Modules)`, `Blade::if('module')`, `View::composer('*', BrandingComposer)`.
- Views: `resources/views/layouts/app.blade.php` (CSS vars, favicon, skip link, nav `aria-label="Main"`, `@module('holidays')` link, login/logout), `welcome.blade.php` now extends it; `public/css/app.css`; `public/img/default-logo.png` (placeholder); `app/View/Composers/BrandingComposer.php`.
- Tests: `tests/Feature/Domain/SchemaTest.php` (seeders, EXCLUDE, CHECKs, ledger/audit immutability, yearly unique), `tests/Feature/WhiteLabel/SettingsModulesBrandingTest.php` (override + cache bust, modules licensed∩available∖disabled, `module:api` 404 before auth, `@module`, CSS vars, tampered colour fallback, scoped bindings), `tests/Feature/WhiteLabel/OctaneSettingsIsolationTest.php` (real Octane `FakeWorker`, two requests on one worker), `tests/Unit/WhiteLabelAndEnumsTest.php`.
- **QA-owned test edits (needed by this change, minimal):** `KeycloakLoginTest` 2 asserts compare `Role` enum instead of strings (role is now enum-cast); `ApiDocsTest` + `ProblemJsonTest` gained `RefreshDatabase` because `module:` now reads `settings` (they failed on a freshly wiped test DB, order-dependent).

## Commands run + results
- `migrate:fresh --seed` → `migrate:rollback` (all) → `migrate` → `db:seed --class=DemoSeeder` ×2 on dev `lms`: OK; 4 users (bob manages alice/dave, carol HR, dave is_admin), SL backdate 7, 5 holidays 2026, 12 ledger rows (idempotent).
- Backfill path: seeded users, `migrate:rollback --step=9`, `migrate` → 4 uppercase ULIDs assigned.
- `task test` → 82 passed (559 assertions), also from a wiped `lms_test`. `task lint` → PASS 108 files.
- Mutations: `Settings` with a static memo → Octane test fails; `singleton()` instead of `scoped()` → scoped-binding test fails (the FakeWorker test alone does not catch request-time singletons, since Octane clones the app per request). Restored.
- Live (`docker compose up -d --wait`, nginx :8080): `/` renders layout with default CSS vars; `Settings::set` via tinker → next requests on all workers show `Acme Live` + `#ffd700`/contrast `#000000`; `forget` → defaults. `/css/app.css` 200, `/img/default-logo.png` 200, `/api/v1/me` 401. Final dev DB: fresh + DemoSeeder. `docker compose down`.

## Decisions
- D-41 (uppercase ULIDs, users migration details, FK/CHECK choices, seeder behaviour, append-only scope, after-commit cache bust, composer skips namespaced views, branding URLs until phase 9).

## Bugs found / open issues
- `KeycloakUserProvisioner` (platform-owned) still uses role strings and does not set `last_login_at` nor call `GrantInitialEntitlement` on a `date_of_joining` claim; phase 6 should switch it to `Role::fromKeycloakRoles()` and stamp `last_login_at`.
- `/api/v1/me` stub still returns the bigint `id`; needs `UserResource` (public_id as id).
- `EnsureUserIsActive` (`active` alias) not built (guard already refuses inactive users); and `idempotent` not yet on `POST /leave-requests`.
- Layout nav only has Dashboard + Holidays (module-gated) and links to `/holidays`, which has no route yet.
- `docs/SYSTEM_DESIGN.md` §12 says `LeaveTypeSeeder` uses `updateOrCreate` and DemoSeeder seeds 7 departments + 2026/2027 holidays: architect may align to D-41.

## Next steps
- Phase 6 (domain): `WorkingDayCalculator` (weekend from `Settings::get('work.weekend_days')`, holidays only if `Modules::enabled(Holidays)`), `LeaveBalanceService`, `ApproverResolver`, Submit/Approve/Reject/Cancel actions (catch SQLSTATE `23P01` → 422 overlap; ledger rows via `LeaveLedgerEntry::create`), policies, web + API routes/resources.
- QA: finished items for phase 7 listed in the hand-off below.
