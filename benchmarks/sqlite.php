<?php
declare(strict_types=1);
// Synthetic local SQLite benchmark; NOT a WhatsApp delivery benchmark.
if (PHP_SAPI!=='cli') exit(2);
$root=dirname(__DIR__);
$configPath=$root.'/config.php';
if (file_exists($configPath)) { fwrite(STDERR,"Do not benchmark against a configured deployment.\n"); exit(2); }
file_put_contents($configPath,"<?php return ['gateway_url'=>'http://127.0.0.1:3451','gateway_token'=>'test','admin_password_hash'=>'test','allowed_sessions'=>['bench-device'],'max_message_chars'=>2000,'storage_driver'=>'sqlite'];");
$files=[$root.'/runtime/wabridge.sqlite',$root.'/runtime/wabridge.sqlite-wal',$root.'/runtime/wabridge.sqlite-shm'];
register_shutdown_function(static function()use($configPath,$files) {
    @unlink($configPath);
    foreach ($files as $p) @unlink($p);
});
require $root.'/src/store.php';
$pdo=store();
$client=['id'=>'benchmark','sessions'=>['bench-device'],'limit'=>1000];
$iterations=max(1,min(500,(int)($argv[1]??200)));
$times=[];$total=hrtime(true);
for ($i=0;$i<$iterations;$i++) {
    $begin=hrtime(true);
    enqueue_message($pdo,$client,'bench-device','919876543210','Synthetic benchmark notification','benchmark-'.str_pad((string)$i,10,'0',STR_PAD_LEFT));
    $times[]=(hrtime(true)-$begin)/1e6;
}
sort($times);
$elapsed=(hrtime(true)-$total)/1e9;
echo json_encode([
    'description'=>'SQLite enqueue only, no network or WhatsApp',
    'operations'=>$iterations,
    'seconds'=>round($elapsed,4),
    'ops_per_second'=>round($iterations/max(0.000001,$elapsed),2),
    'enqueue_p50_ms'=>round($times[(int)floor(($iterations-1)*.50)],3),
    'enqueue_p95_ms'=>round($times[(int)floor(($iterations-1)*.95)],3),
    'peak_php_bytes'=>memory_get_peak_usage(true)
],JSON_PRETTY_PRINT).PHP_EOL;
