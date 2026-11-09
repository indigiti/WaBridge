# WaBridge vNext — code-first implementation truth table

Date: 2026-11-09. Base: \`main\` at \`8f1f26620aa30a1ee23a91c09b4d3ad4735269db\`.
Active development: **draft PR #10**, \`feat/code-first-w3-offline-team-inbox\`.
**Do not merge to main or deploy through DigiOps until code, tests, provider-specific acceptance and owner approval are complete.**

## Definition of "complete"

A module is **demo-code complete** only if its PHP services, authenticated preview routes, JSON persistence, no-send policies, and CI tests work offline. This is **not** production-complete. A module is production-ready only after real provider evidence, tenant user access and data isolation, crash/backup drills, operational metrics, compliance and deployment checks are all verified.

| Milestone | Implemented in this PR | Still not production-ready |
|---|---|---|
| W0 storage | Private bounded single-server JSON with atomic per-document writes, locks and revision CAS | Multi-server storage, formal DB adapter, full cross-document transactions |
| W1 events | HMAC signed fixture ingress, owner-bound synthetic channels, deduplication, fenced metadata leases, idempotent two-file projection, manual-review uncertain dispatch, private hashed snapshots + guarded restore | Real Waxum/Cloud event envelope map, provider receipt/job reconciliation, real inbound bodies and credential rotation, production crash/load drills |
| W2 accounts | 3 fictional organizations, pilot metadata, pending memberships, fail-closed role evaluation, invitation hashes, credential verification, scoped hashed tokens and revocation | Real tenant activation, agent browser login, per-channel user scopes, tenant-wide RBAC sweep, audit/MFA/invite email |
| W3 inbox | Fictional threads, isolated JSON, assignment CAS, state transitions, fixed-note collaboration, protected read-only UI | Real inbound conversation ingestion, customer contact storage, live agent reply, verified delivery and secure media |
| W4 automation | Ordered graph editor with add/remove/reorder, typed allowlisted nodes, immutable reviewed versions, trace-only simulations | Conditional multi-edge branching, persistent step scheduling, real trigger subscribers, consent-checked live action dispatch |
| W5 campaigns | Consent-filtered fictional audience, draft → review → simulate → pause, suppression arithmetic, no sends | Real consent evidence, official template eligibility, pacing, budgets, sending, receipts and user approvals |
| W6 AI | Fixture knowledge/source-backed suggestion, privacy confirmation, manual copy/rejection, zero AI provider requests | Tenant knowledge retrieval, actual model gateway and provenance, PII controls, evaluation and approvals |
| W6 Flows | Fixed-choice schema, draft/review/simulated submission, zero Meta provider requests | Official Flow JSON verification, encryption keys, signature validation, publication and authorized inbound callbacks |

## Existing code preserved

- The current PHP queue, \`src/platform/*\` pilot modules, manual AI drafting function, \`public/api/webhook.php\`, provider receipt logic, legacy SQLite/JSON drivers and the Rust Waxum gateway remain unchanged. New vNext implementations are **additional, synthetic-only contracts**, not replacements for tested live operations.
- Real delivery is **never inferred** from Waxum HTTP acceptance or a local simulation; confirmed delivery needs a separately authenticated, correlated provider receipt.

## New private services

\`src/vnext/json-store.php\` remains the bounded transport-neutral staging repository. New development modules:

- \`pilot-guard.php\`: strict synthetic tenant check, safe identifiers and titles.
- \`inbox.php\`: fictional shared queue and revision-safe human actions.
- \`workflows.php\`: bounded ordered graph, reviewed demo versions, dry-run traces.
- \`campaigns.php\`: consent-checked fictional targeting, approval simulation and pause.
- \`assistant.php\`: deterministic fixture knowledge source and approval queue, no AI API.
- \`flows.php\`: schema-bound local form previews.
- \`projector.php\`: replay-safe metadata projections into a separate JSON document.
- \`backup.php\`: private checksum-verified snapshots and explicit one-document CAS restoration.
- \`tenant-tokens.php\`: tenant+scope-bound hashed bearer tokens, active-membership recheck, revocation.
- \`invitations.php\`: one-use hashed invites and credential verification; no live sessions.

## Browser entrypoints

Existing authenticated admin and CSRF protections are reused. New pages are no-index, no-store and safe by default.

- \`/wabridge/account-admin\` — fictional account directory and pending-only provisioning (from W2).
- \`/wabridge/team-inbox\` — fictional shared queue; \`enable_vnext_inbox_preview\` gates writes.
- \`/wabridge/automation-studio\` — typed visual sequence composer.
- \`/wabridge/campaign-manager\` — fictional audience and campaign preview.
- \`/wabridge/ai-assistant\` — review-only sample assistant.
- \`/wabridge/flows-studio\` — local fixed-choice Flow preview.

The latter four routes require \`enable_vnext_studio_preview=true\` for modifications. If absent or false, they remain read-only.
Data is shown only for three static fictional tenants, without customer phone numbers or real provider sessions.

## JSON preview API

\`GET/POST /wabridge/api/v2/preview.php?module=<inbox|workflows|campaigns|assistant|flows>&tenant=<demo-uuid>\`

- **Default off:** \`enable_vnext_preview_api=false\`. When off, return HTTP 404.
- Auth: existing administrator session. No cross-origin access. POST also requires \`X-CSRF-Token\` equal to session CSRF, JSON content type and 4 KiB payload bound.
- Tenant: strict list of three fictional demo UUIDv4 identifiers. Backend independently enforces pilot marker and tenant-scoped JSON ownership.
- Returns \`WABRIDGE-VNEXT-PREVIEW/1\` JSON and \`live_operations_enabled:false\`.
- Supports only synthetic module operations; no real message send, provider import, unapproved knowledge, live campaigns or AI auto replies.
- No API token issuance route is available. Scoped tenant tokens and one-time invites are currently internal contracts, **not** exposed as a user login bypass.

## W1 recovery semantics

1. Signed fixture webhook resolves channel ownership then appends only normalized metadata to \`events.json\`.
2. Metadata worker claims a fenced lease and creates an idempotent \`projections.json\` checkpoint.
3. Worker acknowledges event after checkpoint; crash between steps (2) and (3) safely replays the projection as a no-op.
4. Uncertain outbound intent never automatically retries; it goes to manual review.
5. Operator-initiated \`VNextBackup\` snapshots verify SHA-256 and can restore **one document** with a revision CAS. No automatic restore and no promise of multi-file ACID.

**Known limits:** Once a document hits capacity, writes fail closed. The vNext JSON repository is single-host staging only. Encrypted volumes, retention, disaster-recovery and a transaction-capable production driver remain explicit prerequisites for production tenancy.

## Automated gates

Run independently in a fresh repo, never above actual \`runtime/\` or \`config.php\`:

- \`php tests/vnext-foundation.php\`
- \`php tests/vnext-integration.php\`
- \`bash tests/vnext-http-flow.sh\`
- \`php tests/vnext-projection.php\`
- \`php tests/vnext-backup.php\`
- \`php tests/vnext-tenant-tokens.php\`
- \`php tests/vnext-invitations.php\`
- \`php tests/vnext-inbox.php\`
- \`php tests/vnext-studio.php\`
- \`bash tests/vnext-studio-http-flow.sh\`
- \`bash tests/auth-browser-flow.sh\`
- \`node --check public/assets/vnext-studio.js\`

The PHP checks and DigiOps workflows must run these. The canonical premium visual QA also must pass at the *same exact PR head commit*. These are automated **offline tests**; none certify real phone delivery.

## Hard blockers before production claim

1. **Verified real provider schema:** The current Waxum source/event contract in the PHP repository does not establish an authoritative normalized receipt payload for every provider event. Never guess or claim messages delivered.
2. **Multi-user authorization:** Real agent login, active tenant lifecycle, scoped channel membership, revocation, security audit, session/MFA controls and route/worker RBAC are not yet integrated.
3. **Cross-file coordination:** Legacy queue and new inbox/campaign metadata cannot safely share exactly-once remote dispatch without a durable dispatch transaction/outbox implementation and failure injection.
4. **Campaign compliance:** Official approved templates, purpose-specific opt-in, opt-outs, rate limits, spending caps, real sender authorization and regulatory approval need production evidence.
5. **AI/Flows integration:** Existing optional operator AI draft is distinct from the fictional review sandbox. Real tenant knowledge, evaluation, safe context retrieval, Meta Flows encryption and approved publication have not been proven.
6. **Deployment:** Cloudways/DigiOps remains pinned to the merged \`main\` release until the user explicitly chooses a deployable, fully reviewed artifact; no staging production switch has been performed by PR #10.

**Decision:** The code-first offline feature suite can become testing-complete without a database, but that cannot substitute for provider/device certification or a database/transaction strategy before live multi-tenant operation.


## Next code-first increment: separate tenant sign-in + channel RBAC (2026-11-09)

Additional W2 code is implemented after the initial W1-W6 offline suite:

- \`src/vnext/member-policy.php\`: active tenant/user/membership checks and explicit per-channel role permission checks, denying unknown or cross-tenant channels.
- \`src/vnext/member-auth.php\`: separate tenant session claim, password verification, hashed 15-minute login attempt throttling, idle/absolute session expiry, session ID rotation and live membership revocation checks.
- \`public/tenant-sign-in.php\` and \`public/tenant-workspace.php\`: feature-flagged read-only member login and authorized-channel summary. No legacy admin access is inherited, and customer message bodies are not loaded.
- \`tests/vnext-member-security.php\` and \`tests/vnext-member-http-flow.sh\`: fixture-only permission, throttling, CSRF, cookie, role, cross-tenant, legacy-admin-denial and suspend-after-login tests.
- \`docs/VNEXT-TENANT-ACCESS.md\`: detailed security boundary and exact missing production gates.

**Default:** \`enable_vnext_tenant_login=false\`. No public activation path; neither invitation acceptance nor a tenant role automatically grants access. Real member inbox operations, MFA and audited provisioning remain not yet complete. This is a code-first staging increment only; it has not been merged or deployed via DigiOps.
