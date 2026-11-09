# WaBridge vNext — W0 / W1 / W2 JSON Foundation

Status: **isolated foundation only**. This increment does not expose new routes, permit multi-user login, send messages, run campaigns, or enable AI auto-replies.

## Baseline
- Baseline main: f6592189b6959581fa29df0e82bc34b7def7fe2a
- Existing PHP + Rust gateway integration and public APIs remain unchanged.
- Existing legacy PHP JSON, SQLite and platform metadata stores remain unchanged.
- The additional \`src/vnext\` modules ship inside the private DigiOps payload because the release packager includes \`src/\`.

## Delivered contracts
- \`src/vnext/json-store.php\`: per-scope, per-collection bounded JSON documents, advisory shared/exclusive locks, atomic replacement, revision CAS, strict UUIDv4 tenant directories, symlink/path-traversal checks, 2 MiB/1000-record limits. One document = one atomic transaction, **not** a multi-document transaction.
- \`src/vnext/identity.php\`: organization, invitation-only users and pending memberships, identity held together in a single atomic system document, role-policy preview. Every authorization request returns false until a later audited activation workflow is implemented.
- \`src/vnext/events.php\`: explicit allowlisted normalized provider event metadata, tenant-scoped provider event deduplication, no customer message bodies and no public ingress route.
- \`tests/vnext-foundation.php\`: safe isolated temp fixture without application config, database, Rust or WhatsApp.
- PHP and DigiOps release workflows run this test before declaring success.

## Safety guarantees and limitations
1. No production data is written by the isolated tests.
2. No new public route or live worker is registered.
3. \`recordVerified()\` is **internal-only** and does not authenticate callers. A future adapter must prove webhook signature/identity via the existing verified ingress boundary before calling it.
4. Pending memberships and pilot-disabled organizations **never** grant permissions. A later phase must implement audited activation, route-by-route authorization and channel ownership checks.
5. Single JSON document operations have exclusive locks and atomic replacement. Multiple files have no atomic commit; workflows, inbox and campaign dispatch **must not** use cross-file writes until a separate recovery-safe transaction coordinator is implemented.
6. JSON mode is single-host, bounded staging. Existing Waxum storage is independent and may require SQLite for real WhatsApp sessions.
7. The new events collection is for metadata only and not a substitute for the current signed webhook receiver, delivery receipt reconciliation or full chat ingestion.

## Next increment (not yet implemented)
- Record and verify persistent tenant/channel ownership mapping without enabling a public tenant selector.
- Add an explicit signed-event ingress adapter that invokes normalization only after signature verification, enforces unique event source and correlates provider IDs.
- Build a durable intent/outbox transaction coordinator and simulate crashes at every dispatch transition.
- Implement audit-backed tenant activation, invitation acceptance and RBAC coverage for HTML, APIs and workers.
- Add an authenticated read-only admin preview, with no customer data exposure and no enabled sending.

## Release acceptance
- Run \`php tests/vnext-foundation.php\`.
- Require all existing PHP, visual and DigiOps release checks to pass.
- Test DigiOps release packaging includes \`private/src/vnext/\` but excludes \`runtime/\`.
- Do not merge or deploy to production as active multi-account infrastructure until tenant isolation and recovery checks pass.
