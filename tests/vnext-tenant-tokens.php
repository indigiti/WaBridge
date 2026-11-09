<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/vnext/tenant-tokens.php';
require dirname(__DIR__).'/src/vnext/demo.php';
function tokenAssert(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('W2 token test: '.$message);
}
function tokenReject(callable $fn,string $reason):void {
    try{$fn();}catch(InvalidArgumentException|RuntimeException $expected){return;}
    throw new RuntimeException('Expected failure: '.$reason);
}
function tokenWipe(string $p):void {
    if(is_dir($p)&&!is_link($p)){
        foreach(new DirectoryIterator($p) as $x)if(!$x->isDot())tokenWipe($x->getPathname());
        rmdir($p);
    }elseif(is_file($p)||is_link($p))unlink($p);
}
$dir=sys_get_temp_dir().'/wb-token-test-'.bin2hex(random_bytes(12));
if(!mkdir($dir,0700))throw new RuntimeException('Temp path creation failed');
register_shutdown_function(static function()use($dir):void{tokenWipe($dir);});
$store=new VNextJsonStore($dir);
VNextDemo::seedPilot($store);
$tenant=VNextDemo::accounts()[0]['id'];
$other=VNextDemo::accounts()[1]['id'];
$user='aa550000-0000-4000-8000-000000000001';
$auth=new VNextTenantTokens($store);
tokenReject(fn()=>$auth->issue($tenant,$user,['inbox.read']),
    'Disabled tenant cannot issue tokens');
$store->mutate('system','identity',static function(array &$items)use($tenant,$user):void {
    $items['organizations'][$tenant]['status']='active';
    $items['users'][$user]['status']='active';
    $items['memberships'][$tenant.':'.$user]['status']='active';
});
$identity=new VNextIdentity($store);
tokenAssert($identity->can($tenant,$user,'inbox.read'),'Scoped read right');
tokenAssert($identity->can($tenant,$user,'tenant.manage'),'Owner has tenant manage right');
tokenAssert(!$identity->can($other,$user,'inbox.read'),'Other tenant denied');
$now=time();
$issued=$auth->issue($tenant,$user,['inbox.read','inbox.reply'],1800,$now);
tokenAssert(strlen($issued['token'])===43,'High entropy bearer');
tokenAssert(VNextJsonStore::uuid($issued['id']),'Opaque token identifier');
tokenAssert($auth->authenticate($issued['token'],$tenant,'inbox.read',$now+20)!==null,
    'Correct tenant, scope and expiry');
tokenAssert($auth->authenticate($issued['token'],$tenant,'campaign.approve',$now+20)===null,
    'Disallowed scope refused');
tokenAssert($auth->authenticate($issued['token'],$other,'inbox.read',$now+20)===null,
    'Cross-tenant token denied');
tokenAssert($auth->authenticate($issued['token'],$tenant,'inbox.read',$now+1800)===null,
    'Expired token denied');
tokenAssert($auth->authenticate(str_repeat('x',43),$tenant,'inbox.read',$now+20)===null,
    'Forged token denied');
$raw=(string)file_get_contents($dir.'/system/identity.json');
tokenAssert(!str_contains($raw,$issued['token'])
    && str_contains($raw,hash('sha256',$issued['token'])),
    'Only hash stored, no plaintext bearer');
tokenReject(fn()=>$auth->issue($tenant,$user,['network.send'],1800,$now),
    'Unknown permission cannot issue');
tokenReject(fn()=>$auth->issue($tenant,$user,['inbox.read','inbox.read'],1800,$now),
    'Duplicate scopes denied');
$store->mutate('system','identity',static function(array &$items)use($tenant,$user):void {
    $items['memberships'][$tenant.':'.$user]['status']='suspended';
});
tokenAssert($auth->authenticate($issued['token'],$tenant,'inbox.read',$now+20)===null,
    'Membership suspension invalidates token immediately');
$store->mutate('system','identity',static function(array &$items)use($tenant,$user):void {
    $items['memberships'][$tenant.':'.$user]['status']='active';
});
tokenAssert($auth->revoke($issued['id']),'Token revocation succeeded');
tokenAssert(!$auth->revoke($issued['id']),'Revocation idempotent');
tokenAssert($auth->authenticate($issued['token'],$tenant,'inbox.read',$now+20)===null,
    'Revoked token cannot authenticate');
echo "W2 tenant API token hashing, scope, expiry, revocation and dynamic RBAC: PASS\n";
