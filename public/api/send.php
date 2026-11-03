<?php
declare(strict_types=1);
require dirname(__DIR__).'/_loader.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
function respond(int $code, array $body): never {
    http_response_code($code); echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { header('Allow: POST'); respond(405,['error'=>'method_not_allowed']); }
try {
    $client=client_auth();
    if (!$client) respond(401,['error'=>'unauthorized']);
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>8192) respond(413,['error'=>'payload_too_large']);
    $raw=file_get_contents('php://input',false,null,0,8193);
    if (!is_string($raw)||strlen($raw)>8192) respond(413,['error'=>'payload_too_large']);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($input)||array_is_list($input)) throw new InvalidArgumentException('JSON object required');
    [$sid,$to,$text]=validate_message($input);
    $idem=(string)($_SERVER['HTTP_IDEMPOTENCY_KEY']??'');
    if (!limit_client(store(),$client)) { header('Retry-After: 60'); respond(429,['error'=>'rate_limited']); }
    $job=enqueue_message(store(),$client,$sid,$to,$text,$idem);
    respond(202,['job_id'=>$job['id'],'status'=>$job['state'],'duplicate'=>$job['duplicate']]);
} catch (JsonException|InvalidArgumentException $e) {
    respond(422,['error'=>'invalid_request','detail'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('WaBridge enqueue error '.get_class($e));
    respond(503,['error'=>'service_unavailable']);
}
