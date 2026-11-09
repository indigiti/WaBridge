<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/vnext/invitations.php';
require dirname(__DIR__).'/src/vnext/demo.php';
function inviteCheck(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException('W2 invite failed: '.$reason);
}
function inviteReject(callable $fn,string $reason):void{
    try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}
    throw new RuntimeException('Expected rejection: '.$reason);
}
function inviteClean(string $path):void {
    if(is_dir($path)&&!is_link($path)){
        foreach(new DirectoryIterator($path) as $f)if(!$f->isDot())inviteClean($f->getPathname());
        rmdir($path);
    }elseif(file_exists($path)||is_link($path))unlink($path);
}
$dir=sys_get_temp_dir().'/wb-invites-'.bin2hex(random_bytes(12));
if(!mkdir($dir,0700))throw new RuntimeException('Temp directory unavailable');
register_shutdown_function(static function()use($dir):void{inviteClean($dir);});
$store=new VNextJsonStore($dir);
VNextDemo::seedPilot($store);
$tenant=VNextDemo::accounts()[0]['id'];
$other=VNextDemo::accounts()[1]['id'];
$user='aa550000-0000-4000-8000-000000000001';
$access=new VNextInvitations($store);
$now=time();
$invite=$access->issue($tenant,$user,3600,$now);
inviteCheck(strlen($invite['token'])===43,'Invite has 256-bit unguessable token');
$raw=(string)file_get_contents($dir.'/system/identity.json');
inviteCheck(!str_contains($raw,$invite['token'])
    &&str_contains($raw,hash('sha256',$invite['token'])),
    'Private JSON stores only token digest');
inviteReject(fn()=>$access->issue($tenant,$user,3600,$now+1),
    'Duplicate live invite refused');
inviteReject(fn()=>$access->accept($invite['token'],'short',$now+10),
    'Weak password refused');
inviteReject(fn()=>$access->accept($invite['token'],'Correct Horse Battery Test!',$now+3601),
    'Expired invite refused');
$accepted=$access->accept($invite['token'],'Correct Horse Battery Test!',$now+20);
inviteCheck($accepted['membership_state']==='pending'&&!$accepted['can_access'],
    'Acceptance never authorizes tenant');
inviteReject(fn()=>$access->accept($invite['token'],'Another Strong Test Pass!',$now+30),
    'Invite is one use');
inviteCheck(!$access->verifyPassword($tenant,$user,'Correct Horse Battery Test!'),
    'Disabled tenant still denies password login');
$preview=(new VNextIdentity($store))->adminPreview();
inviteCheck(!isset($preview['users'][0]['password_hash']),'Read-only admin projection excludes hash');
$store->mutate('system','identity',static function(array &$items)use($tenant,$user):void {
    $items['organizations'][$tenant]['status']='active';
    $items['memberships'][$tenant.':'.$user]['status']='active';
});
inviteCheck($access->verifyPassword($tenant,$user,'Correct Horse Battery Test!'),
    'Internal credential verifier requires all active states');
inviteCheck(!$access->verifyPassword($other,$user,'Correct Horse Battery Test!'),
    'Other tenant remains denied');
inviteCheck(!$access->verifyPassword($tenant,$user,'Wrong Password Is Invalid'),
    'Incorrect password denied');
inviteReject(fn()=>$access->issue($tenant,$user,3600,$now),
    'Cannot reinvite already activated user');
echo "W2 one-time tenant invite: hash-only token, password hashing, pending access and role boundary PASS\n";
