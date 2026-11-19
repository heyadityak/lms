#!/usr/bin/env sh
# Scripted browser login through nginx (package.yml smoke, local checks): Better Auth sign-in -> Keycloak login
# form -> callback -> session cookie -> GET /bff/api/me. DEV realm users only (password "password").
# Usage: scripts/smoke-login.sh [user] [password]   env: BASE_URL (default http://localhost:8080)
set -eu
base=${BASE_URL:-http://localhost:8080}
user=${1:-alice}
pass=${2:-password}
jar=$(mktemp)
trap 'rm -f "$jar"' EXIT
fail() { echo "smoke-login: $*" >&2; exit 1; }

auth_url=$(curl -fsS -c "$jar" -b "$jar" -H "Origin: $base" -H 'Content-Type: application/json' \
  -d '{"provider":"keycloak","callbackURL":"/"}' "$base/auth/sign-in/social" \
  | sed -n 's/.*"url":"\([^"]*\)".*/\1/p')
[ -n "$auth_url" ] || fail "no authorization URL from /auth/sign-in/social"

action=$(curl -fsS -c "$jar" -b "$jar" "$auth_url" \
  | grep -o 'action="[^"]*login-actions/authenticate[^"]*"' | head -n1 \
  | sed -e 's/^action="//' -e 's/"$//' -e 's/&amp;/\&/g')
[ -n "$action" ] || fail "no Keycloak login form at $auth_url"

callback=$(curl -sS -o /dev/null -w '%{redirect_url}' -c "$jar" -b "$jar" \
  --data-urlencode "username=$user" --data-urlencode "password=$pass" "$action")
case $callback in "$base/auth/callback/keycloak"*) ;; *) fail "unexpected Keycloak redirect: $callback" ;; esac

landing=$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' -c "$jar" -b "$jar" "$callback")
[ "$landing" = "302 $base/" ] || fail "callback answered '$landing', expected '302 $base/'"

me=$(curl -fsS -b "$jar" "$base/bff/api/me") || fail "/bff/api/me failed"
echo "$me" | grep -q "\"email\":\"$user@" || fail "/bff/api/me is not $user: $me"
echo "smoke-login: $user signed in, /bff/api/me 200"
