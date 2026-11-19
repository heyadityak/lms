# 2026-11-19 — architect — Next.js frontend (BFF) + API-only Laravel

## Goal
Docs-only redesign for the user requirement: Next.js frontend in `web/` with a BFF, Laravel API-only. Later changes from the coordinator: Ant Design v5 instead of Tailwind/shadcn, Figma "Employee Leave Management" as the visual reference (working copy `R5KzPQs6IgzS3JYvXS9z1E`, page "Final One" 484:0), new `docs/UI_GUIDELINES.md`, and an evaluation of team-calendar and leave-preview endpoints.

## What I did (files)
- `.logs/decisions.md`: new **D-43** (a–n). Marked D-05 superseded, D-20 web-login part superseded, D-23 amended, D-39(b)/(d) web parts superseded.
- `docs/api/openapi.yaml`: added `GET /branding` (`getPublicBranding`, public, schema `PublicBranding`, example `PublicBrandingAcme`), added `POST /leave-requests/preview` (`previewLeaveRequest`, schema `LeaveRequestPreview`), changed the `api` module semantics (first-party BFF bypass) in `info` and in `PATCH /modules/{key}`, updated the security scheme text, and changed the branding example `favicon_url` to `/branding/favicon`.
- `docs/API_GUIDELINES.md`: module gate row; new §5.1 BFF contract (forwarded headers, 401 handling, CSRF, URLs); `public` limiter; caching; CORS.
- `docs/SYSTEM_DESIGN.md` v1.5: §1, §2 diagrams, §3 (`web` container, nginx routing, `web/Dockerfile`), §3.1, §5, §6.1 replaced by Frontend (edge routing, Next routes, auth, BFF, data, UI, Laravel non-API routes), §6.2, §8.2 theming via antd `ConfigProvider`, §8.3 gating, §9 env (Laravel removals, `WEB_UPSTREAM`, new `web` env table), §10/§10.1, §11, §13 CI, §14 sources.
- `docs/USE_CASES.md` v1.4: UC-E1, UC-E5 (preview), UC-E13 note, `api` module row, §3.9 deactivation, §3.10 identity.
- `docs/UI_GUIDELINES.md` (new): Figma reference + license caveat, screen inventory with node ids and out-of-scope list, antd mapping, theming tokens, breakpoints, a11y gaps, antd version note.
- `AGENTS.md`: stack (NGINX, auth, Redis, tests, style, frontend, npm deps), layout (`web/`), conventions (thin API controllers, modules, mail links, auth, a frontend rule), run commands, testing (Component + E2E suites, scenarios 11/12/13/15/16), CI, DoD, §9 roster (frontend row; platform, domain and qa rows adjusted).
- Diagrams: `01-system-design.md` (context, containers with `web`, deployment with web image/secrets, scaling), `02-data-flow.md` (D11 = web session cookie), `03-class-diagram.md` (removed `KeycloakLoginController`, `BrandingComposer` and Web stereotypes; added `PublicBrandingController`), `05-activity-diagrams.md` (first login via the BFF and the first API call), `06-sequence-diagrams.md` (1 login via the BFF, 2 logout, 4 apply Browser→NGINX→BFF→NGINX→app, 9 branding via the BFF + antd theme), `README.md`. SVGs re-rendered (31).
- `.logs/BUILD_PLAN.md`: phases 8–17 rewritten.

## Commands run + results
- `docker run --rm -v "$PWD":/work redocly/cli lint /work/docs/api/openapi.yaml`: valid, the 2 accepted warnings only (`/healthz`, `/readyz`).
- `minlag/mermaid-cli` over `docs/diagrams/0*.md`: all 7 files render (after fixing a parenthesised edge label in 02 and `;` inside sequence messages in 06). SYSTEM_DESIGN mermaid blocks render too (scratch copy).
- Web searches: Next.js 16.x is the current stable; Auth.js v5 is still beta and maintained by Better Auth (security only); antd v6 shipped in late 2025 and v5 is in its maintenance window.

## Decisions
- D-43. Refinements to the orchestrator's design: Node 24 LTS instead of 22; `api` module = third-party gate with an `azp` bypass for the BFF; explicit Keycloak endpoints in Auth.js (back-channel vs public URL); `LMS_API_URL` goes through nginx; `URL::forceRootUrl(APP_URL)`; `/branding/{asset}` serves defaults; refresh-token max reuse 1; `large_client_header_buffers`; mail deep links = `APP_URL` + Next path; `web/e2e/**` owned by qa.
- Endpoints added: `GET /api/v1/branding` and `POST /api/v1/leave-requests/preview`. Team calendar endpoint not added: managers and HR use `GET /leave-requests` with the overlap filters; the employee-wide view is UC-E13 (Later).

## Bugs found / open issues
- QA phase 7: web/Socialite tests will be deleted in phase 8. QA must not write more of them.
- User decisions: Auth.js v5 beta pin vs `openid-client` + `jose`; antd v5 (maintenance) vs v6; Figma community file license/attribution.
- Refresh-token race under parallel requests is only mitigated (max reuse 1 + silent re-auth), not eliminated.
- `.claude/agents/frontend.md` (not mine) does not mention antd or the Figma MCP; the orchestrator may want to add them.

## Next steps
Phase 8 (platform) per BUILD_PLAN.
