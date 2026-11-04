<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/platform/webhook-read.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private,no-store,max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex,nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
require_admin_html_page();
$page=max(1,min(2000,(int)($_GET['page']??1)));
$status=(string)($_GET['status']??'');
if(!in_array($status,['','queued','processing','delivered','dead'],true))$status='';
$history=['rows'=>[],'has_next'=>false,'page'=>$page];$error='';
try{$history=platform_webhook_page($page,25,$status);}
catch(Throwable $e){$error='Unable to load webhook activity';error_log('WaBridge webhook ledger '.get_class($e));}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Webhook events · WaBridge</title>
<?php ui_head_assets(); ?></head><body class="dashboard-page">
<?php ui_shell_start('webhook-events'); ?>
<main class="workspace" id="main-content">
<?php ui_breadcrumb('Developer','Webhook events'); ?>
<div class="workspace-top"><div><div class="eyebrow">WEBHOOKS / AUDIT</div><h1>Webhook delivery history</h1>
<p class="lead">25 records per page. Never expose webhook payloads or signing secrets.</p></div>
<a class="button button-ghost" href="/wabridge/developer-tools">Developer tools</a></div>
<?php if($error!==''):?><p class="notice notice-warning"><?= escape($error) ?></p><?php endif;?>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">DELIVERIES</div><h2>Event activity</h2></div><span class="subtle-tag">Page <?= $page ?></span></div>
<form method="get" class="platform-form-grid">
<div class="field"><label>Status<select name="status"><?php foreach([''=>'All','queued'=>'Queued','processing'=>'Processing','delivered'=>'Delivered','dead'=>'Dead letter'] as $key=>$label):?>
<option value="<?= escape($key) ?>" <?= $status===$key?'selected':'' ?>><?= escape($label) ?></option><?php endforeach;?></select></label></div>
<div class="platform-button-row"><button class="button button-ghost">Apply filter</button></div></form>
<div class="table-scroll"><table><thead><tr><th>Event ID</th><th>Subscriber</th><th>Status</th><th>Attempts</th><th>Last HTTP</th><th>Updated (UTC)</th></tr></thead><tbody>
<?php foreach($history['rows'] as $row):?><tr>
<td><code><?= escape((string)($row['event_id']??'')) ?></code></td>
<td><?= escape((string)($row['subscriber_id']??'')) ?></td>
<td><?= escape((string)($row['state']??'')) ?></td>
<td><?= (int)($row['attempts']??0) ?></td>
<td><?= isset($row['last_http'])?(int)$row['last_http']:'—' ?></td>
<td><?= escape(gmdate('Y-m-d H:i',(int)($row['updated_at']??0))) ?></td></tr><?php endforeach;?>
<?php if(!$history['rows']):?><tr><td colspan="6" class="empty-cell">No events for this filter.</td></tr><?php endif;?>
</tbody></table></div>
<div class="platform-button-row">
<?php if($page>1):?><a class="button button-ghost" href="/wabridge/webhook-events?status=<?= rawurlencode($status) ?>&amp;page=<?= $page-1 ?>">Previous</a><?php endif;?>
<?php if($history['has_next']):?><a class="button button-primary" href="/wabridge/webhook-events?status=<?= rawurlencode($status) ?>&amp;page=<?= $page+1 ?>">Next</a><?php endif;?>
</div></section></main><?php ui_shell_end(); ?></body></html>
