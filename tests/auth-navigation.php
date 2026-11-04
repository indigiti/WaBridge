<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
function equal_return(string $input,string $expected):void{
    $actual=auth_safe_return_path($input);
    if($actual!==$expected)throw new RuntimeException(
        'Unsafe login destination or lost internal deep-link: '.$input.
        ' actual='.$actual.' expected='.$expected);
}
$uuid='a8b5de5d-ac84-4169-9347-21144cedd39c';
equal_return('/wabridge/media?utm_source=chatgpt.com','/wabridge/media');
equal_return('/wabridge/media?contact='.$uuid,'/wabridge/media?contact='.$uuid);
equal_return('/wabridge/schedules?contact='.$uuid.'&template='.$uuid,
    '/wabridge/schedules?contact='.$uuid.'&template='.$uuid);
equal_return('/wabridge/contacts?q=Jane%20Doe&page=2',
    '/wabridge/contacts?page=2&q=Jane%20Doe');
equal_return('/wabridge/contacts?q=919876543210','/wabridge/contacts');
equal_return('/wabridge/trace?id='.$uuid,'/wabridge/trace?id='.$uuid);
equal_return('/wabridge/trace?id=unknown','/wabridge/trace');
equal_return('/wabridge/media?contact=oops','/wabridge/media');
equal_return('/wabridge/media?bad=1','/wabridge/media');
equal_return('/wabridge/messages?page=3&status=delivered',
    '/wabridge/messages?page=3&status=delivered');
equal_return('/wabridge/qr?sid=example-01','/wabridge/qr?sid=example-01');
equal_return('/wabridge/','/wabridge/');
foreach([
    'https://attacker.example/wabridge/media',
    '//attacker.example/wabridge/media',
    '/wabridge/%2F%2Fattacker.example',
    '/wabridge/sign-in?return=evil',
    '/wabridge/media#fragment',
    '/wabridge/media'."\r\n".'Location: https://attacker.example',
    '/wabridge/../../outside',
    '/another-app/media',
    'javascript:alert(1)',
] as $unsafe)equal_return($unsafe,'/wabridge/');
if(auth_destination_label('/wabridge/media')!=='Media messages')
    throw new RuntimeException('Missing contextual sign-in title');
if(!function_exists('require_admin_html_page'))
    throw new RuntimeException('Shared HTML guard missing');
echo "Whitelisted same-origin login continuation and safe query handling: PASS\n";
