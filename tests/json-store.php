<?php
declare(strict_types=1);
// Self-contained JSON backend regression. No SQLite extension required.
$root=dirname(__DIR__);
$cfg=$root.'/config.php';
$path=$root.'/runtime/json';
$mode=$root.'/runtime/storage-mode.json';
if (is_file($cfg)||is_dir($path)||is_file($mode)) {
    fwrite(STDERR,"Refusing to test over real private runtime\n");exit(2);
}
$conf=[
    'storage_driver'=>'json','gateway_url'=>'http://127.0.0.1:3451',
    'gateway_token'=>'unused-dev-test-gateway',
    'admin_user'=>'devtest','admin_password_hash'=>'dummy-hash',
    'allowed_sessions'=>['test-device'],'max_message_chars'=>2000,
    'api_clients'=>[]
];
file_put_contents($cfg,'<?php return '.var_export($conf,true).';');
register_shutdown_function(static function() use($cfg,$path) {
    @unlink($cfg);
    foreach (['state.json','state.lock'] as $name) @unlink($path.'/'.$name);
    @rmdir($path);
});
require $root.'/src/store.php';
function eq(mixed $a,mixed $b,string $message): void {
    if ($a!==$b) throw new RuntimeException($message.' got '.var_export($a,true));
}
eq(storage_mode(),'json','Mode');
$db=store();
if (!$db instanceof JsonStore) throw new RuntimeException('Expected JsonStore');
eq(storage_health(),true,'Empty JSON store health');
$client=['id'=>'schoolflow','sessions'=>['test-device'],'limit'=>2];
$token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
storage_client_create(['id'=>'schoolflow','token_sha256'=>hash('sha256',$token),
    'sessions_json'=>'["test-device"]','per_minute'=>2,'created_at'=>time(),
    'expires_at'=>time()+86400]);
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$token;
eq(client_auth()['id'],'schoolflow','API client lookup');
eq(limit_client($db,$client),true,'Rate 1');
eq(limit_client($db,$client),true,'Rate 2');
eq(limit_client($db,$client),false,'Rate blocked');
$first=enqueue_message($db,$client,'test-device','919876543210','Hello JSON','test-join-00000001');
$again=enqueue_message($db,$client,'test-device','919876543210','Hello JSON','test-join-00000001');
eq($again['duplicate'],true,'Idempotency');
eq($again['id'],$first['id'],'Same job');
try {
    enqueue_message($db,$client,'test-device','919876543210','Changed body','test-join-00000001');
    throw new RuntimeException('Conflicting idempotency not detected');
} catch (InvalidArgumentException $expected) {}
try {
    enqueue_message($db,$client,'other-device','919876543210','Hello JSON','test-join-00000002');
    throw new RuntimeException('Cross-session scope not detected');
} catch (InvalidArgumentException $expected) {}
$job=claim_job($db);eq($job['id'],$first['id'],'Claim');
eq(claim_job($db),null,'Double-claim prevention');
complete_job($db,$job['id'],'accepted',200,'{"message_id":"mid01"}');
record_provider_message($db,$job['id'],'provider-mid-001');
eq(update_receipt_from_event($db,'receipt-event-001',['message_id'=>'provider-mid-001','status'=>'delivered']),true,'Receipt match');
eq(storage_job_status($job['id'],'other'),null,'Job scope');
$status=storage_job_status($job['id'],'schoolflow');
eq($status['state'],'accepted','Job status');
eq($status['receipt']['state'],'delivered','Receipt status');
$eventData=['message_id'=>'provider-mid-001','status'=>'read'];
$raw=json_encode($eventData,JSON_THROW_ON_ERROR);
eq(storage_event_accept('event-abcdef123','test-device','receipt',$raw,$eventData,[
    'schoolflow'=>['sessions'=>['test-device'],'events'=>['receipt']]
]),true,'Event accepted');
eq(storage_event_accept('event-abcdef123','test-device','receipt',$raw,$eventData,[]),false,'Webhook duplicate');
$webhook=storage_event_claim();
eq($webhook['event_id'],'event-abcdef123','Webhook queued');
storage_event_finish($webhook['event_id'],$webhook['subscriber_id'],'dead',time(),500);
eq(storage_event_replay('event-abcdef123','schoolflow'),true,'Dead-letter replay');
audit_event('client_created','test','schoolflow');
eq(count(storage_reports()['audit']),1,'Audit');
eq(count(storage_clients()),1,'Clients');
eq((int)storage_queue_stats()[0]['n'],1,'Queue stats');
eq(storage_client_revoke('schoolflow'),1,'Client revocation');
eq(client_auth(),null,'Revoked token must not authenticate');
storage_cleanup();
$perms=fileperms($path.'/state.json') & 0777;
if (($perms & 0077)!==0) throw new RuntimeException('Unsafe JSON state permissions');
if ($db->size()>16777216) throw new RuntimeException('Exceeded JSON storage bound');
echo "JSON storage, locks, idempotency, scopes, rate limits, receipts, webhooks, revocation: PASS\n";
