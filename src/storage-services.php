<?php
declare(strict_types=1);

/**
 * Storage facade: endpoint code does not issue SQL.
 * JSON implementation is explicitly limited to low-volume testing.
 * SQLite is the backward-compatible legacy driver. MariaDB is planned,
 * not advertised as an implemented/verified driver.
 */
function storage_client_create(array $row): void {
    $db=store();
    if ($db instanceof JsonStore) { $db->clientCreate($row);return; }
    $q=$db->prepare('INSERT INTO api_clients(id,token_sha256,sessions_json,per_minute,created_at,expires_at) VALUES(?,?,?,?,?,?)');
    $q->execute([$row['id'],$row['token_sha256'],$row['sessions_json'],$row['per_minute'],$row['created_at'],$row['expires_at']??null]);
}
function storage_client_revoke(string $id): int {
    $db=store();
    if ($db instanceof JsonStore) return $db->clientRevoke($id);
    $q=$db->prepare('UPDATE api_clients SET active=0 WHERE id=? AND active=1');
    $q->execute([$id]);return $q->rowCount();
}
function storage_clients(): array {
    $db=store();
    if ($db instanceof JsonStore) return $db->clients();
    return $db->query('SELECT id,sessions_json,per_minute,active,created_at,expires_at,last_used_at FROM api_clients ORDER BY id LIMIT 100')->fetchAll();
}
function storage_queue_stats(): array {
    $db=store();
    if ($db instanceof JsonStore) return $db->stats();
    return $db->query('SELECT state,COUNT(*) AS n FROM jobs GROUP BY state')->fetchAll();
}
/**
 * Return the oldest eligible queued job's ID and session only.
 * Checking session connectivity before claiming keeps disconnected jobs
 * queued (zero gateway calls) instead of incorrectly marking them sent.
 */
function storage_queued_job(PDO|JsonStore $db, ?string $jobId=null): ?array {
    if($jobId!==null&&!valid_job_id($jobId))
        throw new InvalidArgumentException('Invalid job ID');
    if($db instanceof JsonStore)return $db->queuedJob($jobId);
    if($jobId!==null){
        $q=$db->prepare("SELECT id,session_id FROM jobs WHERE id=? AND state='queued' AND next_at<=? LIMIT 1");
        $q->execute([$jobId,time()]);
    } else {
        $q=$db->prepare("SELECT id,session_id FROM jobs WHERE state='queued' AND next_at<=? ORDER BY created_at,id LIMIT 1");
        $q->execute([time()]);
    }
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?['id'=>(string)$row['id'],'session_id'=>(string)$row['session_id']]:null;
}

/**
 * At most one oldest queued job per WhatsApp session. An offline account
 * must not starve a different, connected session's send queue.
 */
