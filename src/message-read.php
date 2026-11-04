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
    $valid=['','queued','processing','accepted','sent','delivered','read','failed','needs_review'];
    if(!in_array($status,$valid,true))throw new InvalidArgumentException('Invalid delivery status');
    $offset=($page-1)*$perPage;
    $db=store();
    if($db instanceof JsonStore) {
        return $db->read(static function(array $s)use($page,$perPage,$offset,$status,$session,$jobId):array {
            $receipts=[];
            foreach($s['receipts'] as $r) if(is_array($r))
                $receipts[(string)($r['job_id']??'')]=$r;
            $rows=[];
            foreach($s['jobs'] as $job) {
                if(!is_array($job))continue;
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
    if($jobId!==''){$where[]='j.id=?';$parameters[]=$jobId;}
    if($session!==''){$where[]='j.session_id=?';$parameters[]=$session;}
    if($status!=='') {
        $where[]="(CASE WHEN j.state='accepted' AND r.state IN ('sent','delivered','read','failed') THEN r.state ELSE j.state END)=?";
        $parameters[]=$status;
    }
    $whereSql=$where?' WHERE '.implode(' AND ',$where):'';
    $sql='SELECT j.id,j.client_id,j.session_id,j.destination,j.state,j.created_at,j.updated_at,j.attempts,j.response_code,j.error,
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
