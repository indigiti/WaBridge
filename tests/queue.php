<?php
declare(strict_types=1);
// Self-contained queue test; never uses a live WhatsApp device.
$root=dirname(__DIR__);
$cfg=$root.'/config.php';
if (file_exists($cfg)) { fwrite(STDERR,"Refusing to run tests over a real config.php\n"); exit(2); }
$runtime=$root.'/runtime/wabridge.sqlite';
$testConfig = <<<'CFG'
<?php
return [
    'gateway_url'=>'http://127.0.0.1:3451',
    'gateway_token'=>'test-gateway-token',
    'admin_user'=>'admin',
    'admin_password_hash'=>'hash-only-for-ci-test',
    'allowed_sessions'=>['test-device'],
    'max_message_chars'=>2000,
    'api_clients'=>[],
];
CFG;
file_put_contents($cfg,$testConfig);
register_shutdown_function(static function()use($cfg,$runtime){
    @unlink($cfg);
    foreach ([$runtime,$runtime.'-wal',$runtime.'-shm'] as $f) @unlink($f);
});
require $root.'/src/store.php';
function eq(mixed $actual,mixed $expected,string $msg): void {
    if ($actual!==$expected) throw new RuntimeException($msg.' got '.var_export($actual,true));
}
$client=['id'=>'integration-test', 'sessions'=>['test-device'], 'limit'=>2];
$db=store();
[$sid,$to,$body]=validate_message(['session_id'=>'test-device','to'=>'919876543210','text'=>'hello']);
$job1=enqueue_message($db,$client,$sid,$to,$body,'test-request-00000001');
$job2=enqueue_message($db,$client,$sid,$to,$body,'test-request-00000001');
eq($job1['id'],$job2['id'],'Idempotency ID must be identical');
eq($job2['duplicate'],true,'Second send must be deduplicated');
try {
    enqueue_message($db,$client,$sid,$to,'changed','test-request-00000001');
    throw new RuntimeException('Changed body should be rejected');
} catch (InvalidArgumentException $e) {}
try {
    enqueue_message($db,$client,'other-device',$to,$body,'test-request-00000002');
    throw new RuntimeException('Cross-tenant session should be rejected');
} catch (InvalidArgumentException $e) {}
eq(limit_client($db,$client),true,'quota 1');
eq(limit_client($db,$client),true,'quota 2');
eq(limit_client($db,$client),false,'quota limit');
$claimed=claim_job($db);
eq($claimed['id'],$job1['id'],'Claim must return queued job');
eq(claim_job($db),null,'Worker must not double claim processing job');
complete_job($db,$job1['id'],'accepted',200,'{}');
$q=$db->prepare('SELECT state FROM jobs WHERE id=?');
$q->execute([$job1['id']]);
eq($q->fetchColumn(),'accepted','Completed job state');
$secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$hash=hash('sha256',$secret);
$s=$db->prepare('INSERT INTO api_clients(id,token_sha256,sessions_json,per_minute,created_at) VALUES(?,?,?,?,?)');
$s->execute(['schoolflow',$hash,'["test-device"]',15,time()]);
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$secret;
$auth=client_auth();
eq($auth['id'],'schoolflow','SQLite API client should authenticate');
eq($auth['sessions'],['test-device'],'Scope should be preserved');
$db->exec("UPDATE api_clients SET active=0 WHERE id='schoolflow'");
eq(client_auth(),null,'Revocation must take effect');
echo "SQLite queue, idempotency, tenant scope, quota, worker state and token revocation: PASS\n";
