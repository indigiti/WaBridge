# Deploy WaBridge using DigiOps

DigiOps application configuration:
- Repository: indigiti/WaBridge
- Branch: main
- Artifact: digiops-release
- Public URL: /wabridge/
- Public target: public_html/wabridge/
- Private target: private_html/wabridge/

## Release layout

The successful GitHub Actions workflow **DigiOps release** uploads one ZIP artifact with two root directories:

- public/ => public_html/wabridge/ (PHP entrypoint, assets, limited API routes, loader, .htaccess)
- private/ => private_html/wabridge/ (PHP application sources, CLI workers and example configuration)

All runtime state is excluded: config.php, .env, SQLite databases, messaging logs and WhatsApp linked-device credentials.

DigiOps deploys this exact artifact; it does not build PHP or run the Rust protocol engine.

## Before pressing Deploy

1. In DigiOps click **Check update** and verify a successful **DigiOps release** workflow and non-expired digiops-release artifact on branch main.
2. Ensure PHP 8.2+, cURL, mbstring and PDO_SQLITE are enabled.
3. Configure private_html/wabridge/config.php from config.example.php (not in Git). Use a valid admin password hash, strong gateway token, private gateway URL and webhook secret.
4. Ensure private_html/wabridge/runtime/ is writable by PHP, and never exposed over HTTP.
5. Set the private Rust Waxum service on a separately supervised host or an approved persistent-process environment. PHP alone cannot scan a real WhatsApp QR or send messages.
6. Configure Cloudways cron for PHP cli/worker.php and cli/webhooks.php under private_html/wabridge/cli, not the public path. An optional five-minute private cli/watchdog.php observes session connections and creates a snapshot for Diagnostics.
7. Back up existing private configuration and SQLite/session state before the first deployment.

## Deploy and validate

- Use DigiOps **Deploy** only after selecting the verified workflow run, artifact ID and exact SHA.
- Verify the dashboard at /wabridge/.
- Verify /wabridge/api/v1/messages.php returns HTTP 405 when opened with GET; it must never expose PHP source or credentials.
- Check dashboard login, SQLite queue writes, API key revocation, QR pairing with a consenting test device, and session restoration after gateway restart.
- A successful DigiOps release indicates the PHP files were published, **not** that WhatsApp is connected, Rust is running, or messages are delivered.

## Paths used by PHP

The public/_loader.php file detects the private runtime at the sibling private_html/wabridge/ folder. For nonstandard server layouts, set WABRIDGE_PRIVATE_ROOT in PHP environment configuration to the absolute **private** directory. Never set it to a directory beneath public_html.

A PHP source checkout is still testable without the DigiOps folder layout. No private PHP source directory is included in the public release.

## If DigiOps reports no release

Check the GitHub Actions **DigiOps release** workflow rather than the **PHP checks** workflow. Only the former uploads the artifact that DigiOps needs. Verify that the GitHub installation can read workflow runs and download artifacts for indigiti/WaBridge.

## Security

The Rust service should be private, session secrets protected and encrypted at rest, and outbound messaging controlled by consent and rate limits. Unofficial linked-device WhatsApp connectivity is operationally risky and should not be the sole OTP authentication channel.
