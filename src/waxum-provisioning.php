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
        'compat_archive_verified'=>waxum_compat_archive_verified(),
        'compat_binary_installed'=>waxum_compat_installed(),
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
 * Verified compatibility build compiled from Waxum v0.16.1 upstream commit
 * 851bedf3189bda742433ef57bc06e75be590860b inside Ubuntu 20.04.
 * GitHub CI checks that the executable uses no symbol newer than glibc 2.31.
 * The exact tarball digest is pinned both here and in the DigiOps workflow.
 */
function waxum_compat_archive_path(): string {
    return dirname(__DIR__).'/waxum/releases/waxum-v0.16.1-cloudways-glibc231-linux-amd64.tar.gz';
}
function waxum_compat_archive_sha(): string {
    return 'f6e015b58345273893a54d6b1feeacba71cfe8fdc416735bf7f2a93d186d7f8e';
}
function waxum_compat_archive_verified(): bool {
    $path=waxum_compat_archive_path();
    return is_file($path) && !is_link($path) && is_readable($path) &&
        hash_equals(waxum_compat_archive_sha(),(string)hash_file('sha256',$path));
}
function waxum_compat_marker_path(): string {
    return waxum_provision_dir().'/waxum-compat-installed.json';
}
function waxum_compat_installed(): bool {
    $dir=waxum_provision_dir();
    $path=$dir.'/waxum';
    $marker=waxum_compat_marker_path();
    if (is_link($path)||is_link($marker)||!is_file($path)||!is_executable($path)||!is_file($marker))
        return false;
    $raw=file_get_contents($marker);
    if (!is_string($raw)||strlen($raw)>1024) return false;
    $data=json_decode($raw,true);
    return is_array($data) &&
        ($data['source_archive_sha256']??'')===waxum_compat_archive_sha() &&
        is_string($data['binary_sha256']??null) &&
        hash_equals($data['binary_sha256'],(string)hash_file('sha256',$path));
}
function waxum_install_compat_binary(): string {
    if (!in_array(php_uname('m'),['x86_64','amd64'],true))
        throw new RuntimeException('This compatibility binary is only for Linux AMD64');
    if (!waxum_compat_archive_verified())
        throw new RuntimeException('Cloudways compatibility archive missing or checksum invalid');
    if (!class_exists('PharData'))
        throw new RuntimeException('PHP archive extraction is unavailable');
    $root=dirname(__DIR__).'/runtime';
    $dir=waxum_provision_dir();
    if (is_link($root)||is_link($dir)||!is_dir($root)||!is_dir($dir))
        throw new RuntimeException('Private runtime directory is unavailable');
    $target=$dir.'/waxum';
    $backup=$dir.'/waxum.previous-incompatible.bak';
    if (is_link($target)||is_link($backup)||is_link(waxum_compat_marker_path()))
        throw new RuntimeException('Executable backup or marker symlink prohibited');
    if (waxum_compat_installed()) return 'already_installed';
    $scratch=$dir.'/compat-extract-'.bin2hex(random_bytes(8));
    if (!mkdir($scratch,0700)) throw new RuntimeException('Cannot create private executable staging area');
    try {
        $tar=new PharData(waxum_compat_archive_path());
        if (!isset($tar['waxum']) || !$tar['waxum']->isFile())
            throw new RuntimeException('Verified compatibility archive does not contain Waxum');
        $tar->extractTo($scratch,['waxum'],false);
        $src=$scratch.'/waxum';
        if (is_link($src)||!is_file($src)||filesize($src)<100000||filesize($src)>104857600)
            throw new RuntimeException('Compatibility executable invalid');
        $fh=fopen($src,'rb');
        if ($fh===false) throw new RuntimeException('Cannot examine compatibility executable');
        try { $magic=fread($fh,4); } finally { fclose($fh); }
        if ($magic!=="\x7FELF") throw new RuntimeException('Compatibility executable is not ELF');
        if (!chmod($src,0700)) throw new RuntimeException('Cannot protect executable');
        // Keep the previously deployed executable as a non-executable
        // private recovery copy, never touching Waxum session storage.
        if (is_file($target)&&!is_file($backup)) {
            $tmpBackup=$scratch.'/previous.bak';
            if (!copy($target,$tmpBackup)||!chmod($tmpBackup,0600)||!rename($tmpBackup,$backup))
                throw new RuntimeException('Unable to back up previous Rust executable');
        }
        $binaryHash=(string)hash_file('sha256',$src);
        if (!rename($src,$target))
            throw new RuntimeException('Unable to atomically replace Rust executable');
        $metadata=json_encode([
            'upstream_version'=>'v0.16.1',
            'upstream_commit'=>'851bedf3189bda742433ef57bc06e75be590860b',
            'glibc_max'=>'2.31',
            'source_archive_sha256'=>waxum_compat_archive_sha(),
            'binary_sha256'=>$binaryHash,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        waxum_atomic_private_write(waxum_compat_marker_path(),$metadata);
        return 'installed';
    } finally {
        foreach (['waxum','previous.bak'] as $name) {
            if (is_file($scratch.'/'.$name)) @unlink($scratch.'/'.$name);
        }
        @rmdir($scratch);
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
