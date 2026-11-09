# WaBridge vNext — W0 foundation + W1 recovery/webhook + W2 account preview

**Status:** Tested, additive staging foundation. No new real WhatsApp provider integration, live campaigns, AI auto-replies, activated multi-user login or SaaS tenancy. Existing PHP APIs and Rust gateway behavior are unchanged. `main` stays untouched until the PR is reviewed and merged.

## Architecture, preserved code and storage

- Existing `src/json-store.php`, `src/storage-services.php`, `src/platform/*`, `public/api/webhook.php` and CLI send/webhook workers remain authoritative for legacy WaBridge behavior.
- New `src/vnext/json-store.php` is **single-host, private JSON staging storage**, with a 2 MiB cap per document, maximum 1,000 top-level records per document, strict UUIDv4 tenant directory names, permissions, flock, rename-based atomic persistence and optimistic revision checks.
- One document change is atomic. **No transaction across documents or real provider operations is promised.** A future transaction/outbox service must coordinate a committed message job with any separate tenant projections before real sends are enabled.
- New PHP functionality requires no MariaDB, Redis, PDO SQLite or Rust service. Actual Waxum session persistence continues to have independent engine requirements.
- `src/vnext/identity.php`: pending-only organizations/users/memberships; allowlisted administrative projections, fail-closed RBAC lookup. No user activation or login route.
- `src/vnext/channels.php`: ownership mapping confined to synthetic fixture channels associated with disabled pilot organizations. No real channels can be activated by this code.

## W1 signed webhook foundation

- `src/vnext/signed-ingress.php`: HMAC-SHA256 signature over `timestamp + "." + raw_body`, five-minute skew check, strict size limit, full JSON schema validation, explicit `fixture` provider allowlist and private channel-to-tenant ownership resolution. Never trust a `tenant_id` in the request payload.
- `src/vnext/events.php`: normalized metadata-only, bounded tenant event journal; replay key is provider + channel UUID + provider event ID. A conflicting replay is rejected. **No message body, recipient, secret or raw event payload is copied into vNext event state.**
- `public/api/v2/fixture-event.php`: optional synthetic-only POST endpoint. Disabled by default and responds 404 unless private config explicitly sets `enable_vnext_fixture_webhook=true`. The secret must be at least 32 characters in `vnext_fixture_webhook_secret` and never enters HTML. Requires `X-Webhook-Signature-Version: v2`, `X-Webhook-Timestamp`, and `X-Webhook-Signature: sha256=...`.
- The public synthetic endpoint must not be configured as a real WhatsApp webhook. Existing signed `/wabridge/api/webhook.php` remains the actual Rust event endpoint; no provider-side routing to vNext is enabled.
- No delivery state is inferred from metadata normalization. Provider ID to queue-job correlation for real channel inbound/outbound remains a later integration task.

## W1 recovery and dry-run outbox

- `src/vnext/events.php` also provides expiring, fenced leases for *local metadata normalization*. A crashed normalization worker can reclaim an expired event; a stale worker cannot acknowledge a replacement lease. After three failed claims an event requires manual review.
- `src/vnext/recovery.php` and `cli/vnext-recovery.php <tenant-uuid-v4>` perform only local metadata normalization. **Neither sends a WhatsApp message nor creates a real queued message job.** Do not add it as a cron job until explicitly validating tenant scope and operational needs.
- `src/vnext/outbox.php` provides isolated **simulated intent** reservation, duplicate suppression, claim, bounded lease, and completion. When a claim expires, state changes to `needs_review` rather than retrying. This is an interface rehearsal, not a send-capable outbox. The real send boundary remains the legacy queue.

## W2 safe multi-account admin

- Authenticated, noindex, no-store browser route: **`/wabridge/account-admin`** (new `public/account-admin.php` and sidebar link).
- Always displays fictional, read-only examples: Acme Admissions, Greenfield Retail and Regional Support. No customer contact, phone, messages, real credentials, provider sessions or API tokens.
- Pilot data is read only unless private config sets `enable_vnext_pilot_admin=true`; default is **false**. Existing admin session and CSRF are required for all provisioning POSTs.
- When enabled in a controlled single-server staging installation, the existing administrator may add **disabled** pilot organizations, invitation-only users, and **pending** role memberships. Nothing activates new user logins or real channel access.
- A separate **Seed three synthetic pilot accounts** button is available only with the flag enabled **and** an empty pilot identity store. It creates exactly three disabled organizations, 12 invitation-only users, 12 pending memberships and four fixture-only channels in one atomic identity-document write. Re-seeding is idempotent; attempting to overwrite any existing non-demo identity data is rejected.
- Demo fixture external IDs are `demo_fixture_01` through `demo_fixture_04`; they are not real WhatsApp session IDs. Synthetic event testing additionally requires the separate fixture-webhook flag and secret.

## Staging acceptance steps

1. Wait for PHP checks and Premium UI visual QA on the **exact PR head SHA** to pass.
2. From the existing authenticated WaBridge console, navigate to `/wabridge/account-admin` and verify the fictional demo directory appears without runtime setup.
3. Verify anonymous access redirects to the styled login screen and returns to this route after authenticating.
4. Confirm no pilot account activation, campaign sends, AI auto-replies or real WhatsApp connections are available.
5. For isolated testing only, enable `enable_vnext_pilot_admin` in private stage config, use **Seed** once, and verify all seeded records remain disabled/pending.
6. For HMAC fixture simulation only, additionally set `enable_vnext_fixture_webhook` and a strong private `vnext_fixture_webhook_secret`. Never reuse a production gateway secret.
7. Run `php tests/vnext-foundation.php`, `php tests/vnext-integration.php` and `bash tests/vnext-http-flow.sh` in isolated CI workspaces. The HTTP test proves real signed POST acceptance, replay rejection, unknown-channel rejection, payload minimization and default-off behavior. The existing `tests/auth-browser-flow.sh` verifies the authenticated account preview and signed-out redirect.
8. Validate the DigiOps package includes new `private/src/vnext/` modules and `public/account-admin.php` while excluding `runtime/`, `config.php`, test state and credentials.

## Not yet production certified

- Real provider webhook envelope mapping, signed Meta Cloud API verification, channel ownership verification for production accounts, inbound chat bodies and receipt-to-job correlation.
- Multi-file durable intent and dispatch transaction coordination for actual messages; recovery of uncertain remote-side effects.
- Audited account activation and true multi-user RBAC across every existing PHP route/worker.
- Backup/recovery, load/performance on Cloudways, multi-server tenancy, transactional database implementation, and commercial/compliance gates.

Never classify a successful CI run, simulation, normalized fixture event or synthetic channel as evidence of real WhatsApp delivery.
