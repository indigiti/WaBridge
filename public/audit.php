<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
require_admin_html_page();
$page=max(1,min(2000,(int)($_GET['page']??1)));
$error='';$history=['events'=>[],'has_next'=>false,'page'=>$page];
try{$history=storage_audit_page($page,25);}
catch(Throwable $e){
    $error='Administrator history is currently unavailable.';
    error_log('WaBridge audit '.get_class($e));
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Administrator audit · WaBridge</title>
<?php ui_head_assets(); ?></head><body class="dashboard-page">
<?php ui_shell_start('audit'); ?>
<main class="workspace" id="main-content">
<?php ui_breadcrumb('Operations','Administrator audit'); ?>
<div class="workspace-top"><div><div class="eyebrow">SECURITY / AUDIT</div><h1>Administrator events</h1><p class="lead">Full retained history in bounded pages. Only 25 records are read per request.</p></div><a class="button button-ghost" href="/wabridge/reports">Back to reports</a></div>
<?php if($error!==''):?><p class="notice notice-warning" role="alert"><?= escape($error) ?></p><?php endif;?>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">EVENT HISTORY</div><h2>Administrator activity</h2></div><span class="subtle-tag">Page <?= $page ?></span></div>
<div class="table-scroll"><table><thead><tr><th>When (UTC)</th><th>Event</th><th>Actor</th><th>Target</th><th>GUID</th></tr></thead><tbody>
<?php foreach($history['events'] as $event): ?>
<tr><td><?= escape(gmdate('Y-m-d H:i:s',(int)($event['at']??0))) ?></td>
<td><?= escape((string)($event['event']??'unknown')) ?></td>
<td><?= escape((string)($event['actor']??'')) ?></td>
<td><?= escape((string)($event['target']??'')) ?></td>
<td><small><?= escape((string)($event['guid']??'Legacy event')) ?></small></td></tr>
<?php endforeach; ?>
<?php if(!$history['events']):?><tr><td colspan="5" class="empty-cell">No events on this page.</td></tr><?php endif;?>
</tbody></table></div>
<div class="section-links">
<?php if($page>1):?><a class="button button-ghost" href="/wabridge/audit?page=<?= $page-1 ?>">Previous page</a><?php endif;?>
<?php if($history['has_next']):?><a class="button button-primary" href="/wabridge/audit?page=<?= $page+1 ?>">Next page</a><?php endif;?>
</div>
<p class="panel-help">JSON test storage retains at most 500 audit events. Long-term compliance records require a transactional database and defined retention policy.</p>
</section></main><?php ui_shell_end(); ?></body></html>
