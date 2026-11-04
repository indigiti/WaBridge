<?php
declare(strict_types=1);

/**
 * PHP queue dispatcher, shared by the Cloudways CLI worker and the
 * authenticated browser's manual/single-message send.
 *
 * A ready Rust listener is NOT proof of a usable WhatsApp account.
 * Before claiming a job for real delivery, verify that specific session
 * is logged in AND its socket is alive. The preflight does not mutate
 * the queue: an offline account can safely remain queued without being
 * converted to a misleading "attempted send".
 *
 * $sender can be injected by deterministic tests; in this case there is
 * no real network preflight.
 */
function process_next_message(PDO|JsonStore $db, ?callable $sender=null, ?string $jobId=null, ?callable $probe=null): ?array {
    if($jobId!==null&&!valid_job_id($jobId))
        throw new InvalidArgumentException('Invalid queued job ID');
    if($sender===null||$probe!==null) {
        // Explicit browser sends examine only the selected job. The CLI
        // worker examines one oldest job per session, so a disconnected
        // account cannot permanently block another healthy account.
        $candidates=$jobId!==null
            ? array_values(array_filter([storage_queued_job($db,$jobId)]))
            : storage_queued_candidates($db,4);
        if(!$candidates)return null;
        $blocked=null;$chosen=null;
        foreach($candidates as $candidate) {
            $sid=(string)$candidate['session_id'];
            $id=(string)$candidate['id'];
            if(!permitted_session($sid)) {
                $blocked??=['id'=>$id,'state'=>'blocked','http'=>0,
                    'reason'=>'session_not_authorized'];
                continue;
            }
            try {
                $state=$probe!==null?$probe($sid):session_state($sid);
                if(($state['http']??null)===200 &&
                    !empty($state['is_logged_in']) &&
                    !empty($state['socket_alive'])) {
                    $chosen=$id;
                    break;
                }
                $blocked??=[
                    'id'=>$id,'state'=>'blocked',
                    'http'=>(int)($state['http']??0),
                    'reason'=>($state['http']??null)===200
                        ? 'session_disconnected' : 'session_status_unavailable'
                ];
            } catch(Throwable $e) {
                error_log('WaBridge queue preflight '.get_class($e));
                $blocked??=['id'=>$id,'state'=>'blocked','http'=>0,
                    'reason'=>'session_status_unavailable'];
            }
        }
        if($chosen===null)return $blocked;
        $jobId=$chosen;
    }
    // Claim by exact ID when selected to avoid dispatching an unrelated
    // queue record between session preflight and the atomic claim.
    $job=claim_job($db,$jobId);
    if($job===null)return null;
    $sender??='gateway';
    $id=(string)$job['id'];
    try {
        if(!valid_session_id((string)$job['session_id']))
            throw new RuntimeException('invalid_session');
        $r=$sender('POST','/api/v1/sessions/'.$job['session_id'].'/messages/text',[
            'to'=>$job['destination'],'text'=>$job['body'],
        ]);
        if(!is_array($r))throw new RuntimeException('invalid_gateway_result');
        $http=(int)($r['http']??0);
        $data=is_array($r['data']??null)?$r['data']:[];
        $providerId=(string)($data['message_id']??'');
        $status=(string)($data['status']??'');
        // Waxum v0.16.1: immediate sends return
        // {"status":"sent","message_id":"...",...}. This proves Waxum
        // accepted/sent upstream, NOT that WhatsApp delivered.
        if($http>=200&&$http<300&&$providerId!==''&&strlen($providerId)<=200
           &&($status===''||$status==='sent')) {
            complete_job($db,$id,'accepted',$http,'gateway_accepted');
            record_provider_message($db,$id,$providerId);
            return ['id'=>$id,'state'=>'accepted','http'=>$http,'provider_message_id'=>$providerId];
        }
        $reason=($http>=200&&$http<300)
            ? 'missing_provider_id' : 'gateway_http_'.($http>=100&&$http<=599?$http:0);
        // 2xx responses with pending/scheduled status are not immediate sends.
        if($http>=200&&$http<300&&$status==='pending')$reason='unexpected_scheduled_response';
        complete_job($db,$id,'needs_review',$http,$reason);
        return ['id'=>$id,'state'=>'needs_review','http'=>$http,'reason'=>$reason];
    } catch(Throwable $e) {
        // Never automatically retry after a transport error; the WhatsApp
        // server may have received a message just before the connection died.
        error_log('WaBridge send attempt uncertain '.get_class($e));
        complete_job($db,$id,'needs_review',0,'transport_or_worker_failure');
        return ['id'=>$id,'state'=>'needs_review','http'=>0,'reason'=>'transport_or_worker_failure'];
    }
}
