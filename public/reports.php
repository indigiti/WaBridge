<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (!admin_authenticated()) { http_response_code(401);echo '{"error":"unauthorized"}';exit; }
try {
  $db=store();
  $jobs=$db->query('SELECT state,COUNT(*) AS count FROM jobs GROUP BY state')->fetchAll();
  $receipts=$db->query('SELECT state,COUNT(*) AS count FROM message_receipts GROUP BY state')->fetchAll();
  $events=$db->query('SELECT event_type,COUNT(*) AS count FROM events GROUP BY event_type ORDER BY count DESC LIMIT 20')->fetchAll();
  $clients=$db->query('SELECT id,active,per_minute,expires_at,last_used_at FROM api_clients ORDER BY id LIMIT 100')->fetchAll();
  $audits=$db->query('SELECT event,actor,target,at FROM audit_entries ORDER BY id DESC LIMIT 50')->fetchAll();
  $filename=dirname(__DIR__).'/runtime/wabridge.sqlite';
  echo json_encode([
    'jobs'=>$jobs,'receipts'=>$receipts,'event_types'=>$events,
    'api_clients'=>$clients,'audit'=>$audits,
    'storage'=>['sqlite_bytes'=>is_file($filename)?filesize($filename):0],
    'notice'=>'Message job accepted != WhatsApp delivered. Gateway memory requires external monitoring.'
  ],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  error_log('WaBridge reports '.get_class($e));
  http_response_code(503);echo '{"error":"reports_unavailable"}';
}
