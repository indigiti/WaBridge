<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/src/store.php';
require_once dirname(__DIR__).'/src/ops/connection-watchdog.php';
try {
    $report=watchdog_run();
    echo 'Connection watchdog: '.($report['gateway']??$report['state']??'unknown').
        '; sampled '.(int)($report['sampled_sessions']??0).
        '; connected '.(int)($report['connected']??0).
        '; disconnected '.(int)($report['disconnected']??0).
        '; unknown '.(int)($report['unknown']??0)."\n";
} catch(Throwable $e) {
    error_log('WaBridge watchdog '.get_class($e));
    fwrite(STDERR,"Connection watchdog unavailable. Check private server logs.\n");
    exit(1);
}
