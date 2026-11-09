<?php
declare(strict_types=1);
require_once __DIR__.'/pilot-guard.php';
require_once __DIR__.'/events.php';

/**
 * W1 fixture-only event projection checkpoint.
 *
 * The event journal and this file are separate JSON documents. We do not
 * claim multi-file ACID. Recovery is replay-safe:
 *   1. append verified event to journal
 *   2. project this metadata idempotently
 *   3. acknowledge journal lease
 * Crash after (2) and before (3) replays (2) as a no-op.
 *
 * No customer message text, contacts, real delivery status or sends.
 */
final class VNextEventProjector {
    public function __construct(private VNextJsonStore $store) {}

    public function project(string $tenantId,string $eventKey,array $event):bool {
        $scope=(new VNextPilotGuard($this->store))->tenant($tenantId);
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$eventKey)) {
            throw new InvalidArgumentException('Invalid event key');
        }
        $normalized=VNextEventJournal::normalize($tenantId,$event);
        if($normalized['provider']!=='fixture') {
            throw new RuntimeException('Real provider projection is disabled');
        }
        $authorized=false;
        foreach($scope['channels'] as $channel) {
            if($channel['id']===$normalized['channel_id'])$authorized=true;
        }
        if(!$authorized)throw new RuntimeException('Event channel belongs to another tenant');
        $expected=hash('sha256',$normalized['provider']."\0"
            .$normalized['channel_id']."\0".$normalized['provider_event_id']);
        if(!hash_equals($expected,$eventKey)) {
            throw new InvalidArgumentException('Provider event key mismatch');
        }
        return $this->store->mutate($tenantId,'projections',
            static function(array &$items)use($eventKey,$normalized):bool {
                if(isset($items[$eventKey])) {
                    if(($items[$eventKey]['event']??null)!==$normalized) {
                        throw new RuntimeException('Event projection key collision');
                    }
                    return false;
                }
                if(count($items)>=500)throw new RuntimeException('Projection capacity reached');
                $items[$eventKey]=['event'=>$normalized,'processed_at'=>gmdate('c'),
                    'projection'=>'metadata_only','side_effects'=>0];
                return true;
            });
    }

    public function count(string $tenantId):int {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        return $this->store->read($tenantId,'projections',
            static fn(array $items):int=>count($items));
    }
}
