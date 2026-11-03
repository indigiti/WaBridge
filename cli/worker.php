<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/src/store.php';
$pdo=store();
$limit=max(1,min(100,(int)($argv[1] ?? 20)));
$count=0;
while ($count<$limit && ($job=claim_job($pdo))) {
    ++$count;
    try {
        // Use the configured Waxum provider. Successful HTTP acceptance != WhatsApp delivery.
        $r=gateway('POST','/api/v1/sessions/'.$job['session_id'].'/messages/text',[
            'to'=>$job['destination'],'text'=>$job['body']
        ]);
        if ($r['http']>=200 && $r['http']<300) {
            complete_job($pdo,$job['id'],'accepted',$r['http'],json_encode($r['data']));
        } else {
            // Ambiguous upstream errors must NOT be retried automatically:
            // a message may have reached the gateway before the connection failed.
            complete_job($pdo,$job['id'],'needs_review',$r['http'],json_encode($r['data']));
        }
    } catch (Throwable $e) {
        complete_job($pdo,$job['id'],'needs_review',0,get_class($e));
    }
}
$pdo->prepare('DELETE FROM quotas WHERE bucket < ?')->execute([intdiv(time(),60)-120]);
$pdo->prepare('DELETE FROM events WHERE received_at < ?')->execute([time()-7*86400]);
echo "Processed ".$count." jobs\n";
