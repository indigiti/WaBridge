#!/usr/bin/env bash
# Isolated Supervisor entrypoint tests using a mock Rust executable.
set -Eeuo pipefail
root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
unset DATABASE_URL || true

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

# Absent DB configuration must fail closed.
if /bin/bash "$tmp/cli/waxum-supervisor.sh" >"$tmp/failure.log" 2>&1; then
    echo 'FAIL: Supervisor allowed implicit database' >&2
    exit 1
fi
grep -q 'DATABASE_URL missing' "$tmp/failure.log"

# A managed MySQL database URL provided by Supervisor remains supported.
DATABASE_URL='mysql://mock:mock@127.0.0.1/test' /bin/bash "$tmp/cli/waxum-supervisor.sh" >"$tmp/mysql-out.log" 2>&1
grep -q '^SUPERVISED_MOCK_OK$' "$tmp/mysql-out.log"
[[ -d "$tmp/runtime/waxum-data/whatsapp_sessions" ]]
[[ $(stat -c '%a' "$tmp/runtime/waxum-data/whatsapp_sessions") == 700 ]]

# Explicit private opt-in is required for Rust SQLite mode.
if DATABASE_URL="sqlite://$tmp/runtime/waxum-data/waxum.db" /bin/bash "$tmp/cli/waxum-supervisor.sh" >"$tmp/no-optin.log" 2>&1; then
    echo 'FAIL: Rust SQLite started without a private opt-in file' >&2
    exit 1
fi
grep -q 'was not explicitly enabled' "$tmp/no-optin.log"
printf 'DATABASE_URL=sqlite://%s/runtime/waxum-data/waxum.db\n' "$tmp" > "$tmp/runtime/waxum-provision/waxum-db.env"
chmod 600 "$tmp/runtime/waxum-provision/waxum-db.env"
env -u DATABASE_URL /bin/bash "$tmp/cli/waxum-supervisor.sh" >"$tmp/sqlite-out.log" 2>&1
grep -q '^SUPERVISED_MOCK_OK$' "$tmp/sqlite-out.log"

# Reject SQLite database outside the approved private directory.
printf 'DATABASE_URL=sqlite:///tmp/unsafe.db\n' > "$tmp/runtime/waxum-provision/waxum-db.env"
if env -u DATABASE_URL /bin/bash "$tmp/cli/waxum-supervisor.sh" >"$tmp/wrong-db.log" 2>&1; then
    echo 'FAIL: Rust SQLite allowed an unexpected database path' >&2
    exit 1
fi
grep -q 'metadata path must be private and fixed' "$tmp/wrong-db.log"

if grep -q 'aaaaaaaaaaaaaaaa' "$tmp/mysql-out.log" "$tmp/sqlite-out.log"; then
    echo 'FAIL: Supervisor logs leaked superadmin token' >&2
    exit 1
fi
echo 'Cloudways Supervisor: missing DB denied, managed MySQL supported, opt-in SQLite supported, path locked, secret not logged: PASS'
