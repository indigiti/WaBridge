# Deployment and operational checklist

## PHP dashboard on Cloudways

1. PHP 8.2+ with cURL, mbstring, sessions, JSON, and OPcache.
2. Point the domain's public document root at \`public/\`; prevent HTTP access to \`config.php\`, \`src/\` and other internal files. If Cloudways cannot change document root, deploy contents of \`public/\` to \`public_html/\`, with \`src/\` and \`config.php\` one level above it; review relative paths before deployment.
3. Copy \`config.example.php\` to \`config.php\`; set a unique password hash, token, internal gateway base URL and a strong hashed external API token. Example: \`php -r 'echo hash("sha256", bin2hex(random_bytes(32))), PHP_EOL;'\` creates a hashed random value **without exposing the underlying token**; for a usable token, generate it once locally and store token and hash separately.
4. Enforce HTTPS, protect the web dashboard with additional ingress authentication if available, and configure PHP session storage.
5. Do not share dashboard and external API tokens with browser JavaScript or embed them in public HTML.

## Rust gateway

Waxum runs as a separate persistent service. On standard Cloudways, support for custom supervised daemon services is not guaranteed. Use a private VPS if needed. Build or download a pinned Waxum release, set its required token and SQLite storage path, and expose its listener only to the PHP server through a private connection or tightly restricted IP/firewall plus TLS. Configure restart supervision, health checks, encrypted disk or encrypted volume and secure backups.

**Do not use the PHP dashboard as a way to start or restart a daemon from the public Internet.**

## Cloudways acceptance tests

- [ ] Dashboard login over HTTPS
- [ ] Rust health probe returns ready
- [ ] Create session, scan QR, verify connected
- [ ] Send to a consenting test number
- [ ] Receive delivery update / webhook and test message status
- [ ] Restart Rust process; verify session restores without rescanning
- [ ] Inspect RAM/CPU, file permissions, backup recovery
- [ ] Disable or revoke API credentials; verify old token no longer works

## Current limitations

This repository contains a functional PHP control-plane scaffold but no deployment to your hosting server. Waxum's API payloads are version dependent and must be checked against the installed \`/swagger-ui\`. The PHP send proxy directly sends through Waxum, so it does **not** guarantee delivery, deduplication or durable queue acceptance; add those before using automated OTP flows or high-volume workloads. It provides a single-admin MVP; it does not implement production SaaS tenant isolation, key lifecycle, webhook ingestion, billing or audit retention. Rate-limit requests at Nginx/WAF until a durable per-key rate limiter is implemented.
