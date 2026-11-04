<?php
declare(strict_types=1);

/**
 * Private worker heartbeat, not a delivery receipt.
 * No phone number, session identifier, API token, message body, job ID,
 * or upstream response is ever written to this file.
 */
function worker_health_path():string {
    return dirname(__DIR__).'/runtime/worker-health.json';
}
function worker_health_write(array $counters):void {
    $path=worker_health_path();
    $dir=dirname($path);
    if(is_link($dir)||!is_dir($dir))
        throw new RuntimeException('Private worker runtime directory unavailable');
    if(is_link($path))
        throw new RuntimeException('Worker heartbeat symlink prohibited');
    $report=[
        'schema'=>1,
        'last_run_at'=>time(),
        'processed'=>max(0,min(100,(int)($counters['processed']??0))),
        'accepted'=>max(0,min(100,(int)($counters['accepted']??0))),
        'needs_review'=>max(0,min(100,(int)($counters['needs_review']??0))),
        'session_blocked'=>(bool)($counters['session_blocked']??false),
    ];
    $tmp=tempnam($dir,'.worker-heartbeat-');
    if($tmp===false)throw new RuntimeException('Private heartbeat write failed');
    try{
        @chmod($tmp,0600);
        $payload=json_encode($report,JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$payload,LOCK_EX)!==strlen($payload))
            throw new RuntimeException('Private heartbeat write incomplete');
        if(!rename($tmp,$path))throw new RuntimeException('Could not store heartbeat atomically');
        @chmod($path,0600);
    } finally {
        if(is_file($tmp))@unlink($tmp);
    }
}
function worker_health_read():array {
    $empty=['state'=>'not_detected','last_run_at'=>null,'processed'=>0,
        'accepted'=>0,'needs_review'=>0,'session_blocked'=>false];
    $path=worker_health_path();
    if(is_link($path)||!is_file($path))return $empty;
    $raw=@file_get_contents($path);
    if(!is_string($raw)||strlen($raw)>4096||$raw==='')return $empty;
    try{$payload=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}
    catch(Throwable $e){return $empty;}
    if(!is_array($payload)||($payload['schema']??null)!==1)return $empty;
    $last=(int)($payload['last_run_at']??0);
    if($last<=0||$last>time()+60)return $empty;
    $age=max(0,time()-$last);
    return [
       'state'=>$age<=150?'recent':'stale',
       'last_run_at'=>$last,
       'processed'=>max(0,(int)($payload['processed']??0)),
       'accepted'=>max(0,(int)($payload['accepted']??0)),
       'needs_review'=>max(0,(int)($payload['needs_review']??0)),
       'session_blocked'=>(bool)($payload['session_blocked']??false),
    ];
}
