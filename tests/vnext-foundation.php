<?php
declare(strict_types=1);
/**
 * W0-W2 isolated regression: no config.php, database, Rust or live provider.
 * Only files beneath a fresh random temp directory are touched.
 */
require dirname(__DIR__).'/src/vnext/identity.php';
require dirname(__DIR__).'/src/vnext/events.php';

function ensure(bool $ok,string $message):void {
    if (!$ok) throw new RuntimeException($message);
}
function expectFailure(callable $task,string $message):void {
    try {$task();} catch (InvalidArgumentException|RuntimeException|JsonException $expected) {return;}
    throw new RuntimeException('Expected failure: '.$message);
}
function wipe(string $path):void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) wipe($entry->getPathname());
        }
        rmdir($path);
    } else {unlink($path);}
}
$root=sys_get_temp_dir().'/wabridge-vnext-fixture-'.bin2hex(random_bytes(10));
if (!mkdir($root,0700)) throw new RuntimeException('Fixture directory unavailable');
register_shutdown_function(static function()use($root):void {wipe($root);});
$store=new VNextJsonStore($root);
$identity=new VNextIdentity($store);
$orgA=$identity->createOrganization('Company Alpha');
$orgB=$identity->createOrganization('Company Beta');
$user=$identity->createUser('OWNER@EXAMPLE.TEST','Pilot Owner');
$membership=$identity->grant($orgA['id'],$user['id'],'owner');
ensure($membership['status']==='pending','Membership must be pending');
ensure($orgA['status']==='pilot_disabled','Tenant must remain disabled');
ensure(!$identity->can($orgA['id'],$user['id'],'tenant.manage'),'Fail-closed authorization');
ensure(!$identity->can($orgB['id'],$user['id'],'inbox.read'),'Cross-tenant authorization');
ensure(!$identity->can($orgA['id'],$user['id'],'unknown.permission'),'Unknown permission denied');
ensure($identity->snapshot()===['organizations'=>2,'users'=>1,'memberships'=>1],
    'Expected identity counts');
expectFailure(fn()=>$identity->createUser('owner@example.test','Duplicate'),'Duplicate normalized email');
expectFailure(fn()=>$identity->grant($orgB['id'],$user['id'],'superuser'),'Unknown role denied');
expectFailure(fn()=>$store->read('../etc','events',static fn()=>null),'Traversal denied');
expectFailure(fn()=>$store->read('system','events',static fn()=>null),'System events denied');
expectFailure(fn()=>$store->read($orgA['id'],'identity',static fn()=>null),'Tenant identity denied');

$revision=$store->revision('system','identity');
expectFailure(fn()=>$store->mutate('system','identity',
    static function(array &$items):void {$items['bad']='value';},$revision+1),'Stale revision');
ensure($store->revision('system','identity')===$revision,'Conflict must not modify state');
expectFailure(fn()=>$store->mutate('system','identity',
    static function(array &$items):void {$items['bad']='value';throw new RuntimeException('rollback');}),
    'Thrown mutation');
ensure($store->revision('system','identity')===$revision,'Failed mutation no revision change');
ensure($store->read('system','identity',
    static fn(array $items):bool => !isset($items['bad'])),'Failed mutation no partial persist');

$events=new VNextEventJournal($store);
$evt=[
    'type'=>'message.delivered','provider'=>'fixture',
    'channel_id'=>$orgA['id'],'provider_event_id'=>'event-delivery-0001',
    'provider_message_id'=>'provider-msg-001','occurred_at'=>time(),
];
$normalized=VNextEventJournal::normalize($orgA['id'],$evt);
ensure($events->recordVerified($normalized),'First event inserted');
ensure(!$events->recordVerified($normalized),'Replayed event idempotent');
ensure($events->count($orgA['id'])===1,'Exactly one tenant event');
ensure($events->count($orgB['id'])===0,'Events isolated');
$changed=$normalized;$changed['type']='message.read';
expectFailure(fn()=>$events->recordVerified($changed),'Conflicting event denied');
expectFailure(fn()=>VNextEventJournal::normalize($orgA['id'],array_merge($evt,['type'=>'arbitrary'])),
    'Unsupported provider event denied');
expectFailure(fn()=>VNextEventJournal::normalize($orgA['id'],array_merge($evt,['provider'=>'unknown'])),
    'Unsupported provider denied');
expectFailure(fn()=>VNextEventJournal::normalize($orgA['id'],array_merge($evt,['occurred_at'=>time()+999999])),
    'Future event denied');

// Stored JSON must be private and remain parsable after sequential mutations.
$file=$root.'/system/identity.json';
ensure(is_file($file),'Identity file missing');
ensure((fileperms($file)&0777)===0600,'Unsafe JSON file permissions');
ensure((fileperms(dirname($file))&0777)===0700,'Unsafe JSON directory permissions');
ensure(json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR)['revision']===$revision,
    'Identity document corrupted');
echo "W0-W2 offline JSON, tenant isolation, event deduplication and CAS: PASS\n";
