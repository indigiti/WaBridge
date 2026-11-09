<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/vnext/recovery.php';
require dirname(__DIR__).'/src/vnext/demo.php';
function projectionAssert(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('W1 projection failed: '.$message);
}
function projectionReject(callable $f,string $reason):void {
    try{$f();}catch(InvalidArgumentException|RuntimeException $e){return;}
    throw new RuntimeException('Expected refusal: '.$reason);
}
function projectionWipe(string $p):void{
    if(is_dir($p)&&!is_link($p)){
        foreach(new DirectoryIterator($p) as $item)if(!$item->isDot())projectionWipe($item->getPathname());
        rmdir($p);
    }elseif(is_file($p)||is_link($p))unlink($p);
}
$dir=sys_get_temp_dir().'/wabridge-projector-'.bin2hex(random_bytes(12));
if(!mkdir($dir,0700))throw new RuntimeException('Temp root unavailable');
register_shutdown_function(static function()use($dir):void{projectionWipe($dir);});
$store=new VNextJsonStore($dir);
VNextDemo::seedPilot($store);
$a=VNextDemo::accounts()[0]['id'];
$b=VNextDemo::accounts()[1]['id'];
$channel='bb660000-0000-4000-8000-000000000001';
$journal=new VNextEventJournal($store);
$projector=new VNextEventProjector($store);
$event=VNextEventJournal::normalize($a,[
    'type'=>'message.inbound','provider'=>'fixture','channel_id'=>$channel,
    'provider_event_id'=>'fixture-projection-00001','provider_message_id'=>'fictional-001',
    'occurred_at'=>time(),
]);
projectionAssert($journal->recordVerified($event),'Record synthetic event');
$now=time();
$claim=$journal->claim($a,$now,30);
projectionAssert($claim!==null,'First worker claims event');
projectionAssert($projector->project($a,$claim['event_key'],$claim['event']),
    'First checkpoint committed');
projectionAssert($projector->count($a)===1,'Exactly one metadata projection');
projectionAssert(!$projector->project($a,$claim['event_key'],$claim['event']),
    'Replay after crash does not duplicate projection');
projectionReject(fn()=>$projector->project($b,$claim['event_key'],$claim['event']),
    'Cross-tenant event rejected');
projectionReject(fn()=>$projector->project($a,str_repeat('0',64),$event),
    'False event digest rejected');
projectionAssert($journal->claim($a,$now,30)===null,'Original lease exclusive');
$recovered=$journal->claim($a,$now+31,30);
projectionAssert($recovered!==null&&$recovered['lease_token']!==$claim['lease_token'],
    'Crash lease reclaimed safely');
projectionAssert(!$journal->acknowledge($a,$claim['event_key'],$claim['lease_token']),
    'Old lease acknowledgement fenced');
projectionAssert(!$projector->project($a,$recovered['event_key'],$recovered['event']),
    'Replayed projection remains idempotent');
projectionAssert($journal->acknowledge($a,$recovered['event_key'],$recovered['lease_token']),
    'Reclaimed event acknowledged');
projectionAssert($journal->states($a)['normalized']===1,'Event completed once');
projectionAssert((new VNextRecovery($journal,$projector))->drain($a,20,$now+35)['normalized']===0,
    'No repeated completed work');
$event2=$event;
$event2['provider_event_id']='fixture-projection-00002';
projectionAssert($journal->recordVerified($event2),'Second event');
$out=(new VNextRecovery($journal,$projector))->drain($a,20,$now+35);
projectionAssert($out['normalized']===1&&$projector->count($a)===2,
    'Recovery replays pending event and acknowledges after checkpoint');
$state=(string)file_get_contents($dir.'/tenants/'.$a.'/projections.json');
projectionAssert(!str_contains($state,'text')&&!str_contains($state,'customer_body'),
    'Projection remains content-free');
projectionAssert((fileperms($dir.'/tenants/'.$a.'/projections.json')&0777)===0600,
    'Private metadata file permissions');
echo "W1 cross-file recovery: crash-after-projection idempotency, leases and tenant isolation PASS\n";
