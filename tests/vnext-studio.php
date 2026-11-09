<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/vnext/workflows.php';
require dirname(__DIR__).'/src/vnext/campaigns.php';
require dirname(__DIR__).'/src/vnext/assistant.php';
require dirname(__DIR__).'/src/vnext/flows.php';
require dirname(__DIR__).'/src/vnext/demo.php';
function studioCheck(bool $pass,string $message):void {
    if(!$pass)throw new RuntimeException('Studio check failed: '.$message);
}
function studioDeny(callable $test,string $message):void {
    try{$test();}catch(InvalidArgumentException|RuntimeException $expected){return;}
    throw new RuntimeException('Expected rejection: '.$message);
}
function studioClean(string $dir):void {
    if(is_dir($dir)&&!is_link($dir)){
        foreach(new DirectoryIterator($dir) as $f)if(!$f->isDot())studioClean($f->getPathname());
        rmdir($dir);
    }elseif(file_exists($dir)||is_link($dir))unlink($dir);
}
$root=sys_get_temp_dir().'/wabridge-studio-'.bin2hex(random_bytes(12));
if(!mkdir($root,0700))throw new RuntimeException('Temp root failed');
register_shutdown_function(static function()use($root):void{studioClean($root);});
$store=new VNextJsonStore($root);
$tenants=VNextDemo::accounts();
$alpha=$tenants[0]['id'];$beta=$tenants[1]['id'];
$workflow=new VNextWorkflows($store);
$campaign=new VNextCampaigns($store);
$assistant=new VNextAssistant($store);
$flows=new VNextFlows($store);
studioDeny(fn()=>$workflow->list($alpha),'Unprovisioned tenant fail closed');
VNextDemo::seedPilot($store);
studioCheck($workflow->list($beta)===[],'Other tenant initially empty');
studioDeny(fn()=>$workflow->saveDraft($alpha,'Invalid',[
    'trigger.fixture_message','action.stop','action.stop'
]),'Non-terminal stop refused');
studioDeny(fn()=>$workflow->saveDraft($alpha,'Unsafe',[
    'trigger.fixture_message','action.http_request','action.stop'
]),'Network node refused');
studioDeny(fn()=>$workflow->saveDraft($alpha,'Injection',[
    'trigger.fixture_message','trigger.fixture_message','action.stop'
]),'Duplicate trigger refused');
$steps=['trigger.fixture_message','condition.sample_consent','action.assign_demo',
    'action.review','action.stop'];
$wf=$workflow->saveDraft($alpha,'Fictional Admissions Routing',$steps);
studioCheck($wf['version']===1&&$wf['status']==='draft','Graph saved as draft');
studioCheck(count($wf['nodes'])===5 && $wf['nodes'][4]['next']===null,'Linear graph built');
studioDeny(fn()=>$workflow->publishDemo($alpha,$wf['id'],2),'Review CAS');
$reviewed=$workflow->publishDemo($alpha,$wf['id'],1);
studioCheck($reviewed['status']==='published_demo'
    && count($reviewed['published_versions'])===1,'Immutable dry-run review');
$run=$workflow->simulate($alpha,$wf['id'],1);
studioCheck($run['status']==='simulated'&&$run['external_calls']===0
    && count($run['trace'])===5,'No-side-effect execution trace');
$changed=$workflow->saveDraft($alpha,'Fictional Revision',[
    'trigger.fixture_message','action.note_demo','action.stop'],$wf['id'],1);
studioCheck($changed['status']==='draft'
    && count($changed['published_versions'])===1 && $changed['version']===2,
    'Old published snapshot preserved during edit');
studioDeny(fn()=>$workflow->saveDraft($alpha,'Stale',$steps,$wf['id'],1),'Concurrent version rejected');
studioCheck(count($workflow->list($alpha))===1 && $workflow->list($beta)===[],
    'Workflow tenant isolation');

studioDeny(fn()=>$campaign->createDraft($alpha,'Invalid','bad_template','sample_all'),
    'Approved template allowlist');
