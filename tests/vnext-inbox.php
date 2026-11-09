<?php
declare(strict_types=1);
// W3 integration: one randomized temporary JSON sandbox, no application
// config, no network, no real provider, no database connection.
require dirname(__DIR__).'/src/vnext/inbox.php';
require dirname(__DIR__).'/src/vnext/demo.php';

function w3_assert(bool $condition,string $label):void {
    if(!$condition)throw new RuntimeException('W3 failure: '.$label);
}
function w3_reject(callable $f,string $label):void {
    try{$f();}catch(InvalidArgumentException|RuntimeException $e){return;}
    throw new RuntimeException('W3 expected refusal: '.$label);
}
function w3_clean(string $p):void {
    if(is_dir($p)&&!is_link($p)){
        foreach(new DirectoryIterator($p) as $item)if(!$item->isDot())w3_clean($item->getPathname());
        rmdir($p);
    }elseif(file_exists($p)||is_link($p))unlink($p);
}
$dir=sys_get_temp_dir().'/wabridge-w3-'.bin2hex(random_bytes(10));
if(!mkdir($dir,0700))throw new RuntimeException('Cannot open test root');
register_shutdown_function(static function()use($dir):void{w3_clean($dir);});
$store=new VNextJsonStore($dir);
$inbox=new VNextInbox($store);
$accounts=VNextDemo::accounts();
$a=$accounts[0]['id'];
$b=$accounts[1]['id'];

w3_reject(fn()=>$inbox->overview($a),'Cannot read unseeded tenant');
w3_reject(fn()=>$inbox->seed($a),'Cannot seed before synthetic identity');
$seed=VNextDemo::seedPilot($store);
w3_assert($seed['organizations']===3,'Pilot accounts seeded');
w3_assert($inbox->overview($a)['seeded']===false,'Tenant initially empty');
w3_assert($inbox->seed($a)===['seeded'=>true,'count'=>3],'Seed 3 fictional threads');
w3_assert($inbox->seed($a)===['seeded'=>false,'count'=>3],'Re-seed idempotent');
w3_assert($inbox->overview($b)['threads']===[],'Other tenant still empty');
w3_assert(count($inbox->overview($a)['threads'])===3,'Three tenant conversations');
w3_assert(count($inbox->overview($a)['team'])===4,'Four synthetic members');
w3_assert(count($inbox->overview($a,'pending')['threads'])===1,'State filter');
w3_reject(fn()=>$inbox->overview($a,'this_is_not_a_state'),'Unknown status denied');

$thread=$inbox->overview($a)['threads'][0];
$threadId=$thread['id'];
$before=$inbox->detail($a,$threadId);
w3_assert($before!==null && count($before['messages'])===2,'Fixture messages visible');
w3_assert($inbox->detail($b,$threadId)===null,'No cross-tenant conversation');
$foreign=VNextDemo::accounts()[1]['id'];
w3_reject(fn()=>$inbox->assign($a,$threadId,$foreign,1),'Foreign agent denied');
$agents=$inbox->overview($a)['team'];
$updated=$inbox->assign($a,$threadId,$agents[0]['user_id'],1);
w3_assert($updated['assignee_id']===$agents[0]['user_id'] && $updated['version']===2,
    'Atomic assignment increments revision');
w3_reject(fn()=>$inbox->assign($a,$threadId,$agents[1]['user_id'],1),'Stale assignment rejected');
$changed=$inbox->changeState($a,$threadId,'resolved',2);
w3_assert($changed['state']==='resolved' && $changed['version']===3,'State update');
w3_reject(fn()=>$inbox->changeState($a,$threadId,'delivered',3),'Never confuse conversation and delivery states');
$noted=$inbox->addPresetNote($a,$threadId,'Follow-up required',3);
w3_assert($noted['version']===4 && count($noted['notes'])===1,'Add approved synthetic note');
w3_reject(fn()=>$inbox->addPresetNote($a,$threadId,'Call this actual customer on 919000000000',4),
    'Arbitrary personal data note denied');
w3_reject(fn()=>$inbox->changeState($a,$threadId,'open',3),'Stale state rejected');
w3_reject(fn()=>$inbox->addPresetNote($b,$threadId,'Follow-up required',1),
    'No mutation into unseeded tenant');
w3_reject(fn()=>$inbox->seed('not-a-uuid'),'Tenant path validation');
w3_assert($inbox->detail($a,$threadId)['version']===4,'Revisions preserved');
w3_assert(count($inbox->detail($a,$threadId)['messages'])===2,'Notes cannot send');

$file=$dir.'/tenants/'.$a.'/inbox.json';
$raw=(string)file_get_contents($file);
w3_assert(!str_contains($raw,'919000000000'),'Rejected customer phone never written');
w3_assert(!str_contains($raw,'"to":'),'Never stage external recipients');
w3_assert(!str_contains($raw,'provider_message_id'),'No delivery id fabricated');
w3_assert((fileperms($file)&0777)===0600,'Private document mode');
w3_assert((fileperms(dirname($file))&0777)===0700,'Private tenant directory mode');

// When an operator changes ownership metadata, the demo read boundary fails
// closed rather than exposing arbitrary tenant directories.
$store->mutate('system','identity',static function(array &$items)use($a):void{
    $items['organizations'][$a]['status']='active';
});
w3_reject(fn()=>$inbox->overview($a),'Non-disabled tenant refused');
echo "W3 offline Shared Team Inbox: isolation, assignment CAS, state, preset notes and safe seed PASS\n";
