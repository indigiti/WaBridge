<?php
declare(strict_types=1);
// Isolated provider-free integration regression. Do not create config.php,
// modify live runtime, call Waxum, or issue any WhatsApp send.
require dirname(__DIR__).'/src/vnext/signed-ingress.php';
require dirname(__DIR__).'/src/vnext/outbox.php';
require dirname(__DIR__).'/src/vnext/recovery.php';
require dirname(__DIR__).'/src/vnext/demo.php';

function must(bool $condition,string $label):void{
    if(!$condition)throw new RuntimeException('FAILED: '.$label);
}
function mustReject(callable $fn,string $label):void{
    try{$fn();}catch(InvalidArgumentException|RuntimeException|JsonException $ok){return;}
    throw new RuntimeException('Should reject: '.$label);
}
function removeTestTree(string $p):void{
    if(is_dir($p)&&!is_link($p)){
        foreach(new DirectoryIterator($p) as $f)if(!$f->isDot())removeTestTree($f->getPathname());
        rmdir($p);
    }elseif(file_exists($p)||is_link($p))unlink($p);
}
$root=sys_get_temp_dir().'/wb-vnext-integration-'.bin2hex(random_bytes(10));
if(!mkdir($root,0700))throw new RuntimeException('Cannot create test sandbox');
register_shutdown_function(static function()use($root):void{removeTestTree($root);});
$store=new VNextJsonStore($root);
$identity=new VNextIdentity($store);
$alpha=$identity->createOrganization('Fixture Alpha');
$beta=$identity->createOrganization('Fixture Beta');
$person=$identity->createUser('staff@sample.invalid','Sample Staff');
$identity->grant($alpha['id'],$person['id'],'manager');
$admin=$identity->adminPreview();
must(count($admin['organizations'])===2 && count($admin['users'])===1,'Admin safe projection');
must(!isset($admin['users'][0]['password_hash']),'No secrets in admin projection');
must(!$identity->can($alpha['id'],$person['id'],'inbox.read'),'Pending membership cannot access');
$channels=new VNextChannels($store);
$channelAlpha='83b6ab6f-a365-4dd8-a03f-ae16b2d73381';
$channelBeta='697b6c4f-a2e6-40ba-afdb-20fa3c3383a4';
$channels->registerFixture($alpha['id'],$channelAlpha,'alpha_test_001');
$channels->registerFixture($beta['id'],$channelBeta,'beta_test_002');
must(($channels->resolve('fixture','alpha_test_001')['tenant_id']??null)===$alpha['id'],
    'Channel resolves by registered ownership');
must($channels->resolve('waxum','alpha_test_001')===null,'Live provider cannot route');
mustReject(fn()=>$channels->registerFixture($beta['id'],$channelBeta,'alpha_test_001'),
    'Duplicate external channel cannot change owner');
mustReject(fn()=>$channels->registerFixture($beta['id'],'../../bad','bad_channel'),
    'Path traversal channel');
$journal=new VNextEventJournal($store);
$ingress=new VNextSignedIngress($channels,$journal);
$secret='test-secret-32-characters-never-production-0123456789';
$now=time();
$event=['provider'=>'fixture','external_channel_id'=>'alpha_test_001',
    'type'=>'message.delivered','provider_event_id'=>'test-event-0001',
    'provider_message_id'=>'fictional-message-id','occurred_at'=>$now,
    'tenant_id'=>$beta['id'],'body'=>'DO_NOT_PERSIST_PRIVATE_MESSAGE'];
$raw=json_encode($event,JSON_THROW_ON_ERROR);
$stamp=(string)$now;
$sign=static fn(string $body,string $ts):string =>
    'sha256='.hash_hmac('sha256',$ts.'.'.$body,$secret);
$result=$ingress->ingest($raw,$stamp,$sign($raw,$stamp),$secret,$now);
must($result['accepted'] && !$result['duplicate'],'Authenticated event accepted');
must($result['tenant_id']===$alpha['id'],'Untrusted tenant_id ignored');
must($journal->count($alpha['id'])===1 && $journal->count($beta['id'])===0,
    'Strict tenant event isolation');
must($ingress->ingest($raw,$stamp,$sign($raw,$stamp),$secret,$now)['duplicate'],
    'Signed event replay idempotent');
$stored=file_get_contents($root.'/tenants/'.$alpha['id'].'/events.json');
must(!str_contains((string)$stored,'DO_NOT_PERSIST_PRIVATE_MESSAGE'),'No message body stored');
must(!str_contains((string)$stored,$beta['id']),'Untrusted tenant ID not persisted');
mustReject(fn()=>$ingress->ingest($raw,$stamp,$sign($raw,$stamp).'a',$secret,$now),'Tampered signature');
mustReject(fn()=>$ingress->ingest($raw,(string)($now-400),$sign($raw,(string)($now-400)),$secret,$now),
    'Stale signature');
