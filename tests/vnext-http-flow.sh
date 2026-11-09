#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
if [[ -e config.php || -e runtime/vnext ]]; then
  echo "Refusing HTTP integration test over existing private state" >&2
  exit 2
fi
tmp="$(mktemp -d)"
pid=""
cleanup(){
  if [[ -n "$pid" ]]; then kill "$pid" >/dev/null 2>&1 || true; wait "$pid" 2>/dev/null || true; fi
  rm -f config.php
  rm -rf runtime/vnext
  rm -rf "$tmp"
}
trap cleanup EXIT
php -r 'file_put_contents("config.php","<?php return ".var_export([
  "admin_user"=>"offline-fixture",
  "admin_password_hash"=>"no-login",
  "storage_driver"=>"json",
  "enable_vnext_fixture_webhook"=>true,
  "vnext_fixture_webhook_secret"=>"isolated-hmac-testing-secret-only-not-live-0123456789"
],true).";");'
php -r 'require "src/vnext/json-store.php";require "src/vnext/demo.php";VNextDemo::seedPilot(new VNextJsonStore());'
php -S 127.0.0.1:18749 tests/vnext-http-router.php >"$tmp/server.log" 2>&1 &
pid=$!
base="http://127.0.0.1:18749/wabridge/api/v2/fixture-event.php"
for i in {1..30}; do
  if curl -sS --connect-timeout 1 "$base" > /dev/null 2>&1; then break; fi
  sleep .1
done
code="$(curl -sS -o "$tmp/get.json" -w '%{http_code}' "$base")"
[[ "$code" == "405" ]] || { echo "Expected 405 for enabled GET: $code" >&2;exit 1; }
timestamp="$(date +%s)"
raw="$(printf '{"provider":"fixture","external_channel_id":"demo_fixture_01","type":"message.inbound","provider_event_id":"fixture-http-event-001","occurred_at":%s,"body":"NEVER_PERSIST_THIS_MESSAGE","tenant_id":"untrusted"}' "$timestamp")"
sign(){
  php -r '$s="isolated-hmac-testing-secret-only-not-live-0123456789";echo "sha256=".hash_hmac("sha256",$argv[1].".".$argv[2],$s);' "$timestamp" "$1"
}
send(){
  curl -sS -o "$tmp/result.json" -w '%{http_code}' -X POST \
    -H 'Content-Type: application/json' \
    -H 'X-Webhook-Signature-Version: v2' \
    -H "X-Webhook-Timestamp: $timestamp" \
    -H "X-Webhook-Signature: $(sign "$1")" \
    --data-binary "$1" "$base"
}
code="$(send "$raw")"
[[ "$code" == "202" ]] && grep -q '"duplicate":false' "$tmp/result.json" || {
  echo "Signed synthetic event rejected: $code" >&2;exit 1;
}
code="$(send "$raw")"
[[ "$code" == "202" ]] && grep -q '"duplicate":true' "$tmp/result.json" || {
  echo "Replayed event was not deduplicated" >&2;exit 1;
}
code="$(curl -sS -o "$tmp/bad.json" -w '%{http_code}' -X POST \
  -H 'Content-Type: application/json' -H 'X-Webhook-Signature-Version: v2' \
  -H "X-Webhook-Timestamp: $timestamp" \
  -H "X-Webhook-Signature: sha256=$(printf '0%.0s' {1..64})" \
  --data-binary "$raw" "$base")"
[[ "$code" == "401" ]] || { echo "Forged HMAC must return 401" >&2;exit 1; }
unknown="$(printf '%s' "$raw" | sed 's/demo_fixture_01/unknown_fixture_999/g')"
code="$(send "$unknown")"
[[ "$code" == "401" ]] || { echo "Unknown channel must return 401" >&2;exit 1; }
php -r 'require "src/vnext/json-store.php";require "src/vnext/events.php";require "src/vnext/demo.php";
  $id=VNextDemo::accounts()[0]["id"];
  $journal=new VNextEventJournal(new VNextJsonStore());
  if($journal->count($id)!==1)exit(2);
  $path="runtime/vnext/tenants/".$id."/events.json";
  if(str_contains((string)file_get_contents($path),"NEVER_PERSIST_THIS_MESSAGE"))exit(3);'
php -r '$c=require "config.php";$c["enable_vnext_fixture_webhook"]=false;
  file_put_contents("config.php","<?php return ".var_export($c,true).";");'
code="$(send "$raw")"
[[ "$code" == "404" ]] || { echo "Disabled fixture endpoint must return 404" >&2;exit 1; }
echo 'W1 synthetic webhook HTTP: signed, duplicate, forged, unknown-channel, no-body and default-off gates PASS'
