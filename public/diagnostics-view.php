<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ops/connection-watchdog.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
if (!admin_authenticated()) { http_response_code(403);exit('Sign in to WaBridge first.'); }
$error='';
$storage=false;$driver='unknown';
$gateway=['state'=>'unavailable','ready'=>false,'http'=>null,'message'=>'Gateway check unavailable'];
$sessions=[];
$capabilities=[];
try {
    $storage=storage_health();
    $driver=storage_mode();
    $gateway=gateway_diagnostics();
    if ($gateway['ready']) $sessions=upstream_sessions();
    $capabilities=engine_capabilities();
} catch (Throwable $e) {
    $error='Part of the runtime could not be inspected. Check private application logs.';
    error_log('WaBridge diagnostics view '.get_class($e));
}
$watchdog=watchdog_read();
$ready=(bool)($gateway['ready']??false);
$enabled=0;
foreach ($capabilities as $item) if (is_array($item)&&!empty($item['enabled'])) $enabled++;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>System diagnostics · WaBridge</title>
<?php ui_head_assets(); ?></head><body class="dashboard-page">
<?php ui_shell_start('diagnostics',$ready); ?>
<main class="workspace" id="main-content">
<?php ui_breadcrumb('Workspace','System diagnostics'); ?>
<div class="workspace-top"><div><div class="eyebrow">OPERATIONS / HEALTH</div><h1>System diagnostics</h1><p class="lead">Separate PHP, storage and gateway readiness from WhatsApp session connectivity.</p></div>
<a class="button button-ghost" href="/wabridge/diagnostics">Refresh status</a></div>
<?php if($error!==''): ?><div class="notice notice-warning" role="alert"><?= escape($error) ?></div><?php endif; ?>
<section class="readiness-grid workspace-section" aria-label="System health">
 <article class="glass-panel"><div class="eyebrow">PHP RUNTIME</div><strong><?= escape(PHP_VERSION) ?></strong><span class="data-badge ok">Available</span><small>Web administration runtime</small></article>
 <article class="glass-panel"><div class="eyebrow">APPLICATION STORAGE</div><strong><?= escape(strtoupper($driver)) ?></strong><span class="data-badge <?= $storage?'ok':'warn' ?>"><?= $storage?'Ready':'Check required' ?></span><small><?= $driver==='json'?'Private single-server test storage':'Backend health and consistency' ?></small></article>
 <article class="glass-panel"><div class="eyebrow">RUST / WAXUM</div><strong><?= escape(str_replace('_',' ',(string)$gateway['state'])) ?></strong><span class="data-badge <?= $ready?'ok':'warn' ?>">HTTP <?= $gateway['http']===null?'unavailable':(int)$gateway['http'] ?></span><small><?= escape((string)$gateway['message']) ?></small></article>
</section>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">SCHEDULED CONNECTION WATCHDOG</div><h2>WhatsApp session monitoring</h2></div>
<span class="data-badge <?= $watchdog['state']==='recent'?'ok':'warn' ?>"><?= escape($watchdog['state']==='recent'?'Recently checked':($watchdog['state']==='stale'?'Stale':'Not configured')) ?></span></div>
<p class="panel-help">The dashboard reads a private snapshot, not live WhatsApp session probes. Last check: <?= escape($watchdog['checked_at']===null?'Unavailable':gmdate('Y-m-d H:i:s',(int)$watchdog['checked_at']).' UTC') ?>. Gateway: <?= escape((string)$watchdog['gateway']) ?>.</p>
<div class="stats-grid">
<article class="stat-card"><p class="stat-label">Connected</p><div class="stat-value"><?= (int)$watchdog['connected'] ?></div></article>
<article class="stat-card"><p class="stat-label">Disconnected</p><div class="stat-value"><?= (int)$watchdog['disconnected'] ?></div></article>
<article class="stat-card"><p class="stat-label">Unknown</p><div class="stat-value"><?= (int)$watchdog['unknown'] ?></div></article>
<article class="stat-card"><p class="stat-label">Sampled sessions</p><div class="stat-value"><?= (int)$watchdog['sampled_sessions'] ?></div></article>
</div>
<?php if($watchdog['truncated']):?><p class="notice notice-warning">The watchdog sampled its configured per-run limit. Remaining sessions were not inspected; totals do not represent every device.</p><?php endif;?>
<?php if($watchdog['transitions']):?><p class="panel-help">Observed changes: <?= count($watchdog['transitions']) ?>. A transition to connected is an observation, not proof that WhatsApp delivered any message.</p><?php endif;?>
<p class="panel-help">Configure the private five-minute CLI watchdog cron. It never forces QR pairing or unsupported reconnect commands.</p>
</section>
<div class="report-grid workspace-section">
 <section class="glass-panel"><div class="panel-heading"><div><div class="eyebrow">CONNECTIONS</div><h2>WhatsApp sessions</h2></div></div>
 <p class="panel-help"><?= count($sessions) ?> session(s) returned by the Rust gateway. A ready gateway does not guarantee that a particular number is paired.</p>
 <div class="table-scroll"><table><thead><tr><th>Session</th><th>Gateway status</th><th>Pairing</th></tr></thead><tbody>
 <?php foreach($sessions as $session): if (!is_array($session)) continue; $id=(string)($session['id']??$session['session_id']??''); if(!permitted_session($id))continue; ?>
 <tr><td><?= escape($id) ?></td><td><?= escape((string)($session['status']??'unknown')) ?></td><td><a href="/wabridge/qr?sid=<?= rawurlencode($id) ?>">Open QR</a></td></tr>
 <?php endforeach; ?>
 <?php if(!$sessions): ?><tr><td colspan="3" class="empty-cell">No sessions reported, or gateway unavailable.</td></tr><?php endif; ?>
 </tbody></table></div>
 </section>
 <section class="glass-panel"><div class="panel-heading"><div><div class="eyebrow">ENGINE SUPPORT</div><h2>Verified capabilities</h2></div></div>
 <p class="panel-help"><?= $enabled ?> capability entries enabled in the WaBridge adapter. Upstream support does not imply a live send or delivery test.</p>
 <div class="table-scroll"><table><thead><tr><th>Feature</th><th>Status</th></tr></thead><tbody>
 <?php foreach ($capabilities as $name=>$details): if(!is_array($details))continue; ?>
 <tr><td><?= escape(str_replace('_',' ',(string)$name)) ?></td><td><span class="data-badge <?= !empty($details['enabled'])?'ok':'warn' ?>"><?= !empty($details['enabled'])?'Enabled':'Limited' ?></span></td></tr>
 <?php endforeach; ?>
 </tbody></table></div>
 </section>
</div>
<div class="section-links"><a href="/wabridge/api/diagnostics">Raw diagnostics JSON</a><a href="/wabridge/gateway">Gateway settings</a><a href="/wabridge/storage">Storage settings</a></div>
</main><?php ui_shell_end(); ?></body></html>
