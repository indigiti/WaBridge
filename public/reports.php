<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (!admin_authenticated()) { http_response_code(401);echo '{"error":"unauthorized"}';exit; }
try {
  $report=storage_reports();
  $report['notice']='Message job accepted != WhatsApp delivered. Gateway memory requires external monitoring.';
  echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  error_log('WaBridge reports '.get_class($e));
  http_response_code(503);echo '{"error":"reports_unavailable"}';
}
