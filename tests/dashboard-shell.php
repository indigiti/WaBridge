<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$contents=static fn(string $path):string => (string)file_get_contents($root.'/'.$path);
$index=$contents('public/index.php');
$shell=$contents('src/ui-shell.php');
$css=$contents('public/assets/wabridge-console-v3.css');
$glass=$contents('public/assets/wabridge-glass-v4.css');
$js=$contents('public/assets/wabridge-console-v3.js');
$htaccess=$contents('public/.htaccess');
$messages=$contents('public/messages.php');
$requireFragments=static function(string $source,array $fragments,string $description):void {
    foreach($fragments as $fragment)
        if(!str_contains($source,$fragment)) throw new RuntimeException($description.' missing '.$fragment);
};
$requireFragments($shell,[
    'function ui_shell_start','function ui_shell_end','function ui_breadcrumb',
    'class="console-body"','class="console-sidebar"','console-sidebar-toggle',
    'console-mobile-toggle','console-mobile-close','console-backdrop',
    'console-nav-group','/wabridge/diagnostics','/wabridge/reports','/wabridge/performance',
    '/wabridge/messages','/wabridge/gateway','/wabridge/storage',
    '/wabridge/devices','/wabridge/sessions','/wabridge/messaging',
    '/wabridge/api-applications', 'admin_authenticated()', 'csrf()'
],'Shared console shell');
$requireFragments($index,[
    'ui_shell_start($view','ui_shell_end()', 'ui_breadcrumb(',
    'id="overview"','id="sessions"','id="integrations"',
    "in_array(\$view,['overview','messaging']",
    "in_array(\$view,['overview','devices']",
    'href="/wabridge/messages','href="/wabridge/diagnostics',
    'wabridge-ui-v5.css',
    'require_once dirname((new ReflectionFunction'
],'Dashboard');
$requireFragments($css,[
    '.console-body','.console-sidebar','.sidebar-compact','.sidebar-mobile-open',
    '@media(max-width:1100px)','.ops-collapse','.console-hero-actions'
],'Responsive navigation styles');
$requireFragments($glass,[
    'backdrop-filter:blur','--wb-glass','console-breadcrumbs',
    '.glass-panel','.stat-card','prefers-reduced-transparency'
],'Glass design system');
$requireFragments($js,['aria-expanded','localStorage','keydown','sidebar-mobile-open','new URL(href'],'Navigation behavior');
$requireFragments($messages,[
    'admin_authenticated()','assert_csrf()','process_next_message',
    'storage_admin_messages','message_log_effective_status','escape(',
    "ui_shell_start('messages')"
],'Message activity security');
$requireFragments($htaccess,[
    'RewriteEngine On','RewriteBase /wabridge/',
    'RewriteRule ^messages/?$','RewriteRule ^gateway/?$',
    'RewriteRule ^storage/?$','RewriteRule ^qr/?$',
    'RewriteRule ^diagnostics/?
    'RewriteRule ^(devices|sessions|messaging|api-applications)/?$',
    'RewriteRule ^api/diagnostics/?$','RewriteRule ^api/reports/?$',
    'THE_REQUEST','REQUEST_METHOD', 'RewriteRule ^_loader'
],'Cloudways pretty-route configuration');
if(!str_contains($htaccess,'RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$'))
    throw new RuntimeException('Pretty URL redirects may affect POST requests');
if(str_contains($htaccess,'RewriteRule ^api/.* index.php'))
    throw new RuntimeException('API paths incorrectly routed through HTML');
foreach (['public/storage-settings.php'=>"ui_shell_start('storage')",
          'public/gateway-settings.php'=>"ui_shell_start('gateway')",
          'public/qr.php'=>"ui_shell_start('qr',",
          'public/reports-view.php'=>"ui_shell_start('reports')",
          'public/performance.php'=>"ui_shell_start('performance')",
          'public/diagnostics-view.php'=>"ui_shell_start('diagnostics',"] as $path=>$fragment) {
    $data=$contents($path);
    $requireFragments($data,[$fragment,'ui_shell_end()', 'ui_breadcrumb('],$path);
}
foreach (['public/reports.php','public/diagnostics.php'] as $path) {
    $requireFragments($contents($path),['application/json','admin_authenticated()'],$path.' JSON compatibility');
}
echo "Shared glass dashboard, pretty URL table, responsive nav, safe JSON API and admin controls: PASS\n";
,'RewriteRule ^reports/?
    'RewriteRule ^(devices|sessions|messaging|api-applications)/?$',
    'RewriteRule ^api/diagnostics/?$','RewriteRule ^api/reports/?$',
    'THE_REQUEST','REQUEST_METHOD', 'RewriteRule ^_loader'
],'Cloudways pretty-route configuration');
if(!str_contains($htaccess,'RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$'))
    throw new RuntimeException('Pretty URL redirects may affect POST requests');
