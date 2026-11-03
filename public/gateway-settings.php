<?php
declare(strict_types=1);
require __DIR__ . '/_loader.php';
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'none'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; style-src 'self'");
if (!admin_authenticated()) {
    http_response_code(403);
    exit('Sign in to WaBridge before managing gateway settings.');
}
$notice = (string)($_SESSION['gateway_settings_notice'] ?? '');
unset($_SESSION['gateway_settings_notice']);
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        assert_csrf();
        if (($_POST['action'] ?? '') !== 'save') throw new InvalidArgumentException('Unsupported action');
        if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 4096) {
            throw new InvalidArgumentException('Request exceeds size limit');
        }
        $token = (string)($_POST['gateway_token'] ?? '');
        gateway_settings_save($token);
        audit_event('gateway_token_updated', 'admin', 'localhost:3451');
        $_SESSION['gateway_settings_notice'] = 'Private gateway token saved. Connectivity is checked separately below.';
        header('Location: gateway-settings.php', true, 303);
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save settings. Check private runtime permissions.';
        error_log('WaBridge gateway settings write failed: ' . get_class($e));
    }
}
$existing = gateway_settings_read();
$diag = gateway_diagnostics();
$gatewayConfigured = !empty(config()['gateway_token']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Gateway Settings · WaBridge</title>
  <link rel="stylesheet" href="/wabridge/assets/wabridge-premium-v2.css?v=3">
</head>
<body class="dashboard-page">
  <div class="site-shell">
    <header class="site-header">
      <a class="brand-lockup" href="./"><span class="brand-mark" aria-hidden="true"><span class="brand-mark-inner">W</span></span><span class="brand-copy"><strong>Wa<span>Bridge</span></strong><small>MESSAGING INFRASTRUCTURE</small></span></a>
      <div class="header-right"><a class="button button-ghost button-small" href="./">Back to dashboard</a></div>
    </header>
    <main class="workspace">
      <div class="workspace-top"><div>
        <div class="eyebrow">WABRIDGE / WEB-ONLY SETUP</div>
        <h1>Gateway settings</h1>
        <p class="lead">Connect your PHP dashboard to a Rust service already running on the same Cloudways server.</p>
      </div></div>
      <?php if ($notice !== ''): ?><p class="notice notice-success" role="status"><?= escape($notice) ?></p><?php endif; ?>
      <?php if ($error !== ''): ?><p class="notice notice-warning" role="alert"><?= escape($error) ?></p><?php endif; ?>
      <section class="glass-panel workspace-section">
        <div class="panel-heading"><div><div class="eyebrow">STEP 1 / CONNECTION</div><h2>Local Rust connection</h2></div></div>
        <p class="panel-help">For your same-server test the address is fixed to localhost, preventing this form from sending your gateway secret to an arbitrary external server.</p>
        <form method="post" class="form-stack" autocomplete="off">
          <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
          <input type="hidden" name="action" value="save">
          <div class="field">
            <label for="gateway_url">Gateway URL</label>
            <input id="gateway_url" value="<?= escape(local_gateway_url()) ?>" readonly aria-describedby="url_help">
            <span id="url_help" class="panel-help">The address of Rust on this server—not the public WaBridge URL.</span>
          </div>
          <div class="field">
            <label for="gateway_token">Waxum SUPERADMIN_TOKEN</label>
            <input id="gateway_token" name="gateway_token" type="password" required minlength="32" maxlength="256" spellcheck="false" autocomplete="new-password" placeholder="<?= $gatewayConfigured ? 'Paste a new token to replace existing credentials' : 'Paste the token configured in Waxum' ?>">
            <span class="panel-help">The saved secret is never displayed. The token must match the Waxum server's configured SUPERADMIN_TOKEN.</span>
          </div>
          <button class="button button-primary" type="submit">Save private connection settings</button>
        </form>
      </section>
      <div class="stats-grid workspace-section">
        <div class="stat-card">
          <p class="stat-label">Gateway credential</p>
          <p class="stat-value"><?= $gatewayConfigured ? 'Saved' : 'Missing' ?></p>
          <p class="stat-foot"><?= $existing ? 'Browser-managed override in private runtime' : 'Loaded from original private configuration (if present)' ?></p>
        </div>
        <div class="stat-card">
          <p class="stat-label">Rust process status</p>
          <p class="stat-value <?= $diag['ready'] ? 'tone-success' : 'tone-amber' ?>"><?= escape(str_replace('_', ' ', (string)$diag['state'])) ?></p>
          <p class="stat-foot"><?= escape((string)$diag['message']) ?></p>
        </div>
        <div class="stat-card">
          <p class="stat-label">Hosting requirement</p>
          <p class="stat-value">Supervisor</p>
          <p class="stat-foot">Cloudways must approve a long-running Rust process. Saving settings does not install or start Rust.</p>
        </div>
      </div>
      <section class="glass-panel workspace-section">
        <div class="panel-heading"><div><div class="eyebrow">STEP 2 / HOSTING</div><h2>Request web-only Rust activation</h2></div></div>
        <p class="panel-help">Use Cloudways Support Chat or Help Desk from the Cloudways browser dashboard. Do not send tokens or private configuration through support chat.</p>
        <div class="setup-steps">
          <p><strong>Request:</strong> “Please confirm whether Cloudways Supervisord can run a single custom Rust executable for my Custom PHP application at stage.digiti.in/wabridge/, not a Laravel queue worker. I need the process supervised across reboot, with SQLite persistence outside the webroot. Keep TCP port 3451 blocked from public access while allowing PHP to connect locally at 127.0.0.1:3451. Can this be provisioned entirely using your browser control panel or by your support team?”</p>
          <p><strong>If approved:</strong> Ask Cloudways to provide a secure method to deploy a pinned Waxum binary, configure its private token, and assign a supported supervisor. No user terminal should be necessary.</p>
          <p><strong>If declined:</strong> Same-server, web-only persistent Rust hosting is not feasible on this Cloudways plan. Use a managed external Rust host or the official WhatsApp Cloud API instead.</p>
        </div>
      </section>
      <p class="panel-help"><a href="diagnostics.php">Open full diagnostics</a> · <a href="./">Return to dashboard</a></p>
    </main>
    <footer class="site-footer">WaBridge · Secure, web-managed configuration</footer>
  </div>
</body></html>