function storage_queued_candidates(PDO|JsonStore $db,int $limit=12):array {
    $limit=max(1,min(20,$limit));
    if($db instanceof JsonStore)return $db->queuedCandidates($limit);
    // SQLite >= 3.25 supports ROW_NUMBER and PHP 8.4 ships a newer SQLite.
    $q=$db->prepare(
        "SELECT id,session_id FROM (
            SELECT id,session_id,created_at,
                   ROW_NUMBER() OVER(PARTITION BY session_id ORDER BY created_at,id) AS position
            FROM jobs WHERE state='queued' AND next_at<=?
        ) WHERE position=1 ORDER BY created_at,id LIMIT ?"
    );
    $q->bindValue(1,time(),PDO::PARAM_INT);
    $q->bindValue(2,$limit,PDO::PARAM_INT);
    $q->execute();
    return array_map(static fn(array $x):array=>[
        'id'=>(string)$x['id'],'session_id'=>(string)$x['session_id']
    ],$q->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Read-only admin activity log across PHP JSON and legacy SQLite.
 * Full recipient values are used only server-side to form a masked label.
 * Neither message bodies nor raw gateway responses enter this projection.
 */
function storage_admin_messages(int $limit=50): array {
    $limit=max(1,min(200,$limit));
    $db=store();
    if ($db instanceof JsonStore) return $db->adminMessages($limit);
    $q=$db->prepare('SELECT j.id,j.client_id,j.session_id,j.destination,j.state,j.created_at,j.updated_at,j.attempts,j.response_code,j.error,
        j.engine_response AS reason_code,
        r.provider_id,r.state AS receipt_state,r.observed_at AS receipt_observed_at
        FROM jobs j LEFT JOIN message_receipts r ON r.job_id=j.id
        ORDER BY j.created_at DESC, j.id DESC LIMIT ?');
    $q->bindValue(1,$limit,PDO::PARAM_INT);
    $q->execute();
    $rows=[];
    foreach ($q->fetchAll() as $job) {
        $receipt=isset($job['provider_id']) ? [
            'provider_id'=>(string)$job['provider_id'],
            'state'=>(string)($job['receipt_state']??''),
            'observed_at'=>(int)($job['receipt_observed_at']??0),
        ] : null;
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
            'error'=>(string)($job['error']??''),
            'reason_code'=>(string)($job['reason_code']??''),
            'receipt'=>$receipt,
        ];
    }
    return $rows;
}
function storage_job_status(string $jobId,string $clientId): ?array {
    $db=store();
    if ($db instanceof JsonStore) {
        $row=$db->job($jobId,$clientId);
        if (!$row) return null;
        $row['receipt']=$db->receipt($jobId);
        return $row;
    }
    $q=$db->prepare('SELECT id,state,created_at,updated_at,response_code FROM jobs WHERE id=? AND client_id=?');
    $q->execute([$jobId,$clientId]);$row=$q->fetch();
    if (!$row) return null;
    $qr=$db->prepare('SELECT provider_id,state,observed_at FROM message_receipts WHERE job_id=? LIMIT 1');
    $qr->execute([$jobId]);
    $row['receipt']=$qr->fetch()?:null;
    return $row;
}
function storage_event_accept(string $eventId,string $sid,string $type,string $raw,array $event,array $subscribers): bool {
    $db=store();
    if ($db instanceof JsonStore) {
        $added=$db->eventReceive($eventId,$sid,$type,$raw,$subscribers);
        if ($added) update_receipt_from_event($db,$eventId,$event);
        return $added;
    }
    $stmt=$db->prepare('INSERT OR IGNORE INTO events(id,session_id,event_type,received_at,payload) VALUES(?,?,?,?,?)');
    $stmt->execute([$eventId,$sid,$type,time(),$raw]);
    if ($stmt->rowCount()!==1) return false;
    update_receipt_from_event($db,$eventId,$event);
    foreach ($subscribers as $subscriberId=>$subscriber) {
        if (!is_array($subscriber) || !preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',(string)$subscriberId)) continue;
        if (!in_array($sid,(array)($subscriber['sessions']??[]),true)) continue;
        $events=(array)($subscriber['events']??['message','receipt','connected','disconnected']);
        if (!in_array($type,$events,true)) continue;
        $insert=$db->prepare('INSERT OR IGNORE INTO event_deliveries(event_id,subscriber_id,next_at,updated_at) VALUES(?,?,?,?)');
        $insert->execute([$eventId,(string)$subscriberId,time(),time()]);
    }
    return true;
}
function storage_reports(): array {
    $db=store();
    if ($db instanceof JsonStore) {
        $report=$db->reports();
        $report['storage']=['driver'=>'json','bytes'=>$db->size(),'single_server_test_mode'=>true];
        return $report;
    }
    return [
        'jobs'=>$db->query('SELECT state,COUNT(*) AS count FROM jobs GROUP BY state')->fetchAll(),
        'receipts'=>$db->query('SELECT state,COUNT(*) AS count FROM message_receipts GROUP BY state')->fetchAll(),
        'event_types'=>$db->query('SELECT event_type,COUNT(*) AS count FROM events GROUP BY event_type ORDER BY count DESC LIMIT 20')->fetchAll(),
        'api_clients'=>$db->query('SELECT id,active,per_minute,expires_at,last_used_at FROM api_clients ORDER BY id LIMIT 100')->fetchAll(),
        'audit'=>$db->query('SELECT guid,event,actor,target,at FROM audit_entries ORDER BY id DESC LIMIT 10')->fetchAll(),
        'storage'=>['driver'=>'sqlite','bytes'=>(int)@filesize(dirname(__DIR__).'/runtime/wabridge.sqlite'),'single_server_test_mode'=>false],
    ];
}
function storage_health(): bool {
    $db=store();
    return $db instanceof JsonStore ? $db->health() : (int)$db->query('SELECT 1')->fetchColumn()===1;
}
function storage_reap_jobs(): void {
    $db=store();
    if ($db instanceof JsonStore) { $db->cleanup();return; }
    $stale=$db->prepare("UPDATE jobs SET state='needs_review',error='worker_interrupted',updated_at=? WHERE state='processing' AND updated_at<?");
    $stale->execute([time(),time()-300]);
}
function storage_cleanup(): void {
    $db=store();
    if ($db instanceof JsonStore) { $db->cleanup();return; }
    $db->prepare('DELETE FROM quotas WHERE bucket < ?')->execute([intdiv(time(),60)-120]);
    $db->prepare('DELETE FROM events WHERE received_at < ?')->execute([time()-7*86400]);
    $db->prepare("DELETE FROM jobs WHERE state IN ('accepted','needs_review') AND updated_at < ?")->execute([time()-14*86400]);
}
function storage_event_stale(): void {
    $db=store();
    if ($db instanceof JsonStore) { $db->eventStale();return; }
    $db->prepare("UPDATE event_deliveries SET state='dead',updated_at=? WHERE state='processing' AND updated_at<?")->execute([time(),time()-600]);
}
function storage_event_claim(): ?array {
    $db=store();
    if ($db instanceof JsonStore) return $db->eventClaim();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare("SELECT d.*,e.payload FROM event_deliveries d JOIN events e ON e.id=d.event_id WHERE d.state='queued' AND d.next_at<=? ORDER BY d.next_at LIMIT 1");
        $q->execute([time()]);$job=$q->fetch();
        if ($job) {
            $claim=$db->prepare("UPDATE event_deliveries SET state='processing',attempts=attempts+1,updated_at=? WHERE event_id=? AND subscriber_id=?");
            $claim->execute([time(),$job['event_id'],$job['subscriber_id']]);
        }
        $db->commit();return $job?:null;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
function storage_event_finish(string $eventId,string $subscriber,string $state,int $nextAt,int $code): void {
    $db=store();
    if ($db instanceof JsonStore) { $db->eventFinish($eventId,$subscriber,$state,$nextAt,$code);return; }
    $q=$db->prepare('UPDATE event_deliveries SET state=?,next_at=?,last_http=?,updated_at=? WHERE event_id=? AND subscriber_id=?');
    $q->execute([$state,$nextAt,$code,time(),$eventId,$subscriber]);
}
function storage_ops(string $mode): array {
    $db=store();
    if ($db instanceof JsonStore) return $db->ops($mode);
    if ($mode==='summary') {
        $out=[];
        foreach (['jobs','event_deliveries'] as $table)
            $out[$table]=$db->query('SELECT state,COUNT(*) AS n FROM '.$table.' GROUP BY state ORDER BY state')->fetchAll();
        return $out;
    }
    if ($mode==='review')
        return $db->query("SELECT id,client_id,session_id,state,attempts,updated_at,response_code FROM jobs WHERE state='needs_review' ORDER BY updated_at DESC LIMIT 50")->fetchAll();
    if ($mode==='dead-events')
        return $db->query("SELECT event_id,subscriber_id,attempts,updated_at,last_http FROM event_deliveries WHERE state='dead' ORDER BY updated_at DESC LIMIT 50")->fetchAll();
    return [];
}
function storage_event_replay(string $eventId,string $subscriber): bool {
    $db=store();
    if ($db instanceof JsonStore) return $db->eventReplay($eventId,$subscriber);
    $q=$db->prepare("UPDATE event_deliveries SET state='queued',attempts=0,next_at=?,updated_at=? WHERE event_id=? AND subscriber_id=? AND state='dead'");
    $q->execute([time(),time(),$eventId,$subscriber]);return $q->rowCount()===1;
}
