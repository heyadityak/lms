# 2026-11-22 — frontend — welcome screen and profile

## Goal
Replace the plain sign-in card with a branded welcome, and add `/profile` for every signed-in role. HR admins edit LMS fields on that page. Do not touch phase 19 screens, PHP, docs, MEMORY, BUILD_PLAN, or decisions. Do not commit.

## What I did (files)
All under `web/`.

- `src/components/SignInCard.tsx` — two-column welcome (stacks under 768px). Brand panel uses the customer primary and `primary_contrast` for the logo chip, name, “Welcome”, and one sentence (request leave, approve time off, check balances). SSO is still `POST /auth/sign-in/social` with `{ provider: 'keycloak', callbackURL: '/' }`. Failure alert unchanged. No email or password fields. Focus ring is a 3px outline.
- `src/app/signin/page.tsx` — passes `primary_color` and `primary_contrast` into the card. Heading level 1 stays the brand name.
- `src/app/globals.css` — `.lms-signin` / `.lms-welcome` layout only.
- `src/app/(app)/profile/page.tsx` — `ProfileView`.
- `src/components/ProfileView.tsx` — `GET /me`. Shows name, email, role, employee code, designation, employment type, department, location, manager, date of joining, and active status. Employee and manager are read-only. `hr_admin` gets a form and `PATCH /users/{id}` with `UserUpdate` only (department, location, manager, joining date, employee code, designation, employment type). Role, name, and email are not sent. Field errors use `applyFieldErrors`. Photo: if `avatar_url` is set, the image is `GET` through `/bff/api/users/{id}/avatar`; otherwise initials. Upload is `POST /me/avatar` (PNG, JPEG, or WebP, 1 MB) and remove is `DELETE /me/avatar`, the generated operations.
- `src/components/UserMenu.tsx` — added a Profile item (`href="/profile"`) immediately before Sign out. Left the header photo upload in place.

Tests: `SignInCard.test.tsx`, `ProfileView.test.tsx`, `UserMenu.test.tsx`.

## Commands run + results
Host Node, from `web/`:

- `npx vitest run` on SignInCard, ProfileView, UserMenu, and AppShell — **12 passed**.
- `npx eslint` on the files above — clean.
- `npx tsc --noEmit` — clean.

No browser pass. The compose stack was not started from this run.

## Decisions
None. `location_id` is on the generated `UserUpdate`, so the HR form includes Location with the other LMS fields. No new ADR.

## Bugs / open issues
Header photo and the profile photo are both upload controls for the same `POST /me/avatar`. The header copy is the other agent's. Uploading on the profile refreshes `GET /me` for this page; the header image updates when the shell user is loaded again.

## Next steps
None for this slice. Orchestrator can click `/signin` and `/profile` on the compose stack.
