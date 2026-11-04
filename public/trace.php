<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/message-log.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ops/retry-policy.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
require_admin_html_page();
$id=(string)($_GET['id']??'');
$job=null;$error='';
try {
    if(!valid_job_id($id))throw new InvalidArgumentException('Invalid message reference');
    $job=storage_message_detail($id);
    if($job!==null&&!permitted_session($job['session_id']))$job=null;
} catch(Throwable $e) {
    $error='The requested message could not be inspected.';
    error_log('WaBridge message trace '.get_class($e));
}
if($job===null)http_response_code(404);
$status=$job===null?'unknown':message_log_effective_status($job);
$receipt=$job['receipt']??null;
$policy=$job===null?null:message_retry_policy($job);
$timeline=$job===null?[]:storage_message_timeline($id);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Message trace · WaBridge</title>
<?php ui_head_assets(); ?></head><body class="dashboard-page">
<?php ui_shell_start('messages'); ?>
<main class="workspace" id="main-content">
<?php ui_breadcrumb('Messaging','Message trace'); ?>
<div class="workspace-top"><div><div class="eyebrow">DELIVERY / TRACE</div><h1>Message trace</h1><p class="lead">Evidence-based lifecycle of one message. No message body or unmasked phone number is exposed.</p></div><a class="button button-ghost" href="/wabridge/messages">All message activity</a></div>
<?php if($error!==''):?><p class="notice notice-warning" role="alert"><?= escape($error) ?></p><?php endif;?>
<?php if($job!==null): ?>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">MESSAGE REFERENCE</div><h2><?= escape($id) ?></h2></div><span class="subtle-tag"><?= escape(message_log_status_text($status)) ?></span></div>
<p class="panel-help">Session <?= escape((string)$job['session_id']) ?> · To <?= escape(message_log_mask_recipient((string)$job['destination'])) ?> · Attempts <?= (int)$job['attempts'] ?><?php if($job['state']==='queued'&&(int)($job['next_at']??0)>time()): ?> · Scheduled <?= escape(message_log_time((int)$job['next_at'])) ?><?php endif;?></p>
<?php if($timeline):?>
<div class="table-scroll"><table><thead><tr><th>Recorded state</th><th>Time (UTC)</th><th>Evidence</th><th>Event GUID</th></tr></thead><tbody>
<?php foreach($timeline as $event):?>
<tr><td><?= escape(message_log_status_text((string)$event['state'])) ?></td>
<td><?= escape(message_log_time((int)$event['at'])) ?></td>
<td><?= escape(match((string)$event['proof']) {
    'local_queue'=>'Persisted in WaBridge queue only',
    'worker_claim'=>'Worker claimed message; upstream outcome pending',
    'gateway_accepted'=>'Waxum accepted message, delivery unconfirmed',
    'verified_provider_receipt'=>'Verified provider-ID-correlated receipt event',
    'operator_cancel'=>'Administrator cancelled before dispatch',
    default=>'Outcome uncertain; do not auto-retry',
}) ?></td>
<td><small><?= escape((string)$event['guid']) ?></small></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php else:?>
<p class="panel-help">Legacy message: only the latest checkpoints were stored before the transition journal was introduced.</p>
<div class="table-scroll"><table><thead><tr><th>Checkpoint</th><th>Observed at (UTC)</th><th>What it proves</th></tr></thead><tbody>
<tr><td>Queued</td><td><?= escape(message_log_time((int)$job['created_at'])) ?></td><td>WaBridge accepted the job into local storage, not WhatsApp</td></tr>
<?php if((int)$job['attempts']>0): ?>
<tr><td>Last worker update</td><td><?= escape(message_log_time((int)$job['updated_at'])) ?></td><td>Latest queue-state change; intermediate timestamps are not retained</td></tr>
<?php endif; ?>
<?php if($job['state']==='accepted'): ?>
<tr><td>Gateway accepted</td><td><?= escape(message_log_time((int)$job['updated_at'])) ?></td><td>Waxum returned a usable provider message ID, not confirmed delivery</td></tr>
<?php elseif($job['state']==='needs_review'): ?>
<tr><td>Outcome uncertain</td><td><?= escape(message_log_time((int)$job['updated_at'])) ?></td><td><?= escape(message_log_reason_text(message_log_reason_code($job))) ?>. Automatic retry prohibited</td></tr>
<?php endif; ?>
<?php if(is_array($receipt)&&in_array((string)($receipt['state']??''),['sent','delivered','read','failed'],true)): ?>
<tr><td><?= escape(ucfirst((string)$receipt['state'])) ?> receipt</td><td><?= escape(message_log_time((int)$receipt['observed_at'])) ?></td><td>Provider-ID-correlated signed event, latest known receipt state</td></tr>
<?php endif; ?>
</tbody></table></div>
<?php endif;?>
<p class="panel-help"><strong>Retry safety:</strong> <?= escape((string)$policy['reason']) ?>. Automated resend: disabled.</p>
<p class="panel-help">Latest known status: <?= escape(message_log_status_text($status)) ?>. New messages retain actual state transitions; older records may have only their final checkpoint. Refresh to inspect new receipts.</p>
</section>
<?php else: ?><p class="notice notice-warning">No visible message matches this reference.</p><?php endif;?>
</main><?php ui_shell_end(); ?></body></html>
