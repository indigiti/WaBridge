<?php
declare(strict_types=1);

/**
 * A job's "accepted" state is only a successful HTTP response from Waxum.
 * Confirmed delivery/read requires a matching provider receipt event.
 */
function message_log_effective_status(array $row): string {
    $job=(string)($row['state']??'');
    $receipt=is_array($row['receipt']??null)?$row['receipt']:null;
    $receiptState=(string)($receipt['state']??'');
    if ($job==='accepted' && in_array($receiptState,['sent','delivered','read','failed'],true))
        return $receiptState;
    return in_array($job,['queued','processing','accepted','needs_review'],true) ? $job : 'unknown';
}
function message_log_status_text(string $state): string {
    return match($state) {
        'queued'=>'Queued — not submitted',
        'processing'=>'Processing — outcome pending',
        'accepted'=>'Accepted by Waxum — delivery unknown',
        'sent'=>'Sent acknowledgment',
        'delivered'=>'Delivered — verified receipt',
        'read'=>'Read — verified receipt',
        'failed'=>'Failed — verified receipt',
        'needs_review'=>'Needs review — do not auto-retry',
        default=>'Unknown',
    };
}
function message_log_mask_recipient(string $recipient): string {
    $digits=preg_replace('/[^0-9]/','',$recipient);
    if (!is_string($digits)||$digits==='') return 'Hidden';
    return '••••'.substr($digits,-4);
}
function message_log_time(int $timestamp): string {
    return $timestamp>0 ? gmdate('Y-m-d H:i:s',$timestamp).' UTC' : 'Unavailable';
}

/**
 * Failure codes come from WaBridge's own fixed internal categories. Never
 * render a raw Rust error body or full transport exception to the browser.
 */
function message_log_reason_code(array $row): string {
    $value=(string)($row['reason_code']??'');
    if($value==='')$value=(string)($row['error']??'');
    $allowed=[
        'gateway_accepted','missing_provider_id','unexpected_scheduled_response',
        'transport_or_worker_failure','worker_interrupted','invalid_session',
        'session_disconnected','session_status_unavailable','session_not_authorized'
    ];
    if(in_array($value,$allowed,true))return $value;
    if(preg_match('/\Agateway_http_(?:0|[1-5][0-9]{2})\z/D',$value))return $value;
    return '';
}
function message_log_reason_text(string $reason): string {
    if($reason==='')return 'No failure reason recorded';
    return match($reason) {
       'gateway_accepted'=>'Gateway accepted the request; awaiting independently verified delivery',
       'missing_provider_id'=>'Gateway response lacked a usable message ID; outcome uncertain',
       'unexpected_scheduled_response'=>'Gateway reported a scheduled rather than immediate send',
       'transport_or_worker_failure'=>'Gateway transport or worker failed; message may have been sent',
       'worker_interrupted'=>'Worker was interrupted during processing; send outcome uncertain',
       'invalid_session'=>'Invalid session ID prevented the send',
       'session_disconnected'=>'Session is not logged in with a live WhatsApp socket',
       'session_status_unavailable'=>'WhatsApp session status could not be confirmed',
       'session_not_authorized'=>'This application is not permitted to use that session',
       default=>match($reason) {
           'gateway_http_401','gateway_http_403'=>'Gateway rejected authentication or authorization',
           'gateway_http_404'=>'Gateway could not find the WhatsApp session or route',
           'gateway_http_409'=>'Gateway reported a conflicting session or request state',
           'gateway_http_429'=>'Gateway rejected or rate-limited the send',
           'gateway_http_503'=>'Gateway unavailable or WhatsApp session not connected',
           'gateway_http_502','gateway_http_504','gateway_http_0'=>'Gateway network or upstream error; outcome uncertain',
           default=>'Gateway rejected or failed the request (HTTP '.substr($reason,13).')',
       },
    };
}
