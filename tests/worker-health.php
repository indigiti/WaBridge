<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/worker-health.php';
$path=worker_health_path();
if(file_exists($path)||is_link($path))
    throw new RuntimeException('Refusing to overwrite a real worker heartbeat');
$directory=dirname($path);
$created=false;
if(!is_dir($directory)){
    if(!mkdir($directory,0700,true))throw new RuntimeException('Could not prepare private fixture directory');
    $created=true;
}
register_shutdown_function(static function()use($path,$directory,$created):void{
    @unlink($path);
    if($created)@rmdir($directory);
});
worker_health_write([
    'processed'=>3,'accepted'=>2,'needs_review'=>1,
    'session_blocked'=>true,'session_id'=>'must-not-be-written',
    'message_body'=>'private-must-not-appear'
]);
$raw=file_get_contents($path);
if(!is_string($raw)||str_contains($raw,'must-not')||strlen($raw)>500)
    throw new RuntimeException('Worker heartbeat may disclose session or message content');
if((fileperms($path)&0077)!==0)throw new RuntimeException('Worker heartbeat permissions too broad');
$report=worker_health_read();
if($report['state']!=='recent'||$report['processed']!==3||
   $report['accepted']!==2||$report['session_blocked']!==true)
    throw new RuntimeException('Worker heartbeat must preserve bounded, harmless counters');
$old=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
$old['last_run_at']=time()-900;
file_put_contents($path,json_encode($old,JSON_THROW_ON_ERROR));
if(worker_health_read()['state']!=='stale')
    throw new RuntimeException('Old worker heartbeat must not be marked recent');
echo "Private automatic queue worker heartbeat, expiry, masking and permissions: PASS\n";
