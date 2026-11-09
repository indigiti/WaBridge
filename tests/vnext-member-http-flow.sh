#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
if [[ -e config.php || -e runtime/vnext || -e runtime/json || -e runtime/platform ]]; then
  echo "Refusing member HTTP test over existing application data" >&2; exit 2
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
  "admin_user"=>"admin-only-test",
  "admin_password_hash"=>password_hash("isolated-admin-test-pass",PASSWORD_DEFAULT),
  "storage_driver"=>"json",
  "enable_vnext_tenant_login"=>true,
  "enable_vnext_pilot_admin"=>false,
  "enable_vnext_studio_preview"=>false,
  "enable_vnext_preview_api"=>false,
  "enable_vnext_fixture_webhook"=>false,
  "enable_ai_drafts"=>false,
  "gateway_token"=>"",
],true).";");'
php -r '
require "src/vnext/member-policy.php";
require "src/vnext/invitations.php";
require "src/vnext/demo.php";
$s=new VNextJsonStore();
VNextDemo::seedPilot($s);
$a=VNextDemo::accounts()[0]["id"];
$u="aa550000-0000-4000-8000-000000000001";
$c="bb660000-0000-4000-8000-000000000001";
(new VNextMemberPolicy($s))->grantFixtureChannel($a,$u,$c);
$i=(new VNextInvitations($s))->issue($a,$u);
(new VNextInvitations($s))->accept($i["token"],"Offline Member Test Password 123!");
$s->mutate("system","identity",static function(array &$items)use($a,$u):void{
  $items["organizations"][$a]["status"]="active";
  $items["memberships"][$a.":".$u]["status"]="active";
});'
php -d "session.save_path=$tmp/sessions" -S 127.0.0.1:18753 tests/auth-router.php >"$tmp/server.log" 2>&1 &
pid=$!
base="http://127.0.0.1:18753"
for i in {1..40}; do
  if curl -sS --connect-timeout 1 "$base/wabridge/tenant-sign-in" >/dev/null 2>&1; then break; fi
  sleep .1
done
login="$base/wabridge/tenant-sign-in"
workspace="$base/wabridge/tenant-workspace"
curl -sS -c "$tmp/cookies" -b "$tmp/cookies" -o "$tmp/page.html" "$login"
csrf="$(php -r '$s=file_get_contents($argv[1]);if(!preg_match("/name=\"_csrf\" value=\"([^\"]+)\"/",$s,$m))exit(2);echo $m[1];' "$tmp/page.html")"
[[ -n "$csrf" ]] || { echo 'No CSRF on tenant login' >&2; exit 1; }
anon="$(curl -sS -D "$tmp/anon.h" -o /dev/null -w '%{http_code}' "$workspace")"
[[ "$anon" == "302" ]] || { echo 'Anonymous tenant portal not redirected' >&2;exit 1; }
response="$(curl -sS -c "$tmp/cookies" -b "$tmp/cookies" -D "$tmp/login.h" \
  -o /dev/null -w '%{http_code}' \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode 'action=login' \
  --data-urlencode 'tenant_id=8aab4000-0000-4000-8000-000000000001' \
  --data-urlencode 'email=demo-user-01@example.invalid' \
  --data-urlencode 'password=Offline Member Test Password 123!' "$login")"
[[ "$response" == "303" ]] || { echo "Member login did not succeed ($response)" >&2;exit 1; }
curl -sS -c "$tmp/cookies" -b "$tmp/cookies" "$workspace" >"$tmp/workspace.html"
grep -q 'Demo · Acme Admissions' "$tmp/workspace.html"
grep -q 'bb660000-0000-4000-8000-000000000001' "$tmp/workspace.html"
if grep -q 'Demo · Greenfield Retail' "$tmp/workspace.html"; then
  echo "Cross-tenant display violation" >&2;exit 1
fi
legacy="$(curl -sS -c "$tmp/cookies" -b "$tmp/cookies" -D "$tmp/admin.h" \
  -o /dev/null -w '%{http_code}' "$base/wabridge/account-admin")"
[[ "$legacy" == "302" ]] || { echo 'Tenant member entered admin console' >&2;exit 1; }

# Suspend the membership from a separate trusted local process.
php -r '$s=new VNextJsonStore();' >/dev/null 2>&1 && {
  echo "Unexpected class autoload" >&2;exit 1;
} || true
php -r 'require "src/vnext/json-store.php";
$s=new VNextJsonStore();
$a="8aab4000-0000-4000-8000-000000000001";
$u="aa550000-0000-4000-8000-000000000001";
$s->mutate("system","identity",static function(array &$items)use($a,$u):void{
$items["memberships"][$a.":".$u]["status"]="suspended";});'
revoked="$(curl -sS -c "$tmp/cookies" -b "$tmp/cookies" -D "$tmp/revoked.h" \
  -o /dev/null -w '%{http_code}' "$workspace")"
[[ "$revoked" == "302" ]] || { echo "Revoked member retained portal access" >&2;exit 1; }
echo "W2 HTTP member login: CSRF, cookie isolation, channel scope, legacy admin denial and revocation PASS"
