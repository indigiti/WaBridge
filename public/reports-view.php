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
if (!admin_authenticated()) { http_response_code(403);exit('Sign in to WaBridge first.'); }
$error='';$report=['jobs'=>[],'receipts'=>[],'event_types'=>[],'audit'=>[],'storage'=>[]];
try {$report=array_replace($report,storage_reports());}
catch (Throwable $e) {
  $error='The reporting store could not be read. Review application diagnostics.';
  error_log('WaBridge reports view '.get_class($e));
}
function report_group_count(array $rows,string $key):int {
    $sum=0;
    foreach($rows as $r) if(is_array($r)&&($r['state']??null)===$key)
        $sum+=(int)($r['count']??$r['n']??0);
    return $sum;
}
$queued=report_group_count($report['jobs'],'queued');
$accepted=report_group_count($report['jobs'],'accepted');
$review=report_group_count($report['jobs'],'needs_review');
$delivered=report_group_count($report['receipts'],'delivered');
$read=report_group_count($report['receipts'],'read');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Operational reports · WaBridge</title>
<?php ui_head_assets(); ?></head><body class="dashboard-page">
<?php ui_shell_start('reports'); ?>
<main class="workspace" id="main-content">
<?php ui_breadcrumb('Workspace','Reports'); ?>
<div class="workspace-top"><div><div class="eyebrow">OPERATIONS / REPORTING</div><h1>Messaging reports</h1><p class="lead">Counts from stored queue events and verified upstream delivery receipts.</p></div>
<a class="button button-ghost" href="/wabridge/reports">Refresh report</a></div>
<?php if($error!==''): ?><div class="notice notice-warning" role="alert"><?= escape($error) ?></div><?php endif; ?>
<section class="stats-grid log-stats workspace-section">
 <article class="stat-card"><p class="stat-label">Queued</p><div class="stat-value"><?= $queued ?></div><p class="stat-foot">Not submitted to Rust</p></article>
 <article class="stat-card"><p class="stat-label">Gateway accepted</p><div class="stat-value"><?= $accepted ?></div><p class="stat-foot">Not proof of delivery</p></article>
 <article class="stat-card"><p class="stat-label">Confirmed delivered / read</p><div class="stat-value"><?= $delivered+$read ?></div><p class="stat-foot">Matching receipt events only</p></article>
 <article class="stat-card"><p class="stat-label">Needs review</p><div class="stat-value"><?= $review ?></div><p class="stat-foot">Unknown or failed send outcome</p></article>
</section>
<div class="report-grid workspace-section">
 <section class="glass-panel"><div class="panel-heading"><div><div class="eyebrow">QUEUE</div><h2>Message processing</h2></div></div>
 <div class="table-scroll report-table"><table><thead><tr><th>State</th><th>Jobs</th></tr></thead><tbody>
 <?php foreach($report['jobs'] as $row):if(!is_array($row))continue; ?>
 <tr><td><?= escape((string)($row['state']??'unknown')) ?></td><td><?= (int)($row['count']??$row['n']??0) ?></td></tr>
 <?php endforeach; ?><?php if(!$report['jobs']):?><tr><td colspan="2" class="empty-cell">No job data recorded.</td></tr><?php endif; ?>
 </tbody></table></div>
 </section>
 <section class="glass-panel"><div class="panel-heading"><div><div class="eyebrow">DELIVERY EVENTS</div><h2>Receipt evidence</h2></div></div>
 <div class="table-scroll report-table"><table><thead><tr><th>Status</th><th>Receipts</th></tr></thead><tbody>
 <?php foreach($report['receipts'] as $row):if(!is_array($row))continue; ?>
 <tr><td><?= escape((string)($row['state']??'unknown')) ?></td><td><?= (int)($row['count']??$row['n']??0) ?></td></tr>
 <?php endforeach; ?><?php if(!$report['receipts']):?><tr><td colspan="2" class="empty-cell">No delivery receipt events confirmed.</td></tr><?php endif; ?>
 </tbody></table></div>
 </section>
 <section class="glass-panel"><div class="panel-heading"><div><div class="eyebrow">INBOUND</div><h2>Webhook activity</h2></div></div>
 <div class="table-scroll report-table"><table><thead><tr><th>Event type</th><th>Count</th></tr></thead><tbody>
 <?php foreach($report['event_types'] as $row):if(!is_array($row))continue; ?>
 <tr><td><?= escape((string)($row['event_type']??'unknown')) ?></td><td><?= (int)($row['count']??$row['n']??0) ?></td></tr>
 <?php endforeach; ?><?php if(!$report['event_types']):?><tr><td colspan="2" class="empty-cell">No stored webhook events.</td></tr><?php endif; ?>
 </tbody></table></div>
 </section>
 <section class="glass-panel"><div class="panel-heading"><div><div class="eyebrow">AUDIT</div><h2>Recent administrator events</h2></div><a class="button button-ghost button-small" href="/wabridge/audit">View full history</a></div>
 <div class="table-scroll report-table"><table><thead><tr><th>When (UTC)</th><th>Event</th><th>Actor</th></tr></thead><tbody>
 <?php foreach(array_slice((array)$report['audit'],0,10) as $row):if(!is_array($row))continue; ?>
 <tr><td><?= escape(gmdate('Y-m-d H:i',(int)($row['at']??0))) ?></td><td><?= escape((string)($row['event']??'unknown')) ?></td><td><?= escape((string)($row['actor']??'')) ?></td></tr>
 <?php endforeach; ?><?php if(!$report['audit']):?><tr><td colspan="3" class="empty-cell">No recent audit events.</td></tr><?php endif; ?>
 </tbody></table></div>
 </section>
</div>
<p class="notice notice-warning">Gateway acceptance is not WhatsApp delivery. The totals reflect retained records, not lifetime history, and structured receipt capture must be configured to report delivered/read accurately.</p>
<div class="section-links"><a href="/wabridge/messages">Message activity</a><a href="/wabridge/api/reports">Raw reports JSON</a><a href="/wabridge/storage">Storage configuration</a></div>
</main><?php ui_shell_end(); ?></body></html>
