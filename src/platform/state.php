<?php
declare(strict_types=1);

/**
 * Separate, private, bounded product metadata store for single-server pilots.
 * No OAuth tokens, passwords, message bodies, payment credentials or webhook
 * signing keys belong here. Transactional production DB is required for SaaS.
 */
function platform_state_folder(): string {return dirname(__DIR__,2).'/runtime/platform';}
function platform_state_blank():array {
    return ['schema'=>1,'contacts'=>[],'templates'=>[],'rules'=>[],
        'workspaces'=>[],'media_attempts'=>[],'outbox'=>[],'usage'=>[]];
}
function platform_state_io(bool $write,callable $fn):mixed {
    $dir=platform_state_folder();
    if(is_link($dir))throw new RuntimeException('Metadata folder symlink prohibited');
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))
        throw new RuntimeException('Private metadata folder unavailable');
    @chmod($dir,0700);
    $file=$dir.'/state.json';$lockPath=$dir.'/state.lock';
    if(is_link($file)||is_link($lockPath))throw new RuntimeException('Metadata symlink prohibited');
    $fh=fopen($lockPath,'c+b');
    if(!$fh)throw new RuntimeException('Cannot acquire private metadata lock');
    @chmod($lockPath,0600);
    try {
        if(!flock($fh,$write?LOCK_EX:LOCK_SH))
            throw new RuntimeException('Metadata lock failed');
        try {
            $s=platform_state_blank();
            if(is_file($file)){
                $raw=file_get_contents($file);
                if(!is_string($raw)||strlen($raw)>2097152)
                    throw new RuntimeException('Metadata state exceeds 2 MiB');
                $data=json_decode($raw,true,20,JSON_THROW_ON_ERROR);
                if(!is_array($data)||($data['schema']??0)!==1)throw new RuntimeException('Metadata schema unsupported');
                foreach($s as $key=>$unused){
                    if($key==='schema')continue;
                    if(!array_key_exists($key,$data)||!is_array($data[$key]))
                        throw new RuntimeException('Invalid metadata collection');
                }
                $s=$data;
            }
            $out=$fn($s);
            if($write){
                $encoded=json_encode($s,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
                if(strlen($encoded)>2097152)throw new RuntimeException('Metadata state full');
                $tmp=tempnam($dir,'.platform-');
                if($tmp===false)throw new RuntimeException('Metadata temp unavailable');
                try{
                    @chmod($tmp,0600);
                    if(file_put_contents($tmp,$encoded,LOCK_EX)!==strlen($encoded)
                       || !rename($tmp,$file))throw new RuntimeException('Metadata write incomplete');
                    @chmod($file,0600);
                }finally{if(is_file($tmp))@unlink($tmp);}
            }
            return $out;
        }finally{flock($fh,LOCK_UN);}
    }finally{fclose($fh);}
}
/** Only a bounded page of safe projected fields reaches each view. */
function platform_page(string $collection,int $page=1,int $limit=10):array {
    if(!in_array($collection,['contacts','templates','rules','workspaces','media_attempts','outbox'],true))
        throw new InvalidArgumentException('Invalid collection');
    $page=max(1,min(1000,$page));$limit=max(1,min(25,$limit));
    return platform_state_io(false,static function(array $s)use($collection,$page,$limit):array {
        $rows=array_values($s[$collection]);
        usort($rows,static fn(array $a,array $b):int=>
            ((int)($b['updated_at']??$b['created_at']??0)<=>(int)($a['updated_at']??$a['created_at']??0))
             ?: strcmp((string)($b['id']??''),(string)($a['id']??'')));
        $slice=array_slice($rows,($page-1)*$limit,$limit+1);
        return ['rows'=>array_slice($slice,0,$limit),'has_next'=>count($slice)>$limit,'page'=>$page];
    });
}
function platform_find(string $collection,string $id):?array {
    if(!in_array($collection,['contacts','templates','rules','workspaces','media_attempts','outbox'],true)
       ||!valid_job_id($id))return null;
    return platform_state_io(false,static fn(array $s):?array=>$s[$collection][$id]??null);
}
