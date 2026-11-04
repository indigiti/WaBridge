<?php
declare(strict_types=1);
// Pure request policy tests; no live gateway call or private credentials.
require dirname(__DIR__).'/src/bootstrap.php';
$cases=[
    ['GET','/livez',3],['GET','/readyz',3],
    ['GET','/api/v1/sessions',5],
    ['GET','/api/v1/sessions/demo-session/status',5],
    ['GET','/api/v1/events',8],
    ['POST','/api/v1/sessions/demo-session/messages/text',18],
    ['POST','/api/v1/sessions/demo-session/messages/image',18],
    ['POST','/api/v1/sessions/demo-session/cloud/connect',18],
];
foreach($cases as [$method,$path,$expected])
    if(gateway_request_timeout($method,$path)!==$expected)
        throw new RuntimeException('Gateway timeout regression: '.$method.' '.$path);
echo "Fail-fast Rust health/status requests with conservative send timeouts: PASS\n";
