# WaBridge — delivery and PHP queue operations

## Diagnosis

Rust Waxum runs QR pairing and WhatsApp messaging. PHP runs an independent persistent queue. Gateway readiness HTTP 200 does not submit queued PHP jobs or establish that WhatsApp is connected.

## Browser testing (no CLI)

1. Open /wabridge/sessions and confirm the selected session is logged in and has a live socket. If disconnected, re-link with QR before sending.
2. Open /wabridge/messaging. Enter an actively linked session, a phone number you own using international digits, and a consented test message.
3. Click Send test message. The browser now claims and dispatches exactly that new job immediately. A separate CLI worker is NOT required for this one browser test.
4. Open /wabridge/messages to inspect upstream HTTP status, failed reason category, and any verified receipt. Accepted by Waxum is NOT WhatsApp delivery.
5. For older queued messages, use Process next queued message only after the session is linked. A disconnected session stays queued and its send attempts remain zero.
6. NEVER automatically retry a Needs review job; first inspect the destination phone and the failure category. It may already have arrived. If safe and consented, create a new test message rather than blindly replaying the old uncertain request.

## Cloudways automatic processing for third-party API queues

Ask Cloudways support to configure a Cron Job every minute using the application's correct PHP 8.4 CLI interpreter, running the private PHP entrypoint with a small batch limit of five.

Script path:
  /home/1323555.cloudwaysapps.com/wbdbbkzpe/private_html/wabridge/cli/worker.php

Arguments:
  5

Cloudways support should verify the actual filesystem path, CLI interpreter, application owner, permissions and private gateway token access. This path is the script location, not a complete shell command. No public cron endpoint, secret in a URL, new database or second Rust Supervisor job is required.

The existing Rust Supervisor must continue independently. Do not reset private JSON or Rust SQLite session data, regenerate tokens, or expose TCP port 3451.

## How to interpret the worker indicator

Open /wabridge/messages. Recently ran means the private PHP worker heartbeat was written within about 150 seconds, NOT that messages were delivered. Not detected means no worker heartbeat has been observed. Stale means a prior heartbeat exists but cron might not be running. Session disconnected means a preflight refused a send without consuming the queued job.

The heartbeat stores counters and timestamps only, never phone numbers, session IDs, message text, access tokens or raw upstream error bodies.

## Distinguish these stages

- Queued: message stored, no Rust send yet.
- Blocked: WhatsApp session not connected; the queue record is preserved.
- Processing: PHP claimed the job atomically, outcome not yet known.
- Accepted: Waxum returned a 2xx response and a message ID. Not delivery proof.
- Needs review: missing provider ID, HTTP rejection, interrupted worker, or uncertain transport. No automatic retry.
- Sent, delivered or read: only after correlating an authenticated provider receipt with the exact upstream message ID.

Waxum's unofficial WhatsApp Web receipts may not be structured in a form that can be correlated by PHP. Therefore, after fixing the queue, a genuinely sent message can still show Accepted — delivery unknown. Avoid manufacturing delivery confirmations.

## Automated proof versus live proof

Automated checks exercise targeted jobs, offline session protection, cross-session fairness, queue idempotency, static failure reasons, private worker heartbeat and interface rendering. Live delivery needs a linked real phone, a consented test number, and the destination's observed WhatsApp state. Cloudways cron and external webhook configuration cannot be confirmed by GitHub tests alone.
