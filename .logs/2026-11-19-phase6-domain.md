# 2026-11-19 — domain — phase 6: leave core (submit/approve/reject/cancel, balances, web + API)

## Goal
BUILD_PLAN phase 6: `WorkingDayCalculator`, `LeaveBalanceService`, `ApproverResolver`, the four leave Actions, `LeaveRequestPolicy`, `/api/v1` leave endpoints per openapi.yaml, minimal accessible Blade UI, plus phase 3–5 leftovers (`EnsureUserIsActive`, provisioner `Role`/`last_login_at`/`GrantInitialEntitlement`, `/holidays`). HR admin screens, reports, notifications and accrual stay in phase 8.

## What I did (files)
- Services `app/Services/`: `WorkingDayCalculator` (pure static `count()` + `forRange()` reading `work.weekend_days` via `Settings` and holidays only when `Modules::enabled(Holidays)`), `LeaveBalanceService` (`balance/pending/available/summary`, per `LeaveYear`), `ApproverResolver` (`approversFor`, `canDecide`), `AccrualCalculator` (`prorate/clipGrant/floorToHalf`), `LeaveRequestQuery` (visibility, inbox, filters/sort/paginate).
- Actions `app/Actions/Leave/`: `SubmitLeaveRequest` (locks requester row; V1–V8 in USE_CASES order; catches SQLSTATE `23P01` → 422 `LEAVE_OVERLAP`), `ApproveLeaveRequest` (re-checks V6 + V7 excluding itself, `debit`), `RejectLeaveRequest` (`COMMENT_REQUIRED`), `CancelLeaveRequest` (`reversal` of the debit for approved), `LeaveTransition` (shared `lockForUpdate` + `canTransitionTo` → 409 + action row). `app/Actions/Accrual/GrantInitialEntitlement.php` (minimal prorated version, needed for balances).
- `app/Policies/LeaveRequestPolicy.php` (`view` 404-deny, `create`, `decide`, `cancel`, `viewApprovals`).
- HTTP: `app/Http/Middleware/EnsureUserIsActive.php`; Form Requests `StoreLeaveRequestRequest`, `ApproveLeaveRequestRequest`, `RejectLeaveRequestRequest`, `CancelLeaveRequestRequest`, `ListLeaveRequestsRequest`; Resources `UserResource`, `LeaveRequestResource` (envelope + page helpers), `LeaveTypeResource`, `HolidayResource`, `BalanceResource`; API controllers `Api/V1/{Me,LeaveRequest,Approval,LeaveType,Holiday}Controller`; web controllers `Web/{Dashboard,LeaveRequest,Approval,Holiday}Controller`.
- Routes: `routes/api.php` (stub `/me` replaced; `/me`, `/me/balances`, `/me/leave-requests`, `/leave-types`, `/holidays` (module), `/leave-requests` GET/POST (`idempotent`)/show/approve/reject/cancel, `/approvals`; group now `module:api, auth:keycloak-jwt, active, throttle:api`). `routes/web.php` (`/` dashboard or landing, `/leave-requests*`, `/approvals`, `/holidays` module-gated; `auth` + `active`).
- Views: `dashboard`, `leave-requests/{index,create,show,_status,_pager}`, `approvals/index`, `holidays/index`; layout nav (Apply, My requests, `@can` Approvals), flash + error summary (`role=status`/`alert`); `public/css/app.css` additions (plain CSS, no Tailwind in repo).
- Exceptions: `RendersBackOnWeb` trait used by `LeaveRuleViolation` + `InvalidTransition` (web → back with errors; API unchanged).
- **Shared/platform files (minimal, additive):** `bootstrap/app.php` alias `active`; `app/Auth/KeycloakUserProvisioner.php`: `Role::fromKeycloakRoles()` instead of the string list (removed `BASE_ROLES`/`ADMIN_ROLE`/`baseRole()`, no other callers), `last_login_at` (throttled 5 min), optional `date_of_joining` claim → `GrantInitialEntitlement`.
- Tests: `tests/Unit/LeaveMathTest.php`; `tests/Feature/Leave/{LeaveTestHelpers,LeaveApiTest,LeaveWebTest}.php` (API responses validated with `ValidatesOpenApi`; note: pass the **spec template path** `/leave-requests/{leaveRequestId}/approve`, not the concrete URI).

## Commands run + results
- `task test` → **110 passed (803 assertions)** (82 before). `task lint` (pint --test) → PASS 145 files.
- Live stack (`docker compose up -d --wait`, `migrate:fresh --seed` + `DemoSeeder`): direct-grant tokens (`lms-dev-cli`, alice/bob) → `/me` real `UserResource` (ULIDs, `last_login_at` set); EL balance 30/0/0/30; alice `POST /leave-requests` 21–25 Dec → 201, `days: 4` (25 Dec holiday), `Location`, rate-limit headers; alice approve own → 403; bob `/approvals` shows it; bob approve → `approved`, second approve → 409 `INVALID_TRANSITION` problem+json; alice EL → entitled 30, used 4, available 26.
- Web (curl through real Keycloak login form): alice dashboard → apply CL 1–2 Dec (2 days, flash) → overlapping apply returns to the form with the overlap error → bob inbox → approve (flash) → alice CL 10/2/0/8 → cancel → 10/0/0/10; `/holidays` lists Christmas; alice `/approvals` → 403.
- Dev DB reset to fresh + DemoSeeder, `docker compose down`.

## Decisions
- D-42 (error-code placement, policy vs state machine, helper classes, web rendering of rule errors, provisioner `last_login_at` throttle + DOJ claim, `/me` 200, notifications deferred).

## Bugs found / open issues
- Domain exceptions (409/422) are logged at ERROR by the default reporter (seen live for `InvalidTransition`). Platform may add `DomainException` to `dontReport` in `bootstrap/app.php`.
- Scoped `Settings`/`Modules` memo persists across requests **inside one test** (no per-request flush outside Octane); tests that change a setting between requests must call `$this->app->forgetScopedInstances()` (helper `setting()` in `LeaveTestHelpers`). Production is unaffected (Octane flushes scoped, classic mode boots per request).
- Not built (phase 8): notifications, `/me/ledger-entries`, `/users/*`, leave type/holiday CRUD, team view UC-M4 beyond `GET /leave-requests` (manager sees reports' requests; per-report balances need `/users/{id}/balances`), auto-cancel on deactivation (`DeactivateUser`), year-start accrual.
- `ApproverResolver::canDecide` lets the direct manager decide regardless of their base role (a report's manager with role `employee` could approve via the web; the API x-roles say manager/hr_admin). Matches USE_CASES §3.6 wording ("resolved manager"); revisit if the architect wants a role check.
- Live run used `app` scale 1 (compose default); concurrency covered by row locks + EXCLUDE backstop test, not by a multi-replica race.

## Next steps
- QA (phase 7): deepen coverage for the finished use cases below (concurrency on approve/cancel, every 4xx shape per endpoint, pagination links, `include` variants, web a11y).
- Domain (phase 8): notifications after commit, HR CRUD, `DeactivateUser` cancelling pending requests via `CancelLeaveRequest`, `GrantInitialEntitlement` from HR edits, year-start job using `AccrualCalculator`.
