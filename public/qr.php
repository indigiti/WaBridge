<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
if (!admin_authenticated()) { http_response_code(403); exit('Forbidden'); }
$sid=(string)($_GET['sid']??'');
if (!permitted_session($sid)) { http_response_code(400); exit('Invalid session'); }
try {
    // Waxum QR PNG endpoint. This endpoint intentionally never serves a public, static QR.
    $cfg=config();
    $base=rtrim((string)$cfg['gateway_url'],'/');
    if (!preg_match('~\Ahttps?://(?:127\.0\.0\.1|localhost|\[::1\]|[a-zA-Z0-9.-]+)(?::[0-9]{2,5})?\z~D',$base)) throw new RuntimeException('Invalid gateway URL');
    $ch=curl_init($base.'/api/v1/sessions/'.rawurlencode($sid).'/qr');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$cfg['gateway_token'],'Accept: image/png'],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    $data=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($status!==200 || !is_string($data) || strlen($data)>524288 || substr($data,0,8)!=="\x89PNG\r\n\x1a\n" || !str_starts_with(strtolower($type),'image/png')) throw new RuntimeException('QR not available');
    header('Content-Type: image/png');
    echo $data;
} catch (Throwable $e) { http_response_code(502); header('Content-Type: text/plain'); echo 'QR is not available yet. Refresh after creating a session.'; }
