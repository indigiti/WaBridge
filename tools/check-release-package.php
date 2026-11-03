<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$path=$root.'/release';
$required=[
    'public/index.php','public/_loader.php','public/.htaccess',
    'public/assets/wabridge-premium-v2.css',
    'public/assets/vendor/qrcode.min.js','public/assets/qr-init.js',
    'public/qr.php','public/diagnostics.php','public/reports.php',
    'public/api/v1/capabilities.php','public/api/v1/batches.php',
    'private/src/gateway-provider.php',
    'private/src/gateway-settings.php','public/gateway-settings.php',
    'private/src/json-store.php','private/src/storage-services.php',
    'private/src/storage-migration.php','public/storage-settings.php',
    'public/qr.php','public/api/send.php','public/api/v1/messages.php',
    'private/src/bootstrap.php','private/src/store.php',
    'private/cli/worker.php','private/cli/webhooks.php',
    'private/build/release.json',
];
foreach($required as $name){
    if (!is_file($path.'/'.$name)){
        fwrite(STDERR,'MISSING RELEASE FILE: '.$name."\n");
        exit(1);
    }
}
foreach(['config.php','runtime/wabridge.sqlite','.env','private/config.php','private/runtime/wabridge.sqlite'] as $forbidden){
    if (file_exists($path.'/'.$forbidden)){
        fwrite(STDERR,'SENSITIVE RELEASE FILE: '.$forbidden."\n");
        exit(1);
    }
}
$manifest=json_decode(file_get_contents($path.'/private/build/release.json'),true,16,JSON_THROW_ON_ERROR);
if(($manifest['schema']??'')!=='WABRIDGE-BUILD/1' || ($manifest['name']??'')!=='WaBridge') exit(1);
$index=file_get_contents($path.'/public/index.php');
if (!str_contains($index,'/wabridge/assets/wabridge-premium-v2.css')) {
    fwrite(STDERR,"Premium stylesheet reference missing in HTML\n");
    exit(1);
}
$css=file_get_contents($path.'/public/assets/wabridge-premium-v2.css');
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two')) {
    fwrite(STDERR,"Premium stylesheet incomplete\n");
    exit(1);
}
echo "WaBridge DigiOps release validated\n";
