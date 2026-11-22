# 2026-11-22 — phase 15 (domain): admin API

## Goal
BUILD_PLAN phase 15: admin API only, no pages. Match `docs/api/openapi.yaml`.

- UC-H16 working week: `GET`/`PATCH /api/v1/settings/working-week`. ISO weekdays 1–7, unique, at most 6. `null` resets to `LMS_WEEKEND_DAYS`. `hr_admin`. Does not rewrite existing `leave_requests.days` (D-30).
- UC-B1–B6 branding: `PATCH`/`DELETE /settings/branding`, asset upload/delete. Role `admin` only (`hr_admin` → 403). Hex colours, no SVG, size limits from the spec. Served by the existing `/branding/{asset}`.
- UC-B5 modules: list + patch. Unlicensed enable → 422. `api` may be disabled; the BFF bypass stays.
- UC-U1–U6 Keycloak user management under `/admin/users`: guardrails, audit rows, Keycloak-first with compensation, 503 `IDP_UNAVAILABLE` with no local fallback, 422 `IDP_READ_ONLY` for federated profile edits, 409 `IDP_CONFLICT` on duplicates. Role `admin`. Module `user_management` off → 404.

## What I did (files)
Public branding moved from `BrandingController` to `PublicBrandingController` (class diagram). Settings branding, modules, and the working week are new controllers on the authenticated `/api/v1` group.

- Working week: `UpdateWorkingWeek`, `WorkingWeekController`, `UpdateWorkingWeekRequest`. Writes or deletes `work.weekend_days`. Never selects `leave_requests`.
- Branding: `UpdateBranding`, `ResetBranding`, `DeleteBrandingAsset`. Name/colours through `Settings`. Logo/favicon stored on `config('branding.disk')` as `{asset}-{sha1}.{ext}`. SVG (mime, extension, or `<svg` sniff) → 415 `UNSUPPORTED_MEDIA_TYPE`. Logo ≤ 1 MB and 1024×1024; favicon PNG/ICO ≤ 256 KB.
- Modules: `UpdateModules::handle(Module, bool, User)`. Licensed + available only, else 422 `MODULE_NOT_LICENSED`. Disabling `api` is allowed; `EnsureModuleEnabled` still lets `azp` = the BFF client through. `Modules::flush()` drops the per-request memo after the write.
- Identity: `KeycloakAdminClient` + `HttpKeycloakAdminClient` (client-credentials token in cache `keycloak:admin-token`, TTL `expires_in − 30s`). Tests bind `Tests\Fakes\FakeKeycloakAdminClient`. Actions: `CreateUserAction` (no credentials; `execute-actions-email` `UPDATE_PASSWORD` + `VERIFY_EMAIL` after commit; local failure deletes the Keycloak user), `UpdateUserAction` (HR path unchanged; `$identity` does Keycloak profile first), `AssignRolesAction`, `SetUserEnabledAction` (disable → logout + `DeactivateUser`), `SendPasswordResetAction`. `UserAdminGuard` for G2–G5 under Redis lock `lock:admin-guard`. `RecordUserAdminAudit` on success and on `DomainException`. `IdentityUserDirectory` lists from Keycloak and mirrors a local row when one is missing.
- Routes in `routes/api.php`. `POST /admin/users` uses `idempotent`. `/admin/users` also uses `module:user_management` and `admin`.
- `Settings::isOverridden()`. `SettingsPolicy` registered in `AppServiceProvider` (`Setting` is the row; there is no `Settings` model). `UserPolicy::manageIdentity`.
- Happy-path tests: `tests/Feature/Admin/AdminApiTest.php` (OpenAPI validator, same helper as `HrAdminApiTest`).

Not touched: `web/**`, Dockerfile, compose, CI, `docs/**`, `.logs/MEMORY.md`, `.logs/BUILD_PLAN.md`, `.logs/decisions.md`. `config/keycloak.php` is platform-owned and was not edited; the actions-email lifespan is `config('keycloak.actions_email_lifespan', 86400)`.

## Commands run + results
- `docker compose run --rm app php artisan test --filter=AdminApiTest` → 7 AdminApiTest + 8 HrAdminApiTest passed (the filter also matches `HrAdminApiTest`).
- `docker compose run --rm app vendor/bin/pint` → fixed 1 style issue in `CreateUserAction.php` (212 files).
- `docker compose run --rm app php artisan test` → **187 passed** (1919 assertions), 20.62s.
- `docker compose run --rm app vendor/bin/pint --test` → PASS, 212 files. No secrets printed. Not committed.

## Decisions
No new ADR (decisions.md was not edited, per the phase brief).

`UpdateModules` takes one module and a boolean. The class diagram shows `handle(array $disabled)`. The OpenAPI operation is `PATCH /modules/{moduleKey}` with `{enabled}`. The action still writes the `modules.disabled` list.

## Bugs found / open issues
Spec mismatches left untouched (domain does not edit `docs/**` or the existing audit CHECK):

1. **Reads are not audited.** API_GUIDELINES §9 says every `/admin/users` call writes `user_admin_audits`. The table CHECK and SYSTEM_DESIGN §4 only allow `create`, `update_profile`, `enable`, `disable`, `assign_role`, `password_reset`, `compensate_delete`. List and detail (including a 503 on those reads) do not insert a row. Mutating calls do, including failures.
2. **Some Keycloak users are omitted from the admin list.** The operation says every search hit gets a local ULID. `users.email` is NOT NULL and `users.role` cannot be null, while `IdentityUser.email` and `IdentityUser.role` are nullable. A Keycloak user with no email, or with no base realm role (`employee` / `manager` / `hr_admin`), is skipped. `total` is still the Keycloak count, so a page can be shorter than `page[size]`.

Other limits, not spec edits:

- `HttpKeycloakAdminClient` role filter scans at most 1000 members (`GET /roles/{role}/users` has no count API). The fake used in tests is exact.
- Actions-email failure after the local commit is logged and the create still returns 201. The Keycloak user and the local row both exist.
- Happy-path tests do not cover asset upload/delete, branding reset, self-role / self-disable, last admin, federated `IDP_READ_ONLY`, or `IDP_CONFLICT`. Those paths are implemented for QA phase 17.

## Next steps
Finished for QA (phase 17) and frontend (phase 16): **UC-H16, UC-B1, UC-B2, UC-B3, UC-B4, UC-B5, UC-B6, UC-U1, UC-U2, UC-U3, UC-U4, UC-U5, UC-U6**.

QA owns the rest of the matrix in AGENTS §6 items 11, 12, and 17 (module off → 404, unlicensed 422 already has a happy-path test, branding upload, last admin, compensation, IDP codes, OpenAPI on every response). Frontend can call these `/api/v1` routes; ids are ULIDs. Team Time Off can pass `GET /settings/working-week` `weekend_days` into the grouping (D-51).
