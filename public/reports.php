<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Vary: Cookie');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (!admin_authenticated()) {
    http_response_code(401);
    echo json_encode([
        'error'=>'login_required',
        'message'=>'Open WaBridge, sign in, then retry this page in the same browser.',
        'login_url'=>'/wabridge/'
    ],JSON_UNESCAPED_SLASHES);
    exit;
}
try {
  $report=storage_reports();
  $report['notice']='Message job accepted != WhatsApp delivered. Gateway memory requires external monitoring.';
  echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  error_log('WaBridge reports '.get_class($e));
  http_response_code(503);echo '{"error":"reports_unavailable"}';
}
