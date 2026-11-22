# 2026-11-22 — custom login (architect)

## Goal
Record the product decision that the sign-in form lives on the LMS welcome page and the browser is not redirected to Keycloak's login UI. Docs only. No application code. No commit. Do not edit `MEMORY.md`.

## What I did (files)
- `.logs/decisions.md`: **D-52**. Welcome-page form, `POST /bff/login`, resource-owner password grant for confidential client `lms-web` only, password not stored, tokens stay in `lms_auth` (D-45), logout stays RP-initiated, Laravel stores no passwords. Marks the interactive auth-code redirect in D-20, D-43 and D-45(b) as amended by D-52. A shorter D-52 had already been appended by platform (realm import / `kcadm`); that text is merged here so there is one ADR. A duplicate numbered D-57 was removed so it would not collide with phase 19.
- `docs/USE_CASES.md`: UC-E1 name and two new sentences in the notes. §3.10's web-login sentence now matches those sentences (it is the rule UC-E1 points at).
- `docs/SYSTEM_DESIGN.md`: short note under the BFF auth flow in §6.1. The constraints-table web-auth row, the welcome-page route row, §10 AuthN, the `lms-web` client line, and the CSP `form-action` parenthetical say the same thing.

Not edited: `docs/api/openapi.yaml` (this route is not a Laravel API), PHP, `web/**`, `.logs/MEMORY.md`.

## Commands run + results
No Redocly run. The OpenAPI spec was not changed. No Mermaid run. Diagrams were not edited.

## Decisions
- **D-52** — welcome-page sign-in via the resource-owner password grant. Amends the interactive browser redirect in D-20, D-43 and D-45(b).

## Bugs found / open issues
- These still describe the authorization-code browser redirect and were left for a follow-up so this run stayed on the login decision: `docs/diagrams/06-sequence-diagrams.md` §1, `docs/diagrams/05-activity-diagrams.md` (sign-in step), `docs/UI_GUIDELINES.md` screen S1, `docs/API_GUIDELINES.md` sign-in row.
- A browser MFA challenge on Keycloak's login UI is not part of this path. Password checks, lockout and brute-force detection stay in Keycloak.
- `lms-dev-cli` keeps its existing dev-only direct grant (D-39). It is not the browser login and is not a production client. Production Direct Access Grants are on `lms-web` only.

## Next steps
- Frontend: welcome-page form posts to `POST /bff/login`; do not send the browser to Keycloak's authorization endpoint for sign-in. Do not store the password. Keep tokens in `lms_auth`.
- Platform: Direct Access Grants on `lms-web` only for production. Logout stays the existing RP-initiated `POST /bff/logout`.
- Orchestrator indexes this log in `.logs/MEMORY.md`. This run did not edit that file.
