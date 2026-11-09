<?php
declare(strict_types=1);
// Local test-only router for php -S, because .htaccess is Apache-specific.
$path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
if($path==='/wabridge/team-inbox'||$path==='/wabridge/team-inbox/'){
    require dirname(__DIR__).'/public/team-inbox.php';
    return true;
}
if($path==='/wabridge/account-admin'||$path==='/wabridge/account-admin/'){
    require dirname(__DIR__).'/public/account-admin.php';
    return true;
}
if($path==='/wabridge/media'||$path==='/wabridge/media/'){
    $_GET['module']='media';
    require dirname(__DIR__).'/public/platform.php';
    return true;
}
if($path==='/wabridge/sign-in'||$path==='/wabridge/'||
   $path==='/wabridge/overview'){
    require dirname(__DIR__).'/public/index.php';
    return true;
}
if($path==='/wabridge/assets/wabridge-ui-v6.css'){
    header('Content-Type: text/css');
    readfile(dirname(__DIR__).'/public/assets/wabridge-ui-v6.css');
    return true;
}
http_response_code(404);
echo 'Not found';
return true;
