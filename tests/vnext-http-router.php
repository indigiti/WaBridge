<?php
declare(strict_types=1);
$path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
if($path==='/wabridge/api/v2/fixture-event.php'){
    require dirname(__DIR__).'/public/api/v2/fixture-event.php';
    return true;
}
http_response_code(404);
echo 'Not found';
return true;
