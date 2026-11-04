<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/message-log.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/message-processor.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/worker-health.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; form-action 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'");
require_admin_html_page();
$notice=''; $error='';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        assert_csrf();
        if (($_POST['action']??'')!=='process_next') throw new InvalidArgumentException('Unsupported action');
        if (!limit_client(store(),['id'=>'manual-worker','limit'=>10])) throw new InvalidArgumentException('Manual processing rate limit reached.');
        if (!gateway_diagnostics()['ready']) throw new InvalidArgumentException('Rust gateway is unavailable; queue was not processed.');
        storage_reap_jobs();
        $out=process_next_message(store());
        if(!$out)$notice='No queued messages are available.';
        elseif($out['state']==='accepted')
            $notice='Job '.$out['id'].' accepted by Waxum (HTTP '.$out['http'].'). WhatsApp delivery is NOT confirmed.';
        elseif($out['state']==='blocked')
            $error='Job '.$out['id'].' remains safely queued. '.
                message_log_reason_text((string)($out['reason']??'session_disconnected')).
                '. No gateway send was attempted.';
        else
            $error='Job '.$out['id'].' needs review (HTTP '.$out['http'].'): '.
                message_log_reason_text((string)($out['reason']??'')).'. Do not blindly resend.';
    } catch (Throwable $e) {
        $error=$e instanceof InvalidArgumentException ? $e->getMessage() : 'Message processing unavailable; see private server logs.';
        error_log('WaBridge message log worker '.get_class($e));
    }
}
$status=(string)($_GET['status']??'');
$allowed=['','queued','processing','accepted','sent','delivered','read','failed','needs_review'];
if (!in_array($status,$allowed,true)) $status='';
$id=(string)($_GET['id']??'');
if (!valid_job_id($id)) $id='';
$session=(string)($_GET['session']??'');
if ($session!==''&&!valid_session_id($session)) $session='';
$page=max(1,min(2000,(int)($_GET['page']??1)));
$rows=[];$hasNext=false;
$counts=array_fill_keys(['queued','processing','accepted','sent','delivered','read','failed','needs_review','unknown'],0);
try {
    $counts=storage_message_status_counts();
    $result=storage_message_page($page,25,$status,$session,$id);
    $hasNext=$result['has_next'];
    foreach($result['rows'] as $row) {
        if(!permitted_session($row['session_id']))continue;
        $row['effective_state']=message_log_effective_status($row);
        $rows[]=$row;
    }
} catch (Throwable $e) {
    $error='Unable to load message history.';
    error_log('WaBridge message log '.get_class($e));
}
$workerHealth=worker_health_read();
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Message Activity - WaBridge</title>
  <?php ui_head_assets(); ?>
