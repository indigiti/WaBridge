# WaBridge release gates — never mark live production without verification

## M1 Foundation
- [x] PHP admin scaffold, API contract and modular storage/service boundary
- [x] Basic PHP lint workflow
- [ ] PHP CI green for release SHA
- [ ] Dependency scan / automated secret scan

## M2 Rust protocol integration
- [x] Waxum REST gateway adapter
- [x] Sessions and QR proxies
- [ ] Pinned and reproducible Rust binary build
- [ ] Real QR pairing, reboot restoration, disconnect recovery

## M3 Dashboard
- [x] Device list, session creation, status and send test form
- [x] Dashboard message queue rather than direct send
- [ ] Accessibility testing, browser integration tests, session login throttling and RBAC

## M4 API and events
- [x] Versioned text endpoint aliases, scoped tokens, SQLite idempotent queue, CLI dispatch, job lookup
- [x] Waxum v2 HMAC timestamp verification for webhook ingestion
- [ ] Webhook-to-job receipt reconciliation, outbound client webhooks, delivery evidence
- [ ] Key rotation/revocation and tenant provisioning UI

## M5 Performance and security
- [x] Quota and bounded batch worker, conservative uncertain-send handling
- [ ] Load tests with gateway + WhatsApp, p95 and RSS measurements
- [ ] Ingress WAF controls, attack tests, encrypted persistent volume, backups
- [ ] Full queue capacity / retention strategy, dead-letter operations

## M6 Deployment and SaaS
- [x] Example Linux systemd and Cloudways cron configurations
- [ ] Verify Cloudways PHP document root and external Rust service connectivity
- [ ] Multi-tenant provisioning, billing and isolated operations
- [ ] Disaster recovery drills, backups, metrics and alerting

**Status: Development/integration preview. Do not claim M1–M6 complete or production-readiness while live gates remain unverified.** Waxum uses an unofficial linked-device protocol and can be restricted by WhatsApp. Auth-critical OTP must have an official or SMS fallback.

## Operational concerns
- Bind the Rust gateway to loopback or a private network; require TLS on any cross-host connection.
- Protect SQLite and WhatsApp credential files with operating-system permissions and encrypted disk.
- Worker accepts at most 100 jobs per invocation and uses no automatic retry after uncertain outcomes.
- API calls return job acceptance, not evidence of device receipt. Read statuses as queued / processing / accepted / needs_review.
- Run at least one complete send, webhook, restart and restore test on the real release build.
