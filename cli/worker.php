<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/src/store.php';
$pdo=store();
// Never automatically retry ambiguous interrupted sends.
storage_reap_jobs();
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
            $providerId=(string)($r['data']['message_id']??'');
            if ($providerId!=='') record_provider_message($pdo,$job['id'],$providerId);
        } else {
            // Ambiguous upstream errors must NOT be retried automatically:
            // a message may have reached the gateway before the connection failed.
            complete_job($pdo,$job['id'],'needs_review',$r['http'],json_encode($r['data']));
        }
    } catch (Throwable $e) {
        complete_job($pdo,$job['id'],'needs_review',0,get_class($e));
    }
}
storage_cleanup();
echo "Processed ".$count." jobs\n";
