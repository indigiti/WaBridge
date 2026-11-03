<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/_loader.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function batch_reply(int $status,array $data): never { http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit; }
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { header('Allow: POST');batch_reply(405,['error'=>'method_not_allowed']); }
try {
    $client=client_auth();
    if (!$client) batch_reply(401,['error'=>'unauthorized']);
    if ((config()['enable_batches']??false)!==true) batch_reply(403,['error'=>'batches_disabled','message'=>'Enable explicitly after validating recipient consent controls']);
    if (!gateway_diagnostics()['ready']) batch_reply(503,['error'=>'gateway_offline']);
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>32768) batch_reply(413,['error'=>'payload_too_large']);
    $raw=file_get_contents('php://input',false,null,0,32769);
    if (!is_string($raw)||strlen($raw)>32768) batch_reply(413,['error'=>'payload_too_large']);
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($input)||array_is_list($input)) throw new InvalidArgumentException('Object required');
    $items=$input['messages']??null;
    if (!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>10) throw new InvalidArgumentException('Batch supports 1 to 10 recipients');
    $prefix=(string)($_SERVER['HTTP_IDEMPOTENCY_KEY']??'');
    if (!preg_match('/\A[a-zA-Z0-9._:-]{8,80}\z/D',$prefix)) throw new InvalidArgumentException('Idempotency-Key required');
    $normalized=[];
    foreach ($items as $i=>$item) {
        if (!is_array($item)) throw new InvalidArgumentException('Invalid message item');
        [$sid,$to,$body]=validate_message($item);
        if (!in_array($sid,$client['sessions'],true)) throw new InvalidArgumentException('Unauthorized session');
        $consentRef=(string)($item['consent_ref']??'');
        $consentAt=(int)($item['consent_at']??0);
        if (!preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D',$consentRef) || $consentAt<time()-365*86400 || $consentAt>time()) {
            throw new InvalidArgumentException('Recorded recipient consent reference and valid timestamp required');
        }
        $normalized[]=[$sid,$to,$body,$prefix.'-'.str_pad((string)$i,3,'0',STR_PAD_LEFT)];
    }
    $results=[];
    foreach ($normalized as [$sid,$to,$body,$idem]) {
        if (!limit_client(store(),$client)) {
            header('Retry-After: 60');
            batch_reply(207,['results'=>$results,'error'=>'rate_limit_reached','queued'=>count($results)]);
        }
        $results[]=enqueue_message(store(),$client,$sid,$to,$body,$idem);
    }
    audit_event('batch_queued',$client['id'],'', ['count'=>count($results)]);
    batch_reply(202,['status'=>'queued','results'=>$results,'message'=>'Queued, not WhatsApp-delivered']);
} catch (JsonException|InvalidArgumentException $e) {
    batch_reply(422,['error'=>'invalid_request','detail'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('WaBridge batch error '.get_class($e));
    batch_reply(503,['error'=>'service_unavailable']);
}
