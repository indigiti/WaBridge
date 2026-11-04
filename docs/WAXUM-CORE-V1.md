# Waxum Core v1 — pinned, headless and reusable

**Stage:** experimental; no changes to production WaBridge binaries, PHP routes, process management, credentials, or session storage.

## What changes in v1

The upstream Waxum source at tag `v0.16.1`, commit `851bedf3189bda742433ef57bc06e75be590860b`, is cloned exactly and transformed by `tools/waxum-core-v1.py`. The patch script refuses unexpected source changes rather than guessing and silently applying a partial refactor.

1. **Preserved default behavior.** Default Cargo features are `embedded-console` and `swagger-ui`, so a normal full build retains both. A separate `--no-default-features` profile omits both from the headless binary; `handlebars`, `qrcode`, and `utoipa-swagger-ui` become optional dependencies. The `utoipa` annotations on public handlers remain compiled; they are not stripped.
2. **One shared event summarizer.** Moves the redacted event summary from the optional console handler to `src/event_summary.rs`, so the always-present session event ring can still summarize message/QR/receipt events in headless mode without linking the console.
3. **Reusable live-session lookup.** Delegates five identical handlers' `get_client` helpers to the existing `api::shared::messages::handlers::get_client` implementation. Installed/paused-client access is deliberately left unchanged; a paused client must not be rejected by a live-socket check.
4. **No protocol rewrite.** QR pairing, WhatsApp connection, LID recipient resolution, storage, Cloud API, NATS, webhook signature validation, message sending and provider receipts remain on existing code paths. The upstream `unsafe impl Send` workaround requires separate protocol/library scrutiny, not blanket refactoring.
5. **No-clone media buffers.** The image, video, audio, document and sticker handlers now record protobuf payload length before moving the owned `Vec<u8>` into `Client::upload`, avoiding five full-payload copy sites. This is a source-level memory-copy optimization, not yet a measured reduction in peak process RSS.
6. **No forced Tokio tuning.** The upstream worker/blocking-thread defaults remain untouched until measured CPU/RSS/thread-count benchmarks justify specific changes. More aggressive threading changes could impair reconnect and media behavior.

## Reproducible build validation

The dedicated `Waxum Core v1` GitHub Action runs:
- Offline Python tests for patch drift detection and size comparison.
- SHA-verification of the same full, glibc 2.31-compatible production Waxum executable previously validated in run `41712571137`.
- A check that the precise upstream SHA matches the pinned revision.
- Source patching and `cargo check --all-features --lib --bin waxum` to detect full-profile compatibility regressions.
- `cargo build --release --no-default-features` for headless output and Rust tests of the event summary.
- CLI smoke, ELF architecture, glibc symbol limit, dependency tree, raw ELF and deterministic gzip measurements.
- A disposable **loopback-only** comparison of full and headless binaries: temporary isolated SQLite data and storage, `/livez`, `/readyz`, authentication rejection/acceptance, missing headless Swagger/console endpoints, and 48 no-session authenticated API calls. Save startup latency, p50/p95, thread count and one RSS sample for **informative** comparison only. No live WhatsApp account is required; no messages are sent.
- A **review-only** artifact with source diff, checksums and provenance, followed by a 5% raw-size reduction gate. Candidate is not used by DigiOps.

## Runtime and security release gates — NOT verified by build CI

Before a new production Waxum binary can be considered:
- Confirm no unexpected public routes in headless mode; `/swagger-ui` and console endpoints must not be available. REST health/auth and all WaBridge-used paths must remain compatible.
- Pair a consented test phone via QR, restart Waxum/Supervisor and confirm login persistence. Verify disconnect/reconnect and session data integrity.
- Send test text, image and document; match provider message ID to actual delivery receipt; confirm inbound message and webhook replay/dedup behavior.
- Confirm Cloudways Supervisor startup under identical env, storage permissions and retained user credentials.
- Test at 0, 1 and multiple active WhatsApp sessions. Compare p50/p95 latency, RSS/PSS, CPU, threads, startup and media peak memory against full baseline on the **same hardware**.
- Record rollback instructions and verify previous full Rust binary can recover sessions without clearing auth credentials.

**Important:** Fewer Rust source functions, more wrapper reuse or smaller executable size do not automatically imply lower latency. `opt-level=z` is already enabled upstream. Changes must be measured and profiled; keep non-inlinable helpers where useful and avoid abstraction that adds allocations or dynamic dispatch.

## Current benchmark reference

Previous isolated Waxum Lite PR #7 successfully compiled with reduced native VoIP support but measured only 2.612% raw binary savings: full `39,142,464` bytes; Lite `38,119,968` bytes. The first Core v1 headless build passed the 5% gate, measuring **39,142,464 to 24,723,936 raw bytes (36.836% reduction)** and **14,887,830 to 10,833,692 deterministic gzip bytes (27.231% reduction)** in successful Actions run `41889795350`. The follow-up media-copy patch and loopback resource smoke require their own refreshed CI evidence. These reductions result mainly from excluding embedded console/Swagger, **not** from extracting five live-client lookup helpers.

No claim is made that a real WhatsApp message or QR session has been verified by source-only CI. The benchmark baseline Actions artifact will eventually expire and must be migrated to a durable digest-pinned source if the workflow becomes permanent.
