<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/runtime-metrics.php';
header('Cache-Control: private, no-store, max-age=0');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
require_admin_html_page();
function metric_bytes(?int $bytes):string {
    if($bytes===null)return 'Unavailable';
    if($bytes<1024)return $bytes.' B';
    if($bytes<1048576)return number_format($bytes/1024,1).' KiB';
    if($bytes<1073741824)return number_format($bytes/1048576,1).' MiB';
    return number_format($bytes/1073741824,2).' GiB';
}
$metrics=runtime_metrics_snapshot();
$rust=$metrics['rust'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Runtime performance · WaBridge</title><?php ui_head_assets(); ?></head>
<body class="dashboard-page"><?php ui_shell_start('performance'); ?>
<main class="workspace" id="main-content">
<?php ui_breadcrumb('Workspace','Performance'); ?>
<div class="workspace-top"><div><div class="eyebrow">OPERATIONS / PERFORMANCE</div><h1>Gateway performance</h1>
<p class="lead">On-demand Rust memory, thread, CPU and storage measurements. No secrets or message data are inspected.</p></div>
<a class="button button-ghost" href="/wabridge/performance">Measure again</a></div>
<?php if(!$rust['available']): ?>
<div class="notice notice-warning" role="status">Rust process telemetry is unavailable to the PHP application user. This does not mean Waxum is stopped: Cloudways may restrict /proc access. Check the separate <a href="/wabridge/diagnostics">gateway readiness probe</a> or ask Cloudways for process metrics.</div>
<?php endif; ?>
<section class="stats-grid workspace-section">
  <article class="stat-card"><p class="stat-label">Rust resident memory (RSS)</p><div class="stat-value"><?= escape(metric_bytes($rust['rss_bytes'])) ?></div><p class="stat-foot">Actual resident process memory, not binary file size.</p></article>
  <article class="stat-card"><p class="stat-label">Peak resident memory</p><div class="stat-value"><?= escape(metric_bytes($rust['peak_rss_bytes'])) ?></div><p class="stat-foot">Maximum RSS recorded by Linux for this process.</p></article>
  <article class="stat-card"><p class="stat-label">Rust CPU utilization</p><div class="stat-value"><?= $rust['cpu_percent']===null?'Unavailable':escape(number_format($rust['cpu_percent'],1).'%') ?></div><p class="stat-foot"><?= escape($rust['cpu_time']) ?> · May exceed 100% on multi-core servers.</p></article>
  <article class="stat-card"><p class="stat-label">Rust threads</p><div class="stat-value"><?= $rust['threads']===null?'Unavailable':(int)$rust['threads'] ?></div><p class="stat-foot">Includes Tokio runtime and blocking workers.</p></article>
</section>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">PRIVATE STORAGE</div><h2>Disk usage by component</h2></div></div>
<p class="panel-help">File-size totals are bounded scans, not Cloudways quota measurements. Symlinks are ignored and no file contents or paths are disclosed. Measurements are point-in-time observations at <?= escape($metrics['observed_at']) ?>.</p>
<div class="table-scroll"><table><thead><tr><th>Area</th><th>Size</th><th>Files</th><th>Coverage</th></tr></thead><tbody>
<?php foreach(['runtime_binary'=>'Rust executable and private provisioning','whatsapp_sessions'=>'Waxum SQLite and WhatsApp session state','php_json'=>'PHP JSON test data'] as $key=>$label): $row=$metrics['disk'][$key]; ?>
<tr><td><?= escape($label) ?></td><td><?= escape($row['available']?metric_bytes($row['bytes']):'Unavailable') ?></td><td><?= $row['available']?(int)$row['files']:'—' ?></td><td><span class="data-badge <?= $row['truncated']?'warn':'ok' ?>"><?= $row['truncated']?'Partial scan':($row['available']?'Complete scan':'Unavailable') ?></span></td></tr>
<?php endforeach; ?></tbody></table></div>
</section>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">MEASUREMENT METHOD</div><h2>Interpreting the results</h2></div></div>
<p class="panel-help">The page uses restricted Linux /proc metadata for the executable at this application's private runtime path. CPU percentage is calculated over an approximately 200 ms sample, not inferred from archive size. Measured memory is not equivalent to total server RAM. If the process is not accessible, values remain unavailable instead of being estimated.</p>
<p class="panel-help">To compare idle and active load, take a baseline with no connected sessions, then repeat after pairing one test number and after sending a consented test message. Do not automatically restart or change the working service to collect measurements.</p>
<div class="section-links"><a href="/wabridge/diagnostics">Gateway diagnostics</a><a href="/wabridge/gateway">Gateway settings</a><a href="/wabridge/reports">Message reports</a></div>
</section>
</main><?php ui_shell_end(); ?></body></html>
