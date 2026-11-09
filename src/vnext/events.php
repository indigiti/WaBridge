<?php
declare(strict_types=1);
require_once __DIR__ . '/json-store.php';

/**
 * W1 contract for verified, upstream-normalized event metadata.
 * This class NEVER verifies network signatures: the existing authenticated
 * webhook boundary must perform signature verification before invoking it.
 * No new public ingress route is exposed in this increment.
 */
final class VNextEventJournal {
    private VNextJsonStore $store;
    private const TYPES = ['message.inbound', 'message.sent', 'message.delivered',
        'message.read', 'message.failed', 'session.connected', 'session.disconnected'];
    public function __construct(VNextJsonStore $store) { $this->store = $store; }

    public static function normalize(string $tenantId, array $event): array {
        if (!VNextJsonStore::uuid($tenantId)) throw new InvalidArgumentException('Invalid tenant');
        $type=(string)($event['type']??'');
        if (!in_array($type,self::TYPES,true)) throw new InvalidArgumentException('Unsupported event type');
        $provider=(string)($event['provider']??'');
        if (!in_array($provider,['waxum','official_cloud','fixture'],true)) {
            throw new InvalidArgumentException('Unknown provider');
        }
        $channel=(string)($event['channel_id']??'');
        if (!VNextJsonStore::uuid($channel)) throw new InvalidArgumentException('Invalid channel');
        $external=(string)($event['provider_event_id']??'');
        if (!preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D',$external)) {
            throw new InvalidArgumentException('Invalid provider event ID');
        }
        $providerMessage=(string)($event['provider_message_id']??'');
        if ($providerMessage!=='' && !preg_match('/\A[a-zA-Z0-9._:-]{1,200}\z/D',$providerMessage)) {
            throw new InvalidArgumentException('Invalid provider message ID');
        }
        $occurred=(int)($event['occurred_at']??0);
        if ($occurred<1 || $occurred>time()+300) throw new InvalidArgumentException('Invalid event timestamp');
        return [
            'schema_version'=>1,'tenant_id'=>$tenantId,'type'=>$type,
            'provider'=>$provider,'channel_id'=>$channel,
            'provider_event_id'=>$external,'provider_message_id'=>$providerMessage,
            'occurred_at'=>$occurred,
        ];
    }

    /**
     * Internal-only verified metadata insertion; HTTP endpoints must never
     * accept a client-provided "verified" flag in place of signature checking.
     */
    public function recordVerified(array $normalized): bool {
        $tenant=(string)($normalized['tenant_id']??'');
        // Revalidate before persistence, even when already normalized.
        $event=self::normalize($tenant,$normalized);
        $key=hash('sha256',$event['provider']."\0".$event['channel_id']."\0".$event['provider_event_id']);
        return $this->store->mutate($tenant,'events',
            static function(array &$items)use($key,$event):bool {
                if (isset($items[$key])) {
                    if ($items[$key]['event'] !== $event) {
                        throw new RuntimeException('Conflicting duplicate provider event');
                    }
                    return false;
                }
                $items[$key]=['event'=>$event,'recorded_at'=>gmdate('c'),'state'=>'pending','attempts'=>0,'lease_until'=>0,'lease_token'=>null];
                return true;
            });
    }


    /**
     * Recoverable, bounded internal metadata processor lease. NEVER use this
     * contract for provider sends: uncertain remote side effects cannot be
     * made exactly-once by a local file lock.
     */
    public function claim(string $tenantId, ?int $now=null, int $leaseSeconds=30): ?array {
        if(!VNextJsonStore::uuid($tenantId))throw new InvalidArgumentException('Invalid tenant');
        $now??=time();
        if($leaseSeconds<5||$leaseSeconds>300)throw new InvalidArgumentException('Invalid lease');
        return $this->store->mutate($tenantId,'events',
            static function(array &$items)use($now,$leaseSeconds):?array {
                foreach($items as $key=>&$row){
                    $status=(string)($row['state']??'');
                    $eligible=in_array($status,['recorded','pending'],true)
                        || ($status==='leased' && (int)($row['lease_until']??0)<=$now);
                    if(!$eligible)continue;
                    $attempts=(int)($row['attempts']??0);
                    if($attempts>=3){
                        $row['state']='needs_review';
                        $row['lease_token']=null;
                        continue;
                    }
                    $token=bin2hex(random_bytes(16));
                    $row['state']='leased';
                    $row['attempts']=$attempts+1;
                    $row['lease_until']=$now+$leaseSeconds;
                    $row['lease_token']=$token;
                    return ['event_key'=>$key,'lease_token'=>$token,'event'=>$row['event'],
                        'attempts'=>$row['attempts']];
                }
                return null;
            });
    }

    /** A stale worker must never acknowledge a newly claimed lease. */
    public function acknowledge(string $tenantId,string $eventKey,string $leaseToken): bool {
        if(!VNextJsonStore::uuid($tenantId)
           || !preg_match('/\A[a-f0-9]{64}\z/D',$eventKey)
           || !preg_match('/\A[a-f0-9]{32}\z/D',$leaseToken)){
            throw new InvalidArgumentException('Invalid lease identity');
        }
        return $this->store->mutate($tenantId,'events',
            static function(array &$items)use($eventKey,$leaseToken):bool {
                $row=$items[$eventKey]??null;
                if(!is_array($row)||($row['state']??'')!=='leased'
                   || !hash_equals((string)($row['lease_token']??''),$leaseToken))return false;
                $items[$eventKey]['state']='normalized';
                $items[$eventKey]['lease_token']=null;
                $items[$eventKey]['lease_until']=0;
                $items[$eventKey]['normalized_at']=gmdate('c');
                return true;
            });
    }

    public function states(string $tenantId): array {
        if(!VNextJsonStore::uuid($tenantId))throw new InvalidArgumentException('Invalid tenant');
        return $this->store->read($tenantId,'events',static function(array $items):array{
            $counts=['pending'=>0,'leased'=>0,'normalized'=>0,'needs_review'=>0];
            foreach($items as $item){
                $state=($item['state']??'')==='recorded'?'pending':(string)($item['state']??'');
                if(isset($counts[$state]))$counts[$state]++;
            }
            return $counts;
        });
    }

    public function count(string $tenantId): int {
        if (!VNextJsonStore::uuid($tenantId)) throw new InvalidArgumentException('Invalid tenant');
        return $this->store->read($tenantId,'events',static fn(array $items):int => count($items));
    }
}
