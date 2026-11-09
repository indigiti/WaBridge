<?php
declare(strict_types=1);
require_once __DIR__.'/events.php';
require_once __DIR__.'/outbox.php';

/**
 * Replays only locally idempotent event normalization.
 * It NEVER submits a WhatsApp request or retries an uncertain send.
 */
final class VNextRecovery {
    public function __construct(private VNextEventJournal $journal) {}

    public function drain(string $tenantId,int $limit=20,?int $now=null):array {
        if(!VNextJsonStore::uuid($tenantId)||$limit<1||$limit>50){
            throw new InvalidArgumentException('Invalid recovery request');
        }
        $now??=time();
        $processed=0;
        for($i=0;$i<$limit;$i++){
            $claim=$this->journal->claim($tenantId,$now,30);
            if($claim===null)break;
            // Recorded event metadata has already been validated and written
            // atomically. This stage merely records completion of that local
            // normalization work. No projection, delivery or sending implied.
            if(!$this->journal->acknowledge($tenantId,$claim['event_key'],$claim['lease_token'])) {
                throw new RuntimeException('Recovery lease acknowledgement failed');
            }
            $processed++;
        }
        return ['normalized'=>$processed,'states'=>$this->journal->states($tenantId)];
    }
}
