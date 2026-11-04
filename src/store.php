<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/json-store.php';
/**
 * Storage driver selection is private and immutable within each request.
 * Existing SQLite installations stay on SQLite until explicitly switched.
 * JSON is the default only for fresh testing installations.
 */
function storage_mode(): string {
    $selector=dirname(__DIR__).'/runtime/storage-mode.json';
    if (is_link($selector)) throw new RuntimeException('Storage selector symlink prohibited');
    if (is_file($selector)) {
        $raw=file_get_contents($selector);
        $selected=json_decode((string)$raw,true,8,JSON_THROW_ON_ERROR);
        if (!is_array($selected)||!in_array($selected['driver']??null,['json','sqlite'],true))
            throw new RuntimeException('Invalid storage mode selector');
        return $selected['driver'];
    }
    $configured=(string)(config()['storage_driver']??'');
    if ($configured!=='') {
        if (!in_array($configured,['json','sqlite'],true))
            throw new RuntimeException('Storage driver unavailable; MariaDB requires a tested adapter');
        return $configured;
    }
    return is_file(dirname(__DIR__).'/runtime/wabridge.sqlite') ? 'sqlite' : 'json';
}
function storage_operational_guard(): void {
    if (is_file(dirname(__DIR__).'/runtime/storage-migrating.flag'))
        throw new RuntimeException('Storage migration in progress; try again later');
}
function store(): PDO|JsonStore {
    storage_operational_guard();
    static $connection;
    if ($connection instanceof PDO || $connection instanceof JsonStore) return $connection;
    if (storage_mode()==='json') return $connection=new JsonStore();
    // Legacy SQLite deployments continue unaffected until explicitly switched.
    $pdo = null;
    $dir = dirname(__DIR__) . '/runtime';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot create runtime directory');
    @chmod($dir, 0700);
    $pdo = new PDO('sqlite:' . $dir . '/wabridge.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
    $pdo->exec('CREATE TABLE IF NOT EXISTS jobs (
      id TEXT PRIMARY KEY, client_id TEXT NOT NULL, session_id TEXT NOT NULL,
      destination TEXT NOT NULL, body TEXT NOT NULL, idem TEXT NOT NULL,
      state TEXT NOT NULL DEFAULT "queued", attempts INTEGER NOT NULL DEFAULT 0,
      next_at INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
      response_code INTEGER, error TEXT, engine_response TEXT,
      UNIQUE (client_id, idem)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_jobs_ready ON jobs(state,next_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_jobs_created ON jobs(created_at DESC,id DESC)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS message_timeline (
      seq INTEGER PRIMARY KEY AUTOINCREMENT,
      guid TEXT NOT NULL UNIQUE,
      job_id TEXT NOT NULL,
      state TEXT NOT NULL,
      proof TEXT NOT NULL,
      at INTEGER NOT NULL,
      FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_message_timeline_job_seq ON message_timeline(job_id,seq)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS message_receipts (
      job_id TEXT NOT NULL, provider_id TEXT NOT NULL UNIQUE,
      state TEXT NOT NULL, observed_at INTEGER NOT NULL, source_event_id TEXT,
      PRIMARY KEY(job_id,provider_id),
      FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS audit_entries (
      id INTEGER PRIMARY KEY AUTOINCREMENT, guid TEXT, event TEXT NOT NULL,
      actor TEXT NOT NULL, target TEXT, at INTEGER NOT NULL, details TEXT NOT NULL DEFAULT "{}"
    )');
    // Existing rows retain their numeric internal ID. New events get a GUID.
    $auditColumns=array_column($pdo->query('PRAGMA table_info(audit_entries)')->fetchAll(),'name');
    if(!in_array('guid',$auditColumns,true))$pdo->exec('ALTER TABLE audit_entries ADD COLUMN guid TEXT');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ix_audit_guid ON audit_entries(guid) WHERE guid IS NOT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_audit_at ON audit_entries(at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS quotas (
      client_id TEXT NOT NULL, bucket INTEGER NOT NULL, used INTEGER NOT NULL,
      PRIMARY KEY(client_id,bucket)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS api_clients (
      id TEXT PRIMARY KEY, token_sha256 TEXT NOT NULL UNIQUE,
      sessions_json TEXT NOT NULL, per_minute INTEGER NOT NULL DEFAULT 30,
      active INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL
    )');
    $columns=$pdo->query('PRAGMA table_info(api_clients)')->fetchAll();
    $names=array_column($columns,'name');
    if (!in_array('expires_at',$names,true)) $pdo->exec('ALTER TABLE api_clients ADD COLUMN expires_at INTEGER');
    if (!in_array('last_used_at',$names,true)) $pdo->exec('ALTER TABLE api_clients ADD COLUMN last_used_at INTEGER');
    $pdo->exec('CREATE TABLE IF NOT EXISTS events (
      id TEXT PRIMARY KEY, session_id TEXT, event_type TEXT, received_at INTEGER NOT NULL, payload TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_events_date ON events(received_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS event_deliveries (
      event_id TEXT NOT NULL, subscriber_id TEXT NOT NULL, state TEXT NOT NULL DEFAULT "queued",
      attempts INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL,
      updated_at INTEGER NOT NULL, last_http INTEGER,
      PRIMARY KEY(event_id,subscriber_id),
      FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_event_delivery_ready ON event_deliveries(state,next_at)');
    return $connection=$pdo;
}
function client_auth(): ?array {
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/\ABearer ([a-zA-Z0-9._~-]{24,256})\z/D', $auth, $matches)) return null;
    $hash = hash('sha256', $matches[1]);
    $db=store();
    if ($db instanceof JsonStore) $managed=$db->clientByHash($hash);
    else {
        $q=$db->prepare('SELECT id,sessions_json,per_minute FROM api_clients WHERE token_sha256=? AND active=1 AND (expires_at IS NULL OR expires_at>?) LIMIT 1');
        $q->execute([$hash,time()]);
        $managed=$q->fetch();
    }
    if ($managed) {
        if ($db instanceof JsonStore) $db->markClientUsed($managed['id']);
        else {
            $usage=$db->prepare('UPDATE api_clients SET last_used_at=? WHERE id=?');
            $usage->execute([time(),$managed['id']]);
        }
        $sessions=json_decode($managed['sessions_json'],true);
        return ['id'=>$managed['id'],'sessions'=>is_array($sessions)?$sessions:[],'limit'=>max(1,min(600,(int)$managed['per_minute']))];
    }
    foreach ((array)(config()['api_clients'] ?? []) as $id => $client) {
        if (!is_array($client) || empty($client['token_sha256']) || !hash_equals((string)$client['token_sha256'], $hash)) continue;
        return ['id'=>(string)$id, 'sessions'=>(array)($client['sessions'] ?? []), 'limit'=>max(1,(int)($client['per_minute'] ?? 30))];
    }
    // Backward compatibility only for initial single-client deployments.
    $old = (string)(config()['external_token_sha256'] ?? '');
    if (preg_match('/\A[a-f0-9]{64}\z/D', $old) && hash_equals($old, $hash)) {
        return ['id'=>'legacy','sessions'=>(array)(config()['allowed_sessions'] ?? []),'limit'=>15];
    }
    return null;
}
function limit_client(PDO|JsonStore $db, array $client): bool {
    storage_operational_guard();
    if ($db instanceof JsonStore) return $db->quota((string)$client['id'],(int)$client['limit']);
    $bucket = intdiv(time(), 60);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO quotas(client_id,bucket,used) VALUES(?,?,1)
          ON CONFLICT(client_id,bucket) DO UPDATE SET used=used+1 WHERE used < ?');
        $stmt->execute([$client['id'], $bucket, $client['limit']]);
        $ok = $stmt->rowCount() === 1;
        $db->commit();
        return $ok;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function enqueue_message(PDO|JsonStore $db, array $client, string $sid, string $to, string $body, string $idem): array {
    storage_operational_guard();
    if (!$client['sessions'] || !in_array($sid, $client['sessions'], true)) throw new InvalidArgumentException('Session not assigned to this API client');
    if (!preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D', $idem)) throw new InvalidArgumentException('Idempotency-Key required, 8–128 safe characters');
    if ($db instanceof JsonStore) return $db->enqueue($client,$sid,$to,$body,$idem);
    $db->exec('BEGIN IMMEDIATE');
    try {
    // A bounded queue prevents runaway disk usage under gateway outages.
    $existing = $db->prepare('SELECT id FROM jobs WHERE client_id=? AND idem=?');
    $existing->execute([$client['id'],$idem]);
    if (!$existing->fetchColumn()) {
        $pending = $db->prepare("SELECT COUNT(*) FROM jobs WHERE client_id=? AND state IN ('queued','processing')");
        $pending->execute([$client['id']]);
        if ((int)$pending->fetchColumn() >= 1000) throw new RuntimeException('Queue capacity exhausted for client');
    }
    $id = wabridge_guid(); $now = time();
    $stmt = $db->prepare('INSERT OR IGNORE INTO jobs(id,client_id,session_id,destination,body,idem,next_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$id,$client['id'],$sid,$to,$body,$idem,$now,$now,$now]);
    $q = $db->prepare('SELECT id,state,session_id,destination FROM jobs WHERE client_id=? AND idem=?');
    $q->execute([$client['id'],$idem]);
    $row = $q->fetch();
    if (!$row || $row['session_id'] !== $sid || $row['destination'] !== $to) throw new InvalidArgumentException('Idempotency key reused with different recipient or session');
    // Reject reused idempotency key with different body without storing plaintext extra fields.
    if ($row['id'] !== $id) {
        $s = $db->prepare('SELECT body FROM jobs WHERE id=?'); $s->execute([$row['id']]);
        if (!hash_equals((string)$s->fetchColumn(), $body)) throw new InvalidArgumentException('Idempotency key reused with different message');
    }
    $result=['id'=>$row['id'],'state'=>$row['state'],'duplicate'=>$row['id'] !== $id];
    if(!$result['duplicate']) {
        $trace=$db->prepare('INSERT INTO message_timeline(guid,job_id,state,proof,at) VALUES(?,?,?,?,?)');
        $trace->execute([wabridge_guid(),$id,'queued','local_queue',time()]);
    }
    $db->commit();
    return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
/**
 * Atomic queue claim. An optional ID ensures a browser-submitted message
 * never accidentally dispatches an unrelated older queued message.
 */
function claim_job(PDO|JsonStore $db, ?string $jobId=null): ?array {
    storage_operational_guard();
    if ($jobId!==null&&!valid_job_id($jobId))
        throw new InvalidArgumentException('Invalid job ID');
    if ($db instanceof JsonStore) return $db->claim($jobId);
    $now=time();
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ($jobId!==null) {
            $q=$db->prepare("SELECT * FROM jobs WHERE id=? AND state='queued' AND next_at<=? LIMIT 1");
            $q->execute([$jobId,$now]);
        } else {
            $q=$db->prepare("SELECT * FROM jobs WHERE state='queued' AND next_at<=? ORDER BY created_at,id LIMIT 1");
            $q->execute([$now]);
        }
        $job=$q->fetch();
        if ($job) {
            $update=$db->prepare("UPDATE jobs SET state='processing',attempts=attempts+1,updated_at=? WHERE id=? AND state='queued'");
            $update->execute([$now,$job['id']]);
            if ($update->rowCount()!==1)$job=null;
            else {
                $trace=$db->prepare('INSERT INTO message_timeline(guid,job_id,state,proof,at) VALUES(?,?,?,?,?)');
                $trace->execute([wabridge_guid(),(string)$job['id'],'processing','worker_claim',time()]);
            }
        }
        $db->commit();
        return $job?:null;
    } catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function complete_job(PDO|JsonStore $db, string $id, string $state, int $code, string $response = ''): void {
    storage_operational_guard();
    if ($db instanceof JsonStore) { $db->complete($id,$state,$code,$response);return; }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $now=time();
        $s=$db->prepare('UPDATE jobs SET state=?,response_code=?,engine_response=?,updated_at=? WHERE id=?');
        $s->execute([$state,$code,substr($response,0,2000),$now,$id]);
        if($s->rowCount()===1) {
            $trace=$db->prepare('INSERT INTO message_timeline(guid,job_id,state,proof,at) VALUES(?,?,?,?,?)');
            $proof=$state==='accepted'?'gateway_accepted':'send_uncertain';
            $trace->execute([wabridge_guid(),$id,$state,$proof,$now]);
        }
        $db->commit();
    } catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}

function audit_event(string $event,string $actor,string $target='',array $details=[]): void {
    // Never include plaintext messages, passwords, tokens or QR secrets.
    if (store() instanceof JsonStore) { store()->audit($event,$actor,$target,$details); return; }
    $q=store()->prepare('INSERT INTO audit_entries(guid,event,actor,target,at,details) VALUES(?,?,?,?,?,?)');
    $q->execute([wabridge_guid(),$event,substr($actor,0,96),substr($target,0,128),time(),json_encode($details,JSON_THROW_ON_ERROR)]);
}
function record_provider_message(PDO|JsonStore $db,string $jobId,string $providerId): void {
    if ($providerId==='' || strlen($providerId)>200) return;
    if ($db instanceof JsonStore) { $db->receiptSave($jobId,$providerId);return; }
    $q=$db->prepare("INSERT OR IGNORE INTO message_receipts(job_id,provider_id,state,observed_at) VALUES(?,?,'accepted',?)");
    $q->execute([$jobId,$providerId,time()]);
}
/**
 * Receipt events can arrive out of order. A late 'sent' cannot undo a
 * verified delivery/read; a late failure cannot erase delivery evidence.
 * An authoritative later delivery/read can resolve a prior failed receipt.
 */
function receipt_state_can_advance(string $current,string $next):bool {
    if($current===$next)return false;
    return match($current) {
        'read'=>false,
        'delivered'=>$next==='read',
        'failed'=>in_array($next,['delivered','read'],true),
        'sent'=>in_array($next,['delivered','read','failed'],true),
        'accepted'=>in_array($next,['sent','delivered','read','failed'],true),
        default=>false,
    };
}
function update_receipt_from_event(PDO|JsonStore $db,string $eventId,array $event): bool {
    // Never guess a receipt's job. Require an exact upstream message ID.
    $messageId=(string)($event['message_id']??($event['data']['message_id']??''));
    $rawState=strtolower((string)($event['status']??($event['data']['status']??'')));
    $map=['sent'=>'sent','delivered'=>'delivered','read'=>'read','played'=>'read','failed'=>'failed'];
    if ($messageId==='' || !isset($map[$rawState])) return false;
    if ($db instanceof JsonStore) return $db->receiptUpdate($messageId,$map[$rawState],$eventId);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $select=$db->prepare('SELECT job_id,state FROM message_receipts WHERE provider_id=? LIMIT 1');
        $select->execute([$messageId]);
        $match=$select->fetch();
        if(!$match){$db->commit();return false;}
        $next=$map[$rawState];$now=time();
        $advance=receipt_state_can_advance((string)$match['state'],$next);
        if($advance) {
            $stmt=$db->prepare('UPDATE message_receipts SET state=?,observed_at=?,source_event_id=? WHERE provider_id=?');
            $stmt->execute([$next,$now,$eventId,$messageId]);
        }
        if($advance) {
            $trace=$db->prepare('INSERT INTO message_timeline(guid,job_id,state,proof,at) VALUES(?,?,?,?,?)');
            $trace->execute([wabridge_guid(),(string)$match['job_id'],$next,'verified_provider_receipt',$now]);
        }
        $db->commit();
        return true;
    } catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}

require_once __DIR__.'/storage-services.php';
require_once __DIR__.'/audit-reader.php';
require_once __DIR__.'/message-read.php';
