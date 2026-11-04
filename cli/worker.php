<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/src/store.php';
require_once dirname(__DIR__).'/src/message-processor.php';
require_once dirname(__DIR__).'/src/worker-health.php';
$db=store();
storage_reap_jobs();
$limit=max(1,min(100,(int)($argv[1] ?? 20)));
$count=0;$blocked=false;$accepted=0;$review=0;
while($count<$limit&&($result=process_next_message($db))){
    if($result['state']==='blocked') {
        // Do not claim the offline job and never spin on it.
        $blocked=true;
        break;
    }
    $count++;
    if($result['state']==='accepted')$accepted++;
    elseif($result['state']==='needs_review')$review++;
    // Gateway acceptance is not WhatsApp delivery.
}
storage_cleanup();
try {
    worker_health_write(['processed'=>$count,'accepted'=>$accepted,'needs_review'=>$review,
        'session_blocked'=>$blocked]);
} catch(Throwable $e) {
    // Heartbeat failure must not turn a successful message send into a retry.
    error_log('WaBridge worker heartbeat '.get_class($e));
}
echo "Worker processed ".$count." job(s); gateway-accepted ".$accepted.
    "; needs-review ".$review."; session-blocked ".($blocked?'yes':'no').
    ". Delivery requires verified matching receipts.\n";
