#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
if [[ -e config.php || -e runtime/vnext || -e runtime/json || -e runtime/platform ]]; then
  echo "Refusing to test over WaBridge application runtime" >&2;exit 2
fi
tmp="$(mktemp -d)"
pid=""
cleanup(){
  if [[ -n "$pid" ]]; then kill "$pid" >/dev/null 2>&1 || true; wait "$pid" 2>/dev/null || true; fi
  rm -f config.php
  rm -rf runtime/vnext runtime/json runtime/platform
  rm -rf "$tmp"
}
trap cleanup EXIT
mkdir -p "$tmp/sessions"
php -r 'file_put_contents("config.php","<?php return ".var_export([
  "admin_user"=>"local-studio-operator",
  "admin_password_hash"=>password_hash("local-studio-test-password",PASSWORD_DEFAULT),
  "storage_driver"=>"json",
  "gateway_url"=>"http://127.0.0.1:3451",
  "gateway_token"=>"unused-fixture-only",
  "allowed_sessions"=>["offline-only"],
  "enable_vnext_studio_preview"=>true,
  "enable_vnext_preview_api"=>true,
  "enable_vnext_pilot_admin"=>true,
  "enable_vnext_inbox_preview"=>false,
  "enable_vnext_fixture_webhook"=>false,
  "enable_ai_drafts"=>false,
  "enable_batches"=>false,
],true).";");'
php -r 'require "src/vnext/json-store.php";require "src/vnext/demo.php";VNextDemo::seedPilot(new VNextJsonStore());'
export WABRIDGE_TEST_SESSION_PATH="$tmp/sessions"
php -d "session.save_path=$tmp/sessions" -S 127.0.0.1:18751 tests/auth-router.php >"$tmp/php.log" 2>&1 &
pid=$!
base="http://127.0.0.1:18751"
for i in {1..40}; do
  if curl -sS --connect-timeout 1 "$base/wabridge/sign-in" > /dev/null 2>&1; then break; fi
  sleep .1
done
curl -sS -c "$tmp/cookies" -b "$tmp/cookies" "$base/wabridge/sign-in" >"$tmp/login.html"
csrf="$(php -r '$s=file_get_contents($argv[1]);if(!preg_match("/name=\"_csrf\" value=\"([^\"]+)\"/",$s,$m))exit(2);echo $m[1];' "$tmp/login.html")"
curl -sS -c "$tmp/cookies" -b "$tmp/cookies" -o /dev/null \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "action=login" \
  --data-urlencode "username=local-studio-operator" \
  --data-urlencode "password=local-studio-test-password" \
  "$base/wabridge/sign-in"
pageget(){
  curl -sS -c "$tmp/cookies" -b "$tmp/cookies" "$base/wabridge/$1" >"$tmp/page.html"
  grep -q "No live message, campaign, external AI call or WhatsApp Flow publication" "$tmp/page.html"
  csrf="$(php -r '$s=file_get_contents($argv[1]);if(!preg_match("/name=\"_csrf\" value=\"([^\"]+)\"/",$s,$m))exit(2);echo $m[1];' "$tmp/page.html")"
}
post(){
  local name="$1"
  shift
  curl -sS -D "$tmp/response.h" -o /dev/null \
    -c "$tmp/cookies" -b "$tmp/cookies" \
    --data-urlencode "_csrf=$csrf" "$@" "$base/wabridge/$name"
  grep -qE '^HTTP/1.1 303' "$tmp/response.h" || {
    echo "Studio mutation did not redirect after CSRF ($name)" >&2
    cat "$tmp/response.h" >&2
    exit 1
  }
}
pageget automation-studio
post automation-studio \
  --data-urlencode 'action=create' \
  --data-urlencode 'title=Fictional Site Routing' \
  --data-urlencode 'condition=condition.sample_consent' \
  --data-urlencode 'step=action.assign_demo'
pageget automation-studio
grep -q 'Fictional Site Routing' "$tmp/page.html"

pageget campaign-manager
post campaign-manager \
  --data-urlencode 'action=create' \
  --data-urlencode 'title=Fictional Opt-in Campaign' \
  --data-urlencode 'template=sample_update' \
  --data-urlencode 'audience=sample_all'
