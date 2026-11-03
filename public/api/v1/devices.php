<?php
declare(strict_types=1);
require dirname(__DIR__,3).'/src/store.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function reply(int $status,array $data): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
try {
    $client=client_auth();
    if (!$client) reply(401,['error'=>'unauthorized']);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if ($method==='GET') {
        $r=gateway('GET','/api/v1/sessions');
        if ($r['http']!==200) reply(502,['error'=>'gateway_unavailable']);
        $all=$r['data']['sessions']??[];
        $filtered=[];
        foreach ($all as $item) {
            if (!is_array($item)) continue;
            $id=(string)($item['id']??'');
            if (in_array($id,$client['sessions'],true)) $filtered[]=[
                'id'=>$id,'name'=>$item['name']??$id,
                'status'=>$item['status']??'unknown',
                'is_logged_in'=>(bool)($item['is_logged_in']??false)
            ];
        }
        reply(200,['sessions'=>$filtered,'count'=>count($filtered)]);
    }
    if ($method==='POST') {
        if (!limit_client(store(),$client)) reply(429,['error'=>'rate_limited']);
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>2048) reply(413,['error'=>'payload_too_large']);
        $raw=file_get_contents('php://input',false,null,0,2049);
        if (!is_string($raw)||strlen($raw)>2048) reply(413,['error'=>'payload_too_large']);
        $json=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        $id=(string)($json['id']??'');
        if (!valid_session_id($id)||!in_array($id,$client['sessions'],true)) reply(403,['error'=>'session_not_assigned']);
        $result=gateway('POST','/api/v1/sessions',['id'=>$id,'name'=>$id]);
        reply($result['http'],$result['data']);
    }
    header('Allow: GET, POST');reply(405,['error'=>'method_not_allowed']);
} catch (Throwable $e) {
    error_log('WaBridge devices endpoint: '.get_class($e));
    reply(503,['error'=>'service_unavailable']);
}
