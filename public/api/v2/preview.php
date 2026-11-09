<?php
declare(strict_types=1);
// W2-W6 preview API. The live provider and real multi-user auth are NOT exposed.
require dirname(__DIR__,2).'/_loader.php';
$src=dirname((new ReflectionFunction('store'))->getFileName());
require_once $src.'/vnext/inbox.php';
require_once $src.'/vnext/workflows.php';
require_once $src.'/vnext/campaigns.php';
require_once $src.'/vnext/assistant.php';
require_once $src.'/vnext/flows.php';
require_once $src.'/vnext/demo.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
function vnext_api_reply(int $status,array $data):never {
    http_response_code($status);
    echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    exit;
}
if((config()['enable_vnext_preview_api']??false)!==true) {
    vnext_api_reply(404,['error'=>'not_found']);
}
if(!admin_authenticated())vnext_api_reply(401,['error'=>'authentication_required']);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if(!in_array($method,['GET','POST'],true)){
    header('Allow: GET, POST');
    vnext_api_reply(405,['error'=>'method_not_allowed']);
}
$allowed=['inbox','workflows','campaigns','assistant','flows'];
$module=(string)($_GET['module']??'');
$tenant=(string)($_GET['tenant']??'');
if(!in_array($module,$allowed,true)||!in_array($tenant,
    array_column(VNextDemo::accounts(),'id'),true)){
    vnext_api_reply(404,['error'=>'unknown_pilot_resource']);
}
if($method==='POST') {
    $header=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
    if($header===''||!hash_equals(csrf(),$header))
        vnext_api_reply(403,['error'=>'csrf_required']);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>4096)
        vnext_api_reply(413,['error'=>'payload_too_large']);
    if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),
        'application/json')) {
        vnext_api_reply(415,['error'=>'json_content_type_required']);
    }
}
try{
    $store=new VNextJsonStore();
    $inbox=new VNextInbox($store);
    $workflows=new VNextWorkflows($store);
    $campaigns=new VNextCampaigns($store);
    $assistant=new VNextAssistant($store);
    $flows=new VNextFlows($store);
    if($method==='GET'){
        $records=match($module){
            'inbox'=>$inbox->overview($tenant),
            'workflows'=>$workflows->list($tenant),
            'campaigns'=>$campaigns->list($tenant),
            'assistant'=>$assistant->list($tenant),
            'flows'=>$flows->list($tenant),
        };
        vnext_api_reply(200,[
            'schema'=>'WABRIDGE-VNEXT-PREVIEW/1',
            'mode'=>'synthetic','tenant_id'=>$tenant,'module'=>$module,
            'data'=>$records,'live_operations_enabled'=>false
        ]);
    }
    $raw=file_get_contents('php://input',false,null,0,4097);
    if(!is_string($raw)||strlen($raw)>4096)vnext_api_reply(413,['error'=>'payload_too_large']);
    $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($body)||array_is_list($body))throw new InvalidArgumentException('JSON object required');
    $action=(string)($body['action']??'');
    $id=(string)($body['id']??'');
    $v=$body['version']??0;
    if(!is_int($v)||$v<0||$v>999999999)throw new InvalidArgumentException('Invalid version');
    $result=match($module) {
        'inbox'=>match($action) {
            'seed'=>$inbox->seed($tenant),
            'assign'=>$inbox->assign($tenant,$id,(string)($body['user_id']??''),$v),
            'status'=>$inbox->changeState($tenant,$id,(string)($body['state']??''),$v),
            'note'=>$inbox->addPresetNote($tenant,$id,(string)($body['note']??''),$v),
            default=>throw new InvalidArgumentException('Unsupported inbox action'),
        },
        'workflows'=>match($action) {
            'save'=>$workflows->saveDraft($tenant,(string)($body['title']??''),
                is_array($body['types']??null)?$body['types']:[],
                $id!==''?$id:null,$id!==''?$v:null),
            'review'=>$workflows->publishDemo($tenant,$id,$v),
            'simulate'=>$workflows->simulate($tenant,$id,$v),
            default=>throw new InvalidArgumentException('Unsupported workflow action'),
        },
        'campaigns'=>match($action){
            'create'=>$campaigns->createDraft($tenant,(string)($body['title']??''),
                (string)($body['template']??''),(string)($body['audience']??'')),
            'review','simulate','pause'=>$campaigns->transition($tenant,$id,$action,$v),
            default=>throw new InvalidArgumentException('Unsupported campaign action'),
        },
        'assistant'=>match($action) {
            'draft'=>$assistant->draft($tenant,(string)($body['topic']??''),
                ($body['privacy_confirmed']??false)===true),
            'review'=>$assistant->review($tenant,$id,(string)($body['decision']??'')),
            default=>throw new InvalidArgumentException('Unsupported AI preview action'),
        },
        'flows'=>match($action) {
            'create'=>$flows->createDraft($tenant,(string)($body['blueprint']??'')),
            'review'=>$flows->review($tenant,$id,$v),
            'submit'=>$flows->submitFixture($tenant,$id,
                is_array($body['answers']??null)?$body['answers']:[]),
            default=>throw new InvalidArgumentException('Unsupported Flow preview action'),
        }
    };
    vnext_api_reply(200,['ok'=>true,'mode'=>'synthetic','result'=>$result]);
}catch(JsonException|InvalidArgumentException $e) {
    vnext_api_reply(422,['error'=>'invalid_preview_request']);
}catch(Throwable $e) {
    error_log('WaBridge vNext preview API '.get_class($e));
    vnext_api_reply(409,['error'=>'preview_unavailable_or_conflicted']);
}
