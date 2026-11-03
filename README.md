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

After deploying through DigiOps, sign in to the application and open:
- /wabridge/storage-settings.php — choose JSON testing mode and safely migrate existing test data
- /wabridge/gateway-settings.php — configure same-server Rust endpoint token
- /wabridge/diagnostics.php — verify PHP, storage and Rust readiness

A running supervised Rust gateway is still necessary for actual QR pairing and message delivery. A PHP webpage cannot safely install or supervise a daemon on Cloudways without provider support.

## Requirements

PHP 8.2+, cURL, mbstring, JSON, session support and filesystem locks. PDO SQLite is optional for the JSON testing mode, but required for migrating existing SQLite data. HTTPS and a public/ document root are required. Keep config.php and runtime/ outside public_html.

## Release status

Integration preview. Automated tests cover JSON storage, legacy SQLite, migration safeguards, API keys and queue state. Live Rust pairing, message receipts and persistent process supervision require real-host validation. Do not treat synthetic SQLite benchmarks as WhatsApp throughput measurements.
