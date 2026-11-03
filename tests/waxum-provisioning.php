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
    @unlink($dir.'/waxum');@unlink($dir.'/waxum-auth.env');@unlink($dir.'/waxum-db.env');
    @unlink($dir.'/waxum-compat-installed.json');@unlink($dir.'/waxum.previous-incompatible.bak');
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
// Cloudways compatibility archive must be source- and checksum-pinned and
// replacing a stopped Rust binary must preserve the previous executable.
if (!waxum_compat_archive_verified())
    throw new RuntimeException('Missing or unverified Cloudways-compatible Waxum archive');
if (waxum_compat_installed())
    throw new RuntimeException('Compatibility marker incorrectly claimed a legacy binary');
$previousHash=(string)hash_file('sha256',$dir.'/waxum');
if (waxum_install_compat_binary()!=='installed')
    throw new RuntimeException('Could not install Cloudways compatible Rust binary');
if (!waxum_compat_installed())
    throw new RuntimeException('Cloudways-compatible marker does not match staged executable');
if (waxum_install_compat_binary()!=='already_installed')
    throw new RuntimeException('Repeated compatible installation did not preserve existing state');
if (!is_file($dir.'/waxum.previous-incompatible.bak') ||
    hash_file('sha256',$dir.'/waxum.previous-incompatible.bak')!==$previousHash)
    throw new RuntimeException('Incompatible Waxum executable backup missing or incorrect');
if ((fileperms($dir.'/waxum.previous-incompatible.bak')&0077)!==0)
    throw new RuntimeException('Old binary backup not private');
if ((fileperms($dir.'/waxum-compat-installed.json')&0077)!==0)
    throw new RuntimeException('Compatibility receipt not private');
if (gateway_settings_read()['gateway_token']!==$token)
    throw new RuntimeException('Rust replacement changed the existing SUPERADMIN_TOKEN');
// Rust-only SQLite test storage must require one explicit browser opt-in.
if (waxum_test_database_mode()!=='not_configured')
    throw new RuntimeException('Rust database mode was silently enabled');
if (waxum_enable_test_sqlite()!=='enabled')
    throw new RuntimeException('Could not enable explicit Rust testing database');
if (waxum_enable_test_sqlite()!=='already_enabled')
    throw new RuntimeException('Repeat explicit test-mode action must be idempotent');
if (waxum_test_database_mode()!=='sqlite_testing')
    throw new RuntimeException('Rust-only SQLite test mode not reported');
$dbEnv=$dir.'/waxum-db.env';
$expected='DATABASE_URL='.waxum_test_database_url()."\n";
if (!str_contains((string)file_get_contents($dbEnv),$expected))
    throw new RuntimeException('Incorrect fixed private Rust metadata database URL');
if ((fileperms($dbEnv)&0077)!==0)
    throw new RuntimeException('Private SQLite opt-in file permissions too broad');
file_put_contents($dbEnv,"DATABASE_URL=mysql://private-config-not-to-be-replaced\n");
clearstatcache(true,$dbEnv);
try {
    waxum_enable_test_sqlite();
    throw new RuntimeException('Custom private Rust database settings were overwritten');
} catch (InvalidArgumentException $expected) {}
if (!str_contains((string)file_get_contents($dbEnv),'private-config-not-to-be-replaced'))
    throw new RuntimeException('Existing private database configuration was corrupted');
@unlink($dbEnv);
$status=waxum_provision_status();
if ($status['service_manager_configured']!==false||$status['runtime_started_by_dashboard']!==false)
    throw new RuntimeException('Dashboard falsely declared Rust running');
echo "Pinned Waxum archive, private token, web staging, no blind rotation, no unsafe process start: PASS\n";
