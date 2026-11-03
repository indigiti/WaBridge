<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
require dirname(__DIR__).'/src/store.php';
function usage(): never {
    fwrite(STDERR,"Usage:\n  php cli/clients.php create <client-id> <session1,session2> [rate/min]\n  php cli/clients.php revoke <client-id>\n  php cli/clients.php list\n");
    exit(2);
}
$action=$argv[1]??'';
if ($action==='create') {
    $id=(string)($argv[2]??'');
    $raw=(string)($argv[3]??'');
    if (!preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',$id)) usage();
    $sessions=array_values(array_unique(array_filter(explode(',',$raw),'valid_session_id')));
    if (!$sessions || count($sessions)>20 || implode(',',$sessions)!==$raw) usage();
    $rate=max(1,min(600,(int)($argv[4]??30)));
    $secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    try {
        storage_client_create(['id'=>$id,'token_sha256'=>hash('sha256',$secret),
            'sessions_json'=>json_encode($sessions,JSON_THROW_ON_ERROR),'per_minute'=>$rate,
            'created_at'=>time(),'expires_at'=>null]);
    } catch (Throwable $e) { fwrite(STDERR,"Client already exists or storage error.\n");exit(1); }
    echo "API client created: ".$id."\nToken (save now, shown only once): ".$secret."\n";exit(0);
}
if ($action==='revoke') {
    $id=(string)($argv[2]??'');
    if (!preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',$id)) usage();
    echo "Revoked ".storage_client_revoke($id)." client(s)\n";exit(0);
}
if ($action==='list') {
    foreach (storage_clients() as $row) {
        echo $row['id']." sessions=".$row['sessions_json']." rate=".$row['per_minute']." active=".$row['active']."\n";
    }
    exit(0);
}
usage();
