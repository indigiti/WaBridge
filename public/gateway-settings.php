<?php
declare(strict_types=1);
require __DIR__ . '/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/waxum-provisioning.php';
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
$generatedToken = ''; // only emitted on the request in which it was created
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        assert_csrf();
        if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 4096)
            throw new InvalidArgumentException('Request exceeds size limit');
        $action = (string)($_POST['action'] ?? '');
        if ($action==='save') {
            if (gateway_diagnostics()['ready'])
                throw new InvalidArgumentException('Do not rotate a connected Rust service token through the dashboard. Coordinate rotation with the service manager.');
            $token = (string)($_POST['gateway_token'] ?? '');
            gateway_settings_save($token);
            audit_event('gateway_token_updated','admin','localhost:3451');
            $_SESSION['gateway_settings_notice']='Private gateway token saved. Saving alone does not start Rust.';
            header('Location: gateway-settings.php',true,303);
            exit;
        }
        if ($action==='generate') {
            $generatedToken=waxum_prepare_new_token();
            audit_event('waxum_credential_prepared','admin','localhost:3451');
            $notice='SUPERADMIN_TOKEN generated and saved privately. Copy it now if needed. The Rust process has NOT been started.';
        } elseif ($action==='stage') {
            $result=waxum_stage_binary();
            audit_event('waxum_binary_staged','admin','v0.16.1',['status'=>$result]);
            $_SESSION['gateway_settings_notice']='Pinned Waxum executable '.$result.' privately. A Cloudways-approved service manager must still start and supervise it.';
            header('Location: gateway-settings.php',true,303);
            exit;
        } elseif ($action==='compat_install') {
            if (($_POST['ack_supervisor_stopped'] ?? '') !== 'yes')
                throw new InvalidArgumentException('Confirm that Cloudways Supervisor has been stopped before replacing the Rust executable.');
            if (gateway_diagnostics()['ready'])
                throw new InvalidArgumentException('Rust is responding. Stop the service through Cloudways before executable replacement.');
            $result=waxum_install_compat_binary();
            audit_event('waxum_compat_binary_installed','admin','v0.16.1-glibc231',['result'=>$result]);
            $_SESSION['gateway_settings_notice']='Cloudways-compatible Waxum '.$result.' privately. Ask Cloudways to restart the existing Supervisor job, then check Diagnostics. Do not change the token or database settings.';
            header('Location: gateway-settings.php',true,303);
            exit;
        } elseif ($action==='sqlite_test') {
            if (gateway_diagnostics()['ready'])
                throw new InvalidArgumentException('Rust is already ready. Stop and back up the gateway before any storage change.');
            if (($_POST['ack_test_sqlite'] ?? '') !== 'yes')
                throw new InvalidArgumentException('Confirm Rust-only SQLite testing mode.');
            $result=waxum_enable_test_sqlite();
            audit_event('waxum_sqlite_test_storage_enabled','admin','private_waxum_metadata');
            $_SESSION['gateway_settings_notice']='Rust SQLite testing mode '.$result.'. Ask Cloudways to restart the existing Supervisor job; do not regenerate the SUPERADMIN_TOKEN. PHP storage remains JSON.';
            header('Location: gateway-settings.php',true,303);
            exit;
        } else {
            throw new InvalidArgumentException('Unsupported action');
        }
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save settings. Check private runtime permissions.';
        error_log('WaBridge gateway settings write failed: ' . get_class($e));
    }
}
$existing = gateway_settings_read();
$diag = gateway_diagnostics();
$gatewayConfigured = !empty(config()['gateway_token']) || $generatedToken!=='';
$rustPrep=waxum_provision_status();
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
        <div class="panel-heading"><div><div class="eyebrow">STEP 1 / PREPARE CREDENTIAL</div><h2>Generate SUPERADMIN_TOKEN</h2></div></div>
        <p class="panel-help">Use this to prepare a new Rust service before its first connection. PHP generates a cryptographically random token and prepares a restricted private environment file for the approved process supervisor. This does not create or start a Rust process.</p>
        <?php if ($generatedToken!==''): ?>
          <div class="notice notice-success" role="status"><strong>One-time generated credential</strong> — copy it now. This value will not be shown again.</div>
          <div class="field">
            <label for="issued_token">SUPERADMIN_TOKEN — one-time display</label>
            <input id="issued_token" type="text" value="<?= escape($generatedToken) ?>" readonly spellcheck="false" autocomplete="off" >
          </div>
          <p class="panel-help">The same secret is already stored in private gateway settings and the private Waxum provisioning file. Never include this value in email, chat, logs or support tickets.</p>
        <?php elseif (!$gatewayConfigured): ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
            <input type="hidden" name="action" value="generate">
            <button class="button button-primary" type="submit">Generate new Rust SUPERADMIN_TOKEN</button>
          </form>
        <?php else: ?>
          <p class="panel-help"><strong>A gateway credential is already saved.</strong> The existing secret is hidden. Do not generate an unrelated replacement while the Rust service might be using the old credential.</p>
        <?php endif; ?>
      </section>
      <section class="glass-panel workspace-section">
        <div class="panel-heading"><div><div class="eyebrow">STEP 2 / EXECUTABLE</div><h2>Prepare verified Waxum Rust binary</h2></div></div>
        <p class="panel-help">DigiOps packages the pinned upstream Waxum v0.16.1 Linux AMD64 archive in the private release. The server is <?= escape((string)$rustPrep['platform_arch']) ?>. The downloaded archive is SHA-256 verified before it is included in DigiOps and reverified before private extraction.</p>
        <p class="panel-help"><strong>Archive:</strong> <?= $rustPrep['archive_checksum_valid'] ? 'Verified' : 'Missing or unverified' ?> ·
        <strong>Executable:</strong> <?= $rustPrep['binary_staged'] ? 'Privately staged' : 'Not staged' ?> ·
        <strong>Service manager:</strong> Not provisioned through WaBridge</p>
        <?php if ($rustPrep['binary_archive_ready'] && !$rustPrep['binary_staged']): ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
            <input type="hidden" name="action" value="stage">
            <button class="button button-primary" type="submit">Prepare executable in private runtime</button>
          </form>
        <?php endif; ?>
        <p class="panel-help">Preparation extracts a fixed, checksum-pinned ELF binary. It does not execute it. Cloudways must explicitly support running and supervising the binary; PHP cannot safely make a permanent service by spawning a detached process from this webpage.</p>
        <div class="setup-steps">
          <p><strong>Cloudways compatibility:</strong> The standard Waxum v0.16.1 executable needs glibc 2.38/2.39, but your Cloudways server reports glibc 2.36 (x86_64). The separate source-pinned compatibility build was compiled against glibc 2.31 and passed an ELF ABI audit.</p>
          <p><strong>Compatible package:</strong> <?= $rustPrep['compat_archive_verified'] ? 'SHA-256 verified and available in private release' : 'Not present or checksum mismatch' ?> ·
          <strong>Executable:</strong> <?= $rustPrep['compat_binary_installed'] ? 'Verified compatible variant installed' : 'Compatible variant not yet installed' ?></p>
          <?php if ($rustPrep['compat_archive_verified'] && !$rustPrep['compat_binary_installed']): ?>
            <form method="post" class="form-stack">
              <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
              <input type="hidden" name="action" value="compat_install">
              <label>
                <input type="checkbox" name="ack_supervisor_stopped" value="yes" required>
                I confirm Cloudways has stopped the existing Waxum Supervisor job. Replacing the executable will preserve the previous binary as a private non-executable backup and will not modify my secret, PHP JSON storage, or WhatsApp session data.
              </label>
              <button class="button button-primary" type="submit">Install Cloudways-compatible Rust executable</button>
            </form>
          <?php elseif ($rustPrep['compat_binary_installed']): ?>
            <p class="notice notice-success" role="status">Cloudways-compatible binary installed and verified. Ask Cloudways to start the existing Supervisor job and check full diagnostics.</p>
          <?php endif; ?>
        </div>

        <div class="notice notice-warning" role="note"><strong>Do not start while port 3451 is publicly accessible.</strong> The pinned upstream Waxum HTTP server binds all interfaces, not localhost-only. Cloudways must block public ingress and provide an approved supervisor before process startup.</div>
        <p class="panel-help"><strong>Separate storage prerequisite:</strong> PHP JSON mode does not replace Waxum's internal database and linked-device state storage. Cloudways must approve the actual Rust storage configuration before first startup.</p>
      </section>
      <section class="glass-panel workspace-section">
        <div class="panel-heading"><div><div class="eyebrow">STEP 3 / RUST DATABASE</div><h2>Choose Rust storage for testing</h2></div></div>
        <p class="panel-help">Cloudways' Supervisor error <code>DATABASE_URL missing</code> is an intentional startup check. Waxum requires persistent storage even while WaBridge PHP uses JSON. For initial single-server testing, Waxum can create its own private SQLite files without a managed MySQL/MariaDB database.</p>
        <p class="panel-help"><strong>Rust database mode:</strong>
          <?= $rustPrep['database_mode']==='sqlite_testing' ? 'Private SQLite — testing only' : ($rustPrep['database_mode']==='custom_private' ? 'Existing private configuration (not modified)' : 'Not configured in private file') ?>
        </p>
        <?php if ($rustPrep['database_mode']==='not_configured'): ?>
          <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
            <input type="hidden" name="action" value="sqlite_test">
            <label>
              <input type="checkbox" name="ack_test_sqlite" value="yes" required>
              I understand Waxum will use SQLite files privately for initial testing, while WaBridge PHP continues using JSON. This is not a no-database implementation or a production HA setup.
            </label>
            <button class="button button-primary" type="submit">Enable private Rust SQLite test mode</button>
          </form>
        <?php elseif ($rustPrep['database_mode']==='sqlite_testing'): ?>
          <p class="notice notice-success" role="status">Private Rust SQLite test configuration is saved. Ask Cloudways to restart the existing Supervisor job, then check Diagnostics. No token rotation is required.</p>
        <?php else: ?>
          <p class="panel-help">A private Rust database configuration already exists. This dashboard will not overwrite custom or production database settings.</p>
        <?php endif; ?>
        <p class="panel-help"><strong>Security:</strong> WhatsApp linked-device sessions also persist to private files. Back them up and protect them from public web access. Before production, review disk persistence, encryption, backup/recovery and the appropriate managed storage architecture.</p>
      </section>
      <section class="glass-panel workspace-section">
        <div class="panel-heading"><div><div class="eyebrow">STEP 4 / CONNECTION</div><h2>Local Rust connection</h2></div></div>
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
          <p class="stat-foot">Rust process lifecycle is managed by Cloudways Supervisor. This dashboard does not control the Supervisor job.</p>
        </div>
      </div>
      <section class="glass-panel workspace-section">
        <div class="panel-heading"><div><div class="eyebrow">STEP 5 / SUPERVISOR</div><h2>Request web-only Rust activation</h2></div></div>
        <p class="panel-help">Cloudways Support confirmed they can provision a Supervisor job when provided with a startup command. Copy this command to their support chat; it contains no secret.</p>
        <?php $supervisorScript=dirname((new ReflectionFunction('store'))->getFileName(),2).'/cli/waxum-supervisor.sh'; ?>
        <div class="field">
          <label for="supervisor_command">Cloudways Supervisor command (absolute server path)</label>
          <input id="supervisor_command" type="text" readonly spellcheck="false" value="<?= escape('/bin/bash '.escapeshellarg($supervisorScript)) ?>">
          <span class="panel-help">Ask Support to run the process as the Cloudways application user, use this exact command, configure automatic restart on failure and reboot, and keep logs private.</span>
        </div>
        <p class="panel-help">The script loads your saved secret from the private environment file. For testing, select <strong>Enable private Rust SQLite test mode</strong> above, then have Cloudways restart Supervisor. Alternatively, a tested <code>DATABASE_URL</code> for managed MySQL/MariaDB or PostgreSQL can be set securely. The script refuses to start without an explicitly selected database mode. Do not paste actual database credentials or tokens into support chat.</p>
        <div class="setup-steps">
          <p><strong>Request:</strong> “Please confirm whether Cloudways Supervisord can run a single custom Rust executable for my Custom PHP application at stage.digiti.in/wabridge/, not a Laravel queue worker. I need the process supervised across reboot, with a storage backend approved for WhatsApp session persistence outside the webroot. Ensure TCP port 3451 is blocked from public access before starting the service, while allowing PHP to connect locally at 127.0.0.1:3451. Use the exact Supervisor command shown above and restart it after a verified database option is configured. Can this be provisioned entirely using your browser control panel or by your support team?”</p>
          <p><strong>If approved:</strong> Ask Cloudways to provide a secure method to deploy a pinned Waxum binary, configure its private token, and assign a supported supervisor. No user terminal should be necessary.</p>
          <p><strong>If declined:</strong> Same-server, web-only persistent Rust hosting is not feasible on this Cloudways plan. Use a managed external Rust host or the official WhatsApp Cloud API instead.</p>
        </div>
      </section>
      <p class="panel-help"><a href="diagnostics.php">Open full diagnostics</a> · <a href="./">Return to dashboard</a></p>
    </main>
    <footer class="site-footer">WaBridge · Secure, web-managed configuration · Runtime startup requires an approved supervisor</footer>
  </div>
</body></html>
