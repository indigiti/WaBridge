<?php
declare(strict_types=1);
// Test filesystem isolation and the private, atomic gateway settings lifecycle.
// Never touch an existing deployment's configuration or secrets.
$root=dirname(__DIR__);
$settingsPath=$root.'/runtime/gateway-settings.json';
$configPath=$root.'/config.php';
if (file_exists($settingsPath) || file_exists($configPath)) {
    fwrite(STDERR,"Refusing to test over existing runtime configuration.\n");
    exit(2);
}
require $root.'/src/gateway-settings.php';
function assert_same(mixed $actual,mixed $expected,string $message): void {
    if ($actual!==$expected) throw new RuntimeException($message);
}
register_shutdown_function(static function() use($settingsPath,$configPath) {
    @unlink($settingsPath);
    @unlink($configPath);
});
foreach (['','short-token','with spaces '.str_repeat('x',32),str_repeat('a',257)] as $invalid) {
    try {
        gateway_settings_save($invalid);
        throw new RuntimeException('Invalid gateway secret accepted');
    } catch (InvalidArgumentException $e) {}
}
assert_same(gateway_settings_read(),null,'Missing settings should return null');
$first=str_repeat('A',40);
$second=str_repeat('B',40);
gateway_settings_save($first);
assert_same(gateway_settings_read()['gateway_token'],$first,'Saved token should be read');
assert_same(gateway_settings_read()['gateway_url'],'http://127.0.0.1:3451','Local-only endpoint mismatch');
$mode=fileperms($settingsPath)&0777;
if (($mode & 0077)!==0) throw new RuntimeException('Gateway secret file permissions too broad');
gateway_settings_save($second);
assert_same(gateway_settings_read()['gateway_token'],$second,'Token rotation failed');
file_put_contents($configPath,'<?php return '.var_export([
    'gateway_url'=>'http://127.0.0.1:3451',
    'gateway_token'=>'',
    'admin_user'=>'testadmin',
    'admin_password_hash'=>'testhash'
],true).';');
require $root.'/src/bootstrap.php';
assert_same(config()['gateway_token'],$second,'Application config should apply private web override');
gateway_settings_clear();
assert_same(gateway_settings_read(),null,'Reset should remove override');
echo "Web-only private gateway settings and token rotation: PASS\n";
