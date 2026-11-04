# Waxum Lite: evidence-based staging acceptance

## Scope and immutable baseline

WaBridge runs a PHP control plane and a separately supervised Rust Waxum v0.16.1 gateway. The production-compatible Rust archive comes from GitHub Actions run `41712571137`, upstream commit `851bedf3189bda742433ef57bc06e75be590860b`, compiled under Ubuntu 20.04 (maximum glibc 2.31). The pinned archive SHA-256 is `f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e`.

This PR does **not** change DigiOps packaging, Supervisor startup, active session state or PHP routes. The Lite candidate is a separate GitHub Actions artifact, expressly marked **not deployable**. The comparison runs against the *extracted*, verified full production executable, not an upstream release compressed with different settings.

## Build and measurement gates

The experimental workflow:

1. Runs offline unit tests for the size comparator before Rust compilation.
2. Verifies baseline archive checksum, source provenance and ABI.
3. Checks out the precise upstream Waxum revision and applies a manifest replacement that aborts on source drift.
4. Removes native VoIP relay and native Opus while retaining core SQLite, transport, Tokio, signaling, encoded-call and tracing features.
5. Builds on Ubuntu 20.04 with the pinned nightly toolchain, audits ELF architecture and glibc <= 2.31, checks the CLI help surface and saves linked libraries and Cargo dependency graph.
6. Measures raw ELF size and deterministic gzip size, records SHA-256 of both executables and keeps a JSON/Markdown report.
7. Uploads the candidate and evidence **before** enforcing a minimum 5% raw size reduction, plus positive gzip reduction, so failed comparisons remain auditable.

A passing size gate **does not** demonstrate lower process memory, quicker startup, QR pairing or message delivery. It does not authorize production deployment. An empty or missing binary fails the comparator.

## Staging / real-device evidence required before any switch

- Verify a consenting phone pairs with a new QR and persists its linked session across gateway/Supervisor restart.
- Confirm sending one text to a consenting test recipient, recording a provider-generated message ID and a matching delivery receipt (rather than only `queued` or `accepted`).
- Confirm incoming text and image/media handling, and expected receipt state transitions.
- Test offline-to-online recovery, bounded queue retry behavior, disconnection and relinking.
- Compare both executables on the **same host** using idle with no sessions, connected idle and one small consented send. Capture Rust RSS/PSS, CPU, thread count, startup latency, filesystem footprint and response latency. Do not infer runtime efficiency from executable size.
- Confirm external REST routes used by the PHP app, authentication and error handling are compatible, and that call-related behavior is deliberately disabled or independently tested.
- Keep a matching rollback binary, credentials and session database backup; prove rollback without clearing WhatsApp sessions.

**Acceptance:** Rust compilation, ABI, and size checks in CI; all application and device compatibility checks separately signed off by an operator with a test phone. Until both tiers pass, the pinned full Waxum archive remains the only DigiOps release input.

## Design constraints

Do not change production source manifests or bootstrap scripts in this optimization PR. Never fetch an unpinned moving branch as a release source. Do not auto-deploy Lite after a passing CI build. Avoid force-sending WhatsApp messages during CI. Any future removal of Swagger, PostgreSQL, MySQL, NATS, TTS or the built-in console requires feature gates and explicit route parity evidence; declaring a crate optional is not proof that its code is dead.
