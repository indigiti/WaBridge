<?php
declare(strict_types=1);
require __DIR__ . '/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/message-log.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; style-src 'self'");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000');
$notice = '';
$error = '';
$issuedToken='';
$queuedJobId='';
$setupError='';
try {
    $cfg = config();
    start_admin_session();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        assert_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            $username = (string)($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
            $loginClient=['id'=>'login-'.hash('sha256',$ip),'limit'=>5];
            if (!limit_client(store(),$loginClient)) {
                $error='Too many sign-in attempts. Try again later.';
            } elseif (hash_equals((string)$cfg['admin_user'], $username) &&
                password_verify($password, (string)$cfg['admin_password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin']=true;
                $_SESSION['login_at']=time();
                $_SESSION['last_seen']=time();
                $notice='Signed in successfully.';
                audit_event('admin_login','admin');
                header('Location: /wabridge/',true,303);
                exit;
            } else {
                usleep(200000);
                $error='Invalid credentials.';
            }
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            $notice = 'Signed out.';
            audit_event('admin_logout','admin');
            header('Location: /wabridge/',true,303);
            exit;
        } elseif (admin_authenticated()) {
            if ($action === 'send') {
                [$sid, $to, $text] = validate_message([
                    'session_id'=>$_POST['session_id'] ?? '',
                    'to'=>$_POST['to'] ?? '',
                    'text'=>$_POST['text'] ?? ''
                ]);
                $job = enqueue_message(store(), ['id'=>'admin','sessions'=>[$sid],'limit'=>10], $sid, $to, $text, 'admin-'.bin2hex(random_bytes(12)));
                $queuedJobId=(string)$job['id'];
                $notice = 'Message queued (ID '.$job['id'].'). Open Message activity to process this job or review its status; delivery is not confirmed.';
            } elseif ($action === 'create') {
                // Match Waxum's session creation fields to the installed engine version.
                $name = trim((string)($_POST['session_name'] ?? ''));
                if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $name)) throw new InvalidArgumentException('Invalid session name.');
                $r = gateway('POST', '/api/v1/sessions', ['id'=>$name,'name'=>$name]);
                $notice = $r['http'] >= 200 && $r['http'] < 300 ? 'Session request accepted.' : '';
                if (!$notice) $error = 'Could not create session (HTTP '.$r['http'].').';
            } elseif ($action==='new_client') {
                $id=(string)($_POST['client_id']??'');
                $raw=trim((string)($_POST['client_sessions']??''));
                if (!preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',$id)) throw new InvalidArgumentException('Invalid application ID');
                $sessions=explode(',',$raw);
                if (count($sessions)>20 || !$sessions) throw new InvalidArgumentException('Invalid session scope');
                foreach ($sessions as $session) if (!valid_session_id($session)) throw new InvalidArgumentException('Invalid session scope');
                $sessions=array_values(array_unique($sessions));
                $rate=max(1,min(300,(int)($_POST['client_rate']??20)));
                $ttl=max(1,min(365,(int)($_POST['client_ttl_days']??90)));
                $expires=time()+$ttl*86400;
                $issuedToken=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
                storage_client_create(['id'=>$id,'token_sha256'=>hash('sha256',$issuedToken),'sessions_json'=>json_encode($sessions,JSON_THROW_ON_ERROR),'per_minute'=>$rate,'created_at'=>time(),'expires_at'=>$expires]);
                $notice='Application created. Copy its API token now; it will not be shown again.';
                audit_event('api_client_created','admin',$id,['sessions'=>$sessions,'per_minute'=>$rate]);
            } elseif ($action==='revoke_client') {
                $id=(string)($_POST['client_id']??'');
                if (!preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',$id)) throw new InvalidArgumentException('Invalid application ID');
                storage_client_revoke($id);
                $notice='API application access revoked.';
                audit_event('api_client_revoked','admin',$id);
            }
        }
    }
} catch (Throwable $e) {
    if (in_array($e->getMessage(), ['PRIVATE_CONFIG_MISSING','ADMIN_CREDENTIALS_NOT_CONFIGURED','ADMIN_USERNAME_NOT_CONFIGURED','INVALID_PRIVATE_CONFIG'], true)) {
        $setupError=$e->getMessage();
        http_response_code(503);
    } else {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Operation failed; check server configuration and logs.';
        error_log('WaBridge admin operation '.get_class($e).': '.substr($e->getMessage(),0,160));
    }
}
$authed = admin_authenticated();
$view=(string)($_GET['view']??'overview');
if (!in_array($view,['overview','devices','sessions','messaging','api-applications'],true)) $view='overview';
$apiClients=[];
$sessions = [];
$health = 'Not connected';
$gatewayState = ['ready'=>false,'state'=>'not_configured','message'=>'Rust connection not configured'];
$stats = ['queued'=>0,'accepted'=>0,'needs_review'=>0];
$recentMessages=[];
if ($authed) {
    try {
        $apiClients=storage_clients();
        foreach (storage_queue_stats() as $row) { if (isset($stats[$row['state']])) $stats[$row['state']] = (int)$row['n']; }
        $gatewayState = gateway_diagnostics();
        $health = $gatewayState['ready'] ? 'Ready' : ucfirst(str_replace('_',' ', $gatewayState['state']));
        $sessions = $gatewayState['ready'] ? upstream_sessions() : [];
        foreach (storage_admin_messages(6) as $msg) {
            if (!permitted_session((string)$msg['session_id'])) continue;
            $msg['effective_state']=message_log_effective_status($msg);
            $recentMessages[]=$msg;
        }
    } catch (Throwable $e) {
        $error = 'Gateway unavailable; check Rust service and configuration.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#f3f6fc">
  <title>WaBridge — Control Center</title>
  <link rel="stylesheet" href="/wabridge/assets/wabridge-ui-v6.css?v=6">
  <?php if ($authed): ?><script src="/wabridge/assets/wabridge-console-v3.js?v=2" defer></script><?php endif; ?>
</head>
<body class="<?= $authed ? 'dashboard-page page-view-'.escape($view) : 'auth-page' ?>">
<div class="ambient ambient-one" aria-hidden="true"></div>
<div class="ambient ambient-two" aria-hidden="true"></div>
<div class="ambient ambient-three" aria-hidden="true"></div>
<?php if ($authed): ?>
  <?php ui_shell_start($view,(bool)$gatewayState['ready']); ?>
<?php else: ?>
<div class="site-shell">
  <header class="site-header">
    <a class="brand-lockup" href="/wabridge/" aria-label="WaBridge home">
      <span class="brand-mark" aria-hidden="true"><span class="brand-mark-inner">W</span></span>
      <span class="brand-copy"><strong>Wa<span>Bridge</span></strong><small>Messaging infrastructure</small></span>
    </a>
    <span class="environment-chip"><span class="live-dot"></span> Secure console</span>
  </header>
<?php endif; ?>
  <main class="<?= $authed ? 'workspace' : 'auth-main' ?>" id="main-content">
    <?php if ($setupError!==''): ?>
      <section class="auth-card setup-card" aria-labelledby="setup-title">
        <div class="card-symbol" aria-hidden="true">✦</div>
        <div class="eyebrow">Setup required</div>
        <h1 id="setup-title">A few details to begin.</h1>
        <p class="intro">The WaBridge application is installed. Configure private administrator credentials to activate your control center.</p>
        <div class="notice notice-warning" role="alert"><strong><?= escape($setupError) ?></strong></div>
        <div class="setup-steps">
          <p><strong>1.</strong> Create <code>private_html/wabridge/config.php</code> from <code>config.example.php</code>.</p>
          <p><strong>2.</strong> Set your administrator username and secure password hash.</p>
          <p><strong>3.</strong> Refresh this page to sign in. Rust connectivity can be configured separately.</p>
        </div>
        <p class="fine-print">Keep private settings and authentication secrets outside the public web directory.</p>
      </section>
    <?php elseif (!$authed): ?>
      <section class="auth-card" aria-labelledby="login-title">
        <div class="card-symbol" aria-hidden="true">✦</div>
        <div class="eyebrow">Your secure workspace</div>
        <h1 id="login-title">Welcome back.</h1>
        <p class="intro">Sign in to manage your connections, messaging and integrations.</p>
        <?php if ($notice): ?><div class="notice notice-success" role="status"><?= escape($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice notice-warning" role="alert"><?= escape($error) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
          <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
          <input type="hidden" name="action" value="login">
          <div class="field">
            <label for="u">Username</label>
            <div class="input-holder"><span class="input-glyph" aria-hidden="true">◎</span><input id="u" name="username" autocomplete="username" placeholder="Your username" required autofocus></div>
          </div>
          <div class="field">
            <label for="p">Password</label>
            <div class="input-holder"><span class="input-glyph" aria-hidden="true">◇</span><input id="p" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required></div>
          </div>
          <button class="button button-primary button-wide" type="submit">Sign in <span aria-hidden="true">→</span></button>
        </form>
        <div class="auth-footer"><span class="secure-mark">◈</span> Protected administrator access <span class="footer-separator">·</span> WaBridge</div>
      </section>
    <?php else: ?>
      <?php ui_breadcrumb('Operations',match($view){'devices'=>'Connect device','sessions'=>'Linked sessions','messaging'=>'Messaging','api-applications'=>'API applications',default=>'Overview'}); ?>
      <div class="workspace-top">
        <div><div class="eyebrow">OPERATIONS / <?= escape(strtoupper($view)) ?></div><h1><?= escape(match($view){'devices'=>'Connect a WhatsApp device','sessions'=>'Linked sessions','messaging'=>'Send a test message','api-applications'=>'API applications',default=>'Workspace overview'}) ?></h1><p class="lead"><?= escape(match($view){'devices'=>'Create a new session and pair your WhatsApp device securely.','sessions'=>'See your connected accounts, connection state and pairing actions.','messaging'=>'Queue a consented message and monitor its actual send status.','api-applications'=>'Issue and manage scoped application credentials.',default=>'A focused view of your connections, message pipeline and system health.'}) ?></p></div>
        <span class="workspace-chip"><span class="live-dot"></span> Secure workspace</span>
      </div>
      <?php if ($notice): ?><div class="notice notice-success" role="status"><?= escape($notice) ?><?php if ($queuedJobId!==''): ?> <a href="/wabridge/messages?id=<?= rawurlencode($queuedJobId) ?>"><strong>Track this message →</strong></a><?php endif; ?></div><?php endif; ?>
      <?php if ($error): ?><div class="notice notice-warning" role="alert"><?= escape($error) ?></div><?php endif; ?>
      <?php if ($view==='overview'): ?>
      <section class="console-hero" aria-labelledby="hero-title">
        <div class="console-hero-main">
          <div class="eyebrow">YOUR MESSAGING WORKSPACE</div>
          <h2 id="hero-title">Every connection.<br>One clear view.</h2>
          <p>Monitor your sessions, send messages with confidence and keep your integrations moving from a single control center.</p>
          <div class="console-hero-actions">
            <a href="/wabridge/devices"><?= ui_icon('devices') ?> Link a device</a>
            <a href="/wabridge/messaging"><?= ui_icon('messaging') ?> Send a message</a>
          </div>
        </div>
        <div class="console-hero-aside">
          <div class="console-hero-status">
            <div class="console-hero-status-label">Rust gateway</div>
            <strong><span class="live-dot" aria-hidden="true"></span><?= $gatewayState['ready']?'Operational':'Needs attention' ?></strong>
            <small><?= $gatewayState['ready']?'Gateway ready · WhatsApp sessions checked separately':'Open diagnostics to review the connection' ?></small>
          </div>
          <div class="console-hero-status">
            <div class="console-hero-status-label">Message pipeline</div>
            <strong><?= (int)$stats['queued'] ?> in queue</strong>
            <small>Review pending and uncertain messages in Activity</small>
          </div>
        </div>
      </section>
      <?php endif; ?>
      <?php if ($view==='overview'): ?>
      <section id="overview" aria-labelledby="overview-title" class="workspace-section">
        <div class="section-heading"><div><div class="eyebrow">REAL-TIME SNAPSHOT</div><h2 id="overview-title">At a glance</h2></div><span class="section-subtitle">Current gateway and message pipeline</span></div>
        <div class="stats-grid">
          <article class="stat-card"><span class="stat-icon" aria-hidden="true"><?= ui_icon('diagnostics') ?></span><p class="stat-label">Gateway health</p><div class="stat-value <?= $health==='Ready' ? 'tone-success' : 'tone-amber' ?>"><?= escape($health) ?></div><div class="stat-foot">Rust gateway readiness</div></article>
          <article class="stat-card"><span class="stat-icon" aria-hidden="true"><?= ui_icon('sessions') ?></span><p class="stat-label">Linked sessions</p><div class="stat-value"><?= count($sessions) ?></div><div class="stat-foot">Reported by gateway</div></article>
          <article class="stat-card"><span class="stat-icon" aria-hidden="true"><?= ui_icon('messaging') ?></span><p class="stat-label">Queued messages</p><div class="stat-value"><?= (int)$stats['queued'] ?></div><div class="stat-foot"><?= (int)$stats['accepted'] ?> accepted; not necessarily delivered</div></article>
          <article class="stat-card"><span class="stat-icon" aria-hidden="true"><?= ui_icon('verification') ?></span><p class="stat-label">Needs review</p><div class="stat-value"><?= (int)$stats['needs_review'] ?></div><div class="stat-foot"><a href="/wabridge/messages?status=needs_review">Review uncertain deliveries</a></div></article>
        </div>
      </section>
      <?php endif; ?>
      <?php if (!$gatewayState['ready']): ?><div class="notice notice-warning" role="status"><strong>WhatsApp gateway: <?= escape(str_replace('_',' ',(string)$gatewayState['state'])) ?>.</strong> <?= escape((string)$gatewayState['message']) ?> <a href="/wabridge/gateway">Configure gateway</a> · <a href="/wabridge/diagnostics">Diagnostics</a></div><?php endif; ?>
      <?php if (in_array($view,['devices','messaging'],true)): ?>
      <div class="workspace-two">
        <?php if ($view==='messaging'): ?>
        <section class="glass-panel workspace-section" id="messaging" aria-labelledby="message-title">
          <div class="panel-heading"><div><div class="eyebrow">MESSAGING</div><h2 id="message-title">Send a test message</h2></div><span class="soft-icon" aria-hidden="true"><?= ui_icon('messaging') ?></span></div>
          <p class="panel-help">Queue a message through a configured WhatsApp session. Submission is not proof of delivery.</p>
          <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="send">
            <div class="field"><label for="sid">Session ID</label><input id="sid" name="session_id" maxlength="64" placeholder="e.g. school-01" required></div>
            <div class="field"><label for="to">Recipient number</label><input id="to" name="to" maxlength="15" inputmode="numeric" placeholder="Country code + number, digits only" required></div>
            <div class="field"><label for="txt">Message</label><textarea id="txt" name="text" rows="4" maxlength="2000" placeholder="Write your message..." required></textarea></div>
            <button type="submit" class="button button-primary">Queue message <span aria-hidden="true">→</span></button>
          </form>
        </section>
        <?php endif; ?>
        <?php if ($view==='devices'): ?>
        <section class="glass-panel workspace-section" id="devices" aria-labelledby="device-title">
          <div class="panel-heading"><div><div class="eyebrow">CONNECTIONS</div><h2 id="device-title">Connect a device</h2></div><span class="soft-icon" aria-hidden="true"><?= ui_icon('devices') ?></span></div>
          <p class="panel-help">Create a session, then scan its QR code from WhatsApp Linked Devices.</p>
          <div class="device-illustration" aria-hidden="true"><span class="device-orbit orbit-a"></span><span class="device-orbit orbit-b"></span><span class="device-core">▦</span></div>
          <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="create">
            <div class="field"><label for="sn">New session name</label><input id="sn" name="session_name" maxlength="64" placeholder="e.g. school-01" required></div>
            <button type="submit" class="button button-primary" <?= $gatewayState['ready'] ? '' : 'disabled title="Rust gateway must be online first"' ?>>Create session <span aria-hidden="true">→</span></button>
          </form>
        </section>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($view==='overview'): ?><div class="console-overview-grid workspace-section"><?php endif; ?>
      <?php if (in_array($view,['overview','devices','sessions'],true)): ?>
      <section class="glass-panel workspace-section" id="sessions" aria-labelledby="sessions-title">
        <div class="panel-heading"><div><div class="eyebrow">CONNECTED ACCOUNTS</div><h2 id="sessions-title">WhatsApp sessions</h2></div><a class="button button-ghost button-small" href="/wabridge/devices">Add device →</a></div>
        <div class="table-scroll"><table><thead><tr><th>Session</th><th>Status</th><th>Pairing</th></tr></thead><tbody>
          <?php $visibleSessions=0; foreach ($sessions as $s): if (!is_array($s)) continue; $sid=(string)($s['id']??$s['session_id']??''); if (!permitted_session($sid)) continue; $visibleSessions++; ?>
            <tr><td><strong><?= escape($sid) ?></strong></td><td><span class="subtle-tag"><?= escape((string)($s['status']??'Unknown')) ?></span></td><td><a class="table-link" href="/wabridge/qr?sid=<?= rawurlencode($sid) ?>">View QR ↗</a></td></tr>
          <?php endforeach; ?>
          <?php if ($visibleSessions===0): ?><tr><td colspan="3" class="empty-cell">No sessions to display. Connect a device once the Rust gateway is available.</td></tr><?php endif; ?>
        </tbody></table></div>
      </section>
      <?php endif; ?>
      <?php if (in_array($view,['overview','messaging'],true)): ?>
      <section class="glass-panel workspace-section" id="recent-activity" aria-labelledby="recent-title">
        <div class="panel-heading console-inline-heading">
          <div><div class="eyebrow">DELIVERY OBSERVABILITY</div><h2 id="recent-title">Recent message activity</h2></div>
          <a class="button button-ghost button-small" href="/wabridge/messages">View full log →</a>
        </div>
        <p class="panel-help">The status below is based on the PHP queue and matching upstream receipts—not on assumed WhatsApp delivery.</p>
        <div class="activity-feed" role="list" aria-label="Recent message events">
          <?php foreach ($recentMessages as $message): ?>
            <?php $state=(string)$message['effective_state']; ?>
            <a class="activity-item" role="listitem" href="/wabridge/messages?id=<?= rawurlencode((string)$message['id']) ?>">
              <span class="activity-avatar" aria-hidden="true"><?= ui_icon('messages') ?></span>
              <span class="activity-details">
                <strong><?= escape((string)$message['session_id']) ?> <span class="activity-recipient">· <?= escape(message_log_mask_recipient((string)$message['destination'])) ?></span></strong>
                <small><?= escape(message_log_time((int)$message['updated_at'])) ?></small>
              </span>
              <span class="activity-state <?= in_array($state,['delivered','read'],true)?'activity-state-success':'' ?>"><?= escape(message_log_status_text($state)) ?></span>
            </a>
          <?php endforeach; ?>
          <?php if (!$recentMessages): ?>
          <div class="activity-empty">
            <span class="activity-avatar" aria-hidden="true"><?= ui_icon('messages') ?></span>
            <strong>No message activity yet</strong>
            <p>Send your first test message to begin tracking the queue, gateway response and any verified receipt.</p>
            <a href="/wabridge/messaging">Compose a test message →</a>
          </div>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>
      <?php if ($view==='overview'): ?></div><?php endif; ?>
      <?php if ($view==='api-applications'): ?>
      <details class="ops-collapse" id="integrations" <?= $view==='api-applications'?'open':'' ?>><summary><span>API applications<span class="ops-caption">Developer credentials and scoped integration access</span></span></summary>
      <section class="glass-panel workspace-section" aria-labelledby="api-title">
        <div class="panel-heading"><div><div class="eyebrow">DEVELOPER PLATFORM</div><h2 id="api-title">API applications</h2></div><span class="soft-icon" aria-hidden="true">{ }</span></div>
        <p class="panel-help">Create scoped credentials for connected applications. Tokens are shown only once and stored as hashes.</p>
        <?php if ($issuedToken!==''): ?><div class="notice notice-warning token-notice"><strong>Save your new API token now — it will not be shown again.</strong><input aria-label="New API token" readonly value="<?= escape($issuedToken) ?>"></div><?php endif; ?>
        <form method="post" class="client-form">
          <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="new_client">
          <div class="field"><label for="appname">Application ID</label><input id="appname" name="client_id" maxlength="64" placeholder="e.g. schoolflow" required></div>
          <div class="field"><label for="appsessions">Allowed session IDs</label><input id="appsessions" name="client_sessions" maxlength="255" placeholder="school-01,school-02" required></div>
          <div class="field"><label for="apprate">Rate / minute</label><input id="apprate" name="client_rate" type="number" min="1" max="300" value="20" required></div>
          <div class="field"><label for="appdays">Expires in days</label><input id="appdays" name="client_ttl_days" type="number" min="1" max="365" value="90" required></div>
          <button type="submit" class="button button-primary">Create application <span aria-hidden="true">→</span></button>
        </form>
        <div class="table-scroll"><table><thead><tr><th>Application</th><th>Allowed sessions</th><th>Limit</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead><tbody>
          <?php foreach ($apiClients as $client): ?><tr>
            <td><strong><?= escape((string)$client['id']) ?></strong></td><td><?= escape((string)$client['sessions_json']) ?></td><td><?= (int)$client['per_minute'] ?>/min</td><td><?= !empty($client['expires_at']) ? escape(gmdate('Y-m-d',(int)$client['expires_at'])) : 'Legacy' ?></td>
            <td><span class="subtle-tag <?= (int)$client['active']===1 ? 'tag-success' : '' ?>"><?= (int)$client['active']===1 ? 'Active' : 'Revoked' ?></span></td>
            <td><?php if ((int)$client['active']===1): ?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="revoke_client"><input type="hidden" name="client_id" value="<?= escape((string)$client['id']) ?>"><button class="button button-small button-ghost" type="submit">Revoke</button></form><?php endif; ?></td>
          </tr><?php endforeach; ?>
          <?php if (!$apiClients): ?><tr><td colspan="6" class="empty-cell">No API applications yet. Add your first integration above.</td></tr><?php endif; ?>
        </tbody></table></div>
      </section>
      </details>
      <?php endif; ?>
    <?php endif; ?>
  </main>
  <?php if ($authed): ?>
    <?php ui_shell_end(); ?>
  <?php else: ?>
    <footer class="site-footer"><span>© WaBridge · Built for connected operations</span><span>Private workspace · Secure sign-in.</span></footer>
  </div>
  <?php endif; ?>
</body></html>
