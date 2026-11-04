<?php
declare(strict_types=1);
// Deterministic provider-free checks; never touch a live Waxum session.
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/ops/connection-watchdog.php';
require dirname(__DIR__).'/src/ops/retry-policy.php';
function permitted_fixture_session(string $sid):bool{return in_array($sid,['test-1','test-2'],true);}
$previous=['devices'=>[['key'=>substr(hash('sha256','test-1'),0,16),'state'=>'disconnected']]];
// Use bootstrap-configured allowed_sessions only in actual runtime; for this
// isolated test we pass session names through the standard config function.
$configPath=dirname(__DIR__).'/config.php';
if(file_exists($configPath))throw new RuntimeException('Refusing to alter live configuration');
file_put_contents($configPath,'<?php return '.var_export([
    'gateway_url'=>'http://127.0.0.1:3451',
    'gateway_token'=>'unit-test-token','admin_user'=>'tests',
    'admin_password_hash'=>'unit-test','allowed_sessions'=>['test-1','test-2']
],true).';');
register_shutdown_function(static fn()=>@unlink($configPath));
$report=watchdog_poll(
    static fn():array=>['ready'=>true],
    static fn():array=>[['id'=>'test-1'],['id'=>'test-2'],['id'=>'unknown']],
    static fn(string $sid):array=>[
        'http'=>200,'is_logged_in'=>$sid==='test-1','socket_alive'=>$sid==='test-1'
    ],
    $previous,2
);
if($report['connected']!==1||$report['disconnected']!==1||
   count($report['transitions'])!==1||$report['reported_sessions']!==2)
    throw new RuntimeException('Watchdog must count allowed sessions and detect recovery');
if(str_contains(json_encode($report,JSON_THROW_ON_ERROR),'test-1'))
    throw new RuntimeException('Watchdog snapshot must not expose session names');
foreach([
    ['queued',0,'process_when_connected'],
    ['queued',1,'hold_for_review'],
    ['processing',1,'wait_for_worker'],
    ['needs_review',1,'manual_investigation'],
    ['accepted',1,'no_retry'],
] as [$state,$attempts,$expected]){
    $policy=message_retry_policy(['state'=>$state,'attempts'=>$attempts]);
    if($policy['action']!==$expected||$policy['automatic_resend']!==false)
        throw new RuntimeException('Unsafe automated retry decision');
}
echo "Connection watchdog recovery observations, privacy and fail-closed retry policy: PASS\n";
