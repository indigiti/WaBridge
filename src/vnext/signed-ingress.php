<?php
declare(strict_types=1);
require_once __DIR__.'/channels.php';
require_once __DIR__.'/events.php';

/**
 * W1 fixture-only signed inbound event adapter.
 * Tenant ownership comes exclusively from the private channel registry,
 * not from caller-provided tenant IDs. No message bodies are persisted.
 */
final class VNextSignedIngress {
    public function __construct(
        private VNextChannels $channels,
        private VNextEventJournal $journal
    ) {}

    public static function verify(string $raw,string $timestamp,string $signature,string $secret,?int $now=null): void {
        $now??=time();
        if(strlen($secret)<32)throw new RuntimeException('Webhook secret is not configured');
        if(strlen($raw)<2||strlen($raw)>8192)throw new InvalidArgumentException('Invalid webhook payload size');
        if(!preg_match('/\A[0-9]{10}\z/D',$timestamp) || abs($now-(int)$timestamp)>300) {
            throw new InvalidArgumentException('Stale webhook timestamp');
        }
        if(!preg_match('/\Asha256=[a-f0-9]{64}\z/D',$signature)) {
            throw new InvalidArgumentException('Invalid webhook signature');
        }
        $expected='sha256='.hash_hmac('sha256',$timestamp.'.'.$raw,$secret);
        if(!hash_equals($expected,$signature))throw new InvalidArgumentException('Webhook HMAC mismatch');
    }

    /** The caller must enforce HTTP POST and the v2 signature version. */
    public function ingest(string $raw,string $timestamp,string $signature,
                           string $secret,?int $now=null): array {
        self::verify($raw,$timestamp,$signature,$secret,$now);
        $payload=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($payload)||array_is_list($payload)) {
            throw new InvalidArgumentException('Webhook JSON object required');
        }
        // Strictly synthetic provider during staging: no real gateway event is
        // ingested until real provider signatures + channel binding are certified.
        $provider=(string)($payload['provider']??'');
        $external=(string)($payload['external_channel_id']??'');
        if($provider!=='fixture')throw new InvalidArgumentException('Live provider disabled');
        $channel=$this->channels->resolve($provider,$external);
        if($channel===null)throw new InvalidArgumentException('Unregistered channel');
        $tenant=(string)$channel['tenant_id'];
        $event=VNextEventJournal::normalize($tenant,[
            'provider'=>$provider,
            'channel_id'=>(string)$channel['id'],
            'type'=>(string)($payload['type']??''),
            'provider_event_id'=>(string)($payload['provider_event_id']??''),
            'provider_message_id'=>(string)($payload['provider_message_id']??''),
            'occurred_at'=>$payload['occurred_at']??0,
        ]);
        $inserted=$this->journal->recordVerified($event);
        return ['accepted'=>true,'duplicate'=>!$inserted,'tenant_id'=>$tenant,
            'event_key'=>hash('sha256',$provider."\0".$channel['id']."\0".$event['provider_event_id'])];
    }
}
