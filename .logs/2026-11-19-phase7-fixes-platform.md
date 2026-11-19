# 2026-11-19 — platform — phase 7 QA fixes (bugs 1, 2, 7)

## Goal
Fix the platform-owned bugs from `.logs/2026-11-19-phase7-qa.md` "Bugs found" (1, 2, 7) and un-tag their `known-bug` tests (assertions unchanged).

## What I did (files)
- `app/Exceptions/ProblemJsonRenderer.php` — **Bug 1:** every 404 (`HttpExceptionInterface`) now gets the fixed `detail` "The requested resource was not found.". Missing ids, hidden ids (`denyAsNotFound`), disabled modules and unknown routes give byte-identical bodies, and no `No query results for model [App\Models\...]` appears.
- `app/Auth/KeycloakUserProvisioner.php`
  - **Bug 2 (root cause, shared by API guard and web callback):** after the sub/email lookup, an existing inactive user is returned untouched, before any `forceFill`. So there is no `last_login_at`, no `date_of_joining`, no relink, and no `GrantInitialEntitlement`. Callers still refuse it: the guard gives 401 and the web callback's `abort_unless(is_active, 403)` is unchanged. The order is now "check before write" on both paths without editing `KeycloakJwtGuard` or `KeycloakLoginController`. The class docblock is updated.
  - **Bug 7:** `dateClaim()` uses `^\d{4}-\d{2}-\d{2}\z` and requires a round-trip through `createFromFormat('!Y-m-d')`, which rejects overflow like `2026-02-30`. A present but invalid claim is ignored and logs `auth.date_of_joining_claim_ignored` (warning). A missing claim logs nothing.
- `tests/Feature/Api/LeaveApiContractTest.php` — removed `#[Group('known-bug')]` from `test_404_body_does_not_reveal_whether_an_id_exists_or_internal_class_names`.
- `tests/Feature/Auth/JwtClaimProbeTest.php` — removed `#[Group('known-bug')]` from `test_joining_date_claim_with_trailing_newline_is_ignored_not_a_500` and `test_deactivated_users_token_has_no_provisioning_side_effects`, and dropped the now-unused `Group` import (pint `no_unused_imports`).

## Commands run + results
- `docker compose run --rm -T app php -r ...`: Carbon `createFromFormat('!Y-m-d')` overflows (`2026-02-30` → `2026-03-02`) and does not throw once the regex has passed, so the round-trip check catches it.
- `docker compose run --rm -T app php artisan test tests/Feature/Auth tests/Feature/Api` → 96 passed.
- `task test` (final run, with no other test container active) → **172 passed (1579 assertions)**, "Excluded known-bug tests: 0" (the domain agent removed its 5 tags as well). Earlier runs showed random failures (including `QueryException` in `ApiDocsTest`) because the domain agent was running tests on the same Postgres test DB at the same time. Those failures were not reproducible on an uncontended run.
- `task lint` → PASS (152 files).
- `task test:known-bugs` → "No tests found" (none of my 3 tests is left in the group).
- Excluded known-bug count: 8 → 5 after my edits (my delta: −3).

## Decisions
- No new D-NN. Fixes follow API_GUIDELINES §5 and USE_CASES §3.9 as written. The architect owns `decisions.md` this round.
- The inactive check lives in the provisioner (the single writer) and not in each caller. That one change fixes both entry points, and the web callback (to be removed, D-43) needed no edit.

## Bugs found / open issues
- An inactive user with no LMS base role still gets 403 (the role check runs first) rather than 401. This is unchanged behaviour and writes nothing.

## Next steps
- Architect: optionally document the fixed 404 `detail` text in API_GUIDELINES §5.