pageget campaign-manager
grep -q 'Fictional Opt-in Campaign' "$tmp/page.html"

pageget ai-assistant
post ai-assistant \
  --data-urlencode 'action=draft' \
  --data-urlencode 'topic=support' \
  --data-urlencode 'privacy_confirmed=yes'
pageget ai-assistant
grep -q 'Suggested draft:' "$tmp/page.html"
grep -q 'Demo support policy' "$tmp/page.html"

pageget flows-studio
post flows-studio \
  --data-urlencode 'action=create' \
  --data-urlencode 'blueprint=sample_enquiry'
pageget flows-studio
grep -q 'Demo enquiry qualification' "$tmp/page.html"

php -r 'require "src/vnext/json-store.php";require "src/vnext/demo.php";
 $id=VNextDemo::accounts()[0]["id"]; $s=(new VNextJsonStore())->read($id,"studio",
 static fn(array $items):array=>$items);
 foreach(["workflows","campaigns","ai_drafts","flows"] as $k)if(count($s[$k]??[])!==1)exit(2);
 if(array_key_exists("outbox",$s)||array_key_exists("api_tokens",$s))exit(3);
 $body=file_get_contents("runtime/vnext/tenants/".$id."/studio.json");
 if(str_contains($body,"api_key")||str_contains($body,"recipient_phone"))exit(4);'

api="$base/wabridge/api/v2/preview.php?module=workflows&tenant=8aab4000-0000-4000-8000-000000000001"
anon_code="$(curl -sS -o "$tmp/anon-api.json" -w '%{http_code}' "$api")"
[[ "$anon_code" == "401" ]] || { echo 'Preview API anonymous GET must be 401' >&2;exit 1; }
api_code="$(curl -sS -c "$tmp/cookies" -b "$tmp/cookies" \
  -o "$tmp/api.json" -w '%{http_code}' "$api")"
[[ "$api_code" == "200" ]] && grep -q '"mode":"synthetic"' "$tmp/api.json" || {
  echo 'Preview API authenticated GET failed' >&2;exit 1;
}
csrf_code="$(curl -sS -c "$tmp/cookies" -b "$tmp/cookies" \
  -X POST -H 'Content-Type: application/json' \
  --data-binary '{"action":"save","title":"Should not save"}' \
  -o "$tmp/denied.json" -w '%{http_code}' "$api")"
[[ "$csrf_code" == "403" ]] || { echo 'Preview API failed to enforce CSRF' >&2;exit 1; }
json='{"action":"save","title":"API Synthetic Workflow","types":["trigger.fixture_message","action.review","action.stop"]}'
api_code="$(curl -sS -c "$tmp/cookies" -b "$tmp/cookies" \
  -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
  --data-binary "$json" -o "$tmp/api-created.json" -w '%{http_code}' "$api")"
[[ "$api_code" == "200" ]] && grep -q '"mode":"synthetic"' "$tmp/api-created.json" || {
  echo "Preview API authenticated POST failed: $api_code" >&2;exit 1;
}
php -r '$c=require "config.php";$c["enable_vnext_preview_api"]=false;
  file_put_contents("config.php","<?php return ".var_export($c,true).";");'
# New PHP process is required for a deterministic config/cache reload.
kill "$pid" >/dev/null 2>&1 || true
wait "$pid" 2>/dev/null || true
pid=""
php -d "session.save_path=$tmp/sessions" -S 127.0.0.1:18751 tests/auth-router.php >"$tmp/php-restart.log" 2>&1 &
pid=$!
for i in {1..40}; do
  if curl -sS --connect-timeout 1 "$base/wabridge/sign-in" > /dev/null 2>&1; then break; fi
  sleep .1
done
off_code="$(curl -sS -c "$tmp/cookies" -b "$tmp/cookies" \
  -o /dev/null -w '%{http_code}' "$api")"
[[ "$off_code" == "404" ]] || { echo 'Preview API must remain default-off' >&2;exit 1; }
echo 'W2-W6 preview API HTTP: session, CSRF, JSON workflow, and feature flag PASS'

echo 'W4-W6 authenticated HTTP creation: CSRF, private JSON, four modules and no sends PASS'
