<?php
declare(strict_types=1);
/**
 * CI-only visual fixture. Not packaged or deployed by DigiOps.
 * Uses exactly the production layout, CSS and icon components with synthetic data.
 */
function admin_authenticated():bool{return true;}
function csrf():string{return 'only-a-visual-fixture';}
function escape(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
require dirname((string)$_SERVER['DOCUMENT_ROOT']).'/src/ui-shell.php';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WaBridge · Visual QA fixture</title>
<link rel="stylesheet" href="/wabridge/assets/wabridge-ui-v6.css?v=6">
<script src="/wabridge/assets/wabridge-console-v3.js?v=2" defer></script>
</head><body class="dashboard-page page-view-overview">
<div class="ambient ambient-one"></div><div class="ambient ambient-two"></div><div class="ambient ambient-three"></div>
<?php ui_shell_start('overview',true); ?>
<main class="workspace" id="main-content">
<?php ui_breadcrumb('Operations','Overview'); ?>
<div class="workspace-top"><div><div class="eyebrow">OPERATIONS / OVERVIEW</div><h1>Workspace overview</h1><p class="lead">A focused view of your connections, message pipeline and system health.</p></div><span class="workspace-chip"><span class="live-dot"></span>Secure workspace</span></div>
<section class="console-hero" aria-labelledby="hero-title">
 <div class="console-hero-main"><div class="eyebrow">YOUR MESSAGING WORKSPACE</div><h2 id="hero-title">Every connection.<br>One clear view.</h2>
 <p>Monitor your sessions, send messages with confidence and keep your integrations moving from a single control center.</p>
 <div class="console-hero-actions"><a href="/wabridge/devices"><?= ui_icon('devices') ?> Link a device</a><a href="/wabridge/messaging"><?= ui_icon('messaging') ?> Send a message</a></div></div>
 <div class="console-hero-aside"><div class="console-hero-status"><div class="console-hero-status-label">Rust gateway</div><strong><span class="live-dot"></span>Operational</strong><small>Gateway ready · Sessions checked separately</small></div>
 <div class="console-hero-status"><div class="console-hero-status-label">Message pipeline</div><strong>8 in queue</strong><small>Review pending or uncertain messages</small></div></div>
</section>
<section class="workspace-section" id="overview"><div class="section-heading"><div><div class="eyebrow">REAL-TIME SNAPSHOT</div><h2>At a glance</h2></div><span class="section-subtitle">Current gateway and message pipeline</span></div>
<div class="stats-grid">
 <article class="stat-card"><span class="stat-icon"><?= ui_icon('diagnostics') ?></span><p class="stat-label">Gateway health</p><div class="stat-value tone-success">Ready</div><div class="stat-foot">Rust gateway readiness</div></article>
 <article class="stat-card"><span class="stat-icon"><?= ui_icon('sessions') ?></span><p class="stat-label">Linked sessions</p><div class="stat-value">12</div><div class="stat-foot">Reported by gateway</div></article>
 <article class="stat-card"><span class="stat-icon"><?= ui_icon('messaging') ?></span><p class="stat-label">Queued messages</p><div class="stat-value">8</div><div class="stat-foot">19 accepted, not necessarily delivered</div></article>
 <article class="stat-card"><span class="stat-icon"><?= ui_icon('verification') ?></span><p class="stat-label">Needs review</p><div class="stat-value">2</div><div class="stat-foot">Review uncertain deliveries</div></article>
</div></section>
<div class="console-overview-grid workspace-section">
 <section class="glass-panel"><div class="panel-heading"><div><div class="eyebrow">CONNECTED ACCOUNTS</div><h2>WhatsApp sessions</h2></div><a class="button button-ghost button-small" href="/wabridge/devices">Add device →</a></div>
 <div class="table-scroll"><table><thead><tr><th>Session</th><th>Status</th><th>Pairing</th></tr></thead><tbody>
 <tr><td><strong>support-team</strong></td><td><span class="subtle-tag tag-success">Connected</span></td><td><a class="table-link" href="#">View QR</a></td></tr>
 <tr><td><strong>school-operations</strong></td><td><span class="subtle-tag">Awaiting scan</span></td><td><a class="table-link" href="#">View QR</a></td></tr>
 </tbody></table></div></section>
 <section class="glass-panel"><div class="panel-heading console-inline-heading"><div><div class="eyebrow">DELIVERY OBSERVABILITY</div><h2>Recent message activity</h2></div><a class="button button-ghost button-small" href="/wabridge/messages">View full log →</a></div>
 <p class="panel-help">Statuses are based on actual gateway responses and receipts.</p>
 <div class="activity-feed" role="list">
 <div class="activity-item" role="listitem"><span class="activity-avatar"><?= ui_icon('messages') ?></span><span class="activity-details"><strong>support-team <span class="activity-recipient">· ••••7890</span></strong><small>Today · 09:12 UTC</small></span><span class="activity-state activity-state-success">Delivered</span></div>
 <div class="activity-item" role="listitem"><span class="activity-avatar"><?= ui_icon('messages') ?></span><span class="activity-details"><strong>school-operations <span class="activity-recipient">· ••••2109</span></strong><small>Today · 09:08 UTC</small></span><span class="activity-state">Accepted · delivery unknown</span></div>
 </div></section>
</div>
</main><?php ui_shell_end(); ?>
<script>
window.addEventListener('load',() => {
  const b=document.body;
  const glass=document.querySelector('.glass-panel');
  const hero=document.querySelector('.console-hero');
  const side=document.querySelector('.console-sidebar');
  const metric=document.querySelector('.stat-card');
  const viewport=document.documentElement.clientWidth;
  b.dataset.visualReady='true';
  b.dataset.visualWidth=String(viewport);
  b.dataset.visualGlass=String(getComputedStyle(glass).backdropFilter!=='none');
  b.dataset.visualHero=String(hero.getBoundingClientRect().height>160);
  b.dataset.visualFont=getComputedStyle(hero).fontFamily.includes('WaBridge Inter')?'inter':'fallback';
  b.dataset.visualOverflow=String(document.documentElement.scrollWidth>viewport+5);
  b.dataset.visualMetricWidth=String(Math.round(metric.getBoundingClientRect().width));
  b.dataset.visualMetric=String(metric.getBoundingClientRect().width>130);
  b.dataset.visualSidebar=String(side.getBoundingClientRect().width>=75);
});
</script>
</body></html>
