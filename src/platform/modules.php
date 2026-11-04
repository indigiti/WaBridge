<?php
declare(strict_types=1);
require_once __DIR__.'/service.php';

/**
 * P2/P3/P4/P5 pilot modules. These functions never process payments or
 * auto-message customers and are deliberately separate from the critical
 * WhatsApp queue path. All operator actions require an authenticated admin.
 */
function platform_rule_create(string $session,string $keyword,string $suggestion):array {
    if(!permitted_session($session))throw new InvalidArgumentException('Unauthorized session');
    $keyword=platform_text($keyword,80);
    $suggestion=platform_text($suggestion,800);
    return platform_state_io(true,static function(array &$s)use($session,$keyword,$suggestion):array {
        if(count($s['rules'])>=50)throw new RuntimeException('Pilot automation limit reached');
        $now=time();
        $row=['id'=>wabridge_guid(),'session_id'=>$session,'keyword'=>$keyword,
            'suggestion'=>$suggestion,'mode'=>'manual_review',
            'created_at'=>$now,'updated_at'=>$now];
        $s['rules'][$row['id']]=$row;return $row;
    });
}
/** Deterministic rule preview; output is advisory, never an automatic send. */
function platform_rule_preview(string $session,string $incoming):array {
    if(!permitted_session($session))return [];
    $incoming=mb_substr($incoming,0,2000);
    return platform_state_io(false,static function(array $s)use($session,$incoming):array {
        $found=[];
        foreach($s['rules'] as $rule){
            if($rule['session_id']!==$session)continue;
            if(mb_stripos($incoming,(string)$rule['keyword'])===false)continue;
            $found[]=['id'=>$rule['id'],'keyword'=>$rule['keyword'],
                'suggestion'=>$rule['suggestion'],'action'=>'review_only'];
            if(count($found)>=5)break;
        }
        return $found;
    });
}
function platform_workspace_create(string $name):array {
    $name=platform_text($name,90);
    return platform_state_io(true,static function(array &$s)use($name):array {
        if(count($s['workspaces'])>=10)throw new RuntimeException('Pilot workspace limit reached');
        foreach($s['workspaces'] as $row)
            if(mb_strtolower($row['name'])===mb_strtolower($name))
                throw new InvalidArgumentException('Workspace name already exists');
        $now=time();$row=['id'=>wabridge_guid(),'name'=>$name,
            'status'=>'configuration_only','billing'=>'disabled',
            'created_at'=>$now,'updated_at'=>$now];
        $s['workspaces'][$row['id']]=$row;return $row;
    });
}
function platform_media_send(string $sid,string $contactId,string $kind,string $bytes,
                            string $mime,string $caption='',string $filename='',
                            ?callable $send=null,?callable $probe=null):array {
    $types=[
        'image'=>['image/jpeg','image/png','image/webp'],
        'document'=>['application/pdf','text/plain'],
        'audio'=>['audio/mpeg','audio/ogg','audio/mp4'],
        'video'=>['video/mp4'],
    ];
    if(!isset($types[$kind])||!in_array($mime,$types[$kind],true))
        throw new InvalidArgumentException('Media type and MIME do not match');
    if(strlen($bytes)<1||strlen($bytes)>1048576)
        throw new InvalidArgumentException('Media must be 1 byte to 1 MiB');
    if(!permitted_session($sid)||!valid_job_id($contactId))
        throw new InvalidArgumentException('Invalid session or contact');
    $contact=platform_find('contacts',$contactId);
    if(!$contact||!($contact['consented']??false))
        throw new InvalidArgumentException('Contact consent required for media');
    if($caption!=='')$caption=platform_text($caption,300);
    if($kind==='document'&&!preg_match('/\A[a-zA-Z0-9._-]{1,90}\z/D',$filename))
        throw new InvalidArgumentException('Safe document filename required');
    $session=$probe? $probe($sid):session_state($sid);
    if(!is_array($session)||($session['http']??0)!==200
       ||empty($session['is_logged_in'])||empty($session['socket_alive']))
        throw new InvalidArgumentException('WhatsApp session is not actively connected');
    $attempt=['id'=>wabridge_guid(),'contact_id'=>$contactId,'session_id'=>$sid,
        'type'=>$kind,'state'=>'needs_review','response_code'=>null,
        'provider_id'=>null,'created_at'=>time(),'updated_at'=>time()];
    platform_state_io(true,static function(array &$s)use($attempt):void {
        if(count($s['media_attempts'])>=100)
            array_shift($s['media_attempts']);
        $s['media_attempts'][$attempt['id']]=$attempt;
    });
    $media=['data'=>base64_encode($bytes),'mimetype'=>$mime];
    $payload=['to'=>(string)$contact['phone'],$kind=>$media];
    if(in_array($kind,['image','document','video'],true)&&$caption!=='')
        $payload['caption']=$caption;
    if($kind==='document')$payload['filename']=$filename;
    if($kind==='audio')$payload['ptt']=false;
    try{
        $response=($send??'gateway')('POST','/api/v1/sessions/'.$sid.'/messages/'.$kind,$payload);
        if(!is_array($response))throw new RuntimeException('Invalid provider response');
        $code=(int)($response['http']??0);
        $data=is_array($response['data']??null)?$response['data']:[];
        $providerId=(string)($data['message_id']??'');
        $accepted=$code>=200&&$code<300&&$providerId!==''&&strlen($providerId)<=200
            &&in_array((string)($data['status']??'sent'),['sent',''],true);
        platform_state_io(true,static function(array &$s)use($attempt,$code,$accepted,$providerId):void {
            if(!isset($s['media_attempts'][$attempt['id']]))return;
            $s['media_attempts'][$attempt['id']]['state']=$accepted?'accepted':'needs_review';
            $s['media_attempts'][$attempt['id']]['response_code']=$code;
            $s['media_attempts'][$attempt['id']]['provider_id']=$accepted?$providerId:null;
            $s['media_attempts'][$attempt['id']]['updated_at']=time();
        });
        return ['id'=>$attempt['id'],'status'=>$accepted?'accepted':'needs_review',
            'http'=>$code,'delivery'=>'unconfirmed'];
    } catch(Throwable $e){
        error_log('WaBridge media outcome uncertain '.get_class($e));
        return ['id'=>$attempt['id'],'status'=>'needs_review',
            'http'=>0,'delivery'=>'unknown'];
    }
}
function platform_cloud_connect(string $sid,array $fields,?callable $upstream=null):array {
    if(empty(config()['enable_official_cloud_connect']))
        throw new InvalidArgumentException('Official Cloud onboarding is disabled in private configuration');
    if(!permitted_session($sid))throw new InvalidArgumentException('Session not authorized');
    $payload=[];
    foreach(['waba_id','phone_number_id','app_secret','webhook_verify_token','access_token'] as $required){
        $value=trim((string)($fields[$required]??''));
        if(strlen($value)<8||strlen($value)>2048)
            throw new InvalidArgumentException('Missing or invalid Cloud onboarding credential');
        $payload[$required]=$value;
    }
    foreach(['business_id','app_id'] as $optional){
        $value=trim((string)($fields[$optional]??''));
        if($value!=='')$payload[$optional]=$value;
    }
    if(!preg_match('/\A[0-9]{8,25}\z/D',$payload['waba_id'])
        ||!preg_match('/\A[0-9]{8,25}\z/D',$payload['phone_number_id']))
        throw new InvalidArgumentException('Meta account and phone number IDs must be numeric');
    // Secrets transit directly to the private Rust provider, never PHP disk,
    // HTTP logs, audit metadata or operator-facing error bodies.
    $r=($upstream??'gateway')('POST','/api/v1/sessions/'.$sid.'/cloud/connect',$payload);
    $ok=is_array($r)&&($r['http']??0)===200;
    audit_event($ok?'official_cloud_connect_accepted':'official_cloud_connect_failed',
        'admin',$sid,['http'=>is_array($r)?(int)($r['http']??0):0]);
    return ['accepted'=>$ok,'http'=>is_array($r)?(int)($r['http']??0):0];
}
function platform_approved_template_send(string $sid,string $contactId,string $name,string $language,
                                        ?callable $upstream=null):array {
    if(empty(config()['enable_official_cloud_connect']))
        throw new InvalidArgumentException('Official provider integration is disabled');
    if(!permitted_session($sid)||!valid_job_id($contactId))
        throw new InvalidArgumentException('Invalid session/contact');
    $contact=platform_find('contacts',$contactId);
    if(!$contact||!($contact['consented']??false))
        throw new InvalidArgumentException('Contact consent required');
    if(!preg_match('/\A[a-z0-9_]{1,90}\z/',$name)||
       !preg_match('/\A[a-z]{2}(?:_[A-Z]{2})?\z/',$language))
        throw new InvalidArgumentException('Invalid approved template name/language');
    $r=($upstream??'gateway')('POST','/api/v1/sessions/'.$sid.'/messages/template',[
        'to'=>$contact['phone'],'name'=>$name,'language_code'=>$language,'components'=>[],
    ]);
    $accepted=is_array($r)&&($r['http']??0)>=200&&($r['http']??0)<300
       &&is_string($r['data']['message_id']??null)&&($r['data']['message_id']??'')!=='';
    audit_event('approved_template_send_attempt','admin',$sid,['accepted'=>$accepted]);
    return ['accepted'=>$accepted,'delivery'=>'unconfirmed'];
}