$evil=json_encode(array_merge($event,['external_channel_id'=>'unknown_test_999']),JSON_THROW_ON_ERROR);
mustReject(fn()=>$ingress->ingest($evil,$stamp,$sign($evil,$stamp),$secret,$now),
    'Unknown channel rejected');
$live=json_encode(array_merge($event,['provider'=>'waxum']),JSON_THROW_ON_ERROR);
mustReject(fn()=>$ingress->ingest($live,$stamp,$sign($live,$stamp),$secret,$now),
    'Real provider disabled');
$conflict=json_encode(array_merge($event,['type'=>'message.read']),JSON_THROW_ON_ERROR);
mustReject(fn()=>$ingress->ingest($conflict,$stamp,$sign($conflict,$stamp),$secret,$now),
    'Conflicting replay rejected');
$claim=$journal->claim($alpha['id'],$now,30);
must($claim!==null && $claim['attempts']===1,'Atomic event claim');
must($journal->claim($alpha['id'],$now,30)===null,'Duplicate worker cannot claim');
must(!$journal->acknowledge($alpha['id'],$claim['event_key'],str_repeat('0',32)),
    'Wrong lease token denied');
$expired=$journal->claim($alpha['id'],$now+31,30);
must($expired!==null && $expired['attempts']===2 && $expired['lease_token']!==$claim['lease_token'],
    'Expired local normalization lease recovered');
must(!$journal->acknowledge($alpha['id'],$claim['event_key'],$claim['lease_token']),
    'Stale worker fenced after recovery');
must($journal->acknowledge($alpha['id'],$expired['event_key'],$expired['lease_token']),
    'Recovered worker acknowledges normalization');
must($journal->states($alpha['id'])['normalized']===1,'Exactly one normalized event');
must((new VNextRecovery($journal))->drain($alpha['id'])['normalized']===0,
    'Recovery never replays completed events');

$outbox=new VNextOutbox($store);
$reserved=$outbox->reserve($alpha['id'],'fake-message-0001');
must(!$reserved['duplicate'],'Reserve simulation intent');
must($outbox->reserve($alpha['id'],'fake-message-0001')['duplicate'],
    'Intent idempotency');
$send=$outbox->claim($alpha['id'],$now,10);
must($send!==null,'Intent atomic claim');
must($outbox->claim($alpha['id'],$now,10)===null,'Intent not double claimed');
must(!$outbox->finishSimulation($alpha['id'],$send['id'],str_repeat('f',32),$now+1),
    'Bad outbox lease denied');
must($outbox->claim($alpha['id'],$now+11,10)===null,'Expired dispatch NOT automatically retried');
must($outbox->states($alpha['id'])['needs_review']===1,'Expired dispatch requires review');
must(!$outbox->finishSimulation($alpha['id'],$send['id'],$send['token'],$now+11),
    'Stale simulated dispatch cannot finish');
$outbox->reserve($beta['id'],'fake-message-0002');
$fresh=$outbox->claim($beta['id'],$now,30);
must($fresh!==null && $outbox->finishSimulation($beta['id'],$fresh['id'],$fresh['token'],$now+1),
    'Safe simulated completion');
must($outbox->states($beta['id'])['simulated']===1 && $outbox->states($alpha['id'])['simulated']===0,
    'Outbox tenants isolated');
$demo=VNextDemo::overview();
must($demo['is_synthetic']===true && $demo['live_sends_enabled']===false,
    'Demo fixture sends disabled');
must(count($demo['accounts'])===3 && !str_contains(json_encode($demo,JSON_THROW_ON_ERROR),'@'),
    'Demo fixture has no personal contact info');

$demoStore=new VNextJsonStore($root.'/seeded-demo');
$firstSeed=VNextDemo::seedPilot($demoStore);
must($firstSeed['seeded'] && $firstSeed['organizations']===3,'Explicit demo seed');
must(VNextDemo::seedPilot($demoStore)['seeded']===false,'Idempotent synthetic seeding');
$seedPreview=(new VNextIdentity($demoStore))->adminPreview();
must(count($seedPreview['organizations'])===3 && count($seedPreview['users'])===12
    && count($seedPreview['memberships'])===12 && $seedPreview['channels']===4,
    'Pilot seed matches fictional account counts');
foreach($seedPreview['organizations'] as $account) {
    must($account['status']==='pilot_disabled','Seed never activates tenant');
}
foreach($seedPreview['memberships'] as $member){
    must($member['status']==='pending','Seed never activates membership');
}
must(!(new VNextIdentity($demoStore))->can($seedPreview['organizations'][0]['id'],
    $seedPreview['users'][0]['id'],'inbox.read'),'Seeded users cannot access inbox');
$seedChannel=(new VNextChannels($demoStore))->resolve('fixture','demo_fixture_01');
must($seedChannel!==null,'Seed channel fixture only');
mustReject(fn()=>VNextDemo::seedPilot($store),'Never overwrite existing pilot metadata');

echo "W1/W2 HMAC ingress, tenant ownership, event replay/recovery, safe dry-run outbox and demo: PASS\n";
