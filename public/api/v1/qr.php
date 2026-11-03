<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/_loader.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { http_response_code(405);echo '{"error":"method_not_allowed"}';exit; }
    $client=client_auth();
    if (!$client) { http_response_code(401);echo '{"error":"unauthorized"}';exit; }
    $id=(string)($_GET['session_id']??'');
    if (!valid_session_id($id)||!in_array($id,$client['sessions'],true)) { http_response_code(403);echo '{"error":"session_not_assigned"}';exit; }
    if (!limit_client(store(),$client)) { http_response_code(429);echo '{"error":"rate_limited"}';exit; }
    $r=gateway('GET','/api/v1/sessions/'.$id.'/qr');
    http_response_code($r['http']);
    echo json_encode($r['data'],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('WaBridge QR endpoint: '.get_class($e));
    http_response_code(503);echo '{"error":"service_unavailable"}';
}
