<?php
declare(strict_types=1);

/**
 * Independent connection watchdog read model.
 * No browser network calls, no automatic WhatsApp login, and no credentials,
 * phone numbers, or raw session names in persisted telemetry.
 */
function watchdog_path():string {
    return dirname(__DIR__,2).'/runtime/connection-watchdog.json';
}
function watchdog_poll(callable $gateway,callable $sessions,callable $probe,array $previous=[],int $max=8):array {
    $max=max(1,min(8,$max));
    $health=$gateway();
    $ready=!empty($health['ready']);
    $rows=[];$count=0;$total=0;$transitions=[];
    $prior=[];
    foreach((array)($previous['devices']??[]) as $old) {
        if(is_array($old)&&isset($old['key'],$old['state']))$prior[(string)$old['key']]=(string)$old['state'];
    }
    if($ready) {
        foreach((array)$sessions() as $session) {
            if(!is_array($session))continue;
            $sid=(string)($session['id']??$session['session_id']??'');
            if(!permitted_session($sid))continue;
            $total++;
            if($count>=$max)continue;
            $count++;
            $key=substr(hash('sha256',$sid),0,16);
            try {
                $state=$probe($sid);
                $online=is_array($state)&&($state['http']??null)===200&&
                    !empty($state['is_logged_in'])&&!empty($state['socket_alive']);
                $status=!is_array($state)||($state['http']??null)!==200?'unknown':($online?'connected':'disconnected');
            } catch(Throwable $e) {
                error_log('WaBridge watchdog probe '.get_class($e));
                $status='unknown';
            }
            $rows[]=['key'=>$key,'state'=>$status];
            $before=$prior[$key]??null;
            if($before!==null&&$before!==$status) {
                $transitions[]=['key'=>$key,'from'=>$before,'to'=>$status];
            }
        }
    }
    return [
        'schema'=>1,'checked_at'=>time(),'gateway'=>$ready?'ready':'unavailable',
        'devices'=>$rows,'reported_sessions'=>$total,'sampled_sessions'=>count($rows),
        'sample_limit'=>$max,'truncated'=>$total>$max,
        'connected'=>count(array_filter($rows,static fn(array $r):bool=>$r['state']==='connected')),
        'disconnected'=>count(array_filter($rows,static fn(array $r):bool=>$r['state']==='disconnected')),
        'unknown'=>count(array_filter($rows,static fn(array $r):bool=>$r['state']==='unknown')),
        'transitions'=>$transitions,
    ];
}
function watchdog_read():array {
    $none=['state'=>'not_detected','checked_at'=>null,'gateway'=>'unknown',
        'devices'=>[],'reported_sessions'=>0,'sampled_sessions'=>0,
        'connected'=>0,'disconnected'=>0,'unknown'=>0,'truncated'=>false,'transitions'=>[]];
    $path=watchdog_path();
    if(is_link($path)||!is_file($path))return $none;
    $raw=@file_get_contents($path,false,null,0,16385);
    if(!is_string($raw)||$raw===''||strlen($raw)>16384)return $none;
    try{$data=json_decode($raw,true,10,JSON_THROW_ON_ERROR);}
    catch(Throwable $e){return $none;}
    if(!is_array($data)||($data['schema']??null)!==1)return $none;
    $time=(int)($data['checked_at']??0);
    if($time<=0||$time>time()+60)return $none;
    // Do not project arbitrary keys from the JSON snapshot into HTML.
    $result=$none;
    foreach(['gateway','devices','reported_sessions','sampled_sessions',
             'connected','disconnected','unknown','truncated','transitions'] as $key)
        if(isset($data[$key])&&gettype($data[$key])===gettype($none[$key]))
            $result[$key]=$data[$key];
    $result['checked_at']=$time;
    $result['state']=time()-$time<=900?'recent':'stale';
    return $result;
}
function watchdog_run():array {
    $path=watchdog_path();$dir=dirname($path);
    if(is_link($dir)||!is_dir($dir))throw new RuntimeException('Private watchdog runtime directory missing');
    if(is_link($path))throw new RuntimeException('Watchdog snapshot symlink prohibited');
    $lock=$dir.'/connection-watchdog.lock';
    if(is_link($lock))throw new RuntimeException('Watchdog lock symlink prohibited');
    $fh=fopen($lock,'c+b');
    if(!$fh)throw new RuntimeException('Cannot acquire watchdog lock');
    @chmod($lock,0600);
    try{
        if(!flock($fh,LOCK_EX|LOCK_NB))return ['state'=>'already_running'];
        $prev=watchdog_read();
        $result=watchdog_poll('gateway_diagnostics','upstream_sessions','session_state',$prev,8);
        $encoded=json_encode($result,JSON_THROW_ON_ERROR);
        $tmp=tempnam($dir,'.watchdog-');
        if($tmp===false)throw new RuntimeException('Cannot create watchdog snapshot');
        try{
            @chmod($tmp,0600);
            if(file_put_contents($tmp,$encoded,LOCK_EX)!==strlen($encoded))
                throw new RuntimeException('Watchdog write incomplete');
            if(!rename($tmp,$path))throw new RuntimeException('Watchdog atomic update failed');
            @chmod($path,0600);
        }finally{if(is_file($tmp))@unlink($tmp);}
        return $result;
    }finally{
        flock($fh,LOCK_UN);
        fclose($fh);
    }
}
