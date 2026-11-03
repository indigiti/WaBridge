<?php
declare(strict_types=1);

/**
 * Browser preparation only: a process must be started/supervised by a
 * Cloudways-approved service manager. This module intentionally cannot
 * spawn executables or run arbitrary commands in a public PHP request.
 */
function waxum_provision_dir(): string {
    return dirname(__DIR__).'/runtime/waxum-provision';
}
function waxum_provision_status(): array {
    $manifest=dirname(__DIR__).'/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
    $arch=php_uname('m');
    $archiveExists=is_file($manifest);
    $expected='2a8d5d9f89f600a413854b8823db3f9892e26c81ff876398716b56d21e863a61';
    $verified=$archiveExists && is_readable($manifest) && hash_file('sha256',$manifest)===$expected;
    $env=waxum_provision_dir().'/waxum-auth.env';
    return [
        'platform_arch'=>$arch,
        'bundle_version'=>'v0.16.1',
        'binary_archive_ready'=>$verified && in_array($arch,['x86_64','amd64'],true),
        'archive_checksum_valid'=>$verified,
        'auth_env_prepared'=>is_file($env)&&is_readable($env)&&!is_link($env),
        'service_manager_configured'=>false,
        'runtime_started_by_dashboard'=>false,
    ];
}
function waxum_atomic_private_write(string $filename,string $contents): void {
    $dir=waxum_provision_dir();
    $root=dirname(__DIR__).'/runtime';
    if (is_link($root) || is_link($dir) || is_link($filename))
        throw new RuntimeException('Symlink prohibited in private provisioning directory');
    if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root))
        throw new RuntimeException('Private runtime is unavailable');
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))
        throw new RuntimeException('Private Waxum provisioning directory unavailable');
    @chmod($dir,0700);
    $tmp=tempnam($dir,'.env-');
    if ($tmp===false) throw new RuntimeException('Unable to prepare temporary provisioning file');
    try {
        if (!chmod($tmp,0600)) throw new RuntimeException('Unable to protect provisioning file');
        if (file_put_contents($tmp,$contents,LOCK_EX)!==strlen($contents))
            throw new RuntimeException('Unable to write provisioning file');
        if (!rename($tmp,$filename)) throw new RuntimeException('Unable to install provisioning file');
        @chmod($filename,0600);
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}
/**
 * Generate a one-time server-side token for a not-yet-connected Waxum service.
 * Refuse blind rotation: an existing running service must be coordinated
 * separately to avoid disconnecting production messaging.
 */
function waxum_prepare_new_token(): string {
    if (gateway_settings_read()!==null || (string)(config()['gateway_token']??'')!=='') {
        throw new InvalidArgumentException('A gateway token already exists. Do not rotate a live Rust secret without service-manager coordination.');
    }
    $dir=waxum_provision_dir();
    $env=$dir.'/waxum-auth.env';
    if (is_file($env)||is_link($env))
        throw new RuntimeException('A private Rust provisioning file already exists');
    $token=bin2hex(random_bytes(32));
    $jwt=bin2hex(random_bytes(48));
    // Do NOT set DATABASE_URL: upstream Waxum's own data backend requires a
    // separately approved, tested configuration (it may default to SQLite).
    $contents="# WaBridge provisioning secrets. Load only through an approved server-side service manager.\n".
        "# Do not publish, serve, download or commit this file.\n".
        "SUPERADMIN_TOKEN=".$token."\n".
        "JWT_SECRET=".$jwt."\n".
        "PORT=3451\n";
    waxum_atomic_private_write($env,$contents);
    try {
        gateway_settings_save($token);
    } catch (Throwable $e) {
        @unlink($env); // do not leave a credential the PHP gateway cannot use
        throw $e;
    }
    return $token;
}
