# Waxum Rust — web-managed provisioning on Cloudways

## Browser-only capabilities now implemented

Once DigiOps deploys the new WaBridge release, sign in to:

https://stage.digiti.in/wabridge/gateway-settings.php

1. **Generate SUPERADMIN_TOKEN** (only when no active/previous gateway token is configured).
   PHP uses `random_bytes(32)`, displays the token one time, and saves the same token to the existing private WaBridge gateway config and `private_html/wabridge/runtime/waxum-provision/waxum-auth.env`.
2. **Prepare executable**: DigiOps packages a pinned official Waxum **v0.16.1 Linux AMD64** tar.gz only in private_html. The browser management action verifies its SHA-256 checksum and extracts only the predetermined ELF executable to `private_html/wabridge/runtime/waxum-provision/waxum` with 0700 file permissions, if PHP PharData is available.
3. **View diagnostics**: the current Rust liveness/readiness and WhatsApp session count are reported separately. A saved token and staged executable **do not mean the Rust process is running**.

We neither execute arbitrary shell code nor create unmanaged detached PHP-child processes. We do not open a public process-management API. The executable is not accessible from the website's public_html directory.

## What Cloudways must confirm before launch

Cloudways' documented Supervisord UI covers Laravel worker use cases. It is **not** proof that generic long-lived Rust binaries can be installed or supervised. Use Cloudways browser-based Support Chat to request approved provisioning and lifecycle management.

Give the provider the paths to:
- Fixed Rust binary: `private_html/wabridge/runtime/waxum-provision/waxum`
- Secure environment file: `private_html/wabridge/runtime/waxum-provision/waxum-auth.env`
- Loopback HTTP API to WaBridge: `http://127.0.0.1:3451`

The environment file provides `SUPERADMIN_TOKEN`, `JWT_SECRET` and `PORT`. It **does not set DATABASE_URL** and is not a complete service configuration. Waxum still needs persistent WhatsApp session storage and a verified backend that your hosting plan permits. WaBridge JSON settings do NOT remove Waxum's own SQLite/session dependencies.

Waxum v0.16.1's upstream HTTP listener currently binds all interfaces at `0.0.0.0:3451`. Do **not** start the binary while inbound port 3451 is open to the internet. Cloudways must block that port from external access before service activation or provide an equivalent provider-approved private-listener solution. Never expose a superadmin-controlled WhatsApp API publicly.

## Explicitly unsupported

- Clicking Run/Start/Stop from PHP without an installed, restricted, provider-approved process manager
- Starting a server daemon from an incoming public HTTP request with `exec`, `shell_exec`, `nohup` or `proc_open` as a persistence workaround
- Attempting to use the generated token as proof of an active WhatsApp session
- Blunt token rotation while Waxum is active; coordinate token changes with the service owner
- Claiming WhatsApp QR pairing is ready until the Rust process, storage, and private network are independently verified

## Verification sequence

1. Dashboard says token prepared, archive verified, binary staged.
2. Cloudways confirms the executable can run as a supervised custom process and private network access is enforced.
3. Provider sets any missing supported Waxum storage configuration and starts the process.
4. WaBridge Diagnostics changes from `not_configured` / `unreachable` to `ready`.
5. Authenticated API session enumeration succeeds; only then create a test device and scan QR.
6. Verify recovery after a planned Rust process restart and a server restart.

Pinned source: https://github.com/imtaqin/waxum/releases/tag/v0.16.1


## Supervisor command for Cloudways Support (after they approve private port controls)

WaBridge now deploys a dedicated private startup script at:

`private_html/wabridge/cli/waxum-supervisor.sh`

Its precise **absolute** path appears in the authenticated WaBridge **Gateway Settings → Hosting** screen. Give Cloudways Support the command exactly as shown there. The generic command is:

```sh
/bin/bash /ABSOLUTE_CLOUDWAYS_APP_ROOT/private_html/wabridge/cli/waxum-supervisor.sh
```

This script:
- loads the existing secret from the private `runtime/waxum-provision/waxum-auth.env` file;
- requires `DATABASE_URL` from the Supervisor environment or the optional private `runtime/waxum-provision/waxum-db.env`;
- refuses to start if the database is missing or not `mysql://` or `postgres://`, preventing an unexpected default SQLite metadata database;
- creates writable private WhatsApp state storage in `runtime/waxum-data/whatsapp_sessions` and exports `WHATSAPP_STORAGE_PATH`;
- changes to a persistent working directory and uses `exec` so Supervisord monitors the actual Rust process;
- does not log the token or full database URI.

**Cloudways Support action:** Configure this exact command, run as the Cloudways application owner with read access to the 0600 secret file, enable auto-restart on failure and reboot, and keep Supervisor logs private. Configure the managed MariaDB `DATABASE_URL` through a private provider-controlled secret/environment mechanism. Never share the value through chat.

**Hard stop before startup:** The upstream Waxum v0.16.1 binary listens on `0.0.0.0:3451`. Even if PHP connects to `127.0.0.1:3451`, the binary is publicly exposed unless Cloudways blocks port 3451 from all public inbound traffic. Confirm firewall behavior first.

**Separate backend caveat:** Even when Waxum metadata uses MariaDB, upstream linked-device storage can still use SQLite internally. Do not claim strict no-SQLite compliance until the upstream session-storage implementation is audited or replaced.
