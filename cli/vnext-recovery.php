<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__).'/src/vnext/recovery.php';
// CLI accepts only explicitly provisioned UUIDv4 tenant IDs.
// No real provider calls, no messaging, no campaign execution.
$id=(string)($argv[1]??'');
if(!VNextJsonStore::uuid($id)||count($argv)!==2){
    fwrite(STDERR,"Usage: php cli/vnext-recovery.php <tenant-uuid-v4>\n");
    exit(2);
}
try {
    $result=(new VNextRecovery(new VNextEventJournal(new VNextJsonStore())))
        ->drain($id,20);
    echo 'Locally normalized: '.(int)$result['normalized']."\n";
}catch(Throwable $e){
    error_log('WaBridge vNext recovery '.get_class($e));
    fwrite(STDERR,"Recovery unavailable; inspect private logs\n");
    exit(1);
}
