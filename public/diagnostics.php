<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!admin_authenticated()) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }
try {
    $db=storage_health();
    $gateway=gateway_diagnostics();
    http_response_code($db?200:503);
    echo json_encode([
        'php'=>['ready'=>true,'version'=>PHP_VERSION],
        'storage'=>['ready'=>$db,'driver'=>storage_mode(),'testing_only'=>storage_mode()==='json'],
        'rust'=>$gateway,
        'sessions'=>count(upstream_sessions()),
        'capabilities'=>engine_capabilities(),
        'timestamp'=>gmdate('c')
    ],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('WaBridge diagnostics: '.get_class($e));
    http_response_code(503);
    echo json_encode(['error'=>'diagnostics_unavailable']);
}