$c=$campaign->createDraft($alpha,'Sample Opt-in Campaign','sample_update','sample_all');
studioCheck($c['preview']['total']===4 && $c['preview']['eligible']===2
    && $c['preview']['suppressed']===2,'Consent and suppression preview');
studioDeny(fn()=>$campaign->transition($alpha,$c['id'],'simulate',1),
    'Cannot simulate without review');
$approved=$campaign->transition($alpha,$c['id'],'review',1);
$simulated=$campaign->transition($alpha,$c['id'],'simulate',2);
studioCheck($approved['state']==='reviewed_demo' && $simulated['state']==='simulated'
    && $simulated['simulated_count']===2&&$simulated['external_requests']===0,
    'Consent-only fictional fanout never sends');
$paused=$campaign->transition($alpha,$c['id'],'pause',3);
studioCheck($paused['state']==='paused','Campaign pause');
studioDeny(fn()=>$campaign->transition($alpha,$c['id'],'launch',4),'Live campaign unavailable');
studioCheck($campaign->list($beta)===[],'Campaign tenant isolation');

studioDeny(fn()=>$assistant->draft($alpha,'support',false),'Privacy must be confirmed');
studioDeny(fn()=>$assistant->draft($alpha,'secret_topic',true),'Unapproved knowledge');
$draft=$assistant->draft($alpha,'support',true);
studioCheck($draft['state']==='awaiting_human_review'
    && $draft['provider_requests']===0 && !$draft['auto_reply_enabled']
    && $draft['source']==='Demo support policy','Source-backed fixture draft');
studioDeny(fn()=>$assistant->review($alpha,$draft['id'],'send_now'),'No send action');
$copy=$assistant->review($alpha,$draft['id'],'approve_for_copy');
studioCheck($copy['state']==='copy_approved','Human copy approval only');
studioDeny(fn()=>$assistant->review($alpha,$draft['id'],'approve_for_copy'),
    'Repeated AI review rejected');
studioCheck($assistant->list($beta)===[],'AI draft isolation');

$flow=$flows->createDraft($alpha,'sample_enquiry');
studioDeny(fn()=>$flows->submitFixture($alpha,$flow['id'],['interest'=>'Product information']),
    'Flow must be reviewed');
$ready=$flows->review($alpha,$flow['id'],1);
studioCheck($ready['state']==='ready_for_simulation' && !$ready['provider_published'],
    'Reviewed but not published to Meta');
studioDeny(fn()=>$flows->submitFixture($alpha,$flow['id'],[
    'interest'=>'Personal mobile 919876543210','priority'=>'General'
]),'Free-form PII not allowed');
$submission=$flows->submitFixture($alpha,$flow['id'],[
    'interest'=>'Product information','priority'=>'Follow-up requested'
]);
studioCheck($submission['state']==='simulated' && $submission['external_requests']===0,
    'Validated offline Flow submission');
studioCheck($flows->list($beta)===[],'Flow tenant isolation');

// No real delivery, customer phone, secret or external address in persisted state.
$path=$root.'/tenants/'.$alpha.'/studio.json';
$raw=(string)file_get_contents($path);
studioCheck($raw!=='' && !str_contains($raw,'919876543210')
    && !str_contains($raw,'https://') && !str_contains($raw,'api_key'),
    'Studio state cannot contain rejected personal data or network targets');
studioCheck((fileperms($path)&0777)===0600,'Private JSON file');
$store->mutate('system','identity',static function(array &$items)use($alpha):void {
    $items['organizations'][$alpha]['status']='active';
});
studioDeny(fn()=>$flows->list($alpha),'Active tenant not eligible for synthetic module');

foreach(['automation-studio','campaign-manager','ai-assistant','flows-studio'] as $module) {
    $examples=VNextDemo::studioExamples($module);
    studioCheck(count($examples)===1 && ($examples[0]['id']??'')!=='',
        'Static preview example provided for '.$module);
    studioCheck(!str_contains(json_encode($examples,JSON_THROW_ON_ERROR),'919876543210'),
        'Static example contains no rejected PII');
}

echo "W4-W6 offline graph, campaign consent, assistant review, Flows, tenant isolation PASS\n";
