<?php
declare(strict_types=1);
// Provider-free integration tests; refuse to overwrite application data.
$root=dirname(__DIR__);$cfg=$root.'/config.php';
$folder=$root.'/runtime/platform';$json=$root.'/runtime/json';
foreach([$cfg,$folder.'/state.json',$json.'/state.json'] as $file)
    if(file_exists($file))throw new RuntimeException('Existing test state: abort');
file_put_contents($cfg,'<?php return '.var_export([
    'admin_user'=>'admin','admin_password_hash'=>'dummy',
    'gateway_url'=>'http://127.0.0.1:3451','gateway_token'=>'fixture',
    'allowed_sessions'=>['unit-session'],'storage_driver'=>'json',
    'enable_official_cloud_connect'=>true
],true).';');
register_shutdown_function(static function()use($cfg,$folder,$json):void{
    @unlink($cfg);
    foreach([$folder,$json] as $dir){
        @unlink($dir.'/state.json');@unlink($dir.'/state.lock');
        @rmdir($dir);
    }
});
require $root.'/src/store.php';
require $root.'/src/message-processor.php';
require $root.'/src/message-log.php';
require $root.'/src/platform/modules.php';
function check(bool $truth,string $label):void{if(!$truth)throw new RuntimeException($label);}
$c=platform_contact_save('Sample Person','919876543210','optin-2026',true);
check(valid_job_id($c['id']),'contact GUID');
check(platform_contact_consented($c['phone']),'consent recorded');
$t=platform_template_create('Reminder','Hello {{name}}, meeting on {{date}}');
check(platform_template_render($t,$c,'2026-11-06')==='Hello Sample Person, meeting on 2026-11-06',
    'template expansion');
$when=time()+600;
$s=platform_schedule($c['id'],$t['id'],'unit-session',$when);
check(valid_job_id($s['job_id']),'scheduled job GUID');
$db=store();check($db instanceof JsonStore,'JSON pilot driver');
$job=$db->read(static fn(array $state):array=>$state['jobs'][$s['job_id']]);
check($job['next_at']===$when,'future queue notBefore persisted');
$cancelJob=platform_schedule($c['id'],$t['id'],'unit-session',time()+720);
check(platform_schedule_cancel($cancelJob['id']),'cancel a queued job');
$cancelled=$db->read(static fn(array $state):array=>$state['jobs'][$cancelJob['job_id']]);
check($cancelled['state']==='cancelled','cancelled job state persists');
check(!platform_schedule_cancel($cancelJob['id']),'cancel is idempotent and cannot repeat');
$counter=0;
$sender=static function()use(&$counter):array{$counter++;return ['http'=>200,'data'=>['message_id'=>'unexpected']];};
$connected=static fn(string $sid):array=>['http'=>200,'is_logged_in'=>true,'socket_alive'=>true];
check(process_next_message($db,$sender,$s['job_id'],$connected)===null,'scheduled not sent early');
$db->mutate(static function(array &$state)use($s):void{$state['jobs'][$s['job_id']]['next_at']=time()-1;});
check(platform_contact_revoke($c['id']),'withdraw consent');
$out=process_next_message($db,$sender,$s['job_id'],$connected);
check(($out['reason']??'')==='consent_revoked'&&$counter===0,'dispatch-time opt-out');
try{platform_schedule($c['id'],$t['id'],'unit-session',time()+600);
    throw new RuntimeException('withdrawn consent allowed');
}catch(InvalidArgumentException $expected){}
$c=platform_contact_save('Sample Person','919876543210','new-optin-2026',true);
$m=platform_media_send('unit-session',$c['id'],'image','mock-bytes','image/png','caption','',
    static function(string $method,string $path,array $payload):array{
        check(str_ends_with($path,'/messages/image'),'media route');
        check(base64_decode($payload['image']['data'],true)==='mock-bytes','media payload');
        return ['http'=>200,'data'=>['status'=>'sent','message_id'=>'provider-media-id']];
    },$connected);
check($m['status']==='accepted'&&$m['delivery']==='unconfirmed','media only gateway accepted');
$rule=platform_rule_create('unit-session','admission','Manual reply suggested');
$matches=platform_rule_preview('unit-session','admission information');
check(count($matches)===1&&$matches[0]['action']==='review_only','no automated response');
$w=platform_workspace_create('Pilot Workspace');
check(valid_job_id($w['id'])&&$w['billing']==='disabled','workspaces are configuration only');
$secret='fixture-secret-that-should-not-be-persisted';
$r=platform_cloud_connect('unit-session',[
  'waba_id'=>'123456789012','phone_number_id'=>'123456789013',
  'access_token'=>$secret,'app_secret'=>'fixture-app-secret',
  'webhook_verify_token'=>'fixture-webhook-token',
],static function(string $method,string $path,array $payload)use($secret):array{
    check(str_ends_with($path,'/cloud/connect'),'official cloud route');
    check($payload['access_token']===$secret,'provider receives token in private request');
    return ['http'=>200,'data'=>['session'=>['id'=>'unit-session']]];
});
check($r['accepted'],'Cloud API adapter');
$cloudMessage=platform_approved_template_send('unit-session',$c['id'],'welcome','en_US',
    static fn():array=>['http'=>200,'data'=>['message_id'=>'cloud-id']]);
check($cloudMessage['accepted']&&$cloudMessage['delivery']==='unconfirmed','approved template send');
$raw=(string)file_get_contents($folder.'/state.json');
check(!str_contains($raw,$secret),'provider credentials never persisted');
check(count(platform_page('contacts',1,10)['rows'])===1,'bounded pilot query');
echo "Product modules, consent-safe scheduling, media, cloud adapters, rule previews: PASS\n";
