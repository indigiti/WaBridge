<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$path=$root.'/release';
$required=[
    'public/index.php','public/_loader.php','public/.htaccess',
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
echo "WaBridge DigiOps release validated\n";
