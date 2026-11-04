# WaBridge P1–P5 — implemented scope vs. release gates

The baseline release ed3b1434 is a staged PHP + private Rust architecture.
This work adds feature modules without requiring Node.js.

## P1 — Messaging
- Opt-in contact directory (private, bounded), withdrawal and dispatch-time checks
  for scheduled jobs.
- Text template creation/preview and UTC scheduling (1 minute–30 days) with fail-closed metadata saving and automatic queued-only compensating cancellation
  using the existing queue's next_at field and idempotency protection.
- Opted-in, one-shot media send adapter supporting image, document, audio and
  video by base64, 1 MiB max; send logs contain metadata only. Provider
  acceptance is **not** delivery; no ambiguous auto retry.
- Browser operations are CSRF protected and private.

## P2 — Developer
- Versioned existing APIs remain compatible.
- API playground examples, paginated payload-free webhook history, webhook queue/dead-letter status and guarded
  replay action. Endpoint URLs are never accepted from request payloads.
- Never display raw API tokens or webhook secrets.

## P3 — Automation
- Keyword rule authoring and deterministic dry-run suggestions; operator-approved third-party AI drafting is available only when explicitly enabled and configured. Automatic
  customer replies are disabled pending opt-in/concurrency and inbound
  payload envelope verification. No uncontrolled AI transmission.

## P4 — Operations
- Existing worker, watchdog, audit, receipt and diagnostic controls retained.
- Security readiness page with protected limits/feature flags and role policy.
  Multi-role access is NOT certified. Never enable non-admin authentication
  without a consistent permission sweep of every API/HTML route.

## P5 — SaaS
- Workspace configuration preview with UUIDs and limited counts. Workspaces
  are not bound to all jobs/API keys, therefore multi-tenant SaaS remains
  **disabled**. No billing collection or charge processing is implemented.
- Optional approved WhatsApp Cloud connection and template send through
  pinned Rust engine, guarded by private config enable_official_cloud_connect.
  Meta account verification, webhook setup and real handset delivery are manual.

## Mandatory before production
1. Verify Cloudways cron and deployment SHA, and one consented receipt-correlated
   send per provider on a live handset.
2. Confirm limits and rate control with concurrency under real load.
3. Implement MariaDB transactional tenancy, isolation tests, RBAC route
   authorization and tenant-aware billing before offering SaaS subscriptions.
4. Encrypt, back up and recover all messaging and metadata state; avoid
   storing Meta/OAuth secrets in the PHP metadata JSON file.
5. Verify approved Meta templates, account eligibility and current platform
   messaging policies before production sends. Unofficial QR integration may
   become unavailable independently of WaBridge code.

**Do not claim P0–P5 fully production-accepted from a passing build.**
