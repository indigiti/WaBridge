<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/src/store.php';
$db=store();
$subscribers=(array)(config()['webhook_subscribers']??[]);
$max=max(1,min(50,(int)($argv[1]??15)));
$count=0;
$db->prepare("UPDATE event_deliveries SET state='dead',updated_at=? WHERE state='processing' AND updated_at<?")
    ->execute([time(),time()-600]);
while ($count<$max) {
    $db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare("SELECT d.*,e.payload FROM event_deliveries d JOIN events e ON e.id=d.event_id WHERE d.state='queued' AND d.next_at<=? ORDER BY d.next_at LIMIT 1");
        $q->execute([time()]);
        $job=$q->fetch();
        if (!$job) { $db->commit(); break; }
        $claim=$db->prepare("UPDATE event_deliveries SET state='processing',attempts=attempts+1,updated_at=? WHERE event_id=? AND subscriber_id=?");
        $claim->execute([time(),$job['event_id'],$job['subscriber_id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    ++$count;
    $subscriber=$subscribers[$job['subscriber_id']]??null;
    $url=is_array($subscriber)?(string)($subscriber['url']??''):'';
    $secret=is_array($subscriber)?(string)($subscriber['secret']??''):'';
    $status=0;
    if (preg_match('~\Ahttps://[a-zA-Z0-9.-]+(?::443)?(?:/[^\s#]*)?\z~D',$url) && strlen($secret)>=32) {
        $ts=(string)time();
        $signature='sha256='.hash_hmac('sha256',$ts.'.'.$job['payload'],$secret);
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$job['payload'],
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'X-WaBridge-Event-Id: '.$job['event_id'],
                'X-WaBridge-Timestamp: '.$ts,
                'X-WaBridge-Signature: '.$signature
            ],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>8,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        ]);
        curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    }
    $ok=$status>=200 && $status<300;
    $attempts=(int)$job['attempts']+1;
    $next=$ok?'delivered':($attempts>=5?'dead':'queued');
    $delay=$ok?0:min(900,15*(2**$attempts));
    $u=$db->prepare('UPDATE event_deliveries SET state=?,next_at=?,last_http=?,updated_at=? WHERE event_id=? AND subscriber_id=?');
    $u->execute([$next,time()+$delay,$status,time(),$job['event_id'],$job['subscriber_id']]);
}
echo "Processed ".$count." outbound events\n";
