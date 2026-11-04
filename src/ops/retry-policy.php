<?php
declare(strict_types=1);

/**
 * Conservative automated retry decision. An attempt that might have reached
 * WhatsApp can never be retried automatically, even after an HTTP timeout.
 */
function message_retry_policy(array $job):array {
    $state=(string)($job['state']??'unknown');
    $attempts=max(0,(int)($job['attempts']??0));
    if($state==='queued'&&$attempts===0)
        return ['action'=>'process_when_connected','automatic_resend'=>false,
            'reason'=>'No send attempt has been claimed; normal queue processing is permitted'];
    if($state==='queued')
        return ['action'=>'hold_for_review','automatic_resend'=>false,
            'reason'=>'A previously attempted message returned to queue unexpectedly'];
    if($state==='processing')
        return ['action'=>'wait_for_worker','automatic_resend'=>false,
            'reason'=>'Send may already be in progress'];
    if($state==='needs_review'||$state==='failed')
        return ['action'=>'manual_investigation','automatic_resend'=>false,
            'reason'=>'Message outcome or recipient delivery needs independent verification'];
    return ['action'=>'no_retry','automatic_resend'=>false,
        'reason'=>'Gateway acceptance and delivery states are not valid retry triggers'];
}
