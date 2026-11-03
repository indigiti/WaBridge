<?php
declare(strict_types=1);
// Browser-only Waxum preparation test with actual release archive and no process spawning.
$root=dirname(__DIR__);
$cfg=$root.'/config.php';
$settings=$root.'/runtime/gateway-settings.json';
$dir=$root.'/runtime/waxum-provision';
if (is_file($cfg)||is_file($settings)||is_dir($dir))
    throw new RuntimeException('Refusing to run provisioning test over private customer data');
$fixture=['admin_user'=>'testadmin','admin_password_hash'=>'testhash',
    'gateway_url'=>'http://127.0.0.1:3451','gateway_token'=>''];
file_put_contents($cfg,'<?php return '.var_export($fixture,true).';');
register_shutdown_function(static function ()use($cfg,$settings,$dir) {
    @unlink($cfg);@unlink($settings);
    @unlink($dir.'/waxum');@unlink($dir.'/waxum-auth.env');
    @rmdir($dir);
});
require $root.'/src/bootstrap.php';
require $root.'/src/waxum-provisioning.php';
$before=waxum_provision_status();
if (in_array($before['platform_arch'],['amd64','x86_64'],true)
    && !$before['archive_checksum_valid'])
    throw new RuntimeException('Pinned Waxum archive checksum not verified');
$token=waxum_prepare_new_token();
if (!preg_match('/\A[a-f0-9]{64}\z/D',$token))
    throw new RuntimeException('Generated token format invalid');
if (gateway_settings_read()['gateway_token']!==$token)
    throw new RuntimeException('Generated credential not saved in private PHP config');
$env=file_get_contents($dir.'/waxum-auth.env');
if (!str_contains($env,'SUPERADMIN_TOKEN='.$token."\n"))
    throw new RuntimeException('Rust env does not match PHP gateway token');
if (str_contains($env,'DATABASE_URL='))
    throw new RuntimeException('Waxum data backend must not be silently invented');
foreach ([$settings,$dir.'/waxum-auth.env'] as $file) {
    if ((fileperms($file)&0077)!==0)
        throw new RuntimeException('Private credential file permissions too broad');
}
try {
    waxum_prepare_new_token();
    throw new RuntimeException('Blind credential rotation was allowed');
} catch (InvalidArgumentException $expected) {}
if ($before['binary_archive_ready']) {
    $result=waxum_stage_binary();
    if ($result!=='staged') throw new RuntimeException('Pinned browser staging failed');
    if (waxum_stage_binary()!=='already_staged')
        throw new RuntimeException('Staging did not prevent overwriting existing Rust binary');
    if (!waxum_provision_status()['binary_staged'])
        throw new RuntimeException('Executable status not registered');
    $fp=fopen($dir.'/waxum','rb');
    $magic=fread($fp,4);fclose($fp);
    if ($magic!=="\x7FELF") throw new RuntimeException('Staged binary is not an ELF executable');
    if ((fileperms($dir.'/waxum')&0077)!==0)
        throw new RuntimeException('Staged binary permissions too broad');
}
$status=waxum_provision_status();
if ($status['service_manager_configured']!==false||$status['runtime_started_by_dashboard']!==false)
    throw new RuntimeException('Dashboard falsely declared Rust running');
echo "Pinned Waxum archive, private token, web staging, no blind rotation, no unsafe process start: PASS\n";
