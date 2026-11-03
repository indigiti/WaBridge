<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$path=$root.'/release';
$required=[
    'public/index.php','public/_loader.php','public/.htaccess',
    'public/assets/wabridge-premium-v2.css','public/assets/wabridge-console-v3.css',
    'public/assets/wabridge-console-v3.js','public/assets/wabridge-glass-v4.css',
    'public/messages.php','public/diagnostics-view.php','public/reports-view.php',
    'public/qr.php','public/diagnostics.php','public/reports.php',
    'public/gateway-settings.php','public/storage-settings.php',
    'public/assets/vendor/qrcode.min.js','public/assets/qr-init.js',
    'public/api/send.php','public/api/v1/messages.php',
    'public/api/v1/capabilities.php','public/api/v1/batches.php',
    'private/src/store.php','private/src/bootstrap.php','private/src/json-store.php',
    'private/src/ui-shell.php','private/src/message-log.php','private/src/message-processor.php',
    'private/src/gateway-provider.php','private/src/gateway-settings.php',
    'private/src/storage-services.php','private/src/storage-migration.php',
    'private/src/waxum-provisioning.php',
    'private/cli/worker.php','private/cli/webhooks.php','private/cli/waxum-supervisor.sh',
    'private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz',
    'private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz',
    'private/build/release.json'
];
foreach ($required as $name) {
    if (!is_file($path.'/'.$name)) {
        fwrite(STDERR,"MISSING RELEASE FILE: ".$name."\n");
        exit(1);
    }
}
foreach (['config.php','runtime/wabridge.sqlite','.env',
          'private/config.php','private/runtime/wabridge.sqlite'] as $name) {
    if (file_exists($path.'/'.$name)) {
        fwrite(STDERR,"SENSITIVE RELEASE FILE: ".$name."\n");
        exit(1);
    }
}
$manifest=json_decode((string)file_get_contents($path.'/private/build/release.json'),true,16,JSON_THROW_ON_ERROR);
if (($manifest['schema']??'')!=='WABRIDGE-BUILD/1'||($manifest['name']??'')!=='WaBridge')
    throw new RuntimeException('Invalid DigiOps release manifest');
$index=(string)file_get_contents($path.'/public/index.php');
if (!str_contains($index,'wabridge-premium-v2.css') || !str_contains($index,'wabridge-glass-v4.css'))
    throw new RuntimeException('Premium dashboard stylesheet missing');
$css=(string)file_get_contents($path.'/public/assets/wabridge-premium-v2.css');
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two'))
    throw new RuntimeException('Existing premium stylesheet incomplete');
$glass=(string)file_get_contents($path.'/public/assets/wabridge-glass-v4.css');
if (!str_contains($glass,'backdrop-filter:blur') || !str_contains($glass,'.console-breadcrumbs'))
    throw new RuntimeException('Glass v4 design system invalid');
$layout=(string)file_get_contents($path.'/private/src/ui-shell.php');
if (!str_contains($layout,'function ui_shell_start') || !str_contains($layout,'function ui_shell_end'))
    throw new RuntimeException('Shared admin shell missing');
$routes=(string)file_get_contents($path.'/public/.htaccess');
foreach (['^messages/?$','^gateway/?$','^storage/?$','^qr/?$',
          '^diagnostics/?$','^reports/?$','^api/diagnostics/?$','^api/reports/?$'] as $route) {
    if (!str_contains($routes,$route)) throw new RuntimeException('Pretty URL missing: '.$route);
}
$fixedDigests=[
    'private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz'=>
      '2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61',
    'private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz'=>
      'f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e'
];
foreach($fixedDigests as $file=>$digest) {
    if (!hash_equals($digest,(string)hash_file('sha256',$path.'/'.$file)))
        throw new RuntimeException('Pinned private Waxum archive checksum mismatch: '.$file);
}
if(file_exists($path.'/public/waxum'))
    throw new RuntimeException('Rust binary must never be deployed to public HTML');
echo "WaBridge DigiOps release validated\n";
