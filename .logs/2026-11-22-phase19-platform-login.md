# 2026-11-22 — platform — phase 19: lms-web direct access grants

## Goal
Enable the resource-owner password grant on the confidential Keycloak client `lms-web` only, so the Next.js BFF can call the token endpoint. The browser must not be sent to Keycloak's login screen. No Laravel password store. No `web/**` or `docs/**` edits. No commit.

## What I did
`docker/keycloak/realm-lms.json`: `lms-web` `directAccessGrantsEnabled` `false` → `true`. `publicClient` stays `false`. `standardFlowEnabled` stays `true`.

Left alone:

- `lms-api-cli` (public) stays `directAccessGrantsEnabled: false`.
- `lms-dev-cli` (public, DEV-only smoke client) was already `true` and was not changed.
- `lms-admin` (confidential service account) stays `false`.

An existing dev Keycloak volume will NOT pick up realm import changes until the realm is re-imported. `start-dev --import-realm` imports `realm-lms.json` only when realm `lms` is absent. This compose file has no Keycloak data volume; the realm is stored in the Postgres database `keycloak`. Re-importing means deleting that realm, which deletes its users. Do not `docker compose down -v` to apply this flag.

The running dev Keycloak (`laravel-lms-rewrite-keycloak-1`, healthy) was updated in place with `kcadm.sh`. Admin login uses the container env `KC_BOOTSTRAP_ADMIN_USERNAME` / `KC_BOOTSTRAP_ADMIN_PASSWORD`, which compose sets from `KEYCLOAK_ADMIN` / `KEYCLOAK_ADMIN_PASSWORD` (see `.env.example`). The same command, for a realm that has not been updated yet:

```bash
docker compose exec -T keycloak bash -lc '
set -euo pipefail
CFG=/tmp/kcadm-lms-web.config
/opt/keycloak/bin/kcadm.sh config credentials \
  --server http://127.0.0.1:8080 \
  --realm master \
  --user "$KC_BOOTSTRAP_ADMIN_USERNAME" \
  --password "$KC_BOOTSTRAP_ADMIN_PASSWORD" \
  --config "$CFG"
CID=$(/opt/keycloak/bin/kcadm.sh get clients -r lms -q clientId=lms-web --fields id --config "$CFG" \
  | sed -n "s/.*\"id\" *: *\"\([^\"]*\)\".*/\1/p")
/opt/keycloak/bin/kcadm.sh update "clients/${CID}" -r lms -s directAccessGrantsEnabled=true --config "$CFG"
/opt/keycloak/bin/kcadm.sh get "clients/${CID}" -r lms --fields clientId,publicClient,directAccessGrantsEnabled --config "$CFG"
rm -f "$CFG"
'
```

## Commands + results
- `docker compose ps`: keycloak `Up 7 hours (healthy)`.
- `kcadm.sh get clients -r lms` before: `lms-web` `publicClient=false`, `directAccessGrantsEnabled=false`. User count 4.
- `kcadm.sh update clients/<id> -r lms -s directAccessGrantsEnabled=true`.
- After: `lms-web` `directAccessGrantsEnabled=true`, `publicClient=false`. `lms-api-cli` still `false`. `lms-dev-cli` still `true`. `lms-admin` still `false`. User count still 4.
- Resource-owner password grant as dev user `alice` against confidential `lms-web`: `ROPC_LMS_WEB=ok`. Token not printed. Temporary kcadm config files removed.

## Decisions
**D-52** — welcome page uses the resource-owner password grant on confidential `lms-web` (amends the browser redirect in D-43/D-45). Laravel still stores no passwords. Public clients were not enabled.

## Bugs / open issues
- The realm-file description for `lms-web` still says "auth code + PKCE" only. Standard flow is still on; the new grant is additional. Architect owns the docs wording.
- A fresh `docker compose up` of an empty `keycloak` database imports the updated JSON. A realm that already exists does not.

## Next steps
- Frontend: welcome page collects username and password; BFF calls the Keycloak token endpoint with `grant_type=password` and client `lms-web`. Do not redirect the browser to Keycloak's login screen.
- Architect: amend the login flow in `docs/` if the product copy should match D-52.