if(str_contains($htaccess,'RewriteRule ^api/.* index.php'))
    throw new RuntimeException('API paths incorrectly routed through HTML');
foreach (['public/storage-settings.php'=>"ui_shell_start('storage')",
          'public/gateway-settings.php'=>"ui_shell_start('gateway')",
          'public/qr.php'=>"ui_shell_start('qr',",
          'public/reports-view.php'=>"ui_shell_start('reports')",
          'public/diagnostics-view.php'=>"ui_shell_start('diagnostics',"] as $path=>$fragment) {
    $data=$contents($path);
    $requireFragments($data,[$fragment,'ui_shell_end()', 'ui_breadcrumb('],$path);
}
foreach (['public/reports.php','public/diagnostics.php'] as $path) {
    $requireFragments($contents($path),['application/json','admin_authenticated()'],$path.' JSON compatibility');
}
echo "Shared glass dashboard, pretty URL table, responsive nav, safe JSON API and admin controls: PASS\n";
,'RewriteRule ^performance/?
    'RewriteRule ^(devices|sessions|messaging|api-applications)/?$',
    'RewriteRule ^api/diagnostics/?$','RewriteRule ^api/reports/?$',
    'THE_REQUEST','REQUEST_METHOD', 'RewriteRule ^_loader'
],'Cloudways pretty-route configuration');
if(!str_contains($htaccess,'RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$'))
    throw new RuntimeException('Pretty URL redirects may affect POST requests');
if(str_contains($htaccess,'RewriteRule ^api/.* index.php'))
    throw new RuntimeException('API paths incorrectly routed through HTML');
foreach (['public/storage-settings.php'=>"ui_shell_start('storage')",
          'public/gateway-settings.php'=>"ui_shell_start('gateway')",
          'public/qr.php'=>"ui_shell_start('qr',",
          'public/reports-view.php'=>"ui_shell_start('reports')",
          'public/diagnostics-view.php'=>"ui_shell_start('diagnostics',"] as $path=>$fragment) {
    $data=$contents($path);
    $requireFragments($data,[$fragment,'ui_shell_end()', 'ui_breadcrumb('],$path);
}
foreach (['public/reports.php','public/diagnostics.php'] as $path) {
    $requireFragments($contents($path),['application/json','admin_authenticated()'],$path.' JSON compatibility');
}
echo "Shared glass dashboard, pretty URL table, responsive nav, safe JSON API and admin controls: PASS\n";
,
    'RewriteRule ^(devices|sessions|messaging|api-applications)/?$',
    'RewriteRule ^api/diagnostics/?$','RewriteRule ^api/reports/?$',
    'THE_REQUEST','REQUEST_METHOD', 'RewriteRule ^_loader'
],'Cloudways pretty-route configuration');
if(!str_contains($htaccess,'RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$'))
    throw new RuntimeException('Pretty URL redirects may affect POST requests');
if(str_contains($htaccess,'RewriteRule ^api/.* index.php'))
    throw new RuntimeException('API paths incorrectly routed through HTML');
foreach (['public/storage-settings.php'=>"ui_shell_start('storage')",
          'public/gateway-settings.php'=>"ui_shell_start('gateway')",
          'public/qr.php'=>"ui_shell_start('qr',",
          'public/reports-view.php'=>"ui_shell_start('reports')",
          'public/diagnostics-view.php'=>"ui_shell_start('diagnostics',"] as $path=>$fragment) {
    $data=$contents($path);
    $requireFragments($data,[$fragment,'ui_shell_end()', 'ui_breadcrumb('],$path);
}
foreach (['public/reports.php','public/diagnostics.php'] as $path) {
    $requireFragments($contents($path),['application/json','admin_authenticated()'],$path.' JSON compatibility');
}
echo "Shared glass dashboard, pretty URL table, responsive nav, safe JSON API and admin controls: PASS\n";
