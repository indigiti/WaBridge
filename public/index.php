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
    $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Operation failed; check server configuration and logs.';
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
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>WaBridge Control Center</title>
<link rel="stylesheet" href="assets/app.css"></head>
<body><main class="wrap">
<header class="top"><div class="brand">Wa<span>Bridge</span> <span class="pill muted">Control Center</span></div>
<?php if ($authed): ?><form method="post" class="inline"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="logout"><button class="secondary">Sign out</button></form><?php endif; ?>
</header>
<?php if ($notice): ?><div class="alert" role="status"><?= escape($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert" role="alert"><?= escape($error) ?></div><?php endif; ?>
<?php if (!$authed): ?>
<section class="panel" style="max-width:440px;margin:9vh auto"><h1>Welcome back</h1><p class="muted">Sign in to manage your WhatsApp gateway.</p>
<form method="post"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="login">
<label for="u">Username</label><input id="u" name="username" autocomplete="username" required>
<label for="p">Password</label><input id="p" type="password" name="password" autocomplete="current-password" required>
<button type="submit">Sign in</button></form></section>
<?php else: ?>
<h1>Gateway overview</h1><p class="muted">Connected devices, messaging and gateway health, without a Node.js runtime.</p>
<div class="grid">
<section class="panel"><div class="muted">Rust gateway</div><div class="metric"><?= escape($health) ?></div><span class="pill">Readiness probe</span></section>
<section class="panel"><div class="muted">Device sessions</div><div class="metric"><?= count($sessions) ?></div><span class="pill">Reported by engine</span></section>
<section class="panel"><div class="muted">Message queue</div><div class="metric"><?= (int)$stats['queued'] ?> pending</div><span class="pill"><?= (int)$stats['accepted'] ?> accepted · <?= (int)$stats['needs_review'] ?> require review</span></section>
</div>
<div class="two">
<section class="panel"><h2>Send test message</h2><form method="post"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="send">
<label for="sid">Session ID</label><input id="sid" name="session_id" required maxlength="64" placeholder="my-device">
<label for="to">Recipient (country code + number, digits only)</label><input id="to" name="to" required maxlength="15" inputmode="numeric" placeholder="919876543210">
<label for="txt">Text message</label><textarea id="txt" name="text" rows="4" required maxlength="2000" placeholder="Your message"></textarea>
<button type="submit">Send message</button></form></section>
<section class="panel"><h2>Connect a new device</h2><p class="muted">Create a session, then use its QR route to link WhatsApp. QR images require authentication and must never be exposed as a public asset.</p>
<form method="post"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="create">
<label for="sn">New session name</label><input id="sn" name="session_name" maxlength="64" required placeholder="school-01">
<button type="submit">Create session</button></form></section>
</div>
<section class="panel"><h2>Devices</h2><div style="overflow-x:auto"><table><thead><tr><th>Session ID</th><th>Status</th><th>QR</th></tr></thead><tbody>
<?php foreach ($sessions as $s): if (!is_array($s)) continue;
    $sid=(string)($s['id']??$s['session_id']??'');
    if (!permitted_session($sid)) continue; ?>
<tr><td><?= escape($sid) ?></td><td><?= escape((string)($s['status']??'unknown')) ?></td><td><a href="qr.php?sid=<?= rawurlencode($sid) ?>">View QR</a></td></tr>
<?php endforeach; ?>
<?php if (!$sessions): ?><tr><td colspan="3" class="muted">No sessions reported. Verify gateway connection and create a session.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><h2>API applications and credentials</h2>
<p class="muted">Each application can access only the listed WhatsApp sessions. API tokens are displayed once and stored as hashes.</p>
<?php if ($issuedToken!==''): ?><div class="alert"><strong>New API token — copy now:</strong><input aria-label="New API token" readonly value="<?= escape($issuedToken) ?>"></div><?php endif; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="new_client">
<div class="grid"><div><label for="appname">Application ID</label><input id="appname" name="client_id" required maxlength="64" placeholder="schoolflow"></div>
<div><label for="appsessions">Session IDs, comma-separated</label><input id="appsessions" name="client_sessions" required maxlength="255" placeholder="school-01"></div>
<div><label for="apprate">Requests per minute</label><input id="apprate" name="client_rate" type="number" min="1" max="300" value="20" required></div></div>
<button type="submit">Create API application</button></form>
<div style="overflow-x:auto"><table><thead><tr><th>Application</th><th>Allowed sessions</th><th>Rate / min</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($apiClients as $client): ?><tr><td><?= escape((string)$client['id']) ?></td><td><?= escape((string)$client['sessions_json']) ?></td><td><?= (int)$client['per_minute'] ?></td>
<td><?= ((int)$client['active']===1) ? 'Active' : 'Revoked' ?></td><td>
<?php if ((int)$client['active']===1): ?><form method="post" class="inline"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="revoke_client"><input type="hidden" name="client_id" value="<?= escape((string)$client['id']) ?>"><button class="secondary" type="submit">Revoke</button></form><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if (!$apiClients): ?><tr><td colspan="5" class="muted">No external applications yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endif; ?>
<footer class="footer">WaBridge v0.1 development build · Unofficial WhatsApp connections may be restricted. Use recipient consent.</footer>
</main></body></html>
