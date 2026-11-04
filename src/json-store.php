<?php
declare(strict_types=1);

/**
 * JSON file storage for low-volume, single-server DEVELOPMENT ONLY.
 * Every write is serialized using a persistent lock file and atomic rename.
 * Never put these files under public_html. Do not use for multi-server production.
 */
final class JsonStore {
    private const MAX_BYTES = 16777216; // 16 MiB hard bound
    private string $dir;
    private string $file;
    private string $lock;
    public function __construct(?string $directory = null) {
        $this->dir = $directory ?? dirname(__DIR__).'/runtime/json';
        if (!is_dir($this->dir) && !mkdir($this->dir,0700,true) && !is_dir($this->dir))
            throw new RuntimeException('Cannot create private JSON directory');
        if (is_link($this->dir)) throw new RuntimeException('JSON directory symlink prohibited');
        @chmod($this->dir,0700);
        $this->file=$this->dir.'/state.json';
        $this->lock=$this->dir.'/state.lock';
    }
    private function blank(): array {
        return ['schema'=>1,'clients'=>[],'jobs'=>[],'quotas'=>[],
            'events'=>[],'deliveries'=>[],'receipts'=>[],'audit'=>[]];
    }
    private function withLock(bool $write, callable $callback): mixed {
        if (is_link($this->lock)) throw new RuntimeException('JSON lock symlink prohibited');
        $fh = fopen($this->lock,'c+b');
        if ($fh === false) throw new RuntimeException('Cannot open JSON storage lock');
        @chmod($this->lock,0600);
        try {
            if (!flock($fh,$write ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Cannot lock JSON storage');
            try {
                if (is_link($this->file)) throw new RuntimeException('JSON state symlink prohibited');
                $state=$this->blank();
                if (is_file($this->file)) {
                    $length=filesize($this->file);
                    if ($length===false || $length > self::MAX_BYTES) throw new RuntimeException('JSON state capacity exceeded');
                    $raw=file_get_contents($this->file);
                    if (!is_string($raw) || $raw==='') throw new RuntimeException('JSON state is unreadable');
                    $decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                    if (!is_array($decoded) || ($decoded['schema']??null)!==1)
                        throw new RuntimeException('Unsupported JSON storage schema');
                    foreach ($state as $name=>$default) {
                        if (!array_key_exists($name,$decoded) || gettype($decoded[$name])!==gettype($default))
                            throw new RuntimeException('JSON storage validation failed: '.$name);
                    }
                    $state=$decoded;
                }
                $answer=$callback($state);
                if ($write) {
                    $encoded=json_encode($state,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
                    if (strlen($encoded)>self::MAX_BYTES) throw new RuntimeException('JSON state size limit exceeded');
                    $temp=tempnam($this->dir,'.state-');
                    if ($temp===false) throw new RuntimeException('Cannot create JSON temp state');
                    try {
                        if (!chmod($temp,0600)) throw new RuntimeException('Cannot protect JSON temp state');
                        $written=file_put_contents($temp,$encoded,LOCK_EX);
                        if ($written!==strlen($encoded)) throw new RuntimeException('Incomplete JSON state write');
                        if (!rename($temp,$this->file)) throw new RuntimeException('Cannot commit JSON state');
                        @chmod($this->file,0600);
                    } finally {
                        if (is_file($temp)) @unlink($temp);
                    }
                }
                return $answer;
            } finally { flock($fh,LOCK_UN); }
        } finally { fclose($fh); }
    }
    public function read(callable $fn): mixed { return $this->withLock(false,$fn); }
    public function mutate(callable $fn): mixed { return $this->withLock(true,$fn); }
    public function health(): bool { return $this->read(static fn(array $s):bool => $s['schema']===1); }
    public function size(): int { return is_file($this->file) ? (int)filesize($this->file) : 0; }
    public function clientByHash(string $hash): ?array {
        return $this->read(static function(array $s)use($hash) {
            foreach ($s['clients'] as $row) {
                if ((int)($row['active']??0)!==1) continue;
                if (!empty($row['expires_at']) && (int)$row['expires_at']<=time()) continue;
                if (hash_equals((string)($row['token_sha256']??''),$hash)) return $row;
            }
            return null;
        });
    }
    public function markClientUsed(string $id): void {
        $this->mutate(static function(array &$s)use($id) {
            if (isset($s['clients'][$id])) $s['clients'][$id]['last_used_at']=time();
        });
    }
    public function clientCreate(array $row): void {
        $this->mutate(static function(array &$s)use($row) {
            $id=(string)$row['id'];
            if (isset($s['clients'][$id])) throw new InvalidArgumentException('API client already exists');
            foreach ($s['clients'] as $previous)
                if (hash_equals((string)$previous['token_sha256'],(string)$row['token_sha256']))
                    throw new InvalidArgumentException('API token already exists');
            if (count($s['clients'])>=100) throw new RuntimeException('JSON test mode supports at most 100 clients');
            $s['clients'][$id]=$row+['active'=>1,'expires_at'=>null,'last_used_at'=>null];
        });
    }
    public function clientRevoke(string $id): int {
        return $this->mutate(static function(array &$s)use($id) {
            if (!isset($s['clients'][$id]) || (int)$s['clients'][$id]['active']===0) return 0;
            $s['clients'][$id]['active']=0;return 1;
        });
    }
    public function clients(): array {
        return $this->read(static function(array $s):array {
            $rows=array_values($s['clients']);
            usort($rows,static fn($a,$b)=>strcmp((string)$a['id'],(string)$b['id']));
            return array_slice($rows,0,100);
        });
    }
    public function quota(string $id,int $limit): bool {
        return $this->mutate(static function(array &$s)use($id,$limit) {
            $now=intdiv(time(),60);
            foreach ($s['quotas'] as $key=>$unused) if ((int)explode('|',$key)[1]<$now-2) unset($s['quotas'][$key]);
            $key=$id.'|'.$now;
            $used=(int)($s['quotas'][$key]??0);
            if ($used>=$limit) return false;
            $s['quotas'][$key]=$used+1;return true;
        });
    }
    public function enqueue(array $client,string $sid,string $to,string $body,string $idem): array {
        return $this->mutate(static function(array &$s)use($client,$sid,$to,$body,$idem) {
            $count=0;
            foreach ($s['jobs'] as $existing) {
                if ($existing['client_id']===$client['id'] && $existing['idem']===$idem) {
                    if ($existing['session_id']!==$sid || $existing['destination']!==$to ||
                        !hash_equals((string)$existing['body'],$body))
                        throw new InvalidArgumentException('Idempotency key reused with different message');
                    return ['id'=>$existing['id'],'state'=>$existing['state'],'duplicate'=>true];
                }
                if ($existing['client_id']===$client['id'] && in_array($existing['state'],['queued','processing'],true)) ++$count;
            }
            if ($count>=1000 || count($s['jobs'])>=4000) throw new RuntimeException('JSON queue capacity exceeded');
            $id=wabridge_guid();$now=time();
            $s['jobs'][$id]=[
                'id'=>$id,'client_id'=>$client['id'],'session_id'=>$sid,
                'destination'=>$to,'body'=>$body,'idem'=>$idem,'state'=>'queued',
                'attempts'=>0,'next_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
                'response_code'=>null,'error'=>null,'engine_response'=>null
            ];
            return ['id'=>$id,'state'=>'queued','duplicate'=>false];
        });
    }
    public function claim(?string $jobId=null): ?array {
        if($jobId!==null&&!valid_job_id($jobId))
            throw new InvalidArgumentException('Invalid job ID');
        return $this->mutate(static function(array &$s)use($jobId) {
            $best=null;
            foreach ($s['jobs'] as $id=>$row) {
                if ($jobId!==null&&$id!==$jobId)continue;
                if ($row['state']!=='queued'||(int)$row['next_at']>time())continue;
                if ($best===null||$row['created_at']<$s['jobs'][$best]['created_at'])$best=$id;
            }
            if($best===null)return null;
            $job=$s['jobs'][$best];
            $s['jobs'][$best]['state']='processing';
            $s['jobs'][$best]['attempts']=(int)$job['attempts']+1;
            $s['jobs'][$best]['updated_at']=time();
            return $job;
        });
    }
    /** Return only a queued job ID/session, never the body or recipient. */
    /**
     * One oldest queued job per session, to prevent an offline account
     * from blocking every other connected account in a single-server queue.
     */
    public function queuedCandidates(int $limit=12):array {
        $limit=max(1,min(20,$limit));
        return $this->read(static function(array $state)use($limit):array {
            $bySession=[];$now=time();
            foreach($state['jobs'] as $id=>$job){
                if(($job['state']??'')!=='queued'||(int)($job['next_at']??0)>$now)continue;
                $sid=(string)($job['session_id']??'');
                if(!valid_session_id($sid))continue;
                $time=(int)($job['created_at']??0);
                $candidate=['id'=>(string)$id,'session_id'=>$sid,'created_at'=>$time];
                $prev=$bySession[$sid]??null;
                if($prev===null||$time<$prev['created_at']||
                   ($time===$prev['created_at']&&strcmp((string)$id,$prev['id'])<0))
                    $bySession[$sid]=$candidate;
            }
            $rows=array_values($bySession);
            usort($rows,static fn(array $a,array $b):int=>
                ($a['created_at']<=>$b['created_at'])?:strcmp($a['id'],$b['id']));
            return array_slice(array_map(static fn(array $row):array=>[
                'id'=>$row['id'],'session_id'=>$row['session_id']
            ],$rows),0,$limit);
        });
    }
    public function queuedJob(?string $jobId=null): ?array {
        if($jobId!==null&&!valid_job_id($jobId))
            throw new InvalidArgumentException('Invalid job ID');
        return $this->read(static function(array $s)use($jobId):?array {
            $best=null;
            foreach($s['jobs'] as $id=>$job) {
                if($jobId!==null&&$id!==$jobId)continue;
                if($job['state']!=='queued'||(int)$job['next_at']>time())continue;
                if($best===null||$job['created_at']<$s['jobs'][$best]['created_at'])$best=$id;
            }
            return $best===null?null:['id'=>$best,'session_id'=>(string)$s['jobs'][$best]['session_id']];
        });
    }
    public function complete(string $id,string $state,int $code,string $response): void {
        $this->mutate(static function(array &$s)use($id,$state,$code,$response) {
            if (!isset($s['jobs'][$id])) throw new RuntimeException('Job missing');
            $s['jobs'][$id]['state']=$state;
            $s['jobs'][$id]['response_code']=$code;
            $s['jobs'][$id]['engine_response']=substr($response,0,2000);
            $s['jobs'][$id]['updated_at']=time();
        });
    }
    public function job(string $id,string $clientId): ?array {
        return $this->read(static function(array $s)use($id,$clientId) {
            $row=$s['jobs'][$id]??null;
            if (!$row || $row['client_id']!==$clientId) return null;
            return array_intersect_key($row,array_flip(['id','state','created_at','updated_at','response_code']));
        });
    }
    /**
     * Private admin telemetry; intentionally excludes message body,
     * idempotency keys, and raw gateway payload.
     */
    public function adminMessages(int $limit=50): array {
        $limit=max(1,min(200,$limit));
        return $this->read(static function(array $state) use($limit):array {
            $rows=[];
            foreach ($state['jobs'] as $job) {
                if (!is_array($job)) continue;
                $receipt=null;
                foreach ($state['receipts'] as $r) {
                    if (($r['job_id']??null)===($job['id']??null)) { $receipt=$r;break; }
                }
                $rows[]=[
                    'id'=>(string)($job['id']??''),
                    'client_id'=>(string)($job['client_id']??''),
                    'session_id'=>(string)($job['session_id']??''),
                    'destination'=>(string)($job['destination']??''),
                    'state'=>(string)($job['state']??'unknown'),
                    'created_at'=>(int)($job['created_at']??0),
                    'updated_at'=>(int)($job['updated_at']??0),
                    'attempts'=>(int)($job['attempts']??0),
                    'response_code'=>isset($job['response_code'])?(int)$job['response_code']:null,
                    'error'=>(string)($job['error']??''),
                    'reason_code'=>(string)($job['engine_response']??''),
                    'receipt'=>$receipt===null ? null : [
                        'provider_id'=>(string)($receipt['provider_id']??''),
                        'state'=>(string)($receipt['state']??''),
                        'observed_at'=>(int)($receipt['observed_at']??0),
                    ],
                ];
            }
            usort($rows,static fn(array $a,array $b):int =>
                ($b['created_at']<=>$a['created_at']) ?: strcmp($b['id'],$a['id']));
            return array_slice($rows,0,$limit);
        });
    }
    public function receipt(string $jobId): ?array {
        return $this->read(static function(array $s)use($jobId) {
            foreach ($s['receipts'] as $r) if ($r['job_id']===$jobId)
                return array_intersect_key($r,array_flip(['provider_id','state','observed_at']));
            return null;
        });
    }
    public function receiptSave(string $jobId,string $providerId): void {
        $this->mutate(static function(array &$s)use($jobId,$providerId) {
            if (!isset($s['jobs'][$jobId]) || isset($s['receipts'][$providerId])) return;
            $s['receipts'][$providerId]=['job_id'=>$jobId,'provider_id'=>$providerId,'state'=>'accepted','observed_at'=>time(),'source_event_id'=>null];
        });
    }
    public function receiptUpdate(string $providerId,string $state,string $eventId): bool {
        return $this->mutate(static function(array &$s)use($providerId,$state,$eventId) {
            if (!isset($s['receipts'][$providerId])) return false;
            $s['receipts'][$providerId]['state']=$state;
            $s['receipts'][$providerId]['source_event_id']=$eventId;
            $s['receipts'][$providerId]['observed_at']=time();
            return true;
        });
    }
    public function audit(string $event,string $actor,string $target,array $details): void {
        $this->mutate(static function(array &$s)use($event,$actor,$target,$details) {
            $s['audit'][]=['guid'=>wabridge_guid(),'event'=>$event,'actor'=>substr($actor,0,96),'target'=>substr($target,0,128),'at'=>time(),'details'=>$details];
            if (count($s['audit'])>500) $s['audit']=array_slice($s['audit'],-500);
        });
    }
    public function eventReceive(string $id,string $sid,string $type,string $raw,array $subscribers): bool {
        return $this->mutate(static function(array &$s)use($id,$sid,$type,$raw,$subscribers) {
            if (isset($s['events'][$id])) return false;
            if (count($s['events'])>=500 || strlen($raw)>65536) throw new RuntimeException('JSON webhook event limit exceeded');
            $s['events'][$id]=['id'=>$id,'session_id'=>$sid,'event_type'=>$type,'received_at'=>time(),'payload'=>$raw];
            foreach ($subscribers as $subId=>$sub) {
                if (!is_array($sub) || !preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',(string)$subId)) continue;
                if (!in_array($sid,(array)($sub['sessions']??[]),true)) continue;
                if (!in_array($type,(array)($sub['events']??['message','receipt','connected','disconnected']),true)) continue;
                if (count($s['deliveries'])>=1000) throw new RuntimeException('Webhook delivery backlog full');
                $key=$id.'|'.$subId;
                $s['deliveries'][$key]=['event_id'=>$id,'subscriber_id'=>$subId,'state'=>'queued','attempts'=>0,'next_at'=>time(),'updated_at'=>time(),'last_http'=>null];
            }
            return true;
        });
    }
    public function eventStale(): void {
        $this->mutate(static function(array &$s) {
            foreach ($s['deliveries'] as &$r)
                if ($r['state']==='processing' && $r['updated_at']<time()-600)
                    { $r['state']='dead';$r['updated_at']=time(); }
            unset($r);
        });
    }
    public function eventClaim(): ?array {
        return $this->mutate(static function(array &$s) {
            $best=null;
            foreach ($s['deliveries'] as $key=>$r) {
                if ($r['state']!=='queued' || $r['next_at']>time()) continue;
                if ($best===null || $r['next_at']<$s['deliveries'][$best]['next_at']) $best=$key;
            }
            if ($best===null) return null;
            $event=$s['events'][$s['deliveries'][$best]['event_id']]??null;
            if (!$event) { $s['deliveries'][$best]['state']='dead';return null; }
            $job=$s['deliveries'][$best];
            $s['deliveries'][$best]['state']='processing';
            $s['deliveries'][$best]['attempts']++;
            $s['deliveries'][$best]['updated_at']=time();
            return $job+['payload'=>$event['payload']];
        });
    }
    public function eventFinish(string $eventId,string $subscriber,string $state,int $nextAt,int $code): void {
        $this->mutate(static function(array &$s)use($eventId,$subscriber,$state,$nextAt,$code) {
            $key=$eventId.'|'.$subscriber;
            if (!isset($s['deliveries'][$key])) return;
            $s['deliveries'][$key]['state']=$state;
            $s['deliveries'][$key]['next_at']=$nextAt;
            $s['deliveries'][$key]['last_http']=$code;
            $s['deliveries'][$key]['updated_at']=time();
        });
    }
    public function eventReplay(string $eventId,string $subscriber): bool {
        return $this->mutate(static function(array &$s)use($eventId,$subscriber) {
            $key=$eventId.'|'.$subscriber;
            if (!isset($s['deliveries'][$key]) || $s['deliveries'][$key]['state']!=='dead') return false;
            $s['deliveries'][$key]['state']='queued';
            $s['deliveries'][$key]['attempts']=0;
            $s['deliveries'][$key]['next_at']=time();
            $s['deliveries'][$key]['updated_at']=time();return true;
        });
    }
    public function cleanup(): void {
        $this->mutate(static function(array &$s) {
            $now=time();
            foreach ($s['jobs'] as $id=>$j) {
                if ($j['state']==='processing' && $j['updated_at']<$now-300) {
                    $s['jobs'][$id]['state']='needs_review';
                    $s['jobs'][$id]['error']='worker_interrupted';
                    $s['jobs'][$id]['updated_at']=$now;
                } elseif (in_array($j['state'],['accepted','needs_review'],true) && $j['updated_at']<$now-14*86400)
                    unset($s['jobs'][$id]);
            }
            foreach ($s['events'] as $id=>$ev) if ($ev['received_at']<$now-7*86400) {
                unset($s['events'][$id]);
                foreach ($s['deliveries'] as $key=>$delivery) if ($delivery['event_id']===$id) unset($s['deliveries'][$key]);
            }
            foreach ($s['receipts'] as $id=>$r) if (!isset($s['jobs'][$r['job_id']])) unset($s['receipts'][$id]);
            foreach ($s['quotas'] as $key=>$unused) if ((int)explode('|',$key)[1]<intdiv($now,60)-120) unset($s['quotas'][$key]);
        });
    }
    private static function groups(array $rows,string $field): array {
        $counts=[];
        foreach ($rows as $row) {
            $value=(string)($row[$field]??'unknown');
            $counts[$value]=($counts[$value]??0)+1;
        }
        $result=[];
        foreach ($counts as $state=>$count) $result[] = [$field=>$state,'count'=>$count,'n'=>$count];
        return $result;
    }
    public function stats(): array {
        return $this->read(static fn(array $s) => self::groups($s['jobs'],'state'));
    }
    public function reports(): array {
        return $this->read(static function(array $s):array {
            $clients=array_map(static fn($x)=>array_intersect_key($x,array_flip(['id','active','per_minute','expires_at','last_used_at'])),array_values($s['clients']));
            return [
                'jobs'=>self::groups($s['jobs'],'state'),
                'receipts'=>self::groups($s['receipts'],'state'),
                'event_types'=>self::groups($s['events'],'event_type'),
                'api_clients'=>array_slice($clients,0,100),
                'audit'=>array_slice(array_reverse(array_map(static fn($x)=>array_intersect_key($x,array_flip(['guid','event','actor','target','at'])),$s['audit'])),0,10),
            ];
        });
    }
    public function ops(string $mode): array {
        return $this->read(static function(array $s)use($mode) {
            if ($mode==='review') return array_slice(array_values(array_filter($s['jobs'],static fn($r)=>$r['state']==='needs_review')),0,50);
            if ($mode==='dead-events') return array_slice(array_values(array_filter($s['deliveries'],static fn($r)=>$r['state']==='dead')),0,50);
            if ($mode==='summary') return ['jobs'=>self::groups($s['jobs'],'state'),'event_deliveries'=>self::groups($s['deliveries'],'state')];
            return [];
        });
    }
    /**
     * One-time import of a consistent legacy snapshot. No existing JSON data
     * may be overwritten. The original SQLite file is retained untouched.
     */
    public function importSnapshot(array $snapshot): void {
        $this->mutate(function(array &$state)use($snapshot) {
            foreach (['clients','jobs','quotas','events','deliveries','receipts','audit'] as $key) {
                if (!isset($snapshot[$key]) || !is_array($snapshot[$key]))
                    throw new InvalidArgumentException('Invalid migration snapshot');
                if (count($state[$key])!==0)
                    throw new RuntimeException('Existing JSON data prevents migration');
            }
            $state=$this->blank();
            foreach (['clients','jobs','quotas','events','deliveries','receipts','audit'] as $key)
                $state[$key]=$snapshot[$key];
            if (count($state['jobs'])>4000 || count($state['clients'])>100 ||
                count($state['events'])>500 || count($state['deliveries'])>1000 ||
                count($state['audit'])>500) throw new RuntimeException('Migration exceeds JSON test-mode limits');
        });
    }
    public function hasActiveData(): bool {
        return $this->read(static fn(array $s) =>
            count($s['clients'])>0 || count($s['jobs'])>0 || count($s['events'])>0);
    }
}
