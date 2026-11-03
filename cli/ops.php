<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/src/store.php';
$db=store();
$action=$argv[1]??'summary';
if ($action==='summary') {
    foreach (['jobs','event_deliveries'] as $table) {
        echo strtoupper($table)."\n";
        foreach ($db->query('SELECT state, COUNT(*) AS n FROM '.$table.' GROUP BY state ORDER BY state') as $row) {
            echo '  '.$row['state'].': '.$row['n']."\n";
        }
    }
    $file=dirname(__DIR__).'/runtime/wabridge.sqlite';
    echo "SQLite file bytes: ".(is_file($file)?filesize($file):0)."\n";
    exit(0);
}
if ($action==='review') {
    $q=$db->query("SELECT id,client_id,session_id,state,attempts,updated_at,response_code FROM jobs WHERE state='needs_review' ORDER BY updated_at DESC LIMIT 50");
    foreach ($q as $row) echo json_encode($row,JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}
if ($action==='dead-events') {
    $q=$db->query("SELECT event_id,subscriber_id,attempts,updated_at,last_http FROM event_deliveries WHERE state='dead' ORDER BY updated_at DESC LIMIT 50");
    foreach ($q as $row) echo json_encode($row,JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}
if ($action==='replay-event') {
    $event=(string)($argv[2]??'');$subscriber=(string)($argv[3]??'');
    if (!preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D',$event) || !preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',$subscriber)) {
        fwrite(STDERR,"Expected event ID and subscriber ID.\n");exit(2);
    }
    $q=$db->prepare("UPDATE event_deliveries SET state='queued',attempts=0,next_at=?,updated_at=? WHERE event_id=? AND subscriber_id=? AND state='dead'");
    $q->execute([time(),time(),$event,$subscriber]);
    echo $q->rowCount()===1?"Event rescheduled. Consumer must deduplicate.\n":"No matching dead event.\n";
    exit(0);
}
fwrite(STDERR,"Usage: php cli/ops.php [summary|review|dead-events|replay-event <event-id> <subscriber-id>]\n");
exit(2);
