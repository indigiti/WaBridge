# WaBridge

A PHP control plane for a separately deployed Rust Waxum WhatsApp gateway. Includes a device dashboard, authenticated QR pairing proxy, SQLite message queue, scoped client API, and webhook dispatch workers.

No Node.js or separate database server is required for the development configuration. The Rust engine must run as a persistent service.

## Getting started

Read [deployment instructions](docs/DEPLOYMENT.md) and [release acceptance checklist](docs/PRODUCTION-GATES.md). PHP 8.2+ needs PDO SQLite, cURL, mbstring and sessions. The PHP document root must point to public/ and configuration must remain outside it.

## Status

Integration preview: PHP checks pass in GitHub Actions; live WhatsApp QR pairing, message delivery, server restart recovery, Cloudways suitability and production performance require verification. Do not treat this as a fully deployed production system.
