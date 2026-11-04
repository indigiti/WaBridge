<?php
declare(strict_types=1);

/**
 * WaBridge v6 application shell. Browser admin only; never for JSON/API routes.
 * All icon SVGs are static reviewed markup, not user-controlled HTML.
 */
function ui_icon(string $name): string {
    $shapes=[
      'overview'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
      'reports'=>'<path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/>',
      'diagnostics'=>'<path d="M3 12h4l3-6 4 12 3-6h4"/>',
      'performance'=>'<path d="M4 20V4m0 16h16M7 16l4-5 3 2 5-7"/>',
      'verification'=>'<path d="M4 12l5 5L20 6"/>',
      'devices'=>'<rect x="6" y="2.5" width="12" height="19" rx="3"/><path d="M10 18h4"/>',
      'sessions'=>'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 9h18m-12 6h6"/>',
      'messaging'=>'<path d="M21 3 3 10l7 3 3 8 8-18Z"/><path d="m10 13 5-5"/>',
      'messages'=>'<path d="M5 4h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-5 3V6a2 2 0 0 1 2-2Z"/><path d="M8 10h8m-8 4h5"/>',
      'api-applications'=>'<path d="m9 7-5 5 5 5m6-10 5 5-5 5m-2-14-2 18"/>',
      'gateway'=>'<rect x="4" y="5" width="16" height="14" rx="3"/><path d="M8 9h8m-8 4h5m3 0h.01"/>',
      'storage'=>'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v7c0 2 4 3 8 3s8-1 8-3V5M4 12v7c0 2 4 3 8 3s8-1 8-3v-7"/>',
      'qr'=>'<path d="M3 9V3h6m6 0h6v6M3 15v6h6m6 0h6v-6"/><path d="M7 7h3v3H7zm7 0h3v3h-3zm-7 7h3v3H7zm7 0h3v3h-3z"/>',
      'contacts'=>'<circle cx="8" cy="8" r="3"/><path d="M2 19v-2a6 6 0 0 1 12 0v2M17 8a3 3 0 0 1 0 6m1 3a5 5 0 0 1 4 4"/>',
      'templates'=>'<path d="M6 3h9l4 4v14H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm9 0v5h5M8 13h8m-8 4h8"/>',
      'schedules'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m-12 4h4m-4 3h4"/>',
      'media'=>'<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8" cy="9" r="2"/><path d="m4 19 6-6 4 4 3-3 4 4"/>',
      'automation'=>'<rect x="2" y="9" width="6" height="6" rx="1"/><rect x="16" y="2" width="6" height="6" rx="1"/><rect x="16" y="16" width="6" height="6" rx="1"/><path d="M8 12h4l4-7M12 12l4 7"/>',
      'developer-tools'=>'<path d="m9 8-4 4 4 4m6-8 4 4-4 4M13 5l-2 14"/>',
      'security-center'=>'<path d="m12 2 8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z"/><path d="m9 12 2 2 4-4"/>',
      'workspaces'=>'<rect x="3" y="4" width="8" height="8" rx="1"/><rect x="13" y="4" width="8" height="8" rx="1"/><rect x="3" y="14" width="8" height="8" rx="1"/><rect x="13" y="14" width="8" height="8" rx="1"/>',
      'cloud-api'=>'<path d="M7 18h12a4 4 0 0 0 .3-8A7 7 0 0 0 6 8a5 5 0 0 0 1 10Z"/><path d="m10 13 2 2 4-4"/>',
      'webhook-events'=>'<path d="M8 3A6 6 0 0 1 18 8v3m0 7A6 6 0 0 1 7 18h9M3 10a6 6 0 0 1 6-6m-3 0 3-1-1 4m12 14-3 1 1-4"/>',
      'audit'=>'<path d="M6 3h12v18H6zM9 8h6m-6 4h6m-6 4h4"/>',
      'trace'=>'<path d="M4 5h9l-3-3m3 3-3 3M20 19h-9l3 3m-3-3 3-3M4 19a7 7 0 0 1-1-8m17-6a7 7 0 0 1 1 8"/>',
      'chevron'=>'<path d="m9 18 6-6-6-6"/>',
    ];
    $shape=$shapes[$name]??$shapes['overview'];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'.$shape.'</svg>';
}
function ui_shell_start(string $page, ?bool $gatewayReady=null): void {
    $pageLabels=[
        'overview'=>'Overview','devices'=>'Connect device','sessions'=>'Linked sessions',
        'messaging'=>'Send message','messages'=>'Message activity','reports'=>'Reports',
        'diagnostics'=>'Diagnostics','performance'=>'Performance','verification'=>'Verification',
        'api-applications'=>'API applications','gateway'=>'Gateway settings',
        'storage'=>'Storage settings','qr'=>'QR pairing','audit'=>'Audit log','trace'=>'Message trace',
        'contacts'=>'Contacts','templates'=>'Templates','schedules'=>'Schedules','media'=>'Media messages',
        'automation'=>'Automation','developer-tools'=>'API tools','security-center'=>'Security',
        'workspaces'=>'Workspaces','cloud-api'=>'Official Cloud API','webhook-events'=>'Webhook events'
    ];
    if(!isset($pageLabels[$page]))throw new InvalidArgumentException('Unknown console page');
    if(!admin_authenticated())throw new RuntimeException('Console requires administrator authentication');
    $navGroups=[
      'Workspace'=>[
        ['overview','/wabridge/','overview'],['reports','/wabridge/reports','reports'],
         ['audit','/wabridge/audit','audit'],
        ['performance','/wabridge/performance','performance'],
        ['verification','/wabridge/verification','verification'],
        ['diagnostics','/wabridge/diagnostics','diagnostics'],
      ],
      'Messaging'=>[
        ['sessions','/wabridge/sessions','sessions'],
        ['devices','/wabridge/devices','devices'],
        ['messaging','/wabridge/messaging','messaging'],
        ['messages','/wabridge/messages','messages'],
        ['media','/wabridge/media','media'],
      ],
      'Contacts & Scheduling'=>[
        ['contacts','/wabridge/contacts','contacts'],
        ['templates','/wabridge/templates','templates'],
        ['schedules','/wabridge/schedules','schedules'],
      ],
      'Automation'=>[['automation','/wabridge/automation','automation']],
      'Developer'=>[
        ['api-applications','/wabridge/api-applications','api-applications'],
        ['developer-tools','/wabridge/developer-tools','developer-tools'],
        ['webhook-events','/wabridge/webhook-events','webhook-events'],
      ],
      'Administration'=>[
        ['gateway','/wabridge/gateway','gateway'],
        ['storage','/wabridge/storage','storage'],
        ['cloud-api','/wabridge/cloud-api','cloud-api'],
        ['security-center','/wabridge/security-center','security-center'],
        ['workspaces','/wabridge/workspaces','workspaces'],
      ],
    ];
?>
<div class="site-shell">
  <header class="site-header console-global-header">
    <a class="brand-lockup" href="/wabridge/" aria-label="WaBridge overview">
      <span class="brand-mark" aria-hidden="true"><span class="brand-mark-inner">W</span></span>
      <span class="brand-copy"><strong>Wa<span>Bridge</span></strong><small>Messaging operations</small></span>
    </a>
    <div class="header-right">
      <span class="header-caption">One workspace. Every connection.</span>
      <span class="console-user" title="Signed in as administrator">Admin</span>
      <button id="console-mobile-toggle" class="sidebar-mobile-toggle" type="button" aria-expanded="false" aria-controls="console-sidebar" aria-label="Open navigation">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
      <form method="post" action="/wabridge/sign-out" class="header-logout">
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
        <span class="console-sidebar-heading">Navigation</span>
        <button id="console-sidebar-toggle" class="sidebar-toggle" type="button" aria-expanded="true" aria-controls="console-sidebar" aria-label="Collapse sidebar" title="Collapse or expand sidebar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5 3 12l7 7M4 12h17"/></svg></button>
        <button id="console-mobile-close" class="sidebar-mobile-close" type="button" aria-label="Close navigation"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5l14 14M19 5 5 19"/></svg></button>
      </div>
      <nav aria-label="Operations menu">
      <?php foreach($navGroups as $group=>$links): ?>
        <?php $activeGroup=false;foreach($links as $item)if($item[0]===$page)$activeGroup=true; ?>
        <details class="console-nav-group" <?= $activeGroup||$group==='Workspace'?'open':'' ?>>
          <summary><span><?= escape($group) ?></span></summary>
          <div class="console-nav-children">
          <?php foreach($links as [$key,$url,$icon]): ?>
            <a class="console-nav-link <?= $key===$page?'is-active':'' ?>" href="<?= escape($url) ?>" <?= $key===$page?'aria-current="page"':'' ?> title="<?= escape($pageLabels[$key]) ?>"><span class="nav-icon" aria-hidden="true"><?= ui_icon($icon) ?></span><span class="nav-label"><?= escape($pageLabels[$key]) ?></span></a>
          <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
      </nav>
      <div class="console-sidebar-bottom">
        <span class="sidebar-meta">Gateway environment</span>
        <strong><?= $gatewayReady===true?'Gateway online':($gatewayReady===false?'Action required':'Private workspace') ?></strong>
        <small><?= $gatewayReady===true?'Rust health check passed. Session status is separate.':($gatewayReady===false?'Check gateway diagnostics for details.':'Secure administrative access.') ?></small>
      </div>
    </aside>
<?php
}
function ui_shell_end():void {
?>
  </div>
  <footer class="site-footer"><span>WaBridge · Messaging operations</span><span>Unofficial WhatsApp connection · Delivery requires a verified receipt</span></footer>
</div>
<?php
}
function ui_breadcrumb(string $section,string $title):void {
?>
<nav class="console-breadcrumbs" aria-label="Breadcrumb"><a href="/wabridge/">Workspace</a><span aria-hidden="true">/</span><span><?= escape($section) ?></span><span aria-hidden="true">/</span><strong aria-current="page"><?= escape($title) ?></strong></nav>
<?php
}
/**
 * Version asset URLs by the DigiOps release commit, not by a fixed ?v=6.
 * This prevents an old cached CSS/JS file from styling a newer PHP page.
 * The manifest is generated at release time and contains no secrets.
 */
function ui_asset_version():string {
    static $version=null;
    if($version!==null)return $version;
    $version='7';
    $manifest=__DIR__.'/../build/release.json';
    if(is_file($manifest)&&!is_link($manifest)){
        $data=json_decode((string)@file_get_contents($manifest),true);
        $sha=is_array($data)?(string)($data['sourceSha']??''):'';
        if(preg_match('/\\A[a-f0-9]{40}\\z/D',$sha))
            $version=substr($sha,0,12);
    }
    return $version;
}
function ui_head_assets():void {
?>
  <link rel="stylesheet" href="/wabridge/assets/wabridge-ui-v6.css?v=<?= escape(ui_asset_version()) ?>">
  <script src="/wabridge/assets/wabridge-console-v3.js?v=<?= escape(ui_asset_version()) ?>" defer></script>
<?php
}
