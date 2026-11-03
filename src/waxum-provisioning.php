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
        'binary_staged'=>is_file(waxum_provision_dir().'/waxum') && is_executable(waxum_provision_dir().'/waxum') && !is_link(waxum_provision_dir().'/waxum'),
        'database_mode'=>waxum_test_database_mode(),
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
 * Explicit Rust-only testing mode. WaBridge PHP stays JSON-only, while
 * Waxum's metadata and linked-device state are stored in private SQLite
 * files. There is no managed database to configure for this test mode.
 */
function waxum_test_database_env(): string {
    return waxum_provision_dir().'/waxum-db.env';
}
function waxum_test_database_url(): string {
    return 'sqlite://'.dirname(__DIR__).'/runtime/waxum-data/waxum.db';
}
function waxum_test_database_mode(): string {
    $file=waxum_test_database_env();
    if (is_link($file)) throw new RuntimeException('Database environment symlink prohibited');
    if (!is_file($file)) return 'not_configured';
    if (!is_readable($file) || filesize($file)>4096)
        throw new RuntimeException('Invalid private database environment file');
    $raw=file_get_contents($file);
    if (!is_string($raw)) throw new RuntimeException('Unreadable private database environment file');
    $expected="# Waxum private metadata storage — TESTING ONLY. Not MariaDB.\n".
        "DATABASE_URL=".waxum_test_database_url()."\n";
    return hash_equals($expected,$raw) ? 'sqlite_testing' : 'custom_private';
}
function waxum_enable_test_sqlite(): string {
    $mode=waxum_test_database_mode();
    if ($mode==='sqlite_testing') return 'already_enabled';
    if ($mode!=='not_configured')
        throw new InvalidArgumentException('A private Rust database configuration already exists. Refusing to overwrite it.');
    $url=waxum_test_database_url();
    if (!str_starts_with($url,'sqlite:///'))
        throw new RuntimeException('Private Rust SQLite path is invalid');
    $contents="# Waxum private metadata storage — TESTING ONLY. Not MariaDB.\n".
        "DATABASE_URL=".$url."\n";
    waxum_atomic_private_write(waxum_test_database_env(),$contents);
    return 'enabled';
}
/**
 * Extract the fixed verified Linux AMD64 Waxum release into a private
 * application directory. This does NOT start the process or modify
 * firewall/supervisor settings. Failure leaves the old binary unchanged.
 */
function waxum_stage_binary(): string {
    $status=waxum_provision_status();
    if (!$status['binary_archive_ready'])
        throw new RuntimeException('Verified Linux AMD64 Waxum archive is unavailable for this server architecture');
    if (!class_exists('PharData'))
        throw new RuntimeException('Cloudways PHP PharData archive support is unavailable');
    $dir=waxum_provision_dir();
    $runtime=dirname(__DIR__).'/runtime';
    if (is_link($runtime) || is_link($dir)) throw new RuntimeException('Private staging symlink prohibited');
    if (!is_dir($runtime) && !mkdir($runtime,0700,true) && !is_dir($runtime))
        throw new RuntimeException('Private runtime directory unavailable');
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))
        throw new RuntimeException('Private Waxum staging directory unavailable');
    @chmod($dir,0700);
    $destination=$dir.'/waxum';
    if (is_link($destination)) throw new RuntimeException('Binary symlink prohibited');
    if (is_file($destination)) return 'already_staged';
    $archive=dirname(__DIR__).'/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
    $temp=$dir.'/extract-'.bin2hex(random_bytes(8));
    if (!mkdir($temp,0700,true)) throw new RuntimeException('Unable to create private staging directory');
    try {
        $tar=new PharData($archive);
        if (!isset($tar['waxum']) || !$tar['waxum']->isFile())
            throw new RuntimeException('Verified Waxum archive has no executable file');
        $tar->extractTo($temp,['waxum'],false);
        $source=$temp.'/waxum';
        if (is_link($source)||!is_file($source)||filesize($source)>104857600||filesize($source)<100000)
            throw new RuntimeException('Invalid Waxum executable');
        $fh=fopen($source,'rb');
        if ($fh===false) throw new RuntimeException('Unreadable extracted executable');
        try { $magic=fread($fh,4); } finally { fclose($fh); }
        if ($magic!=="\x7FELF") throw new RuntimeException('Waxum executable is not an ELF binary');
        if (!chmod($source,0700)) throw new RuntimeException('Unable to set executable permissions');
        if (!rename($source,$destination)) throw new RuntimeException('Unable to stage Waxum executable');
        return 'staged';
    } finally {
        if (is_file($temp.'/waxum')) @unlink($temp.'/waxum');
        @rmdir($temp);
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
