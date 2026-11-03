<?php
declare(strict_types=1);
// Render the shared layout without a live WaBridge installation or DB.
function admin_authenticated():bool{return true;}
function csrf():string{return 'test-csrf-token';}
function escape(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
require dirname(__DIR__).'/src/ui-shell.php';
$pages=['overview','devices','sessions','messaging','messages','reports','diagnostics','api-applications','gateway','storage','qr'];
foreach($pages as $page) {
    ob_start();
    ui_shell_start($page,true);
    echo '<main>Fixture</main>';
    ui_shell_end();
    $html=(string)ob_get_clean();
    foreach(['class="console-body"','id="console-sidebar"','id="console-mobile-toggle"',
             'aria-label="Operations menu"','/wabridge/messages','/wabridge/reports',
             '/wabridge/gateway','method="post" action="/wabridge/sign-out"',
             'name="_csrf"','class="site-footer"'] as $check)
        if(!str_contains($html,$check)) throw new RuntimeException($page.' missing '.$check);
    if(substr_count($html,'class="console-sidebar"')!==1)
        throw new RuntimeException('Duplicate navigation in '.$page);
    if($page!=='qr' && !str_contains($html,'aria-current="page"'))
        throw new RuntimeException('No active navigation for '.$page);
}
ob_start();ui_breadcrumb('Workspace','Diagnostics');$crumb=(string)ob_get_clean();
if(!str_contains($crumb,'aria-label="Breadcrumb"') || !str_contains($crumb,'Diagnostics'))
    throw new RuntimeException('Breadcrumb did not render');
ob_start();ui_head_assets();$assets=(string)ob_get_clean();
if(!str_contains($assets,'wabridge-glass-v4.css') || !str_contains($assets,'wabridge-console-v3.js'))
    throw new RuntimeException('Shared console assets are missing');
echo "Shared glass shell rendered across 11 routes with CSRF logout, navigation, breadcrumbs and mobile controls: PASS\n";
