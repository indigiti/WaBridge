<?php
declare(strict_types=1);

/**
 * Read-model boundary: message screens never query whole tables or expose
 * message text, idempotency keys, or raw gateway error bodies.
 * The JSON adapter remains deliberately single-server test-only.
 */
function storage_message_state(array $job,?array $receipt):string {
    $state=(string)($job['state']??'unknown');
    $r=(string)($receipt['state']??'');
    return $state==='accepted' && in_array($r,['sent','delivered','read','failed'],true)
        ? $r : $state;
}
function storage_message_page(int $page=1,int $perPage=25,string $status='',string $session='',string $jobId=''):array {
    $page=max(1,min(2000,$page));
    $perPage=max(1,min(50,$perPage));
    if($jobId!==''&&!valid_job_id($jobId))throw new InvalidArgumentException('Invalid message ID');
    if($session!==''&&!valid_session_id($session))throw new InvalidArgumentException('Invalid session');
    $valid=['','queued','processing','accepted','sent','delivered','read','failed','needs_review','cancelled'];
    if(!in_array($status,$valid,true))throw new InvalidArgumentException('Invalid delivery status');
    $offset=($page-1)*$perPage;
    $allowed=array_values(array_filter((array)(config()['allowed_sessions']??[]),
        static fn(mixed $sid):bool=>is_string($sid)&&valid_session_id($sid)));
    $db=store();
    if($db instanceof JsonStore) {
        return $db->read(static function(array $s)use($page,$perPage,$offset,$status,$session,$jobId,$allowed):array {
            $receipts=[];
            foreach($s['receipts'] as $r) if(is_array($r))
                $receipts[(string)($r['job_id']??'')]=$r;
            $rows=[];
            foreach($s['jobs'] as $job) {
                if(!is_array($job))continue;
                if($allowed&&!in_array((string)$job['session_id'],$allowed,true))continue;
                if($jobId!==''&&$job['id']!==$jobId)continue;
                if($session!==''&&$job['session_id']!==$session)continue;
                $receipt=$receipts[(string)$job['id']]??null;
                if($status!==''&&storage_message_state($job,$receipt)!==$status)continue;
                $rows[]=[
                    'id'=>(string)$job['id'],
                    'client_id'=>(string)$job['client_id'],
                    'session_id'=>(string)$job['session_id'],
                    'destination'=>(string)$job['destination'],
                    'state'=>(string)$job['state'],
                    'created_at'=>(int)$job['created_at'],
                    'next_at'=>(int)($job['next_at']??0),
                    'updated_at'=>(int)$job['updated_at'],
                    'attempts'=>(int)$job['attempts'],
                    'response_code'=>isset($job['response_code'])?(int)$job['response_code']:null,
                    'reason_code'=>(string)($job['engine_response']??''),
                    'error'=>(string)($job['error']??''),
                    'receipt'=>$receipt===null?null:[
                        'provider_id'=>(string)($receipt['provider_id']??''),
                        'state'=>(string)($receipt['state']??''),
                        'observed_at'=>(int)($receipt['observed_at']??0),
                    ],
                ];
            }
            usort($rows,static fn(array $a,array $b):int=>
                ($b['created_at']<=>$a['created_at'])?:strcmp($b['id'],$a['id']));
            $slice=array_slice($rows,$offset,$perPage+1);
            return ['rows'=>array_slice($slice,0,$perPage),'has_next'=>count($slice)>$perPage,'page'=>$page];
        });
    }
    $where=[];$parameters=[];
    if($allowed){$where[]='j.session_id IN ('.implode(',',array_fill(0,count($allowed),'?')).')';
        foreach($allowed as $allowedSid)$parameters[]=$allowedSid;}
    if($jobId!==''){$where[]='j.id=?';$parameters[]=$jobId;}
    if($session!==''){$where[]='j.session_id=?';$parameters[]=$session;}
    if($status!=='') {
        $where[]="(CASE WHEN j.state='accepted' AND r.state IN ('sent','delivered','read','failed') THEN r.state ELSE j.state END)=?";
        $parameters[]=$status;
    }
    $whereSql=$where?' WHERE '.implode(' AND ',$where):'';
    $sql='SELECT j.id,j.client_id,j.session_id,j.destination,j.state,j.created_at,j.next_at,j.updated_at,j.attempts,j.response_code,j.error,
        j.engine_response AS reason_code,r.provider_id,r.state AS receipt_state,r.observed_at AS receipt_observed_at
        FROM jobs j LEFT JOIN message_receipts r ON r.job_id=j.id'.$whereSql.
        ' ORDER BY j.created_at DESC,j.id DESC LIMIT ? OFFSET ?';
    $q=$db->prepare($sql);
    foreach($parameters as $i=>$v)$q->bindValue($i+1,$v,PDO::PARAM_STR);
    $q->bindValue(count($parameters)+1,$perPage+1,PDO::PARAM_INT);
    $q->bindValue(count($parameters)+2,$offset,PDO::PARAM_INT);
    $q->execute();$rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $job) {
        $receipt=$job['provider_id']===null?null:[
            'provider_id'=>(string)$job['provider_id'],
            'state'=>(string)($job['receipt_state']??''),
            'observed_at'=>(int)($job['receipt_observed_at']??0),
        ];
        $rows[]=[
            'id'=>(string)$job['id'],
            'client_id'=>(string)$job['client_id'],
            'session_id'=>(string)$job['session_id'],
            'destination'=>(string)$job['destination'],
            'state'=>(string)$job['state'],
            'created_at'=>(int)$job['created_at'],
                    'next_at'=>(int)($job['next_at']??0),
            'updated_at'=>(int)$job['updated_at'],
            'attempts'=>(int)$job['attempts'],
            'response_code'=>$job['response_code']===null?null:(int)$job['response_code'],
            'reason_code'=>(string)($job['reason_code']??''),
            'error'=>(string)($job['error']??''),
            'receipt'=>$receipt,
        ];
    }
    return ['rows'=>array_slice($rows,0,$perPage),'has_next'=>count($rows)>$perPage,'page'=>$page];
}
function storage_message_detail(string $id):?array {
    if(!valid_job_id($id))return null;
    $page=storage_message_page(1,1,'','',$id);
    return $page['rows'][0]??null;
}

