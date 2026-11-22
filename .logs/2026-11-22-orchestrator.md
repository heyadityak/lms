# 2026-11-22 — orchestrator

## Goal
Continue from commit `334a81e`. Phases 12 and 13 were half-landed (screens unmounted, HR API untested, no run logs). Finish them with the agent roster, record decisions, and check the leave screens in the browser.

## What I did
- Delegated phase 12 to frontend, phase 13 to domain, and the phase-11 class-diagram hand-off to architect. They ran in parallel.
- Sent frontend back once: Leaves stat cards are one per tracked leave type plus pending, matching UI_GUIDELINES §2 S3.
- Recorded D-51 (Team Time Off uses Sat/Sun until phase 15). No ADR for the validator (D-49 already covers it).
- Marked BUILD_PLAN phases 7, 12, and 13 done. Indexed the missing phase 8–11 logs plus today's logs in MEMORY.md.
- Built `lms-app-dev`, `lms-web`, `lms-nginx-dev` and started the compose stack. Browser check through `http://localhost:8080`.

## Browser check
- Alice (employee): dashboard has Available Leaves, Apply, Next Holidays (Christmas Day). No Approvals nav, no Team Time Off, no pending-approvals stat. Leaves shows Casual 10, Earned 30, Sick 15, Pending 0, empty table. `/leave-requests/new` opens the apply modal (types, dates, half day, day count, reason, handover). Holidays shows the month grid and the seeded dates. `/approvals` is 404. Sign out returns to `/signin`.
- Bob (manager): dashboard has Approvals, Pending approvals 0, Team Time Off ("Nobody is scheduled off"), Next Holidays. `/approvals` loads an empty inbox.

## Commands
`docker compose build app web nginx && docker compose up -d app worker keycloak mailpit web nginx` — exit 0. `/healthz`, `/readyz`, Keycloak discovery 200.

## Next
Phases 14 and 15, in parallel. Not committed.

## Phase 14 follow-up
Empty weekend (`[]`) is allowed in the form, matching D-30. Vitest stayed at 39. Web image rebuilt.

Browser as carol: departments (HR, IT), users (four people; carol cannot deactivate herself), leave types (CL, EL, LWP, SL, edit only). Bob gets 404 on `/admin/departments`. Working week shows the API 404 because the Octane workers have not loaded the new route. Holidays and reports admin are Next 404s: `GET /api/v1/branding` currently returns 403 while phase 15 is mid-edit, so the shell falls back to no modules. Source already points that route at `PublicBrandingController`. Reload workers after phase 15 finishes.
