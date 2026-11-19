# 2026-11-19 — frontend — phase 9: Next.js foundation (`web/`)

## Goal
BUILD_PLAN phase 9: scaffold `web/` (Next 16, TS strict, standalone, non-root image), antd v6, generated API types + `openapi-fetch`, Better Auth 1.7.26 BFF on Postgres `lms_auth` (D-45), `/bff/api` proxy, runtime theming from `GET /api/v1/branding`, app shell + placeholders, Vitest, verified build + live login. No compose/nginx/CI changes (phase 10).

## What I did (files, all under `web/` unless noted)
- Scaffold: `package.json` (exact pins), `package-lock.json`, `next.config.ts` (`output: 'standalone'`, `serverExternalPackages: ['better-auth']`, `outputFileTracingIncludes` for the migrate script), `tsconfig.json` (strict), `eslint.config.mjs` (eslint-config-next core-web-vitals + typescript), `.prettierrc.json`, `.prettierignore`, `.gitignore`, `vitest.config.ts`, `vitest.setup.ts` (cleanup + `matchMedia` stub for antd), `Dockerfile`, `.dockerignore`.
- API types: `src/lib/api/schema.d.ts` (generated, committed), `src/lib/api/client.ts` (`serverApi(token)` → `${LMS_API_URL}/api/v1`, `browserApi` → `/bff/api`). Scripts `gen:api`, `check:api` (`openapi-typescript --check`, exit 1 when stale; verified).
- Auth: `src/auth/options.mjs` (shared Better Auth options: genericOAuth `keycloak` via `discoveryUrl` on `KEYCLOAK_BASE_URL`, PKCE, `requireIdTokenVerification`, `overrideUserInfo`, `postLogoutRedirectURI: '/'`; `pg.Pool(AUTH_DATABASE_URL)`; `basePath: '/auth'`; cookie prefix `lms`; JWE cookie cache 300 s; `encryptOAuthTokens`; `storeAccountCookie: false`; trusted linking `keycloak`; DB rate limit; telemetry off; `disabledPaths`), `src/auth/index.ts` (lazy `getAuth()` + `nextCookies()`, `getAccessToken(headers)`, `clearSession(headers)`, `getSession`), `src/auth/access-token.ts` (refresh helper, 60 s skew, re-read once after a failed refresh, then delete the session), `scripts/auth-migrate.mjs` (one-off `getMigrations(options).runMigrations()`).
- Routes: `src/app/auth/[...all]/route.ts` (Better Auth handler), `src/app/bff/api/[...path]/route.ts` + `src/bff/proxy.ts` (allow-listed headers both ways, streamed bodies, Origin check on POST/PUT/PATCH/DELETE, 401 problem + cookie clear without a session or on Laravel 401, `..` segments → 404, upstream down → 503), `src/bff/problem.ts`, `src/app/bff/logout/route.ts` (Origin check → `signOut` → 303 Keycloak end-session with `id_token_hint`), `src/app/bff/healthz/route.ts` (no I/O), `src/proxy.ts` (Next 16 proxy, ex-middleware: no session cookie → `${APP_URL}/signin`).
- Theming/shell: `src/theme/brand.ts` (`themeFromBranding`: hex re-validation, `colorPrimary/Link/Info`, `colorSuccess` = accent, Button `primaryColor` = `primary_contrast`, radius 6, system font), `src/theme/branding.ts` (cached `fetch` with `revalidate: BRANDING_REVALIDATE_SECONDS`, tag `branding`, never throws), `src/app/layout.tsx` (`force-dynamic`, `generateMetadata` title template + favicon, `AntdRegistry` → `ConfigProvider` → `App`), `src/app/(app)/layout.tsx` (token → `GET /me` (provisions) → `AppShell`), `src/components/{AppShell,SignInCard,Placeholder}.tsx`, `src/components/nav.ts` (role/admin/module rules), pages `/`, `/leave-requests`, `/approvals`, `/holidays` (`notFound()` when the module is off), `/signin`.
- Tests: `src/theme/brand.test.ts`, `src/components/nav.test.ts`, `src/components/AppShell.test.tsx` (Testing Library, jsdom), `src/bff/proxy.test.ts`, `src/auth/access-token.test.ts`.
- Doc (allowed edit): `docs/UI_GUIDELINES.md` §3 rules: confirmed `List → Listy` is right (antd 6.7.1 exports `Listy`; `List` logs "deprecated … use `Listy`"), added that `Listy` is a virtual list (`items`/`itemRender`/required `rowKey`), size name `medium`, and the server-component compound-member pitfall.

