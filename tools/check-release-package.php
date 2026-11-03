<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$path=$root.'/release';
$required=[
    'public/index.php','public/_loader.php','public/.htaccess',
    'public/assets/wabridge-premium-v2.css',
    'public/assets/wabridge-console-v3.css','public/assets/wabridge-console-v3.js',
    'public/assets/wabridge-glass-v4.css','private/src/ui-shell.php',
    'public/diagnostics-view.php','public/reports-view.php',
    'public/messages.php','private/src/message-log.php','private/src/message-processor.php',
    'public/assets/vendor/qrcode.min.js','public/assets/qr-init.js',
    'public/qr.php','public/diagnostics.php','public/reports.php',
    'public/api/v1/capabilities.php','public/api/v1/batches.php',
    'private/src/gateway-provider.php',
    'private/src/gateway-settings.php','public/gateway-settings.php',
    'private/src/json-store.php','private/src/storage-services.php',
    'private/src/storage-migration.php','public/storage-settings.php',
    'private/src/waxum-provisioning.php',
    'private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz',
    'private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz',
    'public/qr.php','public/api/send.php','public/api/v1/messages.php',
    'private/src/bootstrap.php','private/src/store.php',
    'private/cli/worker.php','private/cli/webhooks.php','private/cli/waxum-supervisor.sh',
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
$routes=(string)file_get_contents($path.'/public/.htaccess');
foreach (['^messages/?
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two')) {
    fwrite(STDERR,"Premium stylesheet incomplete\n");
    exit(1);
}
$waxumPath=$path.'/private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
if (!hash_equals('2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61',(string)hash_file('sha256',$waxumPath))) {
    fwrite(STDERR,"Pinned private Rust binary checksum mismatch\n");
    exit(1);
}
$compatPath=$path.'/private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
if (!hash_equals('f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e',(string)hash_file('sha256',$compatPath))) {
    fwrite(STDERR,"Cloudways-compatible Rust binary checksum mismatch\n");
    exit(1);
}
if (file_exists($path.'/public/waxum')) {
    fwrite(STDERR,"Rust binary must not be distributed through public HTML\n");
    exit(1);
}
echo "WaBridge DigiOps release validated\n";
,'^gateway/?
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two')) {
    fwrite(STDERR,"Premium stylesheet incomplete\n");
    exit(1);
}
$waxumPath=$path.'/private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
if (!hash_equals('2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61',(string)hash_file('sha256',$waxumPath))) {
    fwrite(STDERR,"Pinned private Rust binary checksum mismatch\n");
    exit(1);
}
$compatPath=$path.'/private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
if (!hash_equals('f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e',(string)hash_file('sha256',$compatPath))) {
    fwrite(STDERR,"Cloudways-compatible Rust binary checksum mismatch\n");
    exit(1);
}
if (file_exists($path.'/public/waxum')) {
    fwrite(STDERR,"Rust binary must not be distributed through public HTML\n");
    exit(1);
}
echo "WaBridge DigiOps release validated\n";
,'^storage/?
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two')) {
    fwrite(STDERR,"Premium stylesheet incomplete\n");
    exit(1);
}
$waxumPath=$path.'/private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
if (!hash_equals('2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61',(string)hash_file('sha256',$waxumPath))) {
    fwrite(STDERR,"Pinned private Rust binary checksum mismatch\n");
    exit(1);
}
$compatPath=$path.'/private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
if (!hash_equals('f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e',(string)hash_file('sha256',$compatPath))) {
    fwrite(STDERR,"Cloudways-compatible Rust binary checksum mismatch\n");
    exit(1);
}
if (file_exists($path.'/public/waxum')) {
    fwrite(STDERR,"Rust binary must not be distributed through public HTML\n");
    exit(1);
}
echo "WaBridge DigiOps release validated\n";
,'^qr/?
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two')) {
    fwrite(STDERR,"Premium stylesheet incomplete\n");
    exit(1);
}
$waxumPath=$path.'/private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
if (!hash_equals('2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61',(string)hash_file('sha256',$waxumPath))) {
    fwrite(STDERR,"Pinned private Rust binary checksum mismatch\n");
    exit(1);
}
$compatPath=$path.'/private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
if (!hash_equals('f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e',(string)hash_file('sha256',$compatPath))) {
    fwrite(STDERR,"Cloudways-compatible Rust binary checksum mismatch\n");
    exit(1);
}
if (file_exists($path.'/public/waxum')) {
    fwrite(STDERR,"Rust binary must not be distributed through public HTML\n");
    exit(1);
}
echo "WaBridge DigiOps release validated\n";
,'^diagnostics/?
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two')) {
    fwrite(STDERR,"Premium stylesheet incomplete\n");
    exit(1);
}
$waxumPath=$path.'/private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
if (!hash_equals('2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61',(string)hash_file('sha256',$waxumPath))) {
    fwrite(STDERR,"Pinned private Rust binary checksum mismatch\n");
    exit(1);
}
$compatPath=$path.'/private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
if (!hash_equals('f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e',(string)hash_file('sha256',$compatPath))) {
    fwrite(STDERR,"Cloudways-compatible Rust binary checksum mismatch\n");
    exit(1);
}
if (file_exists($path.'/public/waxum')) {
    fwrite(STDERR,"Rust binary must not be distributed through public HTML\n");
    exit(1);
}
echo "WaBridge DigiOps release validated\n";
,'^reports/?
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two')) {
    fwrite(STDERR,"Premium stylesheet incomplete\n");
    exit(1);
}
$waxumPath=$path.'/private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
if (!hash_equals('2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61',(string)hash_file('sha256',$waxumPath))) {
    fwrite(STDERR,"Pinned private Rust binary checksum mismatch\n");
    exit(1);
}
$compatPath=$path.'/private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
if (!hash_equals('f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e',(string)hash_file('sha256',$compatPath))) {
    fwrite(STDERR,"Cloudways-compatible Rust binary checksum mismatch\n");
    exit(1);
}
if (file_exists($path.'/public/waxum')) {
    fwrite(STDERR,"Rust binary must not be distributed through public HTML\n");
    exit(1);
}
echo "WaBridge DigiOps release validated\n";
,'^api/diagnostics/?
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two')) {
    fwrite(STDERR,"Premium stylesheet incomplete\n");
    exit(1);
}
$waxumPath=$path.'/private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
if (!hash_equals('2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61',(string)hash_file('sha256',$waxumPath))) {
    fwrite(STDERR,"Pinned private Rust binary checksum mismatch\n");
    exit(1);
}
$compatPath=$path.'/private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
if (!hash_equals('f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e',(string)hash_file('sha256',$compatPath))) {
    fwrite(STDERR,"Cloudways-compatible Rust binary checksum mismatch\n");
    exit(1);
}
if (file_exists($path.'/public/waxum')) {
    fwrite(STDERR,"Rust binary must not be distributed through public HTML\n");
    exit(1);
}
echo "WaBridge DigiOps release validated\n";
,'^api/reports/?
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two')) {
    fwrite(STDERR,"Premium stylesheet incomplete\n");
    exit(1);
}
$waxumPath=$path.'/private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
if (!hash_equals('2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61',(string)hash_file('sha256',$waxumPath))) {
    fwrite(STDERR,"Pinned private Rust binary checksum mismatch\n");
    exit(1);
}
$compatPath=$path.'/private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
if (!hash_equals('f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e',(string)hash_file('sha256',$compatPath))) {
    fwrite(STDERR,"Cloudways-compatible Rust binary checksum mismatch\n");
    exit(1);
}
if (file_exists($path.'/public/waxum')) {
    fwrite(STDERR,"Rust binary must not be distributed through public HTML\n");
    exit(1);
}
echo "WaBridge DigiOps release validated\n";
] as $pretty) {
    if (!str_contains($routes,$pretty)) {
        fwrite(STDERR,"Required pretty URL missing: ".$pretty."\n");
        exit(1);
    }
}
$glass=(string)file_get_contents($path.'/public/assets/wabridge-glass-v4.css');
if (!str_contains($glass,'backdrop-filter:blur') || !str_contains($glass,'.console-breadcrumbs')) {
    fwrite(STDERR,"Premium shared glass theme is missing\n");
    exit(1);
}
$layout=(string)file_get_contents($path.'/private/src/ui-shell.php');
if (!str_contains($layout,'function ui_shell_start') || !str_contains($layout,'function ui_shell_end')) {
    fwrite(STDERR,"Shared admin shell missing\n");
    exit(1);
}
$css=file_get_contents($path.'/public/assets/wabridge-premium-v2.css');
if (strlen($css)<5000 || !str_contains($css,'.auth-card') || !str_contains($css,'.workspace-two')) {
    fwrite(STDERR,"Premium stylesheet incomplete\n");
    exit(1);
}
$waxumPath=$path.'/private/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
if (!hash_equals('2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61',(string)hash_file('sha256',$waxumPath))) {
    fwrite(STDERR,"Pinned private Rust binary checksum mismatch\n");
    exit(1);
}
$compatPath=$path.'/private/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
if (!hash_equals('f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e',(string)hash_file('sha256',$compatPath))) {
    fwrite(STDERR,"Cloudways-compatible Rust binary checksum mismatch\n");
    exit(1);
}
if (file_exists($path.'/public/waxum')) {
    fwrite(STDERR,"Rust binary must not be distributed through public HTML\n");
    exit(1);
}
echo "WaBridge DigiOps release validated\n";
