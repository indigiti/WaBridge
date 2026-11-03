<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/src/store.php';
require_once dirname(__DIR__).'/src/message-processor.php';
$db=store();
storage_reap_jobs();
$limit=max(1,min(100,(int)($argv[1] ?? 20)));
$count=0;
while ($count<$limit && ($result=process_next_message($db))) {
    $count++;
    // Neither HTTP acceptance nor this worker's exit is delivery evidence.
}
storage_cleanup();
echo "Processed ".$count." jobs (see authenticated Message Log for acceptance/receipt status)\n";
