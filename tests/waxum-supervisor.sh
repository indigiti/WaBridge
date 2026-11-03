#!/usr/bin/env bash
# Self-contained Supervisor entrypoint test with a mock executable.
set -Eeuo pipefail
root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT

mkdir -p "$tmp/cli" "$tmp/runtime/waxum-provision"
cp "$root/cli/waxum-supervisor.sh" "$tmp/cli/waxum-supervisor.sh"
cat > "$tmp/runtime/waxum-provision/waxum" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "$PORT" == 3451 ]] || exit 90
[[ "$WHATSAPP_STORAGE_PATH" == "$PWD/whatsapp_sessions" ]] || exit 91
case "$DATABASE_URL" in
    'mysql://mock:mock@127.0.0.1/test'|"sqlite://$PWD/waxum.db") ;;
    *) exit 92 ;;
esac
[[ "$SUPERADMIN_TOKEN" == 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ]] || exit 93
[[ "$JWT_SECRET" == 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ]] || exit 94
printf '%s\n' 'SUPERVISED_MOCK_OK'
EOF
chmod 700 "$tmp/runtime/waxum-provision/waxum"
cat > "$tmp/runtime/waxum-provision/waxum-auth.env" <<'EOF'
SUPERADMIN_TOKEN=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
JWT_SECRET=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
PORT=3451
EOF
chmod 600 "$tmp/runtime/waxum-provision/waxum-auth.env"
if /bin/bash "$tmp/cli/waxum-supervisor.sh" >"$tmp/failure.log" 2>&1; then
    echo 'FAIL: Supervisor allowed implicit SQLite/default database' >&2
    exit 1
fi
grep -q 'DATABASE_URL missing' "$tmp/failure.log" || {
    echo 'FAIL: startup failure did not indicate missing database' >&2
    exit 1
}
# Supervisor may provide the DB URL as an environment variable.
DATABASE_URL='mysql://mock:mock@127.0.0.1/test' /bin/bash "$tmp/cli/waxum-supervisor.sh" >"$tmp/out.log" 2>&1
grep -q '^SUPERVISED_MOCK_OK$' "$tmp/out.log"
[[ -d "$tmp/runtime/waxum-data/whatsapp_sessions" ]]
[[ $(stat -c '%a' "$tmp/runtime/waxum-data/whatsapp_sessions") == 700 ]]
# Explicit test mode must be selected via a private file, not implicit default.
if DATABASE_URL="sqlite://$tmp/runtime/waxum-data/waxum.db" /bin/bash "$tmp/cli/waxum-supervisor.sh" >"$tmp/no-optin.log" 2>&1; then
    echo 'FAIL: Rust SQLite started without a private opt-in file' >&2
    exit 1
fi
grep -q 'was not explicitly enabled' "$tmp/no-optin.log" || exit 1
printf 'DATABASE_URL=sqlite://%s/runtime/waxum-data/waxum.db\n' "$tmp" > "$tmp/runtime/waxum-provision/waxum-db.env"
chmod 600 "$tmp/runtime/waxum-provision/waxum-db.env"
env -u DATABASE_URL /bin/bash "$tmp/cli/waxum-supervisor.sh" >"$tmp/sqlite-out.log" 2>&1
grep -q '^SUPERVISED_MOCK_OK
    echo 'FAIL: the supervisor revealed a token' >&2
    exit 1
fi
echo 'Cloudways Supervisor startup script: fail-closed DB check, scoped environment, private storage, exec OK'
 "$tmp/sqlite-out.log"
if grep -q 'aaaaaaaaaaaaaaaa' "$tmp/out.log"; then
    echo 'FAIL: the supervisor revealed a token' >&2
    exit 1
fi
echo 'Cloudways Supervisor startup script: fail-closed DB check, scoped environment, private storage, exec OK'
