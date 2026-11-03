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
