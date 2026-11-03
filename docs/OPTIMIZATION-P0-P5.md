# WaBridge P0–P5 optimization and acceptance

## Scope and safety
Target: Cloudways x86_64 / glibc 2.36, PHP 8.4 and JSON test storage, verified Waxum v0.16.1 and private Rust SQLite. Keep the existing compatible daemon, gateway token, session files and Supervisor job unchanged.

A GitHub test result does not confirm Cloudways deployment, real WhatsApp QR pairing, receipt-confirmed delivery, or session persistence after a restart.

## P0 — One verified Rust archive
Only the source-pinned Cloudways-compatible glibc 2.31 Waxum archive enters DigiOps packages. The original upstream glibc 2.38/2.39 archive is not fetched, packed or stageable. Older runtime backups are preserved, not automatically deleted. The previous 15 MB redundant archive is eliminated at the package-content level. Compare GitHub artifact sizes because its surrounding ZIP compression may differ.

## P1 — Single CSS request, maintainable source
Readable source CSS remains split into base, console, glass and typography modules. The PHP build script tools/build-assets.php concatenates them in order to the generated public/assets/wabridge-ui-v5.css. Admin and sign-in pages load only this browser stylesheet rather than three cascading files. Nested table blur is disabled to reduce GPU pressure.

## P2 — Premium self-hosted typography
Fontsource Inter v20 under SIL Open Font License 1.1, source commit 5a72889057f592bb0659629e38337359f94d53b2. Three Latin WOFF2 normal weights 400/600/700 are fetched by tools/fetch-fonts.sh during CI and deployed with the OFL license and source notice. CSS includes font-display swap and system fallbacks; no external font CDN is required by the browser.

Page headings: approximately 32–40px; panel titles: 20–22px; navigation and body: 14–16px; smaller supporting text only where justified. Repeated arbitrary type sizes are overridden in the final semantic module.

## P3 — CPU, RSS, threads and private disk
Authenticated Performance URL: /wabridge/performance. Read-only, on-demand PHP reads Linux process metadata for the exact private Rust executable and scans bounded private directories. It does not invoke a shell or disclose paths, command lines, environment secrets, phone numbers or database content.

The metrics show Rust RSS, peak RSS, thread count and, when schedstat permits, a 200ms CPU rate. Disk totals cover Rust executable/provisioning, WhatsApp SQLite session storage and PHP JSON. Unavailable permissions produce an explicit unavailable label, never guessed measurements.

Record comparable readings: gateway idle/no connections; one connected number idle after 5 minutes; same number during a consented test send. A smaller executable does not prove lower RAM use.

## P4 — Waxum Lite experiment, NOT production
Separate workflow: .github/workflows/waxum-lite-experiment.yml. It attempts a pinned Waxum v0.16.1 build on glibc 2.31 while disabling native VoIP relay and native Opus, retaining the encoded-call runtime and core QR/messaging feature dependencies. No Lite binary is imported by DigiOps, installed by WaBridge or started by Supervisor. The workflow must compile, pass ABI checks and basic REST smoke, and show a measured size benefit.

Even a successful Lite compilation does not prove API behavior or phone compatibility. Test with real QR, sessions, messages, reconnect and receipts on an isolated staging host before considering any switch. Retain a tested rollback. Native VoIP may no longer work.

## P5 — Live acceptance
Authenticated page: /wabridge/verification. It runs read-only checks, and labels real-device and Supervisor-restart gates MANUAL until actual evidence exists.

1. Deploy verified DigiOps artifact. Check pretty routes including /wabridge/performance and /wabridge/verification, and ensure /wabridge/api/diagnostics still returns authenticated JSON.
2. Cloudways confirms Supervisor wbdbbbkzpe_2:wbdbbbkzpe_2_00 RUNNING with autostart and autorestart; port 3451 remains publicly blocked.
3. Observe gateway.ready true and HTTP 200 under Diagnostics.
4. Link one WhatsApp number you control by QR. Confirm its session becomes connected.
5. Send exactly one consented test message. Confirm queue -> accepted and a correlated provider message ID.
6. Only mark delivered/read after a matching signed receipt. If the provider receipt is unavailable, report delivery unknown.
7. Recheck P3 memory, CPU, threads and disk with one connected account.
8. Have Cloudways restart the supervised process; verify automatic reconnect and persistence of the same linked session without re-pairing.
9. Review errors, rate limiting, QR refresh and unauthorized API access before enabling more accounts.

There is no credible way to complete the physical WhatsApp QR scan, signed delivery receipt or Cloudways restart from a GitHub build alone. These are real deployment acceptance gates.

## Rollback
Keep the previous working DigiOps artifact and private compatible Rust binary. A UI deployment must not overwrite runtime SQLite/JSON, SUPERADMIN_TOKEN or Supervisor configuration. Restore the last UI release if routes fail. Lite candidates must remain isolated.

## Cloudways support commands (optional)
- ps -C waxum -o pid,rss,%cpu,etime,nlwp
- du -sh /home/1323555.cloudwaysapps.com/wbdbbbkzpe/private_html/wabridge/runtime/waxum-provision /home/1323555.cloudwaysapps.com/wbdbbbkzpe/private_html/wabridge/runtime/waxum-data

Do not share environment variables, JWT keys, SUPERADMIN_TOKEN or session databases in support chat.
