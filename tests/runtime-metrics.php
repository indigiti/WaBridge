<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/runtime-metrics.php';
$tmp=sys_get_temp_dir().'/wabridge-metrics-'.bin2hex(random_bytes(6));
$dirs=['runtime/waxum-provision','runtime/waxum-data/whatsapp_sessions','runtime/json'];
foreach($dirs as $dir) if(!is_dir($tmp.'/'.$dir)&&!mkdir($tmp.'/'.$dir,0700,true))throw new RuntimeException('Cannot create fixture');
register_shutdown_function(static function()use($tmp){
    foreach(['runtime/waxum-provision/waxum','runtime/waxum-provision/not-ours','runtime/json/state.json'] as $file)@unlink($tmp.'/'.$file);
    @unlink($tmp.'/runtime/waxum-provision/ignored-link');
    foreach(array_reverse(['runtime','runtime/waxum-provision','runtime/waxum-data','runtime/waxum-data/whatsapp_sessions','runtime/json']) as $dir)@rmdir($tmp.'/'.$dir);
    @rmdir($tmp);
});
file_put_contents($tmp.'/runtime/waxum-provision/waxum',str_repeat('z',256));
file_put_contents($tmp.'/runtime/json/state.json',str_repeat('j',32));
if(function_exists('symlink')) @symlink($tmp.'/runtime/json/state.json',$tmp.'/runtime/waxum-provision/ignored-link');
$s=runtime_metrics_snapshot($tmp);
if($s['disk']['runtime_binary']['bytes']!==256 || $s['disk']['runtime_binary']['files']!==1)
    throw new RuntimeException('Invalid Rust private file accounting');
if($s['disk']['php_json']['bytes']!==32)
    throw new RuntimeException('PHP JSON disk accounting failed');
if($s['rust']['available']!==false)
    throw new RuntimeException('No Rust process should be inferred from dummy fixture');
if($s['disk']['whatsapp_sessions']['available']!==true)
    throw new RuntimeException('WhatsApp private directory must be found');
if(array_key_exists('environment',$s['rust'])||array_key_exists('command_line',$s['rust']))
    throw new RuntimeException('Sensitive process fields in telemetry');
$t=runtime_directory_usage($tmp.'/runtime/waxum-provision',0);
if($t['truncated']!==true)throw new RuntimeException('Unbounded filesystem walking');
echo "Safe read-only Waxum process telemetry, private disk accounting and symlink exclusion: PASS\n";
