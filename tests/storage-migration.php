<?php
declare(strict_types=1);
// One-time web migration regression. Never run against real configuration.
$root=dirname(__DIR__);
$runtime=$root.'/runtime';
$cfg=$root.'/config.php';
$paths=[$cfg,$runtime.'/wabridge.sqlite',$runtime.'/storage-mode.json',
    $runtime.'/storage-migrating.flag',$runtime.'/json/state.json'];
foreach($paths as $p) if(file_exists($p)) { fwrite(STDERR,"Existing runtime file; abort test: ".basename($p)."\n");exit(2); }
$testConfig=[
    'storage_driver'=>'sqlite',
    'gateway_url'=>'http://127.0.0.1:3451',
    'gateway_token'=>'test-gateway',
    'admin_user'=>'testadmin','admin_password_hash'=>'hash',
    'allowed_sessions'=>['test-device'],'max_message_chars'=>2000,
];
file_put_contents($cfg,'<?php return '.var_export($testConfig,true).';');
register_shutdown_function(static function()use($root,$runtime,$cfg) {
    @unlink($cfg);
    foreach (['wabridge.sqlite','wabridge.sqlite-wal','wabridge.sqlite-shm',
        'storage-mode.json','storage-migrating.flag','storage-migration.lock'] as $name)
        @unlink($runtime.'/'.$name);
    foreach (['state.json','state.lock'] as $name) @unlink($runtime.'/json/'.$name);
    @rmdir($runtime.'/json');
});
require $root.'/src/store.php';
require $root.'/src/storage-migration.php';
function assert_eq(mixed $a,mixed $b,string $msg):void {
    if($a!==$b) throw new RuntimeException($msg.' (actual '.var_export($a,true).')');
}
assert_eq(storage_mode(),'sqlite','Initial mode');
$db=store();
if (!$db instanceof PDO) throw new RuntimeException('Legacy PDO not active');
$tokenHash=hash('sha256','not-a-real-login-secret');
storage_client_create(['id'=>'legacy-client','token_sha256'=>$tokenHash,
    'sessions_json'=>'["test-device"]','per_minute'=>20,'created_at'=>time(),'expires_at'=>null]);
$client=['id'=>'legacy-client','sessions'=>['test-device'],'limit'=>20];
$job=enqueue_message($db,$client,'test-device','919876543210','Hello migration','migration-key-00001');
try {
    storage_migrate_to_json();
    throw new RuntimeException('Must refuse migration with queued messages');
} catch(RuntimeException $e) {
    if(!str_contains($e->getMessage(),'queued or processing'))
        throw $e;
}
if (file_exists($runtime.'/storage-mode.json')) throw new RuntimeException('Premature mode selector write');
$claimed=claim_job($db);
assert_eq($claimed['id'],$job['id'],'Legacy queue read');
complete_job($db,$job['id'],'accepted',200,'{"message_id":"abc"}');
record_provider_message($db,$job['id'],'provider-legacy-id');
audit_event('test_event','admin','legacy-client');
$out=storage_migrate_to_json();
assert_eq($out['status'],'migrated','Migration completion');
assert_eq(storage_mode(),'json','Persisted mode');
$json=new JsonStore();
assert_eq($json->clientByHash($tokenHash)['id'],'legacy-client','API key migrated');
assert_eq($json->job($job['id'],'legacy-client')['state'],'accepted','Job migrated');
assert_eq($json->receipt($job['id'])['provider_id'],'provider-legacy-id','Receipt migrated');
$migratedTimeline=$json->read(static fn(array $s):array=>$s['timeline'][$job['id']]??[]);
assert_eq(array_column($migratedTimeline,'state'),['queued','processing','accepted'],
    'GUID message history preserved during migration');
$migratedAudit=$json->read(static fn(array $s):array=>$s['audit']);
if(!valid_job_id((string)($migratedAudit[0]['guid']??'')))
    throw new RuntimeException('Administrator event GUID lost during migration');
if(!file_exists($runtime.'/wabridge.sqlite')) throw new RuntimeException('Legacy backup was deleted');
if(file_exists($runtime.'/storage-migrating.flag')) throw new RuntimeException('Migration guard remains');
if(!file_exists($runtime.'/storage-mode.json')) throw new RuntimeException('Missing selector');
echo "Web-only SQLite -> JSON migration, active job guard, preserved data and rollback file: PASS\n";
