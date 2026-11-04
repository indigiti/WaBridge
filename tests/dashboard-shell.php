<?php
declare(strict_types=1);
/**
 * One design contract: legacy CSS generations are no longer loaded, nor shipped.
 * This test covers shared chrome, dedicated routes, modern overview composition,
 * and safe authenticated messaging actions.
 */
$root=dirname(__DIR__);
$read=static fn(string $path):string => (string)file_get_contents($root.'/'.$path);
$index=$read('public/index.php');
$shell=$read('src/ui-shell.php');
$css=$read('public/assets/wabridge-design-v6.css');
$js=$read('public/assets/wabridge-console-v3.js');
$routes=$read('public/.htaccess');
$msg=$read('public/messages.php');
$platform=$read('public/platform.php');
function requireParts(string $data,array $parts,string $area):void {
    foreach($parts as $part)
        if(!str_contains($data,$part))throw new RuntimeException($area.' missing '.$part);
}
requireParts($shell,[
    'function ui_icon','function ui_shell_start','function ui_shell_end','function ui_breadcrumb',
    'class="console-body"','class="console-sidebar"','console-sidebar-toggle',
    'console-mobile-toggle','console-mobile-close','console-backdrop',
    'console-nav-group','/wabridge/messages','/wabridge/reports',
    '/wabridge/diagnostics','/wabridge/performance','/wabridge/verification',
    '/wabridge/gateway','/wabridge/storage','/wabridge/devices',
    '/wabridge/sessions','/wabridge/messaging','/wabridge/api-applications',
    'admin_authenticated()','csrf()','wabridge-ui-v6.css',
    'ui_icon($icon)'
],'Shared shell');
requireParts($index,[
    'ui_shell_start($view','ui_shell_end()','ui_breadcrumb(',
    'id="overview"','id="sessions"','id="integrations"',
    'wabridge-ui-v6.css','console-hero','console-overview-grid',
    'activity-feed','ui_icon(', 'id="hero-title"',
    'name="send_nonce"','hash_equals($issued,$nonce)',
    "if (\$view==='messaging')","if (\$view==='devices')",
    "if (\$view==='api-applications')",
    'href="/wabridge/messages'
],'Overview and focused pages');
requireParts($css,[
    '@font-face','font-display:swap','inter-latin-400.woff2',
    'inter-latin-600.woff2','inter-latin-700.woff2',
    '--glass:rgba(','.console-body','.console-sidebar','.sidebar-compact',
    '.sidebar-mobile-open','.console-hero','.console-overview-grid',
    '.activity-feed','.glass-panel','.stat-card',
    '.auth-card','.table-scroll','prefers-reduced-transparency',
    '@media(max-width:1100px)','@media(max-width:760px)',
    '-webkit-backdrop-filter:blur'
],'Canonical premium design');
foreach(['wabridge-premium-v2.css','wabridge-console-v3.css','wabridge-glass-v4.css',
         'wabridge-typography-v5.css'] as $legacy)
    if(str_contains($index,'href="/wabridge/assets/'.$legacy.'"') ||
       str_contains($shell,'href="/wabridge/assets/'.$legacy.'"'))
        throw new RuntimeException('Legacy UI link still loaded: '.$legacy);
requireParts($read('tools/build-assets.php'),[
    'wabridge-design-v6.css','wabridge-ui-v6.css',
    'one authored source'
],'Unified build');
requireParts($js,['aria-expanded','localStorage','keydown','sidebar-mobile-open','new URL(href,'],'Accessible navigation');
requireParts($platform,[
  'admin_authenticated()','assert_csrf()',
  'platform_contact_save','platform_schedule','platform_media_send',
  'platform_rule_create','platform_workspace_create','platform_cloud_connect',
  'platform_page($collection,$page,10,$search)','ui_shell_start($module)',
  'platform_schedule_nonce','platform_media_nonce','csrf()'
],'Product module routes, CSRF and bounded loads');
requireParts($platform,[
    'platform-context','platform-context-link','platform-form-grid','platform-contact-search',
    'platform_session_field($allowedSessions','No opted-in contact is selected',
    'ui_shell_start($module)','platform_page($collection,$page,10)'
],'Module UX: contextual actions and server-side page bounds');
if(str_contains($platform,'platform-hub-subnav'))
    throw new RuntimeException('Crowded duplicate module link strip returned');
requireParts($read('src/ui-shell.php'),['function ui_asset_version','/build/release.json'],'Versioned assets');
requireParts($read('tests/visual-module-fixture.php'),['moduleContext','moduleFormFit','moduleOverflow'],'Visual module regression');
requireParts($read('public/assets/wabridge-design-v6.css'),[
    '.platform-context-link','.platform-form-grid .field>label',
    'grid-template-columns:repeat(2,minmax(0,1fr))'
],'Module premium form/spacing');
requireParts($msg,[
    'admin_authenticated()','assert_csrf()','process_next_message',
    'storage_message_page','message_log_effective_status','escape(',
    "ui_shell_start('messages')",'wabridge-ui-v6.css'
],'Message log');
requireParts($routes,[
    'RewriteEngine On','RewriteBase /wabridge/',
    'RewriteRule ^messages/?$','RewriteRule ^gateway/?$',
    'RewriteRule ^(contacts|templates|schedules|media|automation|developer-tools|security-center|workspaces|cloud-api)/?$',
    'RewriteRule ^storage/?$','RewriteRule ^qr/?$',
    'RewriteRule ^diagnostics/?$','RewriteRule ^reports/?$',
    'RewriteRule ^performance/?$','RewriteRule ^verification/?$',
    'RewriteRule ^(devices|sessions|messaging|api-applications)/?$',
    'RewriteRule ^api/diagnostics/?$','RewriteRule ^api/reports/?$',
    'THE_REQUEST','REQUEST_METHOD','RewriteRule ^_loader'
],'Cloudways routes');
if(!str_contains($routes,'RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$'))
    throw new RuntimeException('Legacy redirects could lose POST data');
if(str_contains($routes,'RewriteRule ^api/.* index.php'))
    throw new RuntimeException('API endpoints incorrectly rewritten');
foreach([
  'public/storage-settings.php'=>"ui_shell_start('storage')",
  'public/gateway-settings.php'=>"ui_shell_start('gateway')",
  'public/qr.php'=>"ui_shell_start('qr',",
  'public/reports-view.php'=>"ui_shell_start('reports')",
  'public/diagnostics-view.php'=>"ui_shell_start('diagnostics',",
  'public/performance.php'=>"ui_shell_start('performance')",
  'public/verification.php'=>"ui_shell_start('verification',"
] as $file=>$fragment) {
    requireParts($read($file),[$fragment,'ui_shell_end()','ui_breadcrumb('],$file);
}
foreach(['public/reports.php','public/diagnostics.php'] as $file)
    requireParts($read($file),['application/json','admin_authenticated()'],$file.' JSON compatibility');
echo "Single-source premium UI v6, SVG navigation, focused overview, safe API and responsive glass layout: PASS\n";
