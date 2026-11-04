<?php
declare(strict_types=1);
// Isolated, provider-free message log and one-shot worker regression.
$root=dirname(__DIR__);$cfg=$root.'/config.php';$runtime=$root.'/runtime/json';
if (is_file($cfg)||is_dir($runtime)) throw new RuntimeException('Refusing to alter real user data');
file_put_contents($cfg,'<?php return '.var_export([
  'storage_driver'=>'json','gateway_url'=>'http://127.0.0.1:3451',
  'gateway_token'=>'mock-token','admin_user'=>'tests',
  'admin_password_hash'=>'mock-hash','allowed_sessions'=>['test-01']
],true).';');
register_shutdown_function(static function()use($cfg,$runtime){
    @unlink($cfg);
    @unlink($runtime.'/state.json');@unlink($runtime.'/state.lock');
    @rmdir($runtime);
});
require $root.'/src/store.php';
require $root.'/src/message-log.php';
require $root.'/src/message-processor.php';
function ensure(bool $cond,string $message):void{if(!$cond)throw new RuntimeException($message);}
$db=store();
$client=['id'=>'admin','sessions'=>['test-01'],'limit'=>20];
$queued=enqueue_message($db,$client,'test-01','919876543210','Private sensitive message','message-log-test-01');
ensure(message_log_effective_status(['state'=>'queued'])==='queued','queued state');
ensure(message_log_mask_recipient('919876543210')==='••••3210','recipient mask');
$before=storage_admin_messages(20);
ensure(count($before)===1 && $before[0]['state']==='queued','queue projection');
ensure(!isset($before[0]['body'])&&!isset($before[0]['idem'])&&!isset($before[0]['engine_response']),'secret-free projection');
$sent=process_next_message($db,static fn($method,$path,$payload) => [
 'http'=>200,'data'=>['message_id'=>'provider-message-test']
]);
ensure($sent!==null&&$sent['id']===$queued['id']&&$sent['state']==='accepted','accepted via mock provider');
ensure(process_next_message($db,static fn()=>[])===null,'no duplicate sending');
$after=storage_admin_messages(20);
ensure($after[0]['receipt']['provider_id']==='provider-message-test','provider association');
ensure(message_log_effective_status($after[0])==='accepted','HTTP 200 must not imply delivery');
ensure(update_receipt_from_event($db,'event-test-001',['message_id'=>'unmatched','status'=>'delivered'])===false,'unknown provider ID not matched');
ensure(update_receipt_from_event($db,'event-test-002',['message_id'=>'provider-message-test','status'=>'delivered'])===true,'delivery must match provider ID');
ensure(message_log_effective_status(storage_admin_messages(20)[0])==='delivered','verified delivery');
$failed=enqueue_message($db,$client,'test-01','919876543210','Other message','message-log-test-02');
$r=process_next_message($db,static fn()=>['http'=>503,'data'=>['error'=>'upstream']]);
ensure($r['state']==='needs_review' && $r['id']===$failed['id'],'gateway error needs review');
$recent=storage_admin_messages(20);
ensure(count($recent)===2,'history length');
ensure(!isset($recent[0]['body']),'no plaintext in history');
$failureRow=null;
foreach ($recent as $entry) if ($entry['id']===$failed['id']) $failureRow=$entry;
ensure($failureRow!==null && message_log_effective_status($failureRow)==='needs_review','uncertain outcome');

$offline=enqueue_message($db,$client,'test-01','919876543210','Queued while offline','message-log-test-offline');
$target=enqueue_message($db,$client,'test-01','919876543210','Targeted browser send','message-log-test-targeted');
$probed=[];
$blocked=process_next_message($db,
    static function()use(&$probed):array{$probed[]='sent';return ['http'=>200,'data'=>['message_id'=>'must-not-send']];},
    $offline['id'],
    static fn(string $sid):array => ['http'=>200,'is_logged_in'=>true,'socket_alive'=>false]
);
ensure($blocked!==null&&$blocked['state']==='blocked','offline session must block before claim');
ensure($probed===[],'offline session must never invoke sender');
$queuedStill=storage_queued_job($db,$offline['id']);
ensure($queuedStill!==null&&$queuedStill['session_id']==='test-01','offline job must remain queued');
$sentTarget=process_next_message($db,
    static fn()=>['http'=>200,'data'=>['status'=>'sent','message_id'=>'provider-target-001']],
    $target['id'],
    static fn():array=>['http'=>200,'is_logged_in'=>true,'socket_alive'=>true]
);
ensure($sentTarget!==null&&$sentTarget['id']===$target['id']&&$sentTarget['state']==='accepted',
   'browser operation must dispatch exactly its own job');
ensure(storage_queued_job($db,$offline['id'])!==null,'targeted send must not claim older offline job');
$persisted=storage_admin_messages(20);
$targetRow=null;
foreach($persisted as $row) if($row['id']===$target['id'])$targetRow=$row;
ensure($targetRow!==null&&$targetRow['receipt']['provider_id']==='provider-target-001',
  'selected send provider ID not persisted');
$unexpected=enqueue_message($db,$client,'test-01','919876543210','Untraceable upstream send','message-log-test-scheduled');
$result=process_next_message($db,static fn()=>[
    'http'=>200,'data'=>['status'=>'pending','schedule_id'=>'future-job']
],$unexpected['id']);
ensure($result['state']==='needs_review'&&$result['reason']==='unexpected_scheduled_response',
  'unexpected deferred response must remain uncertain');
$storedUncertain=null;
foreach(storage_admin_messages(20) as $row)if($row['id']===$unexpected['id'])$storedUncertain=$row;
ensure($storedUncertain!==null &&
       message_log_reason_code($storedUncertain)==='unexpected_scheduled_response',
       'reason code must be retained by JSON backend');
ensure(str_contains(message_log_reason_text('gateway_http_503'),'Gateway'),
  'rejected send must have actionable explanation');
ensure(message_log_reason_code(['reason_code'=>'Bearer SECRET abc'])==='',
  'untrusted raw gateway error must never leak through admin UI');
ensure(message_log_effective_status($storedUncertain)==='needs_review',
  'unknown gateway send must not appear delivered');

echo "Message activity status, masking, mock send, provider receipt correlation and no ambiguous retries: PASS\n";
