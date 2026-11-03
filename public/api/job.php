<?php
declare(strict_types=1);
require dirname(__DIR__).'/_loader.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { http_response_code(405);exit; }
$c=client_auth();
if (!$c) { http_response_code(401);echo '{"error":"unauthorized"}';exit; }
$id=(string)($_GET['id']??'');
if (!preg_match('/\A[a-f0-9]{32}\z/D',$id)) { http_response_code(400);echo '{"error":"invalid_id"}';exit; }
$row=storage_job_status($id,$c['id']);
if (!$row) { http_response_code(404);echo '{"error":"not_found"}';exit; }

echo json_encode($row,JSON_UNESCAPED_SLASHES);
