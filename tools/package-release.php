<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$release=$root.'/release';
if (!is_dir($root.'/public') || !is_file($root.'/public/index.php')) {
    fwrite(STDERR,"Missing WaBridge public entrypoint\n");
    exit(1);
}
function copyDirectory(string $from,string $to): void {
    if (!is_dir($to) && !mkdir($to,0755,true) && !is_dir($to)) throw new RuntimeException('mkdir failed');
    foreach (new DirectoryIterator($from) as $entry) {
        if ($entry->isDot()) continue;
        $name=$entry->getFilename();
        if (in_array($name,['.git','.env','config.php','runtime','release'],true)) continue;
        // Preserve vendored browser-only QR renderer in public assets. Never
        // package server-side Composer/vendor dependencies or credentials.
        if ($name==='vendor' && !str_ends_with(str_replace('\\','/',$from),'/public/assets')) continue;
        $src=$entry->getPathname();$dest=$to.'/'.$name;
        if ($entry->isLink()) throw new RuntimeException('Symlink prohibited in release: '.$name);
        if ($entry->isDir()) copyDirectory($src,$dest);
        elseif ($entry->isFile()) {
            if (!copy($src,$dest)) throw new RuntimeException('Unable to copy '.$src);
        }
    }
}
function removeDirectory(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new DirectoryIterator($dir) as $entry) {
        if ($entry->isDot()) continue;
        $path=$entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) removeDirectory($path);
        else unlink($path);
    }
    rmdir($dir);
}
removeDirectory($release);
copyDirectory($root.'/public',$release.'/public');
foreach (['src','cli'] as $folder) copyDirectory($root.'/'.$folder,$release.'/private/'.$folder);
copy($root.'/config.example.php',$release.'/private/config.example.php');
$waxumArchive=$root.'/waxum/releases/waxum-v0.16.1-linux-amd64.tar.gz';
if (is_file($waxumArchive)) {
    $waxumTarget=$release.'/private/waxum/releases';
    if (!is_dir($waxumTarget) && !mkdir($waxumTarget,0755,true) && !is_dir($waxumTarget))
        throw new RuntimeException('Cannot create private upstream Waxum release folder');
    if (!copy($waxumArchive,$waxumTarget.'/waxum-v0.16.1-linux-amd64.tar.gz'))
        throw new RuntimeException('Cannot package verified private Waxum archive');
}

// Runtime config.php, SQLite, session secrets, logs and customer data are deliberately excluded.
$metadata=[
    'schema'=>'WABRIDGE-BUILD/1',
    'name'=>'WaBridge',
    'sourceSha'=>getenv('GITHUB_SHA') ?: 'local',
    'branch'=>getenv('GITHUB_REF_NAME') ?: 'local',
    'ciRunId'=>getenv('GITHUB_RUN_ID') ?: '',
    'ciRunAttempt'=>getenv('GITHUB_RUN_ATTEMPT') ?: '',
    'builtAt'=>gmdate('c'),
    'public'=>'public',
    'private'=>'private',
];
if (!is_dir($release.'/private/build')) mkdir($release.'/private/build',0755,true);
file_put_contents($release.'/private/build/release.json',json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo "WaBridge DigiOps release packaged\n";
