<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$notice = '';
$error = '';
try {
    $cfg = config();
    start_admin_session();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        assert_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            $username = (string)($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            if (hash_equals((string)$cfg['admin_user'], $username) &&
                password_verify($password, (string)$cfg['admin_password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                $notice = 'Signed in successfully.';
            } else {
                usleep(200000);
                $error = 'Invalid credentials.';
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
                $r = gateway('POST', '/api/v1/sessions/'.$sid.'/messages/text', ['to'=>$to,'text'=>$text]);
                $notice = $r['http'] >= 200 && $r['http'] < 300 ? 'Send accepted by gateway. Delivery is not confirmed.' : '';
                if (!$notice) $error = 'Gateway rejected message (HTTP '.$r['http'].').';
            } elseif ($action === 'create') {
                // Match Waxum's session creation fields to the installed engine version.
                $name = trim((string)($_POST['session_name'] ?? ''));
                if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $name)) throw new InvalidArgumentException('Invalid session name.');
                $r = gateway('POST', '/api/v1/sessions', ['name'=>$name]);
                $notice = $r['http'] >= 200 && $r['http'] < 300 ? 'Session request accepted.' : '';
                if (!$notice) $error = 'Could not create session (HTTP '.$r['http'].').';
            }
        }
    }
} catch (Throwable $e) {
    $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Operation failed; check server configuration and logs.';
}
$authed = admin_authenticated();
$sessions = [];
$health = 'Not connected';
if ($authed) {
    try {
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
<section class="panel"><div class="muted">Storage</div><div class="metric">SQLite</div><span class="pill">No external DB required</span></section>
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
<?php endif; ?>
<footer class="footer">WaBridge v0.1 development build · Unofficial WhatsApp connections may be restricted. Use recipient consent.</footer>
</main></body></html>
