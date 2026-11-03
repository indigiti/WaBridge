<?php
declare(strict_types=1);

/**
 * One-shot send processor shared by Cloudways CLI workers and the
 * authenticated browser's manual testing action.
 *
 * Claimed jobs are never retried automatically after a network ambiguity:
 * the WhatsApp server may have received the message before the failure.
 * The only positive state from a 2xx response is "accepted", NOT delivered.
 *
 * $sender exists only for deterministic tests. Production uses gateway().
 */
function process_next_message(PDO|JsonStore $db, ?callable $sender=null): ?array {
    $job=claim_job($db);
    if ($job===null) return null;
    $sender ??= 'gateway';
    $id=(string)$job['id'];
    try {
        if (!valid_session_id((string)$job['session_id']))
            throw new RuntimeException('invalid_session');
        $r=$sender('POST','/api/v1/sessions/'.$job['session_id'].'/messages/text',[
            'to'=>$job['destination'], 'text'=>$job['body'],
        ]);
        if (!is_array($r)) throw new RuntimeException('invalid_gateway_result');
        $http=(int)($r['http']??0);
        $data=is_array($r['data']??null) ? $r['data'] : [];
        $providerId=(string)($data['message_id']??'');
        if ($http>=200 && $http<300 && $providerId!=='' && strlen($providerId)<=200) {
            // Never store sensitive upstream response objects as message logs.
            complete_job($db,$id,'accepted',$http,'gateway_accepted');
            record_provider_message($db,$id,$providerId);
            return ['id'=>$id,'state'=>'accepted','http'=>$http,'provider_message_id'=>$providerId];
        }
        // Success without a usable upstream ID is untraceable, and 4xx/5xx
        // responses can be ambiguous. Never call these "delivered".
        complete_job($db,$id,'needs_review',$http,$http>=200 && $http<300 ? 'missing_provider_id' : 'gateway_http_error');
        return ['id'=>$id,'state'=>'needs_review','http'=>$http];
    } catch (Throwable $e) {
        complete_job($db,$id,'needs_review',0,'transport_or_worker_failure');
        return ['id'=>$id,'state'=>'needs_review','http'=>0];
    }
}
