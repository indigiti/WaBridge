<?php
declare(strict_types=1);
require_once __DIR__.'/json-store.php';

/**
 * W1 simulated dispatch-intent state machine. NEVER calls a real provider.
 * Every reservation, claim and acknowledgement touches one JSON document.
 * An expired claim becomes needs_review rather than an automatic resend.
 */
final class VNextOutbox {
    public function __construct(private VNextJsonStore $store) {}

    public function reserve(string $tenantId,string $sourceKey,string $kind='inbox_preview'): array {
        if(!VNextJsonStore::uuid($tenantId)
            || !preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D',$sourceKey)
            || !in_array($kind,['inbox_preview','campaign_preview','automation_preview'],true)){
            throw new InvalidArgumentException('Invalid simulated dispatch');
        }
        $key=hash('sha256',$kind."\0".$sourceKey);
        return $this->store->mutate($tenantId,'intents',
            static function(array &$items)use($key,$sourceKey,$kind):array {
                if(isset($items[$key])){
                    $existing=$items[$key];
                    if(($existing['source_key']??'')!==$sourceKey
                       || ($existing['kind']??'')!==$kind) {
                        throw new RuntimeException('Conflicting simulated dispatch');
                    }
                    return ['record'=>$existing,'duplicate'=>true];
                }
                $row=['id'=>$key,'source_key'=>$sourceKey,'kind'=>$kind,
                    'state'=>'reserved','attempts'=>0,'lease_token'=>null,'lease_until'=>0,
                    'created_at'=>gmdate('c')];
                $items[$key]=$row;
                return ['record'=>$row,'duplicate'=>false];
            });
    }

    public function claim(string $tenantId,int $now,int $ttl=30): ?array {
        if(!VNextJsonStore::uuid($tenantId)||$ttl<5||$ttl>300){
            throw new InvalidArgumentException('Invalid simulated lease');
        }
        return $this->store->mutate($tenantId,'intents',
            static function(array &$items)use($now,$ttl):?array {
                foreach($items as &$row) {
                    if(($row['state']??'')==='claimed' && (int)($row['lease_until']??0)<=$now){
                        // Side effects may already have occurred; never reclaim.
                        $row['state']='needs_review';
                        $row['lease_token']=null;
                    }
                }
                unset($row);
                foreach($items as &$row) {
                    if(($row['state']??'')!=='reserved')continue;
                    $token=bin2hex(random_bytes(16));
                    $row['state']='claimed';
                    $row['attempts']++;
                    $row['lease_token']=$token;
                    $row['lease_until']=$now+$ttl;
                    return ['id'=>$row['id'],'token'=>$token,'kind'=>$row['kind']];
                }
                return null;
            });
    }

    public function finishSimulation(string $tenantId,string $id,string $token,int $now):bool {
        if(!VNextJsonStore::uuid($tenantId)
            || !preg_match('/\A[a-f0-9]{64}\z/D',$id)
            || !preg_match('/\A[a-f0-9]{32}\z/D',$token)){
            throw new InvalidArgumentException('Invalid simulation receipt');
        }
        return $this->store->mutate($tenantId,'intents',
            static function(array &$items)use($id,$token,$now):bool {
                $row=$items[$id]??null;
                if(!is_array($row)||($row['state']??'')!=='claimed'
                    || (int)($row['lease_until']??0)<$now
                    || !hash_equals((string)($row['lease_token']??''),$token))return false;
                $items[$id]['state']='simulated';
                $items[$id]['lease_token']=null;
                $items[$id]['lease_until']=0;
                $items[$id]['simulated_at']=gmdate('c',$now);
                return true;
            });
    }

    public function states(string $tenantId):array {
        if(!VNextJsonStore::uuid($tenantId))throw new InvalidArgumentException('Invalid tenant');
        return $this->store->read($tenantId,'intents',static function(array $items):array {
            $counts=['reserved'=>0,'claimed'=>0,'simulated'=>0,'needs_review'=>0];
            foreach($items as $row){
                $state=(string)($row['state']??'');
                if(isset($counts[$state]))$counts[$state]++;
            }
            return $counts;
        });
    }
}
