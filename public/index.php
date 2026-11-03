<?php
declare(strict_types=1);
require __DIR__ . '/_loader.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'none'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000');
$notice = '';
$error = '';
$issuedToken='';
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
            } else {
                usleep(200000);
                $error='Invalid credentials.';
            }
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            $notice = 'Signed out.';
        } elseif (admin_authenticated()) {
            if ($action === 'send') {
                [$sid, $to, $text] = validate_message([
                    'session_id'=>$_POST['session_id'] ?? '',
                    'to'=>$_POST['to'] ?? '',
                    'text'=>$_POST['text'] ?? ''
                ]);
                $job = enqueue_message(store(), ['id'=>'admin','sessions'=>[$sid],'limit'=>10], $sid, $to, $text, 'admin-'.bin2hex(random_bytes(12)));
                $notice = 'Message queued (ID '.$job['id'].'). Run the CLI worker to send; delivery is not confirmed.';
            } elseif ($action === 'create') {
                // Match Waxum's session creation fields to the installed engine version.
                $name = trim((string)($_POST['session_name'] ?? ''));
                if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $name)) throw new InvalidArgumentException('Invalid session name.');
                $r = gateway('POST', '/api/v1/sessions', ['name'=>$name]);
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
                $issuedToken=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
                $s=store()->prepare('INSERT INTO api_clients(id,token_sha256,sessions_json,per_minute,created_at) VALUES(?,?,?,?,?)');
                $s->execute([$id,hash('sha256',$issuedToken),json_encode($sessions,JSON_THROW_ON_ERROR),$rate,time()]);
                $notice='Application created. Copy its API token now; it will not be shown again.';
            } elseif ($action==='revoke_client') {
                $id=(string)($_POST['client_id']??'');
                if (!preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',$id)) throw new InvalidArgumentException('Invalid application ID');
                $s=store()->prepare('UPDATE api_clients SET active=0 WHERE id=?');
                $s->execute([$id]);
                $notice='API application access revoked.';
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
$apiClients=[];
$sessions = [];
$health = 'Not connected';
$stats = ['queued'=>0,'accepted'=>0,'needs_review'=>0];
if ($authed) {
    try {
        $apiClients=store()->query('SELECT id,sessions_json,per_minute,active FROM api_clients ORDER BY id LIMIT 100')->fetchAll();
        foreach (store()->query('SELECT state, COUNT(*) AS n FROM jobs GROUP BY state') as $row) { if (isset($stats[$row['state']])) $stats[$row['state']] = (int)$row['n']; }
        $h = gateway('GET', '/readyz');
        $health = $h['http'] === 200 ? 'Ready' : 'Unavailable (HTTP '.$h['http'].')';
        $r = gateway('GET', '/api/v1/sessions');
        $data = $r['data'] ?? [];
        $sessions = is_array($data) && array_is_list($data) ? $data : ($data['sessions'] ?? []);
        if (!is_array($sessions)) $sessions = [];
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
  <meta name="theme-color" content="#eef3f7">
  <title>WaBridge — Control Center</title>
  <link rel="stylesheet" href="/wabridge/assets/wabridge-premium-v2.css">
  <!-- Critical first paint: remains usable even if a CDN or proxy serves stale CSS. -->
  <style>
    :root{color-scheme:light;font-family:system-ui,sans-serif;color:#183653;background:#eef3f7}
    *{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#e9f1f2,#edf1f9);min-height:100vh;color:#183653}
    .site-shell{min-height:100vh;display:flex;flex-direction:column}.site-header{display:flex;align-items:center;justify-content:space-between;padding:24px clamp(20px,5vw,66px)}
    .brand-lockup{color:#174471;text-decoration:none;font-weight:700}.brand-copy{display:inline-flex;flex-direction:column}.brand-copy small{font-size:10px;letter-spacing:1px}
    .auth-main{flex:1;display:grid;place-items:center;padding:20px}.auth-card{width:min(100%,505px);padding:38px;border:1px solid white;border-radius:25px;background:rgba(255,255,255,.8);box-shadow:0 20px 70px #21466d18;text-align:center}
    .auth-form,.form-stack{display:grid;gap:16px;text-align:left}.field{display:grid;gap:6px}.field input,.field textarea,.client-form input{width:100%;min-height:46px;border:1px solid #cbd8e6;border-radius:12px;background:white;padding:12px}
    .button{padding:12px 20px;border:0;border-radius:12px;cursor:pointer}.button-primary{color:white;background:#285a9d}.button-wide{width:100%}
    .workspace{max-width:1280px;width:100%;margin:auto;padding:30px 20px}.stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
    .workspace-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.glass-panel,.stat-card{background:rgba(255,255,255,.8);border:1px solid white;border-radius:20px;padding:24px;margin-bottom:20px}
    .section-nav{display:flex;gap:20px;overflow:auto}.table-scroll{overflow-x:auto}.site-footer{padding:25px;text-align:center;font-size:12px;color:#667d92}
    @media(max-width:740px){.stats-grid,.workspace-two{grid-template-columns:1fr}.auth-card{padding:26px}.site-header{padding:20px}}
  </style>
</head>
<body class="<?= $authed ? 'dashboard-page' : 'auth-page' ?>">
<div class="ambient ambient-one" aria-hidden="true"></div>
<div class="ambient ambient-two" aria-hidden="true"></div>
<div class="ambient ambient-three" aria-hidden="true"></div>
<div class="site-shell">
  <header class="site-header">
    <a class="brand-lockup" href="./" aria-label="WaBridge home">
      <span class="brand-mark" aria-hidden="true"><span class="brand-mark-inner">W</span></span>
      <span class="brand-copy"><strong>Wa<span>Bridge</span></strong><small>Messaging infrastructure</small></span>
    </a>
    <div class="header-right">
      <span class="header-caption">Connect. Communicate. Automate.</span>
      <?php if ($authed): ?>
        <form method="post" class="header-logout">
          <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
          <input type="hidden" name="action" value="logout">
          <button class="button button-ghost button-small" type="submit">Sign out <span aria-hidden="true">↗</span></button>
        </form>
      <?php else: ?>
        <span class="environment-chip"><span class="live-dot"></span> Secure console</span>
      <?php endif; ?>
    </div>
  </header>
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
      <div class="workspace-top">
        <div><div class="eyebrow">WA BRIDGE / CONTROL CENTER</div><h1>Good to have you back.</h1><p class="lead">Your messaging infrastructure at a glance.</p></div>
        <span class="workspace-chip"><span class="live-dot"></span> Admin workspace</span>
      </div>
      <?php if ($notice): ?><div class="notice notice-success" role="status"><?= escape($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="notice notice-warning" role="alert"><?= escape($error) ?></div><?php endif; ?>
      <nav class="section-nav" aria-label="Dashboard sections">
        <a href="#overview">Overview</a><a href="#devices">Devices</a><a href="#messaging">Messaging</a><a href="#integrations">API applications</a>
      </nav>
      <section id="overview" aria-labelledby="overview-title" class="workspace-section">
        <div class="section-heading"><div><div class="eyebrow">SYSTEM INSIGHTS</div><h2 id="overview-title">Overview</h2></div><span class="section-subtitle">Live control-plane information</span></div>
        <div class="stats-grid">
          <article class="stat-card"><span class="stat-icon" aria-hidden="true">◉</span><p class="stat-label">Gateway health</p><div class="stat-value <?= $health==='Ready' ? 'tone-success' : 'tone-amber' ?>"><?= escape($health) ?></div><div class="stat-foot">Rust gateway readiness</div></article>
          <article class="stat-card"><span class="stat-icon" aria-hidden="true">▣</span><p class="stat-label">Linked sessions</p><div class="stat-value"><?= count($sessions) ?></div><div class="stat-foot">Reported by gateway</div></article>
          <article class="stat-card"><span class="stat-icon" aria-hidden="true">↗</span><p class="stat-label">Queued messages</p><div class="stat-value"><?= (int)$stats['queued'] ?></div><div class="stat-foot"><?= (int)$stats['accepted'] ?> accepted · <?= (int)$stats['needs_review'] ?> require review</div></article>
        </div>
      </section>
      <div class="workspace-two">
        <section class="glass-panel workspace-section" id="messaging" aria-labelledby="message-title">
          <div class="panel-heading"><div><div class="eyebrow">MESSAGING</div><h2 id="message-title">Send a test message</h2></div><span class="soft-icon" aria-hidden="true">↗</span></div>
          <p class="panel-help">Queue a message through a configured WhatsApp session. Submission is not proof of delivery.</p>
          <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="send">
            <div class="field"><label for="sid">Session ID</label><input id="sid" name="session_id" maxlength="64" placeholder="e.g. school-01" required></div>
            <div class="field"><label for="to">Recipient number</label><input id="to" name="to" maxlength="15" inputmode="numeric" placeholder="Country code + number, digits only" required></div>
            <div class="field"><label for="txt">Message</label><textarea id="txt" name="text" rows="4" maxlength="2000" placeholder="Write your message..." required></textarea></div>
            <button type="submit" class="button button-primary">Queue message <span aria-hidden="true">→</span></button>
          </form>
        </section>
        <section class="glass-panel workspace-section" id="devices" aria-labelledby="device-title">
          <div class="panel-heading"><div><div class="eyebrow">CONNECTIONS</div><h2 id="device-title">Connect a device</h2></div><span class="soft-icon" aria-hidden="true">▣</span></div>
          <p class="panel-help">Create a session, then scan its QR code from WhatsApp Linked Devices.</p>
          <div class="device-illustration" aria-hidden="true"><span class="device-orbit orbit-a"></span><span class="device-orbit orbit-b"></span><span class="device-core">▦</span></div>
          <form method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="create">
            <div class="field"><label for="sn">New session name</label><input id="sn" name="session_name" maxlength="64" placeholder="e.g. school-01" required></div>
            <button type="submit" class="button button-primary">Create session <span aria-hidden="true">→</span></button>
          </form>
        </section>
      </div>
      <section class="glass-panel workspace-section" aria-labelledby="sessions-title">
        <div class="panel-heading"><div><div class="eyebrow">CONNECTED ACCOUNTS</div><h2 id="sessions-title">WhatsApp sessions</h2></div><span class="subtle-tag">QR secured</span></div>
        <div class="table-scroll"><table><thead><tr><th>Session</th><th>Status</th><th>Pairing</th></tr></thead><tbody>
          <?php $visibleSessions=0; foreach ($sessions as $s): if (!is_array($s)) continue; $sid=(string)($s['id']??$s['session_id']??''); if (!permitted_session($sid)) continue; $visibleSessions++; ?>
            <tr><td><strong><?= escape($sid) ?></strong></td><td><span class="subtle-tag"><?= escape((string)($s['status']??'Unknown')) ?></span></td><td><a class="table-link" href="qr.php?sid=<?= rawurlencode($sid) ?>">View QR ↗</a></td></tr>
          <?php endforeach; ?>
          <?php if ($visibleSessions===0): ?><tr><td colspan="3" class="empty-cell">No sessions to display. Connect a device once the Rust gateway is available.</td></tr><?php endif; ?>
        </tbody></table></div>
      </section>
      <section class="glass-panel workspace-section" id="integrations" aria-labelledby="api-title">
        <div class="panel-heading"><div><div class="eyebrow">DEVELOPER PLATFORM</div><h2 id="api-title">API applications</h2></div><span class="soft-icon" aria-hidden="true">{ }</span></div>
        <p class="panel-help">Create scoped credentials for connected applications. Tokens are shown only once and stored as hashes.</p>
        <?php if ($issuedToken!==''): ?><div class="notice notice-warning token-notice"><strong>Save your new API token now — it will not be shown again.</strong><input aria-label="New API token" readonly value="<?= escape($issuedToken) ?>"></div><?php endif; ?>
        <form method="post" class="client-form">
          <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="new_client">
          <div class="field"><label for="appname">Application ID</label><input id="appname" name="client_id" maxlength="64" placeholder="e.g. schoolflow" required></div>
          <div class="field"><label for="appsessions">Allowed session IDs</label><input id="appsessions" name="client_sessions" maxlength="255" placeholder="school-01,school-02" required></div>
          <div class="field"><label for="apprate">Rate / minute</label><input id="apprate" name="client_rate" type="number" min="1" max="300" value="20" required></div>
          <button type="submit" class="button button-primary">Create application <span aria-hidden="true">→</span></button>
        </form>
        <div class="table-scroll"><table><thead><tr><th>Application</th><th>Allowed sessions</th><th>Limit</th><th>Status</th><th>Action</th></tr></thead><tbody>
          <?php foreach ($apiClients as $client): ?><tr>
            <td><strong><?= escape((string)$client['id']) ?></strong></td><td><?= escape((string)$client['sessions_json']) ?></td><td><?= (int)$client['per_minute'] ?>/min</td>
            <td><span class="subtle-tag <?= (int)$client['active']===1 ? 'tag-success' : '' ?>"><?= (int)$client['active']===1 ? 'Active' : 'Revoked' ?></span></td>
            <td><?php if ((int)$client['active']===1): ?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="revoke_client"><input type="hidden" name="client_id" value="<?= escape((string)$client['id']) ?>"><button class="button button-small button-ghost" type="submit">Revoke</button></form><?php endif; ?></td>
          </tr><?php endforeach; ?>
          <?php if (!$apiClients): ?><tr><td colspan="5" class="empty-cell">No API applications yet. Add your first integration above.</td></tr><?php endif; ?>
        </tbody></table></div>
      </section>
    <?php endif; ?>
  </main>
  <footer class="site-footer"><span>© WaBridge · Built for connected operations</span><span>Private workspace · Unofficial WhatsApp connections may be restricted.</span></footer>
</div>
</body></html>
