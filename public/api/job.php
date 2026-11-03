<?php
declare(strict_types=1);
require dirname(__DIR__).'/_loader.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { http_response_code(405);exit; }
$c=client_auth();
if (!$c) { http_response_code(401);echo '{"error":"unauthorized"}';exit; }
$id=(string)($_GET['id']??'');
if (!preg_match('/\A[a-f0-9]{32}\z/D',$id)) { http_response_code(400);echo '{"error":"invalid_id"}';exit; }
$q=store()->prepare('SELECT id,state,created_at,updated_at,response_code FROM jobs WHERE id=? AND client_id=?');
$q->execute([$id,$c['id']]);$row=$q->fetch();
if (!$row) { http_response_code(404);echo '{"error":"not_found"}';exit; }
$receipts=store()->prepare('SELECT provider_id,state,observed_at FROM message_receipts WHERE job_id=? LIMIT 1');
$receipts->execute([$id]);
$row['receipt']=$receipts->fetch()?:null;
echo json_encode($row,JSON_UNESCAPED_SLASHES);