/**
 * Real recorded delivery transitions plus queue-created evidence from
 * immutable jobs.created_at. New jobs avoid an extra synchronous queued
 * journal INSERT. Previously recorded queued events are never duplicated.
 *
 * Keep the empty result for old jobs with no journal so their trace page
 * can show the legacy fallback (latest checkpoints).
 */
function storage_message_timeline(string $id):array {
    if(!valid_job_id($id))return [];
    $db=store();
    if($db instanceof JsonStore)
        return $db->read(static function(array $s)use($id):array {
            $events=array_slice((array)($s['timeline'][$id]??[]),-39);
            if(!$events)return [];
            $job=$s['jobs'][$id]??null;
            if(is_array($job)&&($events[0]['state']??'')!=='queued')
                array_unshift($events,[
                    'guid'=>$id,'state'=>'queued','proof'=>'local_queue',
                    'at'=>(int)($job['created_at']??0),
                ]);
            return $events;
        });
    $stmt=$db->prepare('SELECT guid,state,proof,at FROM message_timeline WHERE job_id=? ORDER BY seq DESC LIMIT 39');
    $stmt->execute([$id]);
    $events=array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    if(!$events)return [];
    if(($events[0]['state']??'')!=='queued'){
        $q=$db->prepare('SELECT created_at FROM jobs WHERE id=?');
        $q->execute([$id]);
        $created=$q->fetchColumn();
        if($created!==false)
            array_unshift($events,[
                'guid'=>$id,'state'=>'queued','proof'=>'local_queue',
                'at'=>(int)$created,
            ]);
    }
    return $events;
}

/**
 * One aggregate query instead of hydrating full Reports and all administrator
 * audit entries for message-status counters. Effective receipt states are
 * mutually exclusive: a delivered job is not also counted as accepted.
 */
function storage_message_status_counts():array {
    $counts=array_fill_keys(['queued','processing','accepted','sent','delivered',
        'read','failed','needs_review','cancelled','unknown'],0);
    $allowed=array_values(array_filter((array)(config()['allowed_sessions']??[]),
        static fn(mixed $sid):bool=>is_string($sid)&&valid_session_id($sid)));
    $db=store();
    if($db instanceof JsonStore)
        return $db->read(static function(array $s)use($counts,$allowed):array {
            $receipts=[];
            foreach($s['receipts'] as $receipt)if(is_array($receipt))
                $receipts[(string)($receipt['job_id']??'')]=$receipt;
            foreach($s['jobs'] as $job) {
                if(!is_array($job))continue;
                if($allowed&&!in_array((string)($job['session_id']??''),$allowed,true))continue;
                $state=storage_message_state($job,$receipts[(string)$job['id']]??null);
                $counts[isset($counts[$state])?$state:'unknown']++;
            }
            return $counts;
        });
    $where=$allowed?' WHERE j.session_id IN ('.implode(',',array_fill(0,count($allowed),'?')).')':'';
    $sql="SELECT CASE WHEN j.state='accepted'
        AND r.state IN ('sent','delivered','read','failed') THEN r.state
        ELSE j.state END AS effective_state,COUNT(*) AS n
        FROM jobs j LEFT JOIN message_receipts r ON r.job_id=j.id".$where.
        ' GROUP BY effective_state';
    $q=$db->prepare($sql);
    foreach($allowed as $i=>$sid)$q->bindValue($i+1,$sid,PDO::PARAM_STR);
    $q->execute();
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        $state=(string)($entry['effective_state']??'unknown');
        $counts[isset($counts[$state])?$state:'unknown']+=(int)$entry['n'];
    }
    return $counts;
}
