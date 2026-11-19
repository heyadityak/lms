# 2026-11-19 — architect — Better Auth + antd v6 (D-45), phase-7 conventions (D-46)

## Goal
Apply the user's two frontend decisions from D-43's open risks: Better Auth (not Auth.js) for the Next.js BFF with Keycloak, and Ant Design v6 (not v5). Coordinator add-on: record the phase-7 bug-fix conventions (approver role, `page[number]` max, `PlainText`, generic 404).

## What I did (files)
- `.logs/decisions.md`: new **D-45** (Better Auth 1.7.26 + Postgres `lms_auth` store + antd v6, amends D-43, supersedes D-43(n)); new **D-46** (approver role rule, `PageNumber` max 10000, `PlainText`, fixed 404 detail + 404 for actions on invisible requests); D-43 marked "Amended by D-45".
- `docs/SYSTEM_DESIGN.md`: §1 UI/Web auth rows, §3 `web` container + postgres init + web one-off migrate row, §3.1 sticky sessions/migrations, §6.1 routes (`/signin`, `/auth/sign-in/social`, logout) + Auth (BFF) paragraph rewritten, §8.2 ConfigProvider (no `cssVar: true`), §9 factors II/IV/VI/XII, web env table (`BETTER_AUTH_SECRET`, `AUTH_DATABASE_URL`, `KEYCLOAK_CLIENT_ID/SECRET`; Auth.js vars and `KEYCLOAK_PUBLIC_URL` removed for web), §10 AuthN web + CSRF, §12 `lms_auth` migrations, §13 smoke step, §14 sources.
- `AGENTS.md`: stack rows (identity, frontend, npm deps: `better-auth`, `pg`, `@types/pg`, antd/icons v6, pinned `@ant-design/cssinjs`; removed `next-auth`, `v5-patch`), layout (`signin/`, `auth/[...all]`, `scripts/auth-migrate.mjs`, `lms_auth` init), run commands, auth/frontend conventions, ownership rows (frontend owns `lms_auth` schema + migrate script; platform creates the DB, env, CI step).
- `docs/UI_GUIDELINES.md`: v0.2, antd v6 throughout, S1 route `/signin`, `List` → `Listy`, v6 prop names per mapping row, "v6 API names only" rule, §4 CSS variables note, §7 version note rewritten.
- `docs/API_GUIDELINES.md`: §5.1 sign-in row (Better Auth); D-46: `page[number]` max, `PlainText`, generic 404 `detail`, 404 for actions on invisible resources.
- `docs/USE_CASES.md` §3.6: approver resolution requires base role manager/hr_admin; transition table wording.
- `docs/api/openapi.yaml`: `PageNumber` `maximum: 10000` + description; `NotFound` example `detail` = "The requested resource was not found." (matches `ProblemJsonRenderer`), description notes actions.
- Diagrams: `01-system-design.md` (web node, pg `lms_auth`, web→pg edge, secrets, scaling table), `02-data-flow.md` (D11 = `lms_auth`), `05-activity-diagrams.md` §6 (Better Auth callback), `06-sequence-diagrams.md` §1 login, §2 logout, §4 apply (token read/refresh in `lms_auth`). SVGs re-rendered.
- `.claude/agents/frontend.md`: Better Auth + antd v6 wording, `lms_auth` ownership.
- `.logs/BUILD_PLAN.md`: phase 9/10 wording, open-decision note resolved.

## Commands run + results
- npm registry dist-tags (2026-11-19): `better-auth` latest 1.7.26 (1.8.0-beta.10 = beta), `antd` 6.7.1, `@ant-design/icons` 6.3.4, `@ant-design/nextjs-registry` 1.3.0 (peer `antd >=5`, `@ant-design/cssinjs >=1`), `@ant-design/cssinjs` 2.2.0 (antd 6.7.1 depends on `~2.1.2`), `next` 16.4.6.
- Read the `better-auth@1.7.26` tarball (scratchpad): stateless mode = `memoryAdapter` (`dist/db/adapter-base.mjs`), still `createUser/createAccount/createSession` on OAuth callback; `rateLimit.storage` defaults to `memory`; `keycloak()` helper builds `discoveryUrl` from `issuer` and does not forward `requireIdTokenVerification`; explicit endpoints → no ID-token verification/nonce; sign-out builds the end-session URL with `id_token_hint` from the `account` row; callback route `/callback/:id`; `getAccessToken` refreshes only < 5 s before expiry; `encryptOAuthTokens`; telemetry off by default; `getMigrations` exported from `better-auth/db/migration`.
- Keycloak discovery from inside compose (`docker compose exec app wget http://keycloak:8080/realms/lms/.well-known/openid-configuration`): issuer/auth/end-session `http://localhost:8081/…`, token/userinfo/jwks `http://keycloak:8080/…` → discovery on the internal URL works as-is.
- antd 6.7.1 tarball: `cssVar` is `{prefix,key}` only (always on), `zeroRuntime` option, `List` deprecated → `Listy` (6.6+), deprecation maps for Modal/Dropdown/Statistic/Timeline/Card/Tag etc.
- `docker run minlag/mermaid-cli` on 01, 02, 05, 06 → all exit 0 (31 SVGs, generated `.md` copies removed).
- `docker run redocly/cli lint docs/api/openapi.yaml` → valid, 2 accepted warnings.

## Decisions
- D-45, D-46 (see decisions.md).

## Bugs found / open issues
- Better Auth "stateless" mode cannot be used with N replicas (hidden per-process memory adapter); hence the `lms_auth` DB. Stated plainly in D-45(d).
- Risks in D-45(l): fast-moving Better Auth minors (exact pin + e2e login before bumps), refresh race beyond Keycloak max reuse 1, lazy cleanup of expired rows, extra pg pool per web replica, ~2 `lms_auth` queries per BFF call.
- `.env.example` (platform) still has the old web vars / lacks `AUTH_DATABASE_URL`, `BETTER_AUTH_SECRET`; `docker/postgres/init/01-test-db.sql` lacks `lms_auth`: phase 10 (platform).
- QA: OpenAPI contract tests now see `PageNumber maximum: 10000`; the NotFound example detail changed to match the renderer.

## Next steps
- Phase 9 (frontend): implement per D-45 (auth) + UI_GUIDELINES v0.2.
- Phase 10 (platform): `lms_auth` DB, web env, auth-migrate one-off in Taskfile + smoke.
