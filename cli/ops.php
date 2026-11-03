<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
require dirname(__DIR__).'/src/store.php';
$action=(string)($argv[1]??'summary');
if ($action==='summary') {
    foreach (storage_ops('summary') as $table=>$rows) {
        echo strtoupper($table)."\n";
        foreach ($rows as $row) echo '  '.$row['state'].': '.$row['n']."\n";
    }
    $meta=storage_reports()['storage'];
    echo "Storage: ".$meta['driver']." (".$meta['bytes']." bytes)\n";exit(0);
}
if ($action==='review' || $action==='dead-events') {
    foreach (storage_ops($action) as $row) echo json_encode($row,JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}
if ($action==='replay-event') {
    $event=(string)($argv[2]??'');$subscriber=(string)($argv[3]??'');
    if (!preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D',$event) ||
        !preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',$subscriber)) {
        fwrite(STDERR,"Expected event ID and subscriber ID.\n");exit(2);
    }
    echo storage_event_replay($event,$subscriber) ? "Event rescheduled. Consumer must deduplicate.\n" : "No matching dead event.\n";
    exit(0);
}
fwrite(STDERR,"Usage: php cli/ops.php [summary|review|dead-events|replay-event <event-id> <subscriber-id>]\n");
exit(2);
