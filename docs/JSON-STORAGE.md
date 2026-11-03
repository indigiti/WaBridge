# WaBridge JSON-first storage on Cloudways (test mode)

## What this changes

WaBridge PHP can now operate without PDO SQLite or an external database server in a new single-server testing installation. API keys, quotas, idempotent message jobs, receipts, webhook events/deliveries and audit metadata are handled through a bounded private JSON state file with file locks and atomic rename.

The existing SQLite path remains available for legacy deployments, but an existing database is never deleted or silently overwritten. The browser-only migration screen is an explicit administrator action.

## Browser-only activation

1. Deploy a successful DigiOps artifact from the WaBridge main branch.
2. Sign in at https://stage.digiti.in/wabridge/.
3. Open Storage settings in the dashboard navigation, or /wabridge/storage-settings.php.
4. If JSON already appears as the active driver, no migration is necessary.
5. If SQLite is active, read the counts and confirmation notice, pause send/webhook workers using the Cloudways browser UI, then click Migrate to JSON testing. Migration refuses to proceed if queued or processing messages or outbound webhooks exist.
6. Open /wabridge/diagnostics.php and verify storage.driver = json and storage.ready = true.
7. Configure cron workers in Cloudways web-based Cron Job Management if you need asynchronous processing. Do not launch daemons from public web pages.

The browser migration copies API-client hashes, quotas, historical jobs and receipts, webhook data, and audit records. The original runtime/wabridge.sqlite file is retained privately. One-click rollback is intentionally not offered: divergent stores can lead to duplicate messages.

If PHP cannot read the old SQLite file, keep old data untouched until it can be migrated safely.

## Private JSON file

Location: private_html/wabridge/runtime/json/state.json. Parent directory permissions 0700, state file 0600. No copy is included in public_html or DigiOps release artifacts. A separate state.lock file serializes mutations; writes use temporary 0600 files and atomic rename.

The JSON state includes message text and webhook payloads in plaintext. Use encrypted disk and private backups. Do not upload this state file to GitHub, email, or chat.

## Boundaries and limits

- Single Cloudways server, small testing workloads only.
- JSON state hard maximum 16 MiB.
- At most 100 API clients and 4,000 total message-job records.
- At most 1,000 queued/processing messages per client.
- At most 500 webhook events, 1,000 event deliveries and 500 recent audit records.
- Terminal message retention: 14 days; webhook events: 7 days.
- No multi-server production service guarantee. Whole-file writes make JSON unsuitable for busy messaging queues.
- WaBridge PHP test mode does not require Node.js, Redis, MariaDB, PostgreSQL or PDO SQLite.
- Rust Waxum has its own WhatsApp session persistence; JSON mode does not replace it.

## Production database plan

MariaDB is the transactional production target. contracts/sql/mariadb-target.sql defines an initial schema but is not an enabled production driver. The driver, migrations, concurrency and recovery certification are future work. Do not configure storage_driver = mariadb yet.

## Validation

- tests/json-store.php checks JSON key scope, quotas, locks, idempotency, queue claims, receipts, events and immediate token revocation.
- tests/storage-migration.php checks rejection of migration with pending jobs, historical data preservation and retention of the legacy SQLite file.
- tests/queue.php keeps backward compatibility coverage against SQLite in GitHub CI.
- The DigiOps release workflow runs the tests before uploading the artifact.

Important: JSON mode addresses PHP storage only. Waxum still requires a persistent Rust service and its own supported storage. The Rust gateway may remain unavailable after switching WaBridge to JSON.
