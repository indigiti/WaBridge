<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $path):string => (string)file_get_contents($root.'/'.$path);
$index=$read('public/index.php');
$shell=$read('src/ui-shell.php');
$css=$read('public/assets/wabridge-console-v3.css');
$glass=$read('public/assets/wabridge-glass-v4.css');
$js=$read('public/assets/wabridge-console-v3.js');
$routes=$read('public/.htaccess');
$msg=$read('public/messages.php');
function requireParts(string $data,array $parts,string $area):void {
    foreach($parts as $part)
       if(!str_contains($data,$part))throw new RuntimeException($area.' missing '.$part);
}
requireParts($shell,[
 'function ui_shell_start','function ui_shell_end','function ui_breadcrumb',
 'class="console-body"','class="console-sidebar"','console-sidebar-toggle',
 'console-mobile-toggle','console-mobile-close','console-backdrop',
 'console-nav-group','/wabridge/messages','/wabridge/reports',
 '/wabridge/diagnostics','/wabridge/performance','/wabridge/verification',
 '/wabridge/gateway','/wabridge/storage','/wabridge/devices',
 '/wabridge/sessions','/wabridge/messaging','/wabridge/api-applications',
 'admin_authenticated()', 'csrf()', 'wabridge-ui-v5.css'
],'Shared shell');
requireParts($index,[
 'ui_shell_start($view','ui_shell_end()','ui_breadcrumb(',
 'id="overview"','id="sessions"','id="integrations"',
 'wabridge-ui-v5.css',
 "in_array(\$view,['overview','messaging']",
 "in_array(\$view,['overview','devices']",
 'href="/wabridge/messages'
],'Dashboard');
requireParts($css,['.console-body','.console-sidebar','.sidebar-compact','.sidebar-mobile-open',
 '@media(max-width:1100px)','.ops-collapse','.console-hero-actions'],'Console CSS');
requireParts($glass,['backdrop-filter:blur','--wb-glass','console-breadcrumbs',
 '.glass-panel','.stat-card','prefers-reduced-transparency'],'Glass design system');
requireParts($read('public/assets/wabridge-typography-v5.css'),[
 '@font-face','font-display:swap','inter-latin-400.woff2',
 'inter-latin-600.woff2','inter-latin-700.woff2','--wb-h1'
],'Typography source');
requireParts($js,['aria-expanded','localStorage','keydown','sidebar-mobile-open',
 'new URL(href'],'Navigation script');
requireParts($msg,['admin_authenticated()','assert_csrf()','process_next_message',
 'storage_admin_messages','message_log_effective_status','escape(',
 "ui_shell_start('messages')"],'Message log');
requireParts($routes,[
 'RewriteEngine On','RewriteBase /wabridge/',
 'RewriteRule ^messages/?$','RewriteRule ^gateway/?$',
 'RewriteRule ^storage/?$','RewriteRule ^qr/?$',
 'RewriteRule ^diagnostics/?$','RewriteRule ^reports/?$',
 'RewriteRule ^performance/?$','RewriteRule ^verification/?$',
 'RewriteRule ^(devices|sessions|messaging|api-applications)/?$',
 'RewriteRule ^api/diagnostics/?$','RewriteRule ^api/reports/?$',
 'THE_REQUEST','REQUEST_METHOD','RewriteRule ^_loader'
],'Cloudways pretty routes');
if(!str_contains($routes,'RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$'))
 throw new RuntimeException('Legacy redirects could lose POST body');
if(str_contains($routes,'RewriteRule ^api/.* index.php'))
 throw new RuntimeException('API endpoints incorrectly rewritten to HTML');
foreach([
 'public/storage-settings.php'=>"ui_shell_start('storage')",
 'public/gateway-settings.php'=>"ui_shell_start('gateway')",
 'public/qr.php'=>"ui_shell_start('qr',",
 'public/reports-view.php'=>"ui_shell_start('reports')",
 'public/diagnostics-view.php'=>"ui_shell_start('diagnostics',",
 'public/performance.php'=>"ui_shell_start('performance')",
 'public/verification.php'=>"ui_shell_start('verification',"
] as $file=>$fragment) {
 $source=$read($file);
 requireParts($source,[$fragment,'ui_shell_end()','ui_breadcrumb('],$file);
}
foreach(['public/reports.php','public/diagnostics.php'] as $file) {
 requireParts($read($file),['application/json','admin_authenticated()'],$file.' JSON compatibility');
}
echo "Premium glass shell, typography, navigation, compatibility routes and acceptance pages: PASS\n";
