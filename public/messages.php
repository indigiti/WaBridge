<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/message-log.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/message-processor.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; form-action 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'");
if (!admin_authenticated()) { http_response_code(403); exit('Sign in to WaBridge first.'); }
$notice=''; $error='';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        assert_csrf();
        if (($_POST['action']??'')!=='process_next') throw new InvalidArgumentException('Unsupported action');
        if (!limit_client(store(),['id'=>'manual-worker','limit'=>10])) throw new InvalidArgumentException('Manual processing rate limit reached.');
        if (!gateway_diagnostics()['ready']) throw new InvalidArgumentException('Rust gateway is unavailable; queue was not processed.');
        storage_reap_jobs();
        $out=process_next_message(store());
        if (!$out) $notice='No queued messages are available.';
        elseif ($out['state']==='accepted') $notice='Job '.$out['id'].' accepted by Rust. Delivery is NOT confirmed.';
        else $error='Job '.$out['id'].' needs review; do not blindly retry an uncertain send.';
    } catch (Throwable $e) {
        $error=$e instanceof InvalidArgumentException ? $e->getMessage() : 'Message processing unavailable; see private server logs.';
        error_log('WaBridge message log worker '.get_class($e));
    }
}
$status=(string)($_GET['status']??'');
$allowed=['','queued','processing','accepted','sent','delivered','read','failed','needs_review'];
if (!in_array($status,$allowed,true)) $status='';
$id=(string)($_GET['id']??'');
if (!preg_match('/\A[a-f0-9]{32}\z/D',$id)) $id='';
$session=(string)($_GET['session']??'');
if ($session!==''&&!valid_session_id($session)) $session='';
$rows=[]; $counts=array_fill_keys(['queued','processing','accepted','sent','delivered','read','failed','needs_review','unknown'],0);
try {
    foreach (storage_admin_messages(200) as $row) {
        if (!permitted_session($row['session_id'])) continue;
        $state=message_log_effective_status($row);
        if (!isset($counts[$state])) $state='unknown';
        $counts[$state]++;
        if (($status!=='' && $state!==$status)||($id!==''&&$row['id']!==$id)||($session!==''&&$row['session_id']!==$session)) continue;
        $row['effective_state']=$state;
        $rows[]=$row;
    }
} catch (Throwable $e) {
    $error='Unable to load message history.';
    error_log('WaBridge message log '.get_class($e));
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Message Activity - WaBridge</title>
<link rel="stylesheet" href="/wabridge/assets/wabridge-premium-v2.css?v=4">
<link rel="stylesheet" href="/wabridge/assets/wabridge-console-v3.css?v=2">
<link rel="stylesheet" href="/wabridge/assets/wabridge-glass-v4.css?v=1">
<script src="/wabridge/assets/wabridge-console-v3.js?v=2" defer></script>
<link rel="stylesheet" href="/wabridge/assets/wabridge-console-v3.css?v=1">
</head><body class="dashboard-page message-log-page">
<?php ui_shell_start('messages'); ?>
<main class="workspace message-workspace">
<?php ui_breadcrumb('WhatsApp','Message activity'); ?>
<div class="workspace-top"><div><div class="eyebrow">OPERATIONS / MESSAGE ACTIVITY</div><h1>Message activity</h1><p class="lead">Queue processing, gateway acceptance, and evidence-based delivery reporting.</p></div><a class="button button-ghost" href="/wabridge/messages">Refresh log</a></div>
<?php if ($notice!==''): ?><p class="notice notice-success" role="status"><?= escape($notice) ?></p><?php endif; ?>
<?php if ($error!==''): ?><p class="notice notice-warning" role="alert"><?= escape($error) ?></p><?php endif; ?>
<section class="stats-grid log-stats">
<article class="stat-card"><p class="stat-label">Queued</p><div class="stat-value"><?= $counts['queued'] ?></div><p class="stat-foot">Waiting for PHP worker</p></article>
<article class="stat-card"><p class="stat-label">Accepted / sent</p><div class="stat-value"><?= $counts['accepted']+$counts['sent'] ?></div><p class="stat-foot">Not proof of delivery</p></article>
<article class="stat-card"><p class="stat-label">Delivered / read</p><div class="stat-value"><?= $counts['delivered']+$counts['read'] ?></div><p class="stat-foot">Verified matching receipts</p></article>
<article class="stat-card"><p class="stat-label">Failed / review</p><div class="stat-value"><?= $counts['failed']+$counts['needs_review'] ?></div><p class="stat-foot">Investigation required</p></article>
</section>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">QUEUE CONTROL</div><h2>Manual processing</h2></div></div>
<p class="panel-help">Send one pending message through Waxum in this browser session. Use a separate supervised PHP worker for automatic processing. An accepted request is not a WhatsApp delivery confirmation.</p>
<form method="post"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="process_next"><button class="button button-primary" type="submit">Process next queued message</button></form>
</section>
<section class="glass-panel workspace-section" id="activity">
<div class="panel-heading"><div><div class="eyebrow">MESSAGE AUDIT</div><h2>Recent messages</h2></div><span class="subtle-tag">Last 200</span></div>
<form method="get" class="message-filter">
<label>Delivery status<select name="status"><?php foreach ([''=>'All','queued'=>'Queued','processing'=>'Processing','accepted'=>'Accepted','sent'=>'Sent','delivered'=>'Delivered','read'=>'Read','failed'=>'Failed','needs_review'=>'Needs review'] as $value=>$label): ?><option value="<?= escape($value) ?>" <?= $status===$value?'selected':'' ?>><?= escape($label) ?></option><?php endforeach; ?></select></label>
<label>Session<input name="session" value="<?= escape($session) ?>" maxlength="64" placeholder="Optional session"></label>
<label>Job ID<input name="id" value="<?= escape($id) ?>" maxlength="32" placeholder="Exact job ID"></label>
<button class="button button-primary" type="submit">Filter</button><a class="button button-ghost" href="/wabridge/messages">Reset</a>
</form>
<p class="panel-help">Recipients are masked; message bodies and secrets are never shown. Times are UTC. Records expire according to the configured test-storage retention.</p>
<div class="table-scroll"><table><thead><tr><th>Created (UTC)</th><th>Job / session</th><th>Recipient</th><th>Status</th><th>Gateway</th><th>Updated (UTC)</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><td><?= escape(message_log_time((int)$row['created_at'])) ?></td>
<td><a class="table-link" href="/wabridge/messages?id=<?= rawurlencode($row['id']) ?>"><?= escape($row['id']) ?></a><small><?= escape($row['session_id']) ?> / <?= escape($row['client_id']) ?></small></td>
<td><?= escape(message_log_mask_recipient($row['destination'])) ?></td>
<td><span class="subtle-tag <?= in_array($row['effective_state'],['delivered','read'],true)?'tag-success':'' ?>"><?= escape(message_log_status_text($row['effective_state'])) ?></span></td>
<td><?= $row['response_code']===null?'—':(int)$row['response_code'] ?><small>Attempts <?= (int)$row['attempts'] ?></small><?php if (!empty($row['receipt']['provider_id'])): ?><small>Provider ID <?= escape(substr($row['receipt']['provider_id'],0,24)) ?></small><?php endif; ?></td>
<td><?= escape(message_log_time((int)($row['receipt']['observed_at']??$row['updated_at']))) ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="empty-cell">No messages match this filter.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
<p class="notice notice-warning">Delivery labels require signed, matching upstream receipt data. Waxum v0.16.1 WhatsApp Web receipt events may be unstructured; a gateway HTTP 200 must remain “Accepted / delivery unknown” until matching delivery evidence exists.</p>
</main><?php ui_shell_end(); ?></body></html>
