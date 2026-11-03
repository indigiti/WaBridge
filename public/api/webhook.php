<?php
declare(strict_types=1);
require dirname(__DIR__).'/_loader.php';
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { http_response_code(405);exit; }
$secret=(string)(config()['webhook_secret']??'');
if (strlen($secret)<32) { http_response_code(503);echo '{"error":"not_configured"}';exit; }
if ((int)($_SERVER['CONTENT_LENGTH']??0)>65536) { http_response_code(413);exit; }
$raw=file_get_contents('php://input',false,null,0,65537);
if (!is_string($raw)||strlen($raw)>65536) { http_response_code(413);exit; }
$signature=(string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']??'');
$version=(string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE_VERSION']??'');
$timestamp=(string)($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP']??'');
if ($version!=='v2' || !preg_match('/\A[0-9]{10,13}\z/D',$timestamp) || abs(time()-(int)$timestamp)>300) {
    http_response_code(401);echo '{"error":"stale_or_unsupported_signature"}';exit;
}
$expected='sha256='.hash_hmac('sha256',$timestamp.'.'.$raw,$secret);
if (!hash_equals($expected,$signature)) { http_response_code(401);echo '{"error":"invalid_signature"}';exit; }
try {
    $event=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
    if (!is_array($event)) throw new InvalidArgumentException('Invalid body');
    $sid=(string)($event['session_id']??$event['session']??'');
    $type=substr((string)($event['event']??$event['type']??'unknown'),0,80);
    $eventId=(string)($event['id']??'');
    if (!preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D',$eventId)) $eventId=hash('sha256',$raw);
    $q=store()->prepare('INSERT OR IGNORE INTO events(id,session_id,event_type,received_at,payload) VALUES(?,?,?,?,?)');
    $q->execute([$eventId,substr($sid,0,64),$type,time(),$raw]);
    if ($q->rowCount()===1) {
        update_receipt_from_event(store(),$eventId,$event);
        foreach ((array)(config()['webhook_subscribers']??[]) as $subscriberId=>$subscriber) {
            if (!is_array($subscriber) || !preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',(string)$subscriberId)) continue;
            if (!in_array($sid,(array)($subscriber['sessions']??[]),true)) continue;
            $events=(array)($subscriber['events']??['message','receipt','connected','disconnected']);
            if (!in_array($type,$events,true)) continue;
            $statement=store()->prepare('INSERT OR IGNORE INTO event_deliveries(event_id,subscriber_id,next_at,updated_at) VALUES(?,?,?,?)');
            $statement->execute([$eventId,(string)$subscriberId,time(),time()]);
        }
    }
    http_response_code(202);echo '{"accepted":true}';
} catch (Throwable $e) { http_response_code(422);echo '{"error":"invalid_payload"}'; }