### Versions pinned
next 16.4.6, react/react-dom 19.3.1, better-auth 1.7.26, pg 8.23.1, antd 6.7.1, @ant-design/icons 6.3.4, @ant-design/nextjs-registry 1.3.0, @ant-design/cssinjs 2.1.2 (= antd's `~2.1.2`, single copy), dayjs 1.11.23, openapi-fetch 0.17.0; dev: typescript 5.9.3, openapi-typescript 7.13.0, eslint 9.39.5, eslint-config-next 16.4.6, prettier 3.10.1, vitest 5.1.1, @testing-library/react 16.3.3, jsdom 31.0.1, @types/node 24.19.2, @types/pg 8.23.1, @types/react 19.3.0, @types/react-dom 19.3.0. Image `node:24.22.3-alpine` (build arg `NODE_IMAGE`).

## antd v6 name check (UI_GUIDELINES §3)
Type-checked a probe file against antd 6.7.1: `Space orientation/separator`, `Dropdown popupRender/classNames.root`, `Card variant/size medium/styles.header|body`, `Statistic styles.content`, `Table size medium/pagination.placement/filterDropdownProps.open`, `Tag variant filled|solid`, `Calendar cellRender/fullCellRender`, `Modal destroyOnHidden/mask.closable/styles.body`, `RangePicker variant/classNames.popup.root/onCalendarChange`, `Drawer size/styles`, `Timeline items/mode start`, `Descriptions items/styles.label|content`, `Alert title/closable.onClose`, `notification title/actions`, `Listy` all exist; old names carry `@deprecated` (`Space direction/split`, `Dropdown.Button`). No mapping was wrong.

## Commands + results
- Host Node v26.7.0 / npm 11.19.0 (≥ 24, used directly).
- `npm ci && npm run lint && npm run typecheck && npm test && npm run build && npm run check:api` → all pass (22 tests in 5 files; lint = ESLint + Prettier clean). `check:api` exits 1 after appending a line to `schema.d.ts`, 0 when fresh.
- `npm ls @ant-design/cssinjs` → one version, 2.1.2, everything else deduped.
- `docker build -t lms-web:phase9 web/` → OK, 213 MB, runs as `uid=1000(node)`. Build stage fails if the standalone tree cannot import `better-auth/db/migration` + `src/auth/options.mjs`.
- Live smoke (compose stack up, phase 8 branding endpoint already live): `CREATE DATABASE lms_auth` by hand; `docker run --network laravel-lms-rewrite_default … node scripts/auth-migrate.mjs` → created `user, session, account, verification, rateLimit`; second run → nothing to do. Two replicas (`docker run` ports 3101/3102, `APP_URL=http://localhost:8080`, `LMS_API_URL=http://nginx`):
  - `/bff/healthz` 200; `/` without cookie → 307 `http://localhost:8080/signin`; `/bff/api/me` without session → 401 `application/problem+json` `UNAUTHENTICATED`.
  - curl login as alice: `POST /auth/sign-in/social` (replica 1) → Keycloak `localhost:8081` (PKCE S256, state, nonce) → callback (rewritten from :8080 to replica 1) → 302 `/`, cookies `lms.session_token` + `lms.session_data`.
  - `GET /bff/api/me` on **replica 2** → 200 alice (`role: employee`), `X-RateLimit-*` passed through. Dashboard on replica 2 → 200, title "Dashboard · Leave Manager", favicon from branding, `--ant-color-primary:#1f6feb` (API value), nav `/`, `/leave-requests`, `/holidays` (no Approvals for an employee).
  - Refresh: set `account.accessTokenExpiresAt = now()+30s` → `/bff/api/me` 200, access token rotated (stored encrypted), new expiry persisted.
  - `POST /bff/logout` without Origin → 403; with Origin → 303 to `…/protocol/openid-connect/logout?id_token_hint=…&post_logout_redirect_uri=http://localhost:8080/&client_id=lms-web`, session row deleted, next `/bff/api/me` → 401.
  - Smoke containers removed; DB `lms_auth` left in the dev Postgres (harmless; phase 10 creates it in the init script for fresh volumes).

## Proposed decisions (orchestrator to merge as D-47)
**D-47 — Phase-9 frontend details (amends D-43, D-45).**
(a) Better Auth is a `serverExternalPackages` entry, so Next traces it into `.next/standalone/node_modules`; `outputFileTracingIncludes` adds `scripts/auth-migrate.mjs` + `src/auth/options.mjs`; the Dockerfile build stage imports both from the standalone tree so a tracing regression fails the image build (D-45 (h) risk). Without the external entry Turbopack bundles Better Auth and the script cannot run.
(b) **Security addition:** `disabledPaths` turns off Better Auth's HTTP routes `/get-access-token`, `/refresh-token`, `/account-info` (they return provider tokens to any cookie holder, which breaks "the browser never sees tokens") and the mirror-editing `/link-social`, `/unlink-account`, `/update-user`, `/delete-user`, `/change-email`. Server-side `auth.api.*` calls are unaffected (router-only check).
(c) Better Auth 1.7.26 encrypts only access and refresh tokens; `account.idToken` is stored in plain text (it is the `id_token_hint`). Accepted: Laravel's guard only accepts `typ=Bearer` (D-39 (b)), so an ID token cannot be replayed against the API. Revisit if the DB threat model requires it.
(d) Options live in `src/auth/options.mjs` (plain JS + JSDoc `@satisfies BetterAuthOptions`), shared by the app and the migrate job; the auth instance is created lazily, so `next build` needs no env or DB. Root layout is `force-dynamic` so branding is never baked at build time.
(e) Generated types are at `web/src/lib/api/schema.d.ts` (AGENTS §3, D-43 (c)), not `web/src/api/` as the phase brief said.
(f) npm deps beyond AGENTS §2 (need the ADR): dev `eslint-config-next`, `@types/node`, `@types/react`, `@types/react-dom`. Deferred: `@tanstack/react-query` (no client data yet; add in phase 12 when first used). Pins forced by peers: ESLint 9.39.5 (eslint-plugin-react inside eslint-config-next 16.4.6 caps at ESLint 9; npm marks 9.x unsupported, bump with eslint-config-next), TypeScript 5.9.3 (openapi-typescript 7 peers `^5`; TS 7.0 is out).
(g) Next 16 renamed middleware to `src/proxy.ts`; it only checks session-cookie presence (redirect to `${APP_URL}/signin`); the `(app)` layout does the real check (token + `GET /me`).
(h) `useSecureCookies` follows the `APP_URL` scheme: `https` → `__Secure-lms.*` cookies (D-45 (e)); plain `http` dev → `lms.*`.
(i) BFF extras: decoded path segments `''`, `.`, `..` → 404 `NOT_FOUND` (blocks `/bff/api/../docs` reaching `/api/docs`); upstream unreachable → 503 `SERVICE_UNAVAILABLE` problem.
(j) antd compound members (`Typography.Title`, …) are `undefined` in server components; antd JSX stays in `'use client'` components (UI_GUIDELINES §3 note added).

## Bugs found / open issues
- **Figma MCP not available** to this subagent (no Figma tools exposed, so neither `/figma-design-to-code` nor `get_variable_defs` could run). Fallback brand colours in `src/theme/brand.ts` are the approximate UI_GUIDELINES values (`#3F5BC6` primary, `#0E8A16` accent), marked `shortcut:`; the shell layout follows UI_GUIDELINES §2/§3 rather than the 484:2 design context. Phase 12 must pull the exact tokens and Sider styling.
- Better Auth's cookie deletion emits `lms.session_token=; Path=/; HttpOnly; SameSite=lax` with **no `Max-Age=0`** (seen on logout and on the BFF 401). The browser keeps an empty cookie, which reads as "no session", so it works; but it is an upstream oddity to watch on the next bump.
- Notifications bell is a disabled icon button (the brief asked for a placeholder; UI_GUIDELINES says show it only once data backs it). Remove or wire it in phase 12.
- After the callback Better Auth redirects to an absolute URL built from the request host. Behind nginx this needs `Host` (with port) forwarded, or users land on the internal host (see phase 10).
- Responsive `xs` Drawer nav (UI_GUIDELINES §5) not built yet (Sider collapses at `lg`); phase 12.
- Keycloak realm `lms-web` still has `webOrigins: ["http://localhost:8080"]` (D-43 (j) says none); platform's call.

## Next steps
- **Phase 10 (platform)**:
  - Compose `web` service: image from `web/Dockerfile` (context `web/`), port 3000, healthcheck `GET :3000/bff/healthz` (the image already has a `HEALTHCHECK` using busybox `wget`).
  - Env: `APP_URL`, `BETTER_AUTH_SECRET`, `AUTH_DATABASE_URL=postgres://lms:secret@postgres:5432/lms_auth`, `KEYCLOAK_BASE_URL=http://keycloak:8080`, `KEYCLOAK_REALM=lms`, `KEYCLOAK_CLIENT_ID=lms-web`, `KEYCLOAK_CLIENT_SECRET=lms-web-dev-secret`, `LMS_API_URL=http://nginx`, optional `BRANDING_REVALIDATE_SECONDS` (30) and `AUTH_SESSION_MAX_AGE` (36000). `PORT`, `HOSTNAME` and `NODE_ENV` are set in the image.
  - Migrate one-off: `docker compose run --rm web node scripts/auth-migrate.mjs`. It is idempotent; `depends_on` postgres healthy; run before `web` replicas.
  - DB `lms_auth` in `docker/postgres/init/01-test-db.sql`.
  - nginx: `/` → web upstream (covers `/auth/`, `/bff/`, `/_next/`, pages); forward `Host $http_host` plus `X-Forwarded-For/-Proto/-Host` (Better Auth redirects, rate-limit IP, BFF forwarding).
  - `web-dev` tooling service must mount the repo root, not just `web/` (`gen:api`/`check:api` read `../docs/api/openapi.yaml`), with workdir `/repo/web`.
  - CI web job: `npm ci && npm run lint && npm run typecheck && npm test && npm run build && npm run check:api` in `web/`.
- Phase 12 (frontend): Figma tokens + Sider styling, TanStack Query, real screens, xs Drawer nav.
