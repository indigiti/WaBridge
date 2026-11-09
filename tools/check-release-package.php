<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$path=$root.'/release';
$required=[
 'public/index.php','public/_loader.php','public/.htaccess',
 'public/assets/wabridge-ui-v6.css',
 'public/assets/wabridge-console-v3.js',
 'public/assets/fonts/inter-latin-400.woff2',
 'public/assets/fonts/inter-latin-600.woff2',
 'public/assets/fonts/inter-latin-700.woff2',
 'public/assets/fonts/OFL.txt','public/assets/fonts/SOURCE.txt',
 'public/assets/vendor/qrcode.min.js','public/assets/qr-init.js',
 'public/api/v2/fixture-event.php','public/account-admin.php','public/messages.php','public/platform.php','public/webhook-events.php','public/performance.php','public/verification.php',
 'public/qr.php','public/diagnostics-view.php','public/reports-view.php',
 'public/diagnostics.php','public/reports.php',
 'public/gateway-settings.php','public/storage-settings.php',
 'public/api/send.php','public/api/v1/messages.php',
 'public/api/v1/capabilities.php','public/api/v1/batches.php',
 'private/src/store.php','private/src/bootstrap.php','private/src/json-store.php',
 'private/src/vnext/json-store.php','private/src/vnext/identity.php','private/src/vnext/events.php',
 'private/src/vnext/channels.php','private/src/vnext/signed-ingress.php','private/src/vnext/recovery.php',
 'private/src/vnext/outbox.php','private/src/vnext/demo.php',
 'private/src/platform/state.php','private/src/platform/service.php','private/src/platform/modules.php',
 'private/src/platform/webhook-read.php',
 'private/src/ui-shell.php','private/src/message-log.php','private/src/message-processor.php',
 'private/src/runtime-metrics.php',
 'private/src/worker-health.php',
 'private/src/gateway-provider.php','private/src/gateway-settings.php',
 'private/src/storage-services.php','private/src/storage-migration.php',
 'private/src/waxum-provisioning.php',
 'private/cli/vnext-recovery.php','private/cli/worker.php','private/cli/webhooks.php','private/cli/waxum-supervisor.sh',
 'private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz',
 'private/build/release.json',
];
foreach($required as $name) {
    if(!is_file($path.'/'.$name))
        throw new RuntimeException('MISSING RELEASE FILE: '.$name);
}
foreach(['config.php','runtime/wabridge.sqlite','.env',
         'private/runtime/vnext/system/identity.json',
         'private/runtime/vnext/tenants',
         'private/config.php','private/runtime/wabridge.sqlite',
         'private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz'] as $forbidden) {
    if(file_exists($path.'/'.$forbidden))
        throw new RuntimeException('FORBIDDEN RELEASE FILE: '.$forbidden);
}
$manifest=json_decode((string)file_get_contents($path.'/private/build/release.json'),true,16,JSON_THROW_ON_ERROR);
if(($manifest['schema']??'')!=='WABRIDGE-BUILD/1'||($manifest['name']??'')!=='WaBridge')
    throw new RuntimeException('Invalid DigiOps release manifest');
$index=(string)file_get_contents($path.'/public/index.php');
if(!str_contains($index,'wabridge-ui-v6.css'))
    throw new RuntimeException('Canonical design v6 link is missing');
$bundle=(string)file_get_contents($path.'/public/assets/wabridge-ui-v6.css');
foreach(['@font-face','.console-hero','.console-overview-grid','.activity-feed',
         '.glass-panel','.console-sidebar','--glass:rgba(',
         '@media(max-width:760px)','prefers-reduced-transparency'] as $design) {
    if(!str_contains($bundle,$design))
        throw new RuntimeException('Incomplete design v6: '.$design);
}
if(!str_contains($bundle,'.platform-form-grid'))
    throw new RuntimeException('Product module styles absent from unified premium bundle');
if(!str_contains($bundle,'.platform-context-link'))
    throw new RuntimeException('Compact product navigation styles absent');
if(strlen($bundle)<15000)
    throw new RuntimeException('Incomplete unified premium design stylesheet');
foreach(['wabridge-premium-v2.css','wabridge-console-v3.css','wabridge-glass-v4.css',
         'wabridge-typography-v5.css','wabridge-design-v6.css','wabridge-ui-v5.css'] as $legacy) {
    if(is_file($path.'/public/assets/'.$legacy))
        throw new RuntimeException('Legacy CSS or source stylesheet was published: '.$legacy);
}
if(!str_contains((string)file_get_contents($path.'/public/assets/fonts/OFL.txt'),'SIL OPEN FONT LICENSE'))
    throw new RuntimeException('Inter font license missing');
foreach([400,600,700] as $weight) {
    $font=$path.'/public/assets/fonts/inter-latin-'.$weight.'.woff2';
    $fh=fopen($font,'rb');
    if($fh===false)throw new RuntimeException('Font unreadable');
    try {$magic=fread($fh,4);} finally {fclose($fh);}
    if($magic!=='wOF2')throw new RuntimeException('Invalid self-hosted font');
}
$layout=(string)file_get_contents($path.'/private/src/ui-shell.php');
foreach(['function ui_shell_start','function ui_shell_end','/wabridge/performance','/wabridge/verification'] as $fragment)
    if(!str_contains($layout,$fragment))throw new RuntimeException('Shared shell missing '.$fragment);
$routes=(string)file_get_contents($path.'/public/.htaccess');
foreach(['^messages/?$','^account-admin/?$','^gateway/?$','^storage/?$','^qr/?$',
         '^diagnostics/?$','^reports/?$','^performance/?$','^verification/?$',
         '^api/diagnostics/?$','^api/reports/?$'] as $route)
    if(!str_contains($routes,$route))throw new RuntimeException('Pretty URL missing '.$route);
$compat=$path.'/private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
if(!hash_equals('f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e',
                (string)hash_file('sha256',$compat)))
    throw new RuntimeException('Pinned Cloudways-compatible Rust checksum mismatch');
if(file_exists($path.'/public/waxum'))
    throw new RuntimeException('Rust executable must not be deployed to public HTML');
echo "WaBridge DigiOps release validated: one Rust package, one CSS, local Inter, performance and launch checks\n";
