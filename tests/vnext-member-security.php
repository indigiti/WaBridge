<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/vnext/member-auth.php';
require dirname(__DIR__).'/src/vnext/invitations.php';
require dirname(__DIR__).'/src/vnext/demo.php';
function memberAssert(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('W2 member security failed: '.$label);
}
function memberReject(callable $f,string $label):void {
    try{$f();}catch(InvalidArgumentException|RuntimeException $expected){return;}
    throw new RuntimeException('Expected fail-closed behavior: '.$label);
}
function memberWipe(string $p):void {
    if(is_dir($p)&&!is_link($p)){
        foreach(new DirectoryIterator($p) as $v)if(!$v->isDot())memberWipe($v->getPathname());
        rmdir($p);
    }elseif(file_exists($p)||is_link($p))unlink($p);
}
$dir=sys_get_temp_dir().'/wb-member-security-'.bin2hex(random_bytes(12));
if(!mkdir($dir,0700))throw new RuntimeException('Temp directory unavailable');
register_shutdown_function(static function()use($dir):void{memberWipe($dir);});
$store=new VNextJsonStore($dir);
VNextDemo::seedPilot($store);
$tenant=VNextDemo::accounts()[0]['id'];
$other=VNextDemo::accounts()[1]['id'];
$user='aa550000-0000-4000-8000-000000000001';
$viewer='aa550000-0000-4000-8000-000000000003';
$channel='bb660000-0000-4000-8000-000000000001';
$foreignChannel='bb660000-0000-4000-8000-000000000003';
$policy=new VNextMemberPolicy($store);
$auth=new VNextMemberAuth($store);
memberAssert($policy->member($tenant,$user)===null,'Pending user denied');
memberAssert(!$policy->channel($tenant,$user,$channel,'channel.read'),'Pending member denied');
memberReject(fn()=>$policy->grantFixtureChannel($tenant,$user,$foreignChannel),
    'No foreign channel grant');
$policy->grantFixtureChannel($tenant,$user,$channel);
$policy->grantFixtureChannel($tenant,$viewer,$channel);
$invites=new VNextInvitations($store);
$token=$invites->issue($tenant,$user);
$invites->accept($token['token'],'A Strong Fictional Password 123!');
$viewerToken=$invites->issue($tenant,$viewer);
$invites->accept($viewerToken['token'],'A Second Fictional Password 456!');
memberAssert($auth->verify($tenant,'demo-user-01@example.invalid','A Strong Fictional Password 123!')===null,
    'Invitation alone cannot sign in');
$store->mutate('system','identity',static function(array &$items)use($tenant,$user,$viewer):void {
    $items['organizations'][$tenant]['status']='active';
    $items['memberships'][$tenant.':'.$user]['status']='active';
    $items['memberships'][$tenant.':'.$viewer]['status']='active';
    $items['memberships'][$tenant.':'.$viewer]['role']='viewer';
});
$member=$auth->verify($tenant,'DEMO-USER-01@example.invalid',
    'A Strong Fictional Password 123!','198.51.100.99');
memberAssert($member!==null && $member['role']==='owner','Valid member credentials');
memberAssert($policy->channel($tenant,$user,$channel,'channel.read'),'Scoped channel read');
memberAssert($policy->channel($tenant,$user,$channel,'channel.reply'),'Owner role reply');
memberAssert(!$policy->channel($tenant,$user,$foreignChannel,'channel.read'),'Foreign channel denied');
memberAssert(!$policy->channel($other,$user,$channel,'channel.read'),'Foreign tenant denied');
memberAssert(!$policy->channel($tenant,$viewer,$channel,'channel.reply'),'Viewer cannot reply');
memberAssert($policy->channel($tenant,$viewer,$channel,'channel.read'),'Viewer can read own channel');
memberAssert(!$policy->channel($tenant,$user,$channel,'channel.random'),'Unknown permission denied');

$now=time();
for($i=0;$i<5;$i++){
    memberAssert($auth->verify($tenant,'demo-user-01@example.invalid',
        'Wrong Password 123!','198.51.100.120',$now+$i)===null,'Wrong password blocked');
}
memberAssert($auth->verify($tenant,'demo-user-01@example.invalid',
    'A Strong Fictional Password 123!','198.51.100.120',$now+7)===null,
    'Sixth attempt throttled despite correct password');
memberAssert($auth->verify($tenant,'demo-user-01@example.invalid',
    'A Strong Fictional Password 123!','198.51.100.121',$now+7)!==null,
    'Different source and valid credentials');
memberAssert($auth->verify($tenant,'demo-user-01@example.invalid',
    'A Strong Fictional Password 123!','198.51.100.120',$now+901)!==null,
    'Attempts expire after bounded window');

$ident=$store->read('system','identity',static fn(array $items):array=>$items);
memberAssert(!str_contains(json_encode($ident['member_attempts']??[],JSON_THROW_ON_ERROR),
    '198.51.100.120'),'No plaintext IP stored');
$store->mutate('system','identity',static function(array &$items)use($tenant,$user):void{
    $items['memberships'][$tenant.':'.$user]['channel_ids']=[];
});
memberAssert(!$policy->channel($tenant,$user,$channel,'channel.read'),
    'Channel access revoked immediately');
$store->mutate('system','identity',static function(array &$items)use($tenant,$user):void{
    $items['memberships'][$tenant.':'.$user]['status']='suspended';
});
memberAssert($policy->member($tenant,$user)===null,'Suspended membership denied');
memberAssert($auth->verify($tenant,'demo-user-01@example.invalid',
    'A Strong Fictional Password 123!','198.51.100.130')===null,
    'Suspended user cannot log in');
echo "W2 tenant login: active membership, per-channel RBAC, hashed throttle, revocation, role isolation PASS\n";
