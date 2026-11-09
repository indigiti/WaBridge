<?php
declare(strict_types=1);
// Optional synthetic-event ingress for provider-free staging tests ONLY.
// This cannot route to real WhatsApp and is disabled in example configuration.
require dirname(__DIR__,2).'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/vnext/signed-ingress.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function vnext_fixture_reply(int $code,array $data):never{
    http_response_code($code);
    echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    exit;
}
$cfg=config();
if(($cfg['enable_vnext_fixture_webhook']??false)!==true){
    vnext_fixture_reply(404,['error'=>'not_found']);
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    header('Allow: POST');
    vnext_fixture_reply(405,['error'=>'method_not_allowed']);
}
$secret=(string)($cfg['vnext_fixture_webhook_secret']??'');
if(strlen($secret)<32)vnext_fixture_reply(503,['error'=>'not_configured']);
if((int)($_SERVER['CONTENT_LENGTH']??0)>8192){
    vnext_fixture_reply(413,['error'=>'payload_too_large']);
}
$raw=file_get_contents('php://input',false,null,0,8193);
if(!is_string($raw)||strlen($raw)>8192)vnext_fixture_reply(413,['error'=>'payload_too_large']);
if(($_SERVER['HTTP_X_WEBHOOK_SIGNATURE_VERSION']??'')!=='v2'){
    vnext_fixture_reply(401,['error'=>'signature_version_invalid']);
}
try{
    $store=new VNextJsonStore();
    $result=(new VNextSignedIngress(new VNextChannels($store),new VNextEventJournal($store)))
        ->ingest($raw,
            (string)($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP']??''),
            (string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']??''),$secret);
    vnext_fixture_reply(202,['accepted'=>true,'duplicate'=>$result['duplicate']]);
}catch(JsonException|InvalidArgumentException $e){
    vnext_fixture_reply(401,['error'=>'invalid_or_unauthorized_event']);
}catch(Throwable $e){
    error_log('WaBridge vNext synthetic ingress: '.get_class($e));
    vnext_fixture_reply(503,['error'=>'event_storage_unavailable']);
}
