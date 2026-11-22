# 2026-11-22 — custom login (frontend)

## Goal
Welcome screen at `/signin` collects username and password and posts them to a same-origin BFF route. The browser is not sent to Keycloak. A successful Keycloak password grant becomes a Better Auth session the existing `getSession` / `getAccessToken` helpers already understand. No commit. Do not edit PHP, docs, the realm, `MEMORY.md`, `BUILD_PLAN.md`, or `decisions.md`.

## What I did (files)
- `web/src/components/SignInCard.tsx`: kept the welcome layout (brand panel, logo, name, “Welcome”, lead line). Replaced the SSO button with a username/password form. Submit `POST`s JSON `{ username, password }` to `/bff/login`. HTTP 401 shows “Those credentials were not accepted.” and does not navigate. Other failures keep the previous unavailable alert. Success does a full navigation to `/` so the new cookie is on the document request. Does not call `/auth/sign-in/social`.
- `web/src/components/SignInCard.test.tsx`: labels, rejected password (no navigation, posts `/bff/login`), unavailable alert.
- `web/src/app/bff/login/route.ts`: `POST /bff/login`. Origin check matches `/bff/logout`. On success the body is `{ ok: true }` plus the Better Auth `Set-Cookie` lines. On Keycloak 401 the body is `{ ok: false }` and there is no cookie. The password is not written to the response.
- `web/src/app/bff/login/route.test.ts`: 401 has no cookie, success copies only the session cookie, cross-origin 403 never calls the grant.
- `web/src/auth/password-grant.ts`: Keycloak token request and a server-only Better Auth endpoint.
- `web/src/auth/password-grant.test.ts`: 401 does not leak the password into the error; the result has tokens and identity only; userinfo fills a missing email; a non-401 is unavailable.
- `web/src/auth/index.ts`: registers `passwordGrantPlugin()` before `nextCookies()` (the cookie plugin stays last).

Not edited: PHP, `docs/**`, `docker/keycloak/realm-lms.json`, `.logs/MEMORY.md`, `.logs/BUILD_PLAN.md`, `.logs/decisions.md`, `web/e2e/**`.

## How the session is created
`POST /bff/login` calls `getAuth().api.signInWithPassword`. That method is a Better Auth endpoint created with `createAuthEndpoint.serverOnly`, so it is not mounted on `/auth` (no path, and `SERVER_ONLY`).

The endpoint exchanges the password at `${KEYCLOAK_BASE_URL}/realms/${KEYCLOAK_REALM}/protocol/openid-connect/token` with `grant_type=password`, client id `KEYCLOAK_CLIENT_ID`, and client secret `KEYCLOAK_CLIENT_SECRET` (`scope=openid profile email`). HTTP 401 from Keycloak becomes Better Auth `APIError` 401. The password is not logged, not stored, and not returned. It is cleared on the endpoint body and on the local credentials object after the token call.

On success the endpoint calls `handleOAuthUserInfo` from `better-auth/oauth2` — the same function the Keycloak authorization-code callback uses after it has tokens. That writes:

- a `user` row (email, name, `emailVerified` from the ID token, or userinfo when the ID token has no email)
- an `account` row with `providerId: 'keycloak'` and `accountId` = the Keycloak `sub`
- access and refresh tokens through `setTokenUtil`, so they are encrypted because `account.encryptOAuthTokens` is already true. The ID token is stored the same way the social callback stores it (not passed through `setTokenUtil`)
- a `session` row via `internalAdapter.createSession`

It then calls `setSessionCookie`, which signs the session token into the existing HttpOnly `lms.session_token` cookie (and the JWE `session_data` cache cookie). `storeAccountCookie` is false, so tokens are not put in a browser cookie.

`getSession` reads that signed cookie. `getAccessToken` lists accounts, finds `providerId === 'keycloak'`, decrypts the stored tokens, and refreshes through the existing generic OAuth provider when the access token is inside the 60s skew. Logout stays `POST /bff/logout`.

No second session store was needed. The OAuth redirect was only how the browser used to deliver the code. The session and account writes happen after the tokens exist, and those writes are available as server functions.

## Commands run + results
From `web/`:

- `npx tsc --noEmit` — exit 0
- `npx vitest run` — 24 files, 70 tests passed
- `npx eslint` on the files above — exit 0
- `npx prettier --check` on the files above — clean

Not run: a browser sign-in against the compose stack. Platform’s log already recorded a successful resource-owner grant for `alice` on `lms-web`.

## Decisions
No new ADR in this run (`.logs/decisions.md` was left unchanged). This follows **D-52** (welcome-page password grant on confidential `lms-web`).

## Bugs found / open issues
- Only Keycloak HTTP 401 is shown as “Those credentials were not accepted.” A 400 `invalid_grant` is the unavailable alert. Keycloak 26 uses 401 for a bad password.
- A Keycloak user with no email cannot get a session. Better Auth’s user row requires one. The form shows the unavailable alert.
- `web/e2e/support.ts` still clicks “Sign in with SSO” and then Keycloak’s `#username` / `#kc-login`. Playwright will not sign in until that helper uses this form. E2E is QA-owned and was not edited.

## Next steps
- QA: point `signIn` / `signOut` in `web/e2e/support.ts` at the welcome form (`Username`, `Password`, “Sign in”) instead of the Keycloak page.
- Orchestrator: index this log in `.logs/MEMORY.md`. This run did not edit that file.
