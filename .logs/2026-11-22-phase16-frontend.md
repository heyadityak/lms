# 2026-11-22 — frontend — phase 16: admin screens

## Goal
BUILD_PLAN phase 16 only. Admin screens so the existing `nav.ts` links render: branding, modules, identity users. Do not redo phase 14 HR screens. Do not edit PHP, docs, compose, CI, `.logs/MEMORY.md`, `.logs/BUILD_PLAN.md`, `.logs/decisions.md`, or the holiday calendar files.

## What I did (files)
All under `web/`.

Pages (server gate, then a client view). Wrong role → `notFound()`.

- `src/app/(app)/admin/settings/branding/page.tsx` → `BrandingView` (`is_admin`)
- `src/app/(app)/admin/settings/modules/page.tsx` → `ModulesView` (`is_admin`)
- `src/app/(app)/admin/identity-users/page.tsx` → `IdentityUsersView` (`is_admin` and `user_management` in public branding modules)

Views:

- `src/components/admin/BrandingView.tsx` — name, hex `ColorPicker` for primary and accent, WCAG warning when white text on the primary is under 4.5:1 (save still allowed). Logo upload PNG/JPEG/WebP, favicon PNG/ICO, SVG refused before the request. Remove asset and reset call DELETE. Writes: `PATCH /settings/branding`, `PUT/DELETE /settings/branding/assets/{logo|favicon}`, `DELETE /settings/branding`.
- `src/components/admin/ModulesView.tsx` — one `Switch` per module from `GET /modules`. Locked switches are disabled and the handler returns before `PATCH`. Status text (Enabled / Disabled / Locked…) sits next to the switch. `PATCH /modules/{moduleKey}` body `{enabled}`. 422 `MODULE_NOT_LICENSED` is an error alert.
- `src/components/admin/IdentityUsersView.tsx` — search (`filter[q]`, role, sign-in), create, edit, roles, enable/disable, password-reset email. Create sends `Idempotency-Key` (`crypto.randomUUID()`) and an `IdentityUserCreate` body with no password field. 503 `IDP_UNAVAILABLE` replaces the page with a Retry result. Federated name/email are read-only; their password reset is text, not a form. Own roles and own enable/disable are not offered.

Support:

- `src/lib/revalidate-branding.ts` — server action. After a successful branding write, and after a module toggle (public `modules` is the same cache), the client calls `revalidateBranding()`.
- `src/theme/contrast.ts` — relative luminance, contrast ratio, warning copy.
- `src/lib/brand-asset.ts` — logo/favicon file check (type, extension, size, SVG).

Nav links in `nav.ts` were already correct. No change there. `HolidaysView.tsx` and its test were not edited.

Admin subnav (same `AdminSubnav` for HR and identity admin). It was a row of default links (`Space` + underlined `Link`) on the page background. It is now a white bar under the header: `nav.lms-admin-subnav`, items still from `navItems`. Current link is `aria-current="page"` with weight 700, primary text, `colorPrimaryBg` fill, and a 2px primary bar (`a[aria-current='page']::after`). Other links use the heading colour, weight 600, no underline. Focus uses a 2px accent outline. Sider unchanged. Vitest: the open admin item is `aria-current="page"` and its siblings are not (HR Users, and Identity users). `npm test` 52 passed; lint and typecheck clean.

## Commands run + results
Host Node, from `web/`:

- `npm test` — 18 files, **51 passed**, 0 failed.
- `npm run lint` — ESLint + Prettier clean (Prettier wrote three files on the first pass; the re-run is clean).
- `npm run typecheck` — `tsc --noEmit` clean.

No browser pass (orchestrator verifies these screens). No container rebuild.

## Decisions
Propose only. `decisions.md` was not edited.

**`revalidateTag` second argument.** Next.js 16.4 types `revalidateTag(tag, profile)`. The action calls `revalidateTag('branding', { expire: 0 })`. In this version, `expire: 0` marks the tag expired and sets the action revalidation flag to static-and-dynamic, so the client router drops its cached shell. `refresh()` is not called after that: it overwrites the flag with dynamic-only and would leave the tagged `GET /api/v1/branding` fetch in place. `updateTag` was not added; `expire: 0` already takes the immediate path.

**Module toggles use the same action.** Enabling or disabling a module changes `modules` on the public branding payload, which is the `branding` cache tag the nav reads. The module screen calls `revalidateBranding()` after a successful PATCH.

## Bugs found / open issues
- No API gap for these screens. `routes/api.php` already registers `GET/PATCH/DELETE /settings/branding`, `PUT/DELETE /settings/branding/assets/{logo|favicon}`, `GET /modules`, `PATCH /modules/{module}`, and the `/admin/users` identity routes (list, create with `idempotent`, get, patch, enable, disable, roles, password-reset).
- Browser not checked. Compose was left running for the other worker’s web rebuild.
- `.logs/MEMORY.md` was not updated: this run was told not to touch it.

## Next steps
Orchestrator: sign in as dave (employee + admin) and walk branding, modules, and identity users. Phase 17 e2e can cover branding name/colour/favicon and module-off nav. A non-admin opening these URLs should 404; identity also 404s when `user_management` is off.
