# 2026-11-19 — phase 11 (domain): leave preview API

## Goal
BUILD_PLAN phase 11: `POST /api/v1/leave-requests/preview` (`previewLeaveRequest`, schema `LeaveRequestPreview`) per D-43 and `docs/api/openapi.yaml`: same body and V1–V9 as `POST /leave-requests`, returns `{days, leave_year, tracks_balance, available_before, available_after}`, writes nothing, no locks, no notification, ignores `Idempotency-Key`.

## What I did (files)
- `app/Services/LeaveRequestValidator.php` (new): V1–V8 moved verbatim out of `SubmitLeaveRequest::handle()`; `validate(User, array): array{type, start, end, part, days, year, available}` (`available` null when the type does not track balance) plus the static `assertNoOverlap()` (V6) moved from `SubmitLeaveRequest`. Read-only, no transaction, no lock.
- `app/Actions/Leave/SubmitLeaveRequest.php`: now takes only `LeaveRequestValidator`; keeps its transaction + `users` row lock, calls `validate()` inside it, then inserts the request and the `submitted` action. 23P01 backstop unchanged.
- `app/Actions/Leave/ApproveLeaveRequest.php`: `SubmitLeaveRequest::assertNoOverlap` → `LeaveRequestValidator::assertNoOverlap` (only caller).
- `app/Http/Controllers/Api/V1/LeaveRequestController.php`: `preview(StoreLeaveRequestRequest, LeaveRequestValidator)`, 200 `{data, meta: {}, links: {}}`. Reuses the store Form Request, so auth (`create` policy) and V9 shapes are identical.
- `routes/api.php`: `POST /leave-requests/preview` (`api.v1.leave-requests.preview`) inside the v1 group (`module:api`, `auth:keycloak-jwt`, `active`, `throttle:api`), no `idempotent`, registered before the `{leaveRequest}` routes.
- `app/Http/Requests/StoreLeaveRequestRequest.php`: doc comment only.
- `tests/Feature/Leave/LeavePreviewTest.php` (new, 7 tests): happy path (EL + LWP null balances) spec-validated via `assertMatchesSpec($r, '/leave-requests/preview', 'post')`; pending reduces `available_before`; every rule failure (BACKDATE_LIMIT_EXCEEDED, INVALID_DATE_RANGE ×2, HALF_DAY_NOT_ALLOWED ×2, NO_WORKING_DAYS, INSUFFICIENT_BALANCE, NO_APPROVER, LEAVE_OVERLAP, VALIDATION_FAILED, LEAVE_TYPE_INACTIVE) spec-validated; no rows written in requests/actions/ledger, same `Idempotency-Key` with a different body → plain 200 (no replay, no 409); weekend/holiday/half-day/module-off/custom-weekend counts; `lms.leave_year_start_month=4`; preview-vs-submit agreement over 10 date ranges (successes compare days + leave_year, failures compare code).

## Commands run + results
- `task test -- --filter=LeavePreviewTest` → 7 passed (101 assertions).
- `task test` → 172 passed (1709 assertions), 0 known-bug.
- `task lint` → pass (146 files). Later re-check after a comment edit with `docker run --rm -v $PWD:/app lms-app-dev vendor/bin/pint --test app tests` → pass, because by then `docker compose` failed to interpolate: phase 10 made `KEYCLOAK_CLIENT_SECRET` required in `compose.yaml` and the local `.env` lacks it (platform's concern; `task test`/`task lint` will fail the same way until `.env` has it).
- Live through nginx (`localhost:8080`), alice token via `lms-dev-cli` password grant: the running Octane workers still had the old routes (405); reloaded them with FrankenPHP's admin API inside the container (`POST localhost:2019/frankenphp/workers/restart`; `octane:reload` says "not running"). Then EL 21–25 Dec with `Idempotency-Key` → 200 `{"days":4,"leave_year":2026,"tracks_balance":true,"available_before":30,"available_after":26}` + `X-RateLimit-*`; 26–27 Dec → 422 `NO_WORKING_DAYS` problem+json. Row counts requests/actions/ledger `0|0|12` before and after.

## Decisions (proposed, for the orchestrator to record)
- **D-NN (proposed) — `LeaveRequestValidator` service.** The V1–V8 rule checks live in `App\Services\LeaveRequestValidator::validate()` (+ static `assertNoOverlap`), shared by `SubmitLeaveRequest` (inside its lock) and the preview endpoint, so Actions keep a single public `handle()` (class-diagram §3 convention). Preview reuses `StoreLeaveRequestRequest`. `docs/diagrams/03-class-diagram.md` should add the class (`SubmitLeaveRequest --> LeaveRequestValidator`, validator → WorkingDayCalculator/LeaveBalanceService/ApproverResolver/LeaveYear, `ApproveLeaveRequest ..> LeaveRequestValidator` for V6) and `LeaveRequestController::preview()`; architect-owned, not edited.

## Bugs found / open issues
- Preview is advisory: two previews can both pass and the second submit fail; by design (D-43).
- Phase 10 in progress: `compose.yaml` now needs `KEYCLOAK_CLIENT_SECRET` in `.env`, which breaks `docker compose run` (and so `task test`) locally until it is set.
- Dev Octane workers don't pick up route changes without a worker restart (no `--watch`); `octane:reload` cannot find the server state. Platform may want to note the admin-API restart or enable watch in dev.

## Next steps
- QA (phase 17): finished use case **UC-E5** (preview part, `previewLeaveRequest`); submit behaviour unchanged (refactor only).
- Frontend (phase 12): endpoint is live at `POST /api/v1/leave-requests/preview`, body = `LeaveRequestCreate`.
- Architect: class-diagram update above.
