<?php
declare(strict_types=1);
// This file is installed at public_html/wabridge/_loader.php.
// Private code lives at private_html/wabridge/src/store.php.
// Do not copy credentials or private source files into the public directory.

$publicApplicationDir=__DIR__;
$projectRoot=dirname(__DIR__); // Git checkout root, not the deployed application slug.
$slug=basename($publicApplicationDir);
$explicit=trim((string)(getenv('WABRIDGE_PRIVATE_ROOT')?:''));
$documentRoot=(string)($_SERVER['DOCUMENT_ROOT']??'');
$documentRootReal=$documentRoot!=='' ? realpath($documentRoot) : false;

$candidates=[];
if ($explicit!=='') $candidates[]=['environment',rtrim($explicit,'/')];
if (is_file($projectRoot.'/src/store.php')) $candidates[]=['checkout',$projectRoot];

// DigiOps remote-agent appHome() is dirname(realpath(DOCUMENT_ROOT)).
// Resolve its private target directly, without assuming the URL's virtual path
// matches the server document root.
if ($documentRootReal!==false) {
    $candidates[]=['document-root',dirname($documentRootReal).'/private_html/'.$slug];
}
// Standard Cloudways checkout: /applications/APP/public_html/wabridge
$candidates[]=['sibling-public-private',dirname($publicApplicationDir,2).'/private_html/'.$slug];
// Alternative PHP FPM mounts in which private_html is adjacent to public_html.
$candidates[]=['adjacent',dirname($projectRoot).'/private_html/'.$slug];

$found=null;
$seen=[];
$checks=[];
foreach ($candidates as [$label,$directory]) {
    $directory=rtrim($directory,'/');
    if(isset($seen[$directory])) continue;
    $seen[$directory]=true;
    $source=$directory.'/src/store.php';
    $checks[$label]=[
        'directory_exists'=>@is_dir($directory),
        'source_exists'=>@is_file($source),
        'readable'=>@is_readable($source),
    ];
    if ($checks[$label]['readable']) {
        $found=$source;
        break;
    }
}

if ($found===null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $restriction=ini_get('open_basedir');
    $hasPrivateDirectory=false;
    $hasSource=false;
    foreach ($checks as $check) {
        $hasPrivateDirectory=$hasPrivateDirectory||$check['directory_exists'];
        $hasSource=$hasSource||$check['source_exists'];
    }
    $code=$restriction!==''?'PRIVATE_PATH_ACCESS_OR_MISSING':($hasPrivateDirectory?'PRIVATE_SOURCE_MISSING':'PRIVATE_DIRECTORY_MISSING');
    error_log('WaBridge '.$code.'; candidates='.json_encode($checks).' PHP_open_basedir_enabled='.($restriction!==''?'yes':'no'));
    // Only categorical health information is disclosed to visitors.
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WaBridge setup</title><style>body{font:16px system-ui,sans-serif;max-width:620px;margin:12vh auto;padding:20px;color:#152238}h1{margin-bottom:8px}p{line-height:1.5;color:#526174}.label{background:#f1f5f9;border-radius:8px;padding:12px;font-family:monospace}</style>';
    echo '<h1>WaBridge needs runtime setup</h1><p>The public release is installed, but the private application source is not accessible to PHP.</p>';
    echo '<p class="label">'.htmlspecialchars($code,ENT_QUOTES,'UTF-8').'</p>';
    echo '<p>Verify that DigiOps deployed <code>private/src/store.php</code> to the configured private folder and that PHP can read it. Check the PHP error log for the detailed candidate checks. Do not move private source into the public folder.</p></html>';
    exit;
}
require_once $found;
