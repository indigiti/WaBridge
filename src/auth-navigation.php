<?php
declare(strict_types=1);

/**
 * Safe in-app return paths for a browser-authenticated administration area.
 * Never trust a Referer, external host or arbitrary user-selected URL.
 * Query values are reconstructed from a small allowlist; tracking parameters
 * and internal rewrite parameters are dropped.
 */
function auth_safe_return_path(?string $candidate): string {
    $fallback='/wabridge/';
    if(!is_string($candidate)||$candidate===''||strlen($candidate)>512||
       preg_match('/[\x00-\x20\x7f\\\\]/',$candidate))return $fallback;
    $parts=parse_url($candidate);
    if(!is_array($parts)||isset($parts['scheme'])||isset($parts['host'])||
       isset($parts['port'])||isset($parts['user'])||isset($parts['pass'])||
       isset($parts['fragment']))return $fallback;
    $path=(string)($parts['path']??'');
    if(!str_starts_with($path,'/wabridge/')||
       !preg_match('~\A/wabridge/(?:[a-z-]{1,35}/?)?\z~D',$path))return $fallback;
    $route=trim(substr($path,strlen('/wabridge/')),'/');
    $routes=[
        '','overview','devices','sessions','messaging','api-applications',
        'messages','trace','gateway','storage','qr','diagnostics','reports',
        'audit','webhook-events','contacts','templates','schedules','media',
        'automation','developer-tools','security-center','workspaces',
        'cloud-api','performance','verification','account-admin',
    ];
    if(!in_array($route,$routes,true))return $fallback;
    $target=$route===''?$fallback:'/wabridge/'.$route;
    $rawQuery=(string)($parts['query']??'');
    if($rawQuery===''||strlen($rawQuery)>350)return $target;
    parse_str($rawQuery,$params);
    if(!is_array($params))return $target;
    $rules=[
        'contacts'=>['page','q'],
        'templates'=>['page'],
        'schedules'=>['page','contact','template'],
        'media'=>['page','contact'],
        'workspaces'=>['page'],
        'automation'=>['page'],
        'messages'=>['page','status','session','id'],
        'trace'=>['id'],
        'qr'=>['sid'],
        'audit'=>['page'],
        'webhook-events'=>['page','status'],
    ];
    $query=[];
    foreach($rules[$route]??[] as $key) {
        $v=$params[$key]??null;
        if(!is_string($v)||$v==='')continue;
        $valid=match($key){
            'page'=>ctype_digit($v)&&(int)$v>=1&&(int)$v<=2000,
            'contact','template','id'=>valid_job_id($v),
            'sid','session'=>valid_session_id($v),
            'status'=>preg_match('/\A[a-z_]{1,25}\z/D',$v)===1,
            'q'=>mb_strlen($v)<=50&&
                preg_match('/[\x00-\x1f\x7f]/',$v)!==1&&
                !(ctype_digit($v)&&strlen($v)>4),
            default=>false,
        };
        if($valid)$query[$key]=$v;
    }
    return $query ? $target.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):$target;
}
function auth_destination_label(string $target):string {
    $path=(string)(parse_url($target,PHP_URL_PATH)?:'');
    return match($path){
        '/wabridge/media'=>'Media messages',
        '/wabridge/messages'=>'Message activity',
        '/wabridge/schedules'=>'Scheduled messages',
        '/wabridge/contacts'=>'Contacts',
        '/wabridge/templates'=>'Message templates',
        '/wabridge/cloud-api'=>'Official Cloud API',
        '/wabridge/automation'=>'Automation studio',
        '/wabridge/qr'=>'Device pairing',
        '/wabridge/trace'=>'Message trace',
        '/wabridge/verification'=>'Release verification',
        '/wabridge/account-admin'=>'Multi-account preview',
        default=>'your workspace',
    };
}
/**
 * Browser HTML routes: redirect to the premium login screen rather than print
 * an unstyled 403. POST is NEVER replayed after authentication; a 303 shows a
 * clear session-expired warning and the operator must submit again.
 * API JSON endpoints intentionally keep their separate 401/403 contracts.
 */
function require_admin_html_page():void {
    if(admin_authenticated())return;
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if(!in_array($method,['GET','HEAD','POST'],true)){
        header('Allow: GET, HEAD, POST');
        http_response_code(405);
        exit;
    }
    $destination=auth_safe_return_path((string)($_SERVER['REQUEST_URI']??'/wabridge/'));
    $login='/wabridge/sign-in?return='.rawurlencode($destination);
    if($method==='POST')$login.='&reason=expired';
    header('Cache-Control: private, no-store, max-age=0');
    header('Vary: Cookie');
    header('Location: '.$login,true,$method==='POST'?303:302);
    exit;
}
