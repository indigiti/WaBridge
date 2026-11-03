# WaBridge P0–P5 development status

## P0 — Rust gateway and QR connection

Implemented in PHP: separate Rust readiness diagnostics, explicit session IDs, session-status adapter, QR JSON retrieval, protected browser QR generation with locally vendored MIT-licensed QRCode library. No Node.js runtime is required. The source of the Rust engine is [Waxum](https://github.com/imtaqin/waxum), which must be deployed as a persistent process.

**Cloudways HTTP 503**: current evidence establishes the PHP dashboard is accessible but the Rust gateway is not ready. The app cannot install/supervise Waxum from PHP itself. To progress:
1. Run a pinned compatible Waxum Rust release on a supervised Linux host or a Cloudways environment explicitly allowing persistent custom daemons.
2. Keep Rust listener private and reachable by Cloudways PHP over protected network transport.
3. In **private** `private_html/wabridge/config.php`, set `gateway_url` to the reachable Rust endpoint and `gateway_token` to its configured bearer credential.
4. Sign in to WaBridge and open `/wabridge/diagnostics.php`. Distinguish `not_configured`, `unreachable`, `not_ready` and `ready`.
5. Create a session, scan the QR via `qr.php?sid=...`, check `/status`, send to a consenting number and verify restart recovery.

Never publish Rust credentials, QR contents or the private config file.

## P1 — Messaging and receipts

Implemented: idempotent queued text messages, upstream message ID persistence, signed webhook receipt update by **exact** provider ID, scoped job-status readback. Statuses can include `accepted` and `delivered`, but no receipt should be assumed if a webhook does not match.

**Unverified:** the precise live receipt envelope emitted by the pinned Waxum version and end-to-end delivery/read transitions. Real-device tests required.

## P2 — Credentials and audit

Implemented: client session allowlists, per-minute quotas, token hashing, revocation, optional expiration with migration for existing SQLite files, last-used tracking, metadata-only admin events. The admin UI can provision expiring keys. Full multi-role administrative RBAC is not yet implemented.

## P3 — Engine capability adapters

Implemented: a declarative capability registry exposed to scoped API clients at `/api/v1/capabilities.php`. It differentiates Rust engine routes confirmed from source from not-yet-implemented WaBridge adapters.

Supported by upstream source but **not yet fully adapted**: media, contacts and group operations. Channels and labels must remain disabled until their Rust provider implementation and permissions are verified. This prevents deceptive UI toggles.

## P4 — Infrastructure and operations

Implemented: authenticated `/reports.php` exposes queue states, known receipts, event counts, API-client metadata, audit events and SQLite size. Authenticated `/diagnostics.php` distinguishes PHP, SQLite and Rust readiness. Existing queue benchmarks are SQLite-only: they are **not** WhatsApp throughput measurements.

**Unverified:** live Rust RSS, actual latency, concurrent sessions, disk pressure tests and backup/restore drill.

## P5 — Safe extensibility, limited batch queue and SDK

Implemented: dependency-free PHP SDK in `sdk/php/Client.php` for messaging, status, devices and capabilities. `/api/v1/batches.php` permits up to ten messages only if explicitly enabled in private configuration, after gateway readiness and per-recipient consent reference/timestamp validation. It remains **disabled by default** because submitting a consent reference does not independently prove consent.

The default behavior remains individual, opt-in messaging. No automatic unsolicited campaigns, infinite retries, or unrestricted batch API. Complete provider-independent plugin lifecycle and Python SDK are future milestones.

## Release gates

Automated PHP tests check queue idempotency, scope isolation, receipt correlation, audit logs, QR capability registry and SQLite. DigiOps packaging checks that QR frontend assets and private adapters are present.

**Do not mark P0–P5 fully complete** until the gateway is running and the live end-to-end checks pass. Unofficial WhatsApp pairing may be disconnected or restricted; do not use as the sole production OTP channel.
