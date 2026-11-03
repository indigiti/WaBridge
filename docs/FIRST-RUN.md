# First-run Cloudways configuration

WaBridge intentionally does not ship administrator passwords or live WhatsApp tokens in release artifacts.

## Filesystem

DigiOps installs deployable code into:
- public_html/wabridge/ (browser-accessible files only)
- private_html/wabridge/ (PHP source and CLI workers)

Create `private_html/wabridge/config.php` privately, next to `config.example.php`. Do not place it in public_html. DigiOps release overlays preserve the private configuration because config.php is excluded from release payloads.

## Required settings for administrator login

Copy config.example.php to config.php using your private file manager or SFTP client. Set:
- `admin_user`: non-default administrator identifier
- `admin_password_hash`: a PHP `password_hash` output for a unique password, never plaintext

Generate the hash on a trusted local PHP installation with:

```sh
php -r 'echo password_hash("REPLACE_WITH_YOUR_RANDOM_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
```

If the application is intentionally being staged without an active Rust service, `gateway_token` may remain empty for the admin configuration stage; the dashboard will show gateway unavailable until configured. Do not use fake tokens for production.

To enable WhatsApp pairing and sending, install a persistent Waxum Rust gateway in a supported environment, set its token privately, set gateway_url to a privately reachable HTTPS/TLS protected endpoint, then set gateway_token to its matching credential. Never expose the upstream Rust service to the open Internet.

## Safe verification

1. After configuring config.php, reload the dashboard. The `PRIVATE_CONFIG_MISSING` or `ADMIN_CREDENTIALS_NOT_CONFIGURED` notice should disappear and the sign-in form should render.
2. Sign in with the configured administrator credentials.
3. Check the gateway status separately. Without the Rust gateway, QR login and message delivery do not work.
4. Check PHP cURL, mbstring, PDO SQLite and write access to private_html/wabridge/runtime.
5. Do not send screenshot or paste private configuration contents, password hashes or tokens into support tickets.

If the private runtime folder exists but the web process cannot read it, the issue is filesystem permission or application-root mapping. It is not fixed by moving private files into the webroot.
