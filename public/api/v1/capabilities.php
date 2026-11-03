<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/_loader.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { http_response_code(405); exit; }
$c=client_auth();
if (!$c) { http_response_code(401);echo '{"error":"unauthorized"}';exit; }
echo json_encode(['provider'=>'waxum','features'=>engine_capabilities()],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
