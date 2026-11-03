<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$cases = [
    ['abc_123', true],['a-b', true],['../etc/passwd', false],['',false],['x/y',false]
];
foreach ($cases as [$sid,$expected]) {
    if (valid_session_id($sid)!==$expected) throw new RuntimeException('Session validator failed: '.$sid);
}
echo "Validator smoke tests passed\n";
