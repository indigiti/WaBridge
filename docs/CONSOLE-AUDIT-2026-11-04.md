# WaBridge post-deployment audit — 4 November 2026

This audit compares the screenshot-reported Media page against repository
source. GitHub checks are not equivalent to a live Cloudways audit.

## Improvements implemented
- Removed a duplicate nine-link horizontal module bar: grouped collapsible
  sidebar remains primary navigation; only contextual actions appear.
- Corrected premium form columns, labels, checkboxes, accessible spacing and
  responsive small-screen behavior, with consistent icons for each module.
- Versioned CSS/JS URLs from the deployed DigiOps build SHA, instead of
  reusing ?v=6 indefinitely. CSS/JS revalidate through Apache headers.
- Added desktop and mobile headless Chrome regression for a representative
  Media module, including CSS application, input alignment, contextual spacing,
  active navigation, Inter typography and no horizontal overflow.
- Contact directory has 10-record pages, searchable name / last-four filters,
  and deep links for any selected contact to media/scheduling; no huge HTML
  select lists or full phone numbers in URL search queries.
- Replaced free-text session guesses with configured session choices where
  scope exists; otherwise links to Linked sessions for a valid ID.
- Empty-state warnings point to required Contacts / Templates actions.
- Release verification now reads status aggregates, private watchdog and
  worker heartbeat snapshots instead of loading 100 message records.
- Scheduled queue metadata writes are fail-closed: pending schedules are never
  silently evicted at capacity, and a failed metadata save triggers an atomic
  cancellation of its unclaimed queue job.
- Shorter GET liveness/status timeouts keep an offline Rust gateway from
  blocking dashboard navigation for the full send timeout.

## Performance / privacy constraints
- Admin event overview remains 10 rows; /wabridge/audit retains 25/page.
- Message activity remains 25/page, webhook history 25/page, platform modules
  10/page. Queries do not hydrate message bodies to render status summaries.
- New records use UUIDv4, legacy message IDs continue to work.
- JSON platform metadata is bounded pilot storage, not a transactional SaaS
  backend. An optimistic "fast" label is inappropriate without Cloudways
  page timings and queue load testing.

## Real release acceptance checks
1. Deploy this exact GitHub release SHA using its DigiOps artifact; verify
   the build manifest in the private installation.
2. Open Chrome DevTools on /wabridge/media. Check stylesheet URL uses a new
   commit-derived ?v= key; refresh and confirm contextual navigation, not the
   nine crowded text links.
3. Confirm Contacts search and older-record media/schedule deep links work.
4. Test an opted-in media message to an owned number; record gateway acceptance
   and require matched delivery receipt before declaring delivered.
5. Test scheduled cancellation, opt-out before dispatch and the worker cron.
6. Check mobile 390px width, keyboard focus, narrow-table horizontal scrolling,
   and the sidebar expansion/collapse.
7. Check only the configured operator can see sensitive audit or contact
   records; verify Webhook and Meta configuration remain private.

## Production gaps not closed by this UI/reliability release
- Multi-role RBAC across every HTML, API and background-worker action.
- MariaDB-backed tenant isolation, per-tenant quotas, and high-volume load
  tests; workspace definitions alone are **not** tenants.
- Billing/subscription reconciliation, invoicing and payment handling.
- Encrypted tenant backups plus tested point-in-time restore and retention.
- Meta Embedded Signup approvals, verified production webhook signatures,
  consent/legal compliance and live WhatsApp delivery tests.
- Production uptime/SLO reporting and realistic sustained performance metrics.

**Do not declare all P0–P5 commercially production-complete until those
foundational gates are implemented, audited and tested.**
