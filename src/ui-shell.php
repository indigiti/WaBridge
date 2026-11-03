<?php
declare(strict_types=1);

/**
 * Shared authenticated WaBridge application shell.
 * Never call from a public endpoint before admin_authenticated().
 * PHP JSON endpoints and non-admin login remain deliberately headless.
 */
function ui_shell_start(string $page, ?bool $gatewayReady=null): void {
    $pageLabels=[
        'overview'=>'Overview', 'devices'=>'Connect device', 'sessions'=>'Linked sessions',
        'messaging'=>'Send message', 'messages'=>'Message activity',
        'reports'=>'Reports', 'diagnostics'=>'Diagnostics',
        'api-applications'=>'API applications', 'gateway'=>'Gateway settings',
        'storage'=>'Storage settings', 'qr'=>'QR pairing'
    ];
    if (!isset($pageLabels[$page])) throw new InvalidArgumentException('Unknown console page');
    if (!admin_authenticated()) throw new RuntimeException('Console requires administrator authentication');
    $navGroups=[
      'Workspace'=>[
        ['overview','/wabridge/','◫'], ['reports','/wabridge/reports','▥'],
        ['diagnostics','/wabridge/diagnostics','◉']
      ],
      'WhatsApp'=>[
        ['devices','/wabridge/devices','＋'], ['sessions','/wabridge/sessions','▦'],
        ['messaging','/wabridge/messaging','➤'], ['messages','/wabridge/messages','▤']
      ],
      'Developer'=>[
        ['api-applications','/wabridge/api-applications','⌘']
      ],
      'Administration'=>[
        ['gateway','/wabridge/gateway','⚙'], ['storage','/wabridge/storage','▧']
      ],
    ];
?>
<div class="site-shell">
  <header class="site-header console-global-header">
    <a class="brand-lockup" href="/wabridge/" aria-label="WaBridge overview">
      <span class="brand-mark" aria-hidden="true"><span class="brand-mark-inner">W</span></span>
      <span class="brand-copy"><strong>Wa<span>Bridge</span></strong><small>Messaging infrastructure</small></span>
    </a>
    <div class="header-right">
      <span class="header-caption">Connect. Communicate. Automate.</span>
      <button id="console-mobile-toggle" class="sidebar-mobile-toggle" type="button" aria-expanded="false" aria-controls="console-sidebar" aria-label="Open navigation">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
      <span class="console-user">Administrator</span>
      <form method="post" action="/wabridge/" class="header-logout">
        <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
        <input type="hidden" name="action" value="logout">
        <button class="button button-ghost button-small" type="submit">Sign out</button>
      </form>
    </div>
  </header>
  <div class="console-body" id="console-body">
    <div id="console-backdrop" class="console-mobile-backdrop" aria-hidden="true"></div>
    <aside class="console-sidebar" id="console-sidebar" aria-label="Primary navigation">
      <div class="console-sidebar-header">
        <span class="console-sidebar-heading">Your workspace</span>
        <button id="console-sidebar-toggle" class="sidebar-toggle" type="button" aria-expanded="true" aria-controls="console-sidebar" aria-label="Collapse sidebar" title="Collapse or expand sidebar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5 3 12l7 7M4 12h17"/></svg></button>
        <button id="console-mobile-close" class="sidebar-mobile-close" type="button" aria-label="Close navigation"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5l14 14M19 5 5 19"/></svg></button>
      </div>
      <nav aria-label="Operations menu">
      <?php foreach ($navGroups as $group=>$links): ?>
        <?php $currentGroup=false;foreach($links as $item) if($item[0]===$page) $currentGroup=true; ?>
        <details class="console-nav-group" <?= ($currentGroup || $group==='Workspace') ? 'open' : '' ?>>
          <summary><span><?= escape($group) ?></span></summary>
          <div class="console-nav-children">
          <?php foreach ($links as [$key,$url,$icon]): ?>
            <a class="console-nav-link <?= $key===$page?'is-active':'' ?>" href="<?= escape($url) ?>" <?= $key===$page?'aria-current="page"':'' ?> title="<?= escape($pageLabels[$key]) ?>"><span class="nav-icon" aria-hidden="true"><?= escape($icon) ?></span><span class="nav-label"><?= escape($pageLabels[$key]) ?></span></a>
          <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
      </nav>
      <div class="console-sidebar-bottom">
        <span class="sidebar-meta">Environment</span>
        <strong>Cloudways · WaBridge</strong>
        <small><?= $gatewayReady===true ? 'Gateway ready' : ($gatewayReady===false ? 'Gateway requires attention' : 'Private operations workspace') ?></small>
      </div>
    </aside>
<?php
}
function ui_shell_end(): void {
?>
  </div>
  <footer class="site-footer"><span>WaBridge · Private operations workspace</span><span>Unofficial WhatsApp API · Verify delivery from receipts</span></footer>
</div>
<?php
}
function ui_breadcrumb(string $section,string $title):void {
?>
<nav class="console-breadcrumbs" aria-label="Breadcrumb"><a href="/wabridge/">Workspace</a><span aria-hidden="true">/</span><span><?= escape($section) ?></span><span aria-hidden="true">/</span><strong aria-current="page"><?= escape($title) ?></strong></nav>
<?php
}
function ui_head_assets():void {
?>
  <link rel="stylesheet" href="/wabridge/assets/wabridge-premium-v2.css?v=3">
  <link rel="stylesheet" href="/wabridge/assets/wabridge-console-v3.css?v=2">
  <link rel="stylesheet" href="/wabridge/assets/wabridge-glass-v4.css?v=1">
  <script src="/wabridge/assets/wabridge-console-v3.js?v=2" defer></script>
<?php
}
