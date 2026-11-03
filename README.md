# WaBridge

WaBridge is a PHP control plane for a separately deployed Rust Waxum WhatsApp gateway. It includes authenticated dashboard, QR pairing proxy, versioned messaging API, scoped API credentials, durable queue, webhook processing, and reporting.

No Node.js is required. **Low-volume JSON testing storage is now available without SQLite or another database server on the PHP host.**

## Storage

- New single-server test installs: private bounded JSON state with file locking, atomic writes and secure file permissions
- Existing WaBridge SQLite deployments: remain on SQLite until the administrator explicitly migrates from the new web-based Storage Settings page
- Production target: MariaDB, schema contract documented but driver not yet implemented
- The Rust Waxum service has **separate** storage requirements, which are unaffected by WaBridge's PHP storage selection

Read [JSON storage and browser migration](docs/JSON-STORAGE.md) and [DigiOps deployment](docs/DIGIOPS.md).

## Browser-only administration

After deploying through DigiOps, sign in at `/wabridge/` (or `/wabridge/sign-in`), then use the collapsible sidebar. All human-facing routes share the same glass dashboard shell:

| Area | Browser-friendly URL |
| --- | --- |
| Overview | `/wabridge/` |
| Connect a device | `/wabridge/devices` |
| Linked sessions | `/wabridge/sessions` |
| Send message | `/wabridge/messaging` |
| Message activity, manual queue testing | `/wabridge/messages` |
| Reports (HTML) | `/wabridge/reports` |
| Diagnostics (HTML) | `/wabridge/diagnostics` |
| API applications | `/wabridge/api-applications` |
| Rust gateway settings | `/wabridge/gateway` |
| PHP storage settings | `/wabridge/storage` |
| QR pairing | `/wabridge/qr?sid=YOUR_SESSION_ID` |
| JSON diagnostic report | `/wabridge/api/diagnostics` |
| JSON operational reports | `/wabridge/api/reports` |

Cloudways: these routes require Apache `mod_rewrite` and `.htaccess` support under the deployed `public_html/wabridge/` directory. If the hosting reverse proxy bypasses Apache's rewrite rules, configure equivalent routes at the proxy. Explicit rewrite rules protect existing `/wabridge/api/v1/*` endpoints. Old HTML `.php` bookmarks redirect to pretty paths for GET/HEAD only; POST and CSRF handling remain in the original controllers. **The legacy `diagnostics.php` and `reports.php` URLs remain authenticated JSON for backward compatibility**, while the new routes return visual HTML reports. Sign-out uses a CSRF-validated POST to `/wabridge/sign-out` — never a logout-on-GET link.

See [DigiOps deployment](docs/DIGIOPS.md). No live Cloudways route verification is implied by a successful GitHub build.

A running supervised Rust gateway is still necessary for actual QR pairing and message delivery. A PHP webpage cannot safely install or supervise a daemon on Cloudways without provider support.

## Optimization and acceptance (P0–P5)

- **Cloudways package optimization:** the deployment bundle includes only the pinned glibc 2.31-compatible Waxum v0.16.1 archive. The older incompatible upstream archive is no longer shipped.
- **Premium front end:** a deterministic single CSS bundle with locally hosted, SIL OFL-licensed Inter fonts and accessibility-conscious glass effects (no Node.js).
- **Monitoring:** the authenticated /wabridge/performance page measures Rust RSS, peak RSS, threads, short-interval CPU and bounded private disk usage when readable from PHP.
- **Verification:** /wabridge/verification distinguishes local API readiness from actual QR pairing, gateway acceptance, matching delivery receipts and session persistence after a Supervisor restart.
- **Rust Lite:** a separate experimental GitHub workflow attempts a smaller build. This is intentionally NOT selected by DigiOps or installed on Cloudways; it requires successful compilation and real-device parity verification.

Full acceptance and rollback instructions: [P0–P5 optimization runbook](docs/OPTIMIZATION-P0-P5.md).

## Requirements

PHP 8.2+, cURL, mbstring, JSON, session support and filesystem locks. PDO SQLite is optional for the JSON testing mode, but required for migrating existing SQLite data. HTTPS and a public/ document root are required. Keep config.php and runtime/ outside public_html.

## Release status

Integration preview. Automated tests cover JSON storage, legacy SQLite, migration safeguards, API keys and queue state. Live Rust pairing, message receipts and persistent process supervision require real-host validation. Do not treat synthetic SQLite benchmarks as WhatsApp throughput measurements.
