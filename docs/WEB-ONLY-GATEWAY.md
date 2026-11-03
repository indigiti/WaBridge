# WaBridge — Browser-only Cloudways gateway onboarding

## Supported through WaBridge's website

Once DigiOps deploys a successful `digiops-release` artifact and you are logged in, open:

https://stage.digiti.in/wabridge/gateway-settings.php

The browser-only settings page:
- fixes the Rust API target to `http://127.0.0.1:3451` for a same-server deployment;
- accepts a **pre-existing** Waxum `SUPERADMIN_TOKEN`, saved with 0600 permissions at `private_html/wabridge/runtime/gateway-settings.json`;
- never renders the saved secret in HTML;
- uses normal administrator session authentication, CSRF verification and metadata-only audit logging;
- does **not** run shell commands, download executables, create root services or start the WhatsApp gateway.

Settings are loaded on subsequent PHP requests and override only `gateway_url` and `gateway_token` from the original private `config.php`. Existing login credentials are untouched. Config and runtime secrets are excluded from DigiOps release artifacts.

## Actual Rust execution prerequisite

Cloudways' documented Supervisord web interface specifically provisions Laravel workers. An arbitrary Rust executable may not be supported without intervention from Cloudways Support.

Use **Cloudways browser dashboard → Support Chat** and request:

> Can Cloudways run a custom, pinned Rust Waxum binary for a Custom PHP application on the same Cloudways server, supervise it across failures and reboots, and permit only local 127.0.0.1 access to TCP 3451? The binary and data must stay outside public_html. I require **web-only setup with no SSH or terminal**, so please confirm whether your staff can provision and manage its service or provide a supported browser-only workflow.

Do not give Cloudways Support the administrator password or `SUPERADMIN_TOKEN` through unapproved channels. Arrange any secret provisioning through a secure provider-approved mechanism. Prefer a least-privilege gateway credential if Waxum supports it in the pinned deployed release.

Waxum's upstream source currently binds `0.0.0.0:3451`, so firewall restrictions are critical. Opening the port publicly is not an acceptable workaround.

If Cloudways does not approve persistent custom daemons, the same-server requirement and zero-terminal requirement cannot both be satisfied reliably. Alternatives are:
- keep the PHP UI at stage.digiti.in and use a managed Rust service provisioned through a provider's web UI, connected privately over TLS;
- switch to Meta's official WhatsApp Cloud API (web-only, but no QR device linking).

## Release candidate status

DigiOps's **“New source commit detected, but no newer deployable artifact is ready”** is a build-selection state, not a WhatsApp error. The correct sequence is:

1. GitHub → WaBridge → Actions → `DigiOps release` → confirm the latest `main` commit has a **successful** run.
2. Confirm that run has an unexpired artifact named exactly `digiops-release`.
3. In DigiOps → WaBridge, click **Check update**.
4. Select the successful run and artifact matching the exact commit. Do not deploy stale artifacts.
5. Click Deploy. Open the web Gateway Settings page and save the **already configured** Rust secret.
6. Confirm `diagnostics.php` changes from `not_configured` to `ready`. Saving the token without an active Rust process changes the state to `unreachable`, not `ready`.

Tests: `php tests/gateway-settings.php`. The DigiOps workflow checks the presence of the page and private backend before uploading an artifact.
