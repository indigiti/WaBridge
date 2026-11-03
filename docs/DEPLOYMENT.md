# Deployment and operational checklist

## PHP dashboard on Cloudways

1. PHP 8.2+ with cURL, mbstring, sessions, JSON, and OPcache.
2. Point the domain's public document root at \`public/\`; prevent HTTP access to \`config.php\`, \`src/\` and other internal files. If Cloudways cannot change document root, deploy contents of \`public/\` to \`public_html/\`, with \`src/\` and \`config.php\` one level above it; review relative paths before deployment.
3. Copy \`config.example.php\` to \`config.php\`; set a unique password hash, token, internal gateway base URL and a strong hashed external API token. Example: \`php -r 'echo hash("sha256", bin2hex(random_bytes(32))), PHP_EOL;'\` creates a hashed random value **without exposing the underlying token**; for a usable token, generate it once locally and store token and hash separately.
4. Enforce HTTPS, protect the web dashboard with additional ingress authentication if available, and configure PHP session storage.
5. Do not share dashboard and external API tokens with browser JavaScript or embed them in public HTML.

## Rust gateway

Waxum runs as a separate persistent service. On standard Cloudways, support for custom supervised daemon services is not guaranteed. Use a private VPS if needed. Build or download a pinned Waxum release, set its required token and SQLite storage path, and expose its listener only to the PHP server through a private connection or tightly restricted IP/firewall plus TLS. Configure restart supervision, health checks, encrypted disk or encrypted volume and secure backups.

**Do not use the PHP dashboard as a way to start or restart a daemon from the public Internet.**

## Cloudways acceptance tests

- [ ] Dashboard login over HTTPS
- [ ] Rust health probe returns ready
- [ ] Create session, scan QR, verify connected
- [ ] Send to a consenting test number
- [ ] Receive delivery update / webhook and test message status
- [ ] Restart Rust process; verify session restores without rescanning
- [ ] Inspect RAM/CPU, file permissions, backup recovery
- [ ] Disable or revoke API credentials; verify old token no longer works

## Current limitations

This repository contains a functional PHP control-plane scaffold but no deployment to your hosting server. Waxum's API payloads are version dependent and must be checked against the installed \`/swagger-ui\`. The PHP send proxy directly sends through Waxum, so it does **not** guarantee delivery, deduplication or durable queue acceptance; add those before using automated OTP flows or high-volume workloads. It provides a single-admin MVP; it does not implement production SaaS tenant isolation, key lifecycle, webhook ingestion, billing or audit retention. Rate-limit requests at Nginx/WAF until a durable per-key rate limiter is implemented.


## Message queue and API clients (current implementation)

Use the versioned external API /api/v1/messages.php. It now returns HTTP 202 with a job_id and requires an Idempotency-Key header (8 to 128 characters) and a scoped application bearer token. POST request acceptance is NOT WhatsApp delivery confirmation. Query /api/v1/jobs.php?id=JOB_ID to see internal state.

Manage API clients without editing source code:
- php cli/clients.php create schoolflow school-01 20
- php cli/clients.php list
- php cli/clients.php revoke schoolflow

The issued token is shown once; its hash is stored in the local SQLite database. A revoked key cannot access the API. Use different credentials per external application and explicit session permissions.

Execute both CLI workers from Cloudways cron or equivalent:
- php cli/worker.php 40
- php cli/webhooks.php 15

Configure any outbound webhook subscribers in private config.php. Each callback is signed with HMAC-SHA256(timestamp.raw_body). The receiving application must validate signature, timestamp and event id. Use php cli/ops.php summary to review queue state and php cli/ops.php dead-events to inspect failed callbacks. Manual replay is available for dead events; consumers must deduplicate.

SQLite data, messages, event contents and Waxum session keys must live outside public_html, with restricted permissions and encrypted backups. The message worker intentionally DOES NOT retry ambiguous send outcomes to prevent duplicate notifications. The queue retains terminal records for 14 days and webhook payloads for 7 days.

## Verification evidence

GitHub Actions executes PHP syntax checks, standalone validation tests, SQLite queue/idempotency/tenant-scope tests, key-revocation tests and an informational local SQLite benchmark. These checks validate PHP logic only. They do not validate actual WhatsApp delivery, uptime, Cloudways Rust process supervision or a production SLA.
