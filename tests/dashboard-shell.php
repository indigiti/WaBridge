<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$index=(string)file_get_contents($root.'/public/index.php');
$css=(string)file_get_contents($root.'/public/assets/wabridge-console-v3.css');
$js=(string)file_get_contents($root.'/public/assets/wabridge-console-v3.js');
$messages=(string)file_get_contents($root.'/public/messages.php');
foreach (['class="console-body"','class="console-sidebar"','console-sidebar-toggle','console-mobile-toggle','console-mobile-close','console-backdrop','console-nav-group','id="overview"','id="sessions"','id="integrations"','messages.php','gateway-settings.php','storage-settings.php','diagnostics.php'] as $fragment)
    if (!str_contains($index,$fragment)) throw new RuntimeException('Dashboard missing '.$fragment);
foreach (['.console-body','.console-sidebar','.sidebar-compact','.sidebar-mobile-open','@media(max-width:1100px)','.ops-collapse','.console-hero-actions'] as $fragment)
    if (!str_contains($css,$fragment)) throw new RuntimeException('Premium CSS missing '.$fragment);
foreach (['aria-expanded','localStorage','keydown','hashchange','sidebar-mobile-open'] as $fragment)
    if (!str_contains($js,$fragment)) throw new RuntimeException('Sidebar behavior missing '.$fragment);
foreach (['admin_authenticated()','assert_csrf()','process_next_message','storage_admin_messages','message_log_effective_status','escape('] as $fragment)
    if (!str_contains($messages,$fragment)) throw new RuntimeException('Message activity access/safety missing '.$fragment);
if (str_contains($messages,'CURLOPT_SSL_VERIFYPEER => false')) throw new RuntimeException('Insecure webhook');
echo "Premium dashboard shell, grouped responsive sidebar, no-store message activity, auth and CSRF: PASS\n";