</head><body class="dashboard-page message-log-page">
<?php ui_shell_start('messages'); ?>
<main class="workspace message-workspace">
<?php ui_breadcrumb('WhatsApp','Message activity'); ?>
<div class="workspace-top"><div><div class="eyebrow">OPERATIONS / MESSAGE ACTIVITY</div><h1>Message activity</h1><p class="lead">Queue processing, gateway acceptance, and evidence-based delivery reporting.</p></div><a class="button button-ghost" href="/wabridge/messages">Refresh log</a></div>
<?php if ($notice!==''): ?><p class="notice notice-success" role="status"><?= escape($notice) ?></p><?php endif; ?>
<?php if ($error!==''): ?><p class="notice notice-warning" role="alert"><?= escape($error) ?></p><?php endif; ?>
<section class="glass-panel workspace-section" aria-label="Background queue processor">
<div class="panel-heading"><div><div class="eyebrow">QUEUE OPERATIONS</div><h2>Automatic worker</h2></div>
<span class="data-badge <?= $workerHealth['state']==='recent'?'ok':'warn' ?>"><?= $workerHealth['state']==='recent'?'Recently ran':($workerHealth['state']==='stale'?'Stale':'Not detected') ?></span></div>
<?php if ($workerHealth['state']==='not_detected'): ?>
<p class="panel-help">No scheduled PHP worker run has been observed. Rust being online does not process the PHP queue. Use <strong>Process next queued message</strong> below for a single test, or ask Cloudways to configure the private CLI worker every minute.</p>
<?php elseif ($workerHealth['state']==='stale'): ?>
<p class="panel-help">Last scheduled worker run: <?= escape(message_log_time((int)$workerHealth['last_run_at'])) ?>. The queue may not be processing automatically. Check the Cloudways cron or Supervisor job.</p>
<?php else: ?>
<p class="panel-help">Last worker run: <?= escape(message_log_time((int)$workerHealth['last_run_at'])) ?> · Processed <?= (int)$workerHealth['processed'] ?> · Gateway accepted <?= (int)$workerHealth['accepted'] ?> · Needs review <?= (int)$workerHealth['needs_review'] ?><?php if($workerHealth['session_blocked']): ?> · <strong>Session disconnected; oldest job remained queued</strong><?php endif; ?>. This reports PHP worker activity, not WhatsApp delivery.</p>
<?php endif; ?>
</section>
<section class="stats-grid log-stats">
<article class="stat-card"><p class="stat-label">Queued</p><div class="stat-value"><?= $counts['queued'] ?></div><p class="stat-foot">Waiting for a send worker or a manual action</p></article>
<article class="stat-card"><p class="stat-label">Accepted / sent</p><div class="stat-value"><?= $counts['accepted']+$counts['sent'] ?></div><p class="stat-foot">Not proof of delivery</p></article>
<article class="stat-card"><p class="stat-label">Delivered / read</p><div class="stat-value"><?= $counts['delivered']+$counts['read'] ?></div><p class="stat-foot">Verified matching receipts</p></article>
<article class="stat-card"><p class="stat-label">Failed / review</p><div class="stat-value"><?= $counts['failed']+$counts['needs_review'] ?></div><p class="stat-foot">Investigation required</p></article>
</section>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">QUEUE CONTROL</div><h2>Manual processing</h2></div></div>
<p class="panel-help">Process the oldest queued message through Waxum. WaBridge checks that its WhatsApp session is logged in with a live socket before claiming it; disconnected sessions remain queued. Automatic API queue processing still requires a separately scheduled PHP worker on Cloudways. An accepted request is not a delivery receipt.</p>
<form method="post"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="process_next"><button class="button button-primary" type="submit">Process next queued message</button></form>
</section>
<section class="glass-panel workspace-section" id="activity">
<div class="panel-heading"><div><div class="eyebrow">MESSAGE AUDIT</div><h2>Recent messages</h2></div><span class="subtle-tag">Page <?= $page ?> · 25 / page</span></div>
<form method="get" class="message-filter">
<label>Delivery status<select name="status"><?php foreach ([''=>'All','queued'=>'Queued','processing'=>'Processing','accepted'=>'Accepted','sent'=>'Sent','delivered'=>'Delivered','read'=>'Read','failed'=>'Failed','needs_review'=>'Needs review'] as $value=>$label): ?><option value="<?= escape($value) ?>" <?= $status===$value?'selected':'' ?>><?= escape($label) ?></option><?php endforeach; ?></select></label>
<label>Session<input name="session" value="<?= escape($session) ?>" maxlength="64" placeholder="Optional session"></label>
<label>Job ID<input name="id" value="<?= escape($id) ?>" maxlength="36" placeholder="GUID or legacy ID"></label>
<button class="button button-primary" type="submit">Filter</button><a class="button button-ghost" href="/wabridge/messages">Reset</a>
</form>
<p class="panel-help">Recipients are masked; message bodies and secrets are never shown. Times are UTC. Records expire according to the configured test-storage retention.</p>
<div class="table-scroll"><table><thead><tr><th>Created (UTC)</th><th>Job / session</th><th>Recipient</th><th>Status</th><th>Gateway / failure reason</th><th>Updated (UTC)</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><td><?= escape(message_log_time((int)$row['created_at'])) ?></td>
<td><a class="table-link" href="/wabridge/trace?id=<?= rawurlencode($row['id']) ?>"><?= escape($row['id']) ?></a><small><?= escape($row['session_id']) ?> / <?= escape($row['client_id']) ?></small></td>
<td><?= escape(message_log_mask_recipient($row['destination'])) ?></td>
<td><span class="subtle-tag <?= in_array($row['effective_state'],['delivered','read'],true)?'tag-success':'' ?>"><?= escape($row['effective_state']==='queued'&&(int)($row['next_at']??0)>time()?'Scheduled — not yet due':message_log_status_text($row['effective_state'])) ?></span></td>
<td><?= $row['response_code']===null?'Not attempted':('HTTP '.(int)$row['response_code']) ?><small>Attempts: <?= (int)$row['attempts'] ?></small><?php if ($row['effective_state']==='needs_review'): ?><small class="message-reason"><?= escape(message_log_reason_text(message_log_reason_code($row))) ?></small><?php endif; ?><?php if (!empty($row['receipt']['provider_id'])): ?><small>Provider ID <?= escape(substr($row['receipt']['provider_id'],0,24)) ?></small><?php endif; ?></td>
<td><?= escape(message_log_time((int)($row['receipt']['observed_at']??$row['updated_at']))) ?><?php if($row['effective_state']==='queued'&&(int)($row['next_at']??0)>time()): ?><small>Due <?= escape(message_log_time((int)$row['next_at'])) ?></small><?php endif;?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="empty-cell">No messages match this filter.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="section-links">
<?php $filters=['status'=>$status,'session'=>$session,'id'=>$id]; ?>
<?php if($page>1): ?><a class="button button-ghost" href="/wabridge/messages?<?= escape(http_build_query($filters+['page'=>$page-1],'','&',PHP_QUERY_RFC3986)) ?>">Previous page</a><?php endif; ?>
<?php if($hasNext): ?><a class="button button-primary" href="/wabridge/messages?<?= escape(http_build_query($filters+['page'=>$page+1],'','&',PHP_QUERY_RFC3986)) ?>">Next page</a><?php endif; ?>
</div>
</section>
<p class="notice notice-warning">For messages marked <strong>Needs review</strong>, inspect the HTTP status and reason below, then check the recipient’s WhatsApp before attempting a new send. An uncertain result is never automatically retried. Delivery labels require matching, verified upstream receipts; HTTP 200 only confirms gateway acceptance.</p>
</main><?php ui_shell_end(); ?></body></html>
