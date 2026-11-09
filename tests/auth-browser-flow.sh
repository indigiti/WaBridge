#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
if [[ -e config.php || -e runtime/json/state.json || -e runtime/platform/state.json || -e runtime/vnext ]]; then
  echo "Refusing to modify a running/private WaBridge installation" >&2
  exit 2
fi
tmp="$(mktemp -d)"
server_pid=""
cleanup(){
  if [[ -n "$server_pid" ]]; then kill "$server_pid" >/dev/null 2>&1 || true; wait "$server_pid" 2>/dev/null || true; fi
  rm -f config.php runtime/json/state.json runtime/json/state.lock \
    runtime/platform/state.json runtime/platform/state.lock
  rmdir runtime/json runtime/platform 2>/dev/null || true
  rm -rf runtime/vnext
  rm -rf "$tmp"
}
trap cleanup EXIT
mkdir -p "$tmp/sessions"
php -r 'file_put_contents("config.php","<?php return ".var_export([
    "admin_user"=>"fixture-operator",
    "admin_password_hash"=>password_hash("fixture-password",PASSWORD_DEFAULT),
    "gateway_url"=>"http://127.0.0.1:3451",
    "gateway_token"=>"fixture-only",
    "storage_driver"=>"json",
    "allowed_sessions"=>["fixture-session"],
],true).";");'
export WABRIDGE_TEST_SESSION_PATH="$tmp/sessions"
# The router injects no credentials and never talks to a real provider.
php -d "session.save_path=$tmp/sessions" -S 127.0.0.1:18747 tests/auth-router.php >"$tmp/php.log" 2>&1 &
server_pid=$!
for n in {1..30}; do
  if curl -sS --connect-timeout 1 http://127.0.0.1:18747/wabridge/sign-in >/dev/null 2>&1; then break; fi
  sleep .1
done
base="http://127.0.0.1:18747"
curl -sS -D "$tmp/get.h" -o /dev/null -c "$tmp/cookies" -b "$tmp/cookies" \
  "$base/wabridge/media?utm_source=chatgpt.com"
grep -qE '^HTTP/1.1 302' "$tmp/get.h"
grep -qiF 'Location: /wabridge/sign-in?return=%2Fwabridge%2Fmedia' "$tmp/get.h"
curl -sS -D "$tmp/post.h" -o /dev/null -c "$tmp/cookies-post" -b "$tmp/cookies-post" \
  -X POST -d 'action=media_send' "$base/wabridge/media"
grep -qE '^HTTP/1.1 303' "$tmp/post.h"
grep -qiF 'reason=expired' "$tmp/post.h"
curl -sS -c "$tmp/cookies" -b "$tmp/cookies" \
  "$base/wabridge/sign-in?return=%2Fwabridge%2Fmedia" > "$tmp/signin.html"
grep -q 'class="auth-card"' "$tmp/signin.html"
grep -q 'continue to Media messages' "$tmp/signin.html"
token="$(php -r '$s=file_get_contents($argv[1]);if(!preg_match("/name=\"_csrf\" value=\"([^\"]+)\"/",$s,$m))exit(2);echo $m[1];' "$tmp/signin.html")"
curl -sS -D "$tmp/login.h" -o /dev/null -c "$tmp/cookies" -b "$tmp/cookies" \
  --data-urlencode "_csrf=$token" \
  --data-urlencode 'action=login' \
  --data-urlencode 'username=fixture-operator' \
  --data-urlencode 'password=fixture-password' \
  --data-urlencode 'return=/wabridge/media' \
  "$base/wabridge/sign-in"
grep -qE '^HTTP/1.1 303' "$tmp/login.h"
grep -qiE '^Location: /wabridge/media' "$tmp/login.h"
curl -sS -c "$tmp/cookies" -b "$tmp/cookies" "$base/wabridge/media" > "$tmp/media.html"
grep -q 'Send one media message' "$tmp/media.html"
grep -q 'platform-context' "$tmp/media.html"
if grep -q 'Sign in to WaBridge first' "$tmp/media.html"; then
  echo "Media route still renders plain 403" >&2; exit 1
fi

curl -sS -D "$tmp/account-anon.h" -o /dev/null \
  "$base/wabridge/account-admin"
grep -qE '^HTTP/1.1 302' "$tmp/account-anon.h"
grep -qiF 'Location: /wabridge/sign-in?return=%2Fwabridge%2Faccount-admin' "$tmp/account-anon.h"
curl -sS -c "$tmp/cookies" -b "$tmp/cookies" "$base/wabridge/account-admin" > "$tmp/account.html"
grep -q 'Multi-account control center' "$tmp/account.html"
grep -q 'Demo · Acme Admissions' "$tmp/account.html"
grep -q 'Simulation only' "$tmp/account.html"
if grep -q 'name="action" value="create_organization"' "$tmp/account.html"; then
  echo "Provisioning form must be disabled by default" >&2; exit 1
fi
echo 'Anonymous account deep link → styled sign-in; authenticated synthetic read-only preview: PASS'
echo 'Anonymous Media deep link → styled sign-in → authenticated Media: PASS'
