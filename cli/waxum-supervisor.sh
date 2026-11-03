#!/usr/bin/env bash
# Trusted Cloudways Supervisor entrypoint, deployed ONLY under private_html.
# Launch this from Supervisor (never from an HTTP request).
set -Eeuo pipefail
umask 077

die() { printf '%s\n' "Waxum startup blocked: $1" >&2; exit 78; }

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)" || die "cannot resolve private CLI directory"
APP_DIR="$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)" || die "cannot resolve private application directory"

BINARY="$APP_DIR/runtime/waxum-provision/waxum"
AUTH_ENV="$APP_DIR/runtime/waxum-provision/waxum-auth.env"
DB_ENV="$APP_DIR/runtime/waxum-provision/waxum-db.env"
DATA_DIR="$APP_DIR/runtime/waxum-data"

[[ -f "$BINARY" && -x "$BINARY" && ! -L "$BINARY" ]] || die "verified executable missing or not executable"
[[ -f "$AUTH_ENV" && -r "$AUTH_ENV" && ! -L "$AUTH_ENV" ]] || die "private authentication environment missing or unreadable"
[[ ! -L "$DB_ENV" ]] || die "database environment symlink prohibited"

# Private files must remain restricted to the application service user.
# Never echo tokens or full database URLs to Supervisor logs.
set -a
# shellcheck disable=SC1090
source "$AUTH_ENV"
if [[ -f "$DB_ENV" ]]; then
    [[ -r "$DB_ENV" ]] || die "database environment unreadable"
    # shellcheck disable=SC1090
    source "$DB_ENV"
fi
set +a

[[ -v SUPERADMIN_TOKEN && -n "$SUPERADMIN_TOKEN" ]] || die "SUPERADMIN_TOKEN missing"
[[ -v JWT_SECRET && -n "$JWT_SECRET" ]] || die "JWT_SECRET missing"
[[ -v DATABASE_URL && -n "$DATABASE_URL" ]] || die "DATABASE_URL missing: configure managed MariaDB/PostgreSQL first"

# Do not silently fall back to SQLite metadata on this Cloudways test server.
case "$DATABASE_URL" in
    mysql://*|postgres://*) ;;
    *) die "DATABASE_URL must use mysql:// or postgres:// (not SQLite)" ;;
esac

[[ ! -L "$DATA_DIR" ]] || die "private WhatsApp storage symlink prohibited"
mkdir -p -- "$DATA_DIR/whatsapp_sessions" || die "cannot create private WhatsApp storage"
chmod 700 -- "$DATA_DIR" "$DATA_DIR/whatsapp_sessions" || die "cannot protect WhatsApp storage"
export WHATSAPP_STORAGE_PATH="$DATA_DIR/whatsapp_sessions"
export PORT=3451
export RUST_LOG=waxum=info,tower_http=info
export WA_RS_BLOCKING_THREADS=32
cd -- "$DATA_DIR" || die "cannot access private working directory"

# SECURITY: upstream v0.16.1 binds to 0.0.0.0:3451. Cloudways MUST
# restrict inbound public traffic before enabling the Supervisor job.
exec "$BINARY"
