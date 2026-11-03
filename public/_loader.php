<?php
declare(strict_types=1);
// Resolve private application code for both repository development and DigiOps deployment.
// No secret or source files are copied into the public release payload.
$projectRoot = dirname(__DIR__);
$privateRoot = getenv('WABRIDGE_PRIVATE_ROOT') ?: '';
if ($privateRoot === '') {
    $privateRoot = is_file($projectRoot . '/src/bootstrap.php')
        ? $projectRoot
        : dirname($projectRoot, 2) . '/private_html/' . basename($projectRoot);
}
$bootstrap = rtrim($privateRoot, '/') . '/src/store.php';
if (!is_file($bootstrap)) {
    http_response_code(503);
    error_log('WaBridge: missing private application bootstrap');
    exit('WaBridge is not configured.');
}
require_once $bootstrap;
