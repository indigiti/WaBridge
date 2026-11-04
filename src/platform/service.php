<?php
declare(strict_types=1);
require_once __DIR__.'/state.php';

function platform_phone(string $phone):string {
    if(!preg_match('/\A[1-9][0-9]{7,14}\z/D',$phone))
        throw new InvalidArgumentException('Use international digits without plus');
    return $phone;
}
function platform_text(string $value,int $max=150):string {
    $text=trim($value);
    if($text===''||mb_strlen($text)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$text))
        throw new InvalidArgumentException('Invalid text field');
    return $text;
}
function platform_contact_save(string $name,string $phone,string $evidence,bool $consented):array {
    $name=platform_text($name,120);$phone=platform_phone($phone);
    $evidence=platform_text($evidence,120);
    return platform_state_io(true,static function(array &$s)use($name,$phone,$evidence,$consented):array {
        $existing=null;
        foreach($s['contacts'] as $id=>$row) if(hash_equals((string)$row['phone'],$phone)){$existing=$id;break;}
        if($existing===null&&count($s['contacts'])>=500)throw new RuntimeException('Contact pilot limit reached');
        $id=$existing??wabridge_guid();$now=time();
        $row=['id'=>$id,'name'=>$name,'phone'=>$phone,'consented'=>$consented,
            'consent_evidence'=>$evidence,'consent_at'=>$consented?$now:null,
            'created_at'=>$s['contacts'][$id]['created_at']??$now,'updated_at'=>$now];
        $s['contacts'][$id]=$row;
        return $row;
    });
}
function platform_contact_consented(string $phone):bool {
    $phone=platform_phone($phone);
    return platform_state_io(false,static function(array $s)use($phone):bool {
        foreach($s['contacts'] as $row)
            if(hash_equals((string)$row['phone'],$phone))return ($row['consented']??false)===true;
        return false;
    });
}
function platform_template_create(string $name,string $body):array {
    $name=platform_text($name,90);$body=platform_text($body,2000);
    preg_match_all('/{{\s*([^}]+)\s*}}/',$body,$matches);
    foreach($matches[1] as $var)
        if(!in_array(trim($var),['name','date'],true))
            throw new InvalidArgumentException('Template supports only {{name}} and {{date}}');
    return platform_state_io(true,static function(array &$s)use($name,$body):array {
        if(count($s['templates'])>=100)throw new RuntimeException('Template pilot limit reached');
        $now=time();$row=['id'=>wabridge_guid(),'name'=>$name,'body'=>$body,
            'created_at'=>$now,'updated_at'=>$now];
        $s['templates'][$row['id']]=$row;return $row;
    });
}
function platform_template_render(array $template,array $contact,?string $date=null):string {
    $rendered=strtr((string)$template['body'],[
        '{{name}}'=>(string)$contact['name'],
        '{{date}}'=>$date??gmdate('Y-m-d'),
    ]);
    if(preg_match('/{{.*?}}/s',$rendered))throw new InvalidArgumentException('Unresolved template variable');
    return platform_text($rendered,2000);
}
function platform_schedule(string $contactId,string $templateId,string $session,int $when):array {
    if(!valid_job_id($contactId)||!valid_job_id($templateId))
        throw new InvalidArgumentException('Choose a contact and template');
    if(!permitted_session($session))throw new InvalidArgumentException('Session not permitted');
    $now=time();
    if($when<$now+60||$when>$now+30*86400)
        throw new InvalidArgumentException('Schedule at least one minute ahead, within 30 days');
    $contact=platform_find('contacts',$contactId);
    $template=platform_find('templates',$templateId);
    if(!$contact||!$template||($contact['consented']??false)!==true)
        throw new InvalidArgumentException('An opted-in contact and template are required');
    $body=platform_template_render($template,$contact);
    $out=enqueue_message(store(),['id'=>'scheduler','sessions'=>[$session],'limit'=>10],
        $session,(string)$contact['phone'],$body,'scheduler-'.wabridge_guid(),$when);
    // Separate metadata never stores the outgoing message text.
    $row=['id'=>wabridge_guid(),'contact_id'=>$contactId,'template_id'=>$templateId,
        'session_id'=>$session,'job_id'=>$out['id'],'send_at'=>$when,
        'created_at'=>time(),'updated_at'=>time()];
    platform_state_io(true,static function(array &$s)use($row):void{
        if(count($s['outbox'])>=300)array_shift($s['outbox']);
        $s['outbox'][$row['id']]=$row;
    });
    audit_event('scheduled_message_created','admin',$out['id'],['send_at'=>$when]);
    return $row;
}
