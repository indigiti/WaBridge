<?php
declare(strict_types=1);

/** Safe, explicit SQLite-to-JSON cutover for SINGLE-SERVER TESTING ONLY. */
function storage_migrate_to_json(): array {
    if (storage_mode()==='json') return ['status'=>'already_json'];
    $old=store();
    if (!$old instanceof PDO) throw new RuntimeException('Legacy SQLite connection unavailable');
    $dir=dirname(__DIR__).'/runtime';
    $migrationLock=$dir.'/storage-migration.lock';
    $flag=$dir.'/storage-migrating.flag';
    $selector=$dir.'/storage-mode.json';
    if (is_link($selector)||is_link($migrationLock)||is_link($flag)) throw new RuntimeException('Symlink prohibited');
    $fh=fopen($migrationLock,'c+b');
    if (!$fh) throw new RuntimeException('Cannot acquire storage migration lock');
    @chmod($migrationLock,0600);
    try {
        if (!flock($fh,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another migration is already running');
        try {
            if (is_file($selector)||is_file($flag)) throw new RuntimeException('Existing migration state; aborting');
            $written=file_put_contents($flag,(string)time(),LOCK_EX);
            if (!$written) throw new RuntimeException('Cannot activate maintenance guard');
            @chmod($flag,0600);
            try {
                $old->exec('BEGIN IMMEDIATE');
                try {
                    $pendingJobs=(int)$old->query("SELECT COUNT(*) FROM jobs WHERE state IN ('queued','processing')")->fetchColumn();
                    $pendingEvents=(int)$old->query("SELECT COUNT(*) FROM event_deliveries WHERE state IN ('queued','processing')")->fetchColumn();
                    if ($pendingJobs>0 || $pendingEvents>0) {
                        throw new RuntimeException('Cannot switch while queued or processing messages/webhooks exist');
                    }
                    $source=[
                        'clients'=>$old->query('SELECT * FROM api_clients')->fetchAll(),
                        'jobs'=>$old->query('SELECT * FROM jobs')->fetchAll(),
                        'quotas'=>$old->query('SELECT * FROM quotas')->fetchAll(),
                        'events'=>$old->query('SELECT * FROM events')->fetchAll(),
                        'deliveries'=>$old->query('SELECT * FROM event_deliveries')->fetchAll(),
                        'receipts'=>$old->query('SELECT * FROM message_receipts')->fetchAll(),
                        'audit'=>$old->query('SELECT * FROM audit_entries ORDER BY id ASC')->fetchAll(),
                        'timeline'=>$old->query('SELECT guid,job_id,state,proof,at FROM message_timeline ORDER BY seq ASC')->fetchAll()
                    ];
                    $snapshot=['clients'=>[],'jobs'=>[],'quotas'=>[],
                        'events'=>[],'deliveries'=>[],'receipts'=>[],'audit'=>[],'timeline'=>[]];
                    foreach ($source['clients'] as $row) {
                        $row['active']=(int)$row['active'];
                        $row['per_minute']=(int)$row['per_minute'];
                        $row['created_at']=(int)$row['created_at'];
                        if ($row['expires_at']!==null) $row['expires_at']=(int)$row['expires_at'];
                        if ($row['last_used_at']!==null) $row['last_used_at']=(int)$row['last_used_at'];
                        $snapshot['clients'][(string)$row['id']]=$row;
                    }
                    foreach ($source['jobs'] as $row) $snapshot['jobs'][(string)$row['id']]=$row;
                    foreach ($source['quotas'] as $row) $snapshot['quotas'][$row['client_id'].'|'.$row['bucket']]=(int)$row['used'];
                    foreach ($source['events'] as $row) $snapshot['events'][(string)$row['id']]=$row;
                    foreach ($source['deliveries'] as $row)
                        $snapshot['deliveries'][$row['event_id'].'|'.$row['subscriber_id']]=$row;
                    foreach ($source['receipts'] as $row) $snapshot['receipts'][(string)$row['provider_id']]=$row;
                    foreach ($source['audit'] as $row) {
                        $details=json_decode((string)$row['details'],true);
                        $snapshot['audit'][]=[
                            'guid'=>$row['guid']??null,
                            'event'=>$row['event'],'actor'=>$row['actor'],'target'=>$row['target'],
                            'at'=>(int)$row['at'],'details'=>is_array($details)?$details:[]
                        ];
                    }
                    foreach($source['timeline'] as $entry) {
                        $jobId=(string)$entry['job_id'];
                        $snapshot['timeline'][$jobId]??=[];
                        $snapshot['timeline'][$jobId][]=[
                            'guid'=>(string)$entry['guid'],
                            'state'=>(string)$entry['state'],
                            'proof'=>(string)$entry['proof'],
                            'at'=>(int)$entry['at'],
                        ];
                        if(count($snapshot['timeline'][$jobId])>20)
                            $snapshot['timeline'][$jobId]=array_slice($snapshot['timeline'][$jobId],-20);
                    }
                    $json=new JsonStore();
                    $json->importSnapshot($snapshot);
                    $details=['driver'=>'json','migrated_at'=>gmdate('c'),
                        'legacy_file_retained'=>true,'schema'=>1];
                    $payload=json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                    $tmp=tempnam($dir,'.storage-mode-');
                    if (!$tmp) throw new RuntimeException('Cannot prepare mode selector');
                    try {
                        if (!chmod($tmp,0600) || file_put_contents($tmp,$payload,LOCK_EX)!==strlen($payload))
                            throw new RuntimeException('Cannot write private mode selector');
                        if (!rename($tmp,$selector)) throw new RuntimeException('Cannot activate JSON mode');
                    } finally { if (is_file($tmp)) @unlink($tmp); }
                    $old->commit();
                    return ['status'=>'migrated','clients'=>count($snapshot['clients']),
                        'jobs'=>count($snapshot['jobs']),'events'=>count($snapshot['events']),
                        'legacy_file_retained'=>true];
                } catch (Throwable $e) {
                    if ($old->inTransaction()) $old->rollBack();
                    throw $e;
                }
            } finally { @unlink($flag); }
        } finally { flock($fh,LOCK_UN); }
    } finally { fclose($fh); }
}
