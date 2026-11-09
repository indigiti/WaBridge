<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/vnext/backup.php';
function backupCheck(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException('W1 backup failure: '.$label);
}
function backupReject(callable $fn,string $label):void{
    try{$fn();}catch(RuntimeException|InvalidArgumentException|JsonException $expected){return;}
    throw new RuntimeException('Expected backup refusal: '.$label);
}
function backupCleanup(string $p):void{
    if(is_dir($p)&&!is_link($p)){
        foreach(new DirectoryIterator($p) as $f)if(!$f->isDot())backupCleanup($f->getPathname());
        rmdir($p);
    }elseif(is_file($p)||is_link($p))unlink($p);
}
$root=sys_get_temp_dir().'/wabridge-backup-'.bin2hex(random_bytes(12));
if(!mkdir($root,0700))throw new RuntimeException('Temp failure');
register_shutdown_function(static function()use($root):void{backupCleanup($root);});
$store=new VNextJsonStore($root);
$a='8aab4000-0000-4000-8000-000000000001';
$b='8aab4000-0000-4000-8000-000000000002';
$store->mutate($a,'studio',static function(array &$items):void{
    $items['sample']=['status'=>'original','version'=>1];
});
$backup=new VNextBackup($store,$root);
$s=$backup->create($a,'studio');
backupCheck($s['revision']===1,'First snapshot version');
$valid=$backup->verify($s['id']);
backupCheck($valid['valid']&&$valid['sha256']===$s['sha256'],'Manifest checksum');
backupCheck((fileperms($root.'/backups/'.$s['id'].'.json')&0777)===0600,
    'Private snapshot permissions');
$store->mutate($a,'studio',static function(array &$items):void{
    $items['sample']=['status'=>'modified','version'=>2];
});
backupReject(fn()=>$backup->restore($s['id'],$a,'studio',1),
    'Optimistic concurrency stale');
backupReject(fn()=>$backup->restore($s['id'],$b,'studio',0),
    'Cross-tenant restore');
$restored=$backup->restore($s['id'],$a,'studio',2);
backupCheck($restored['new_revision']===3,'New revision after restore');
$record=$store->read($a,'studio',static fn(array $items):array=>$items['sample']);
backupCheck($record===['status'=>'original','version'=>1],'Snapshot content restored');
backupReject(fn()=>$backup->restore($s['id'],$a,'studio',2),
    'Cannot replay old CAS restore');
backupReject(fn()=>$backup->verify('../escape'),'Path traversal denied');
$path=$root.'/backups/'.$s['id'].'.json';
$raw=(string)file_get_contents($path);
$altered=str_replace('"original"','"poisoned"',$raw);
backupCheck($altered!==$raw,'Tamper fixture changed file');
file_put_contents($path,$altered);
backupReject(fn()=>$backup->verify($s['id']),'Hash mismatch rejected');
backupReject(fn()=>$backup->restore($s['id'],$a,'studio',3),'Tampered restore rejected');
backupCheck($store->read($a,'studio',static fn(array $items):string=>$items['sample']['status'])
    ==='original','Failed restore cannot mutate');
echo "W1 JSON backup: SHA-256 verification, CAS restore, tenant scope and corruption rejection PASS\n";
