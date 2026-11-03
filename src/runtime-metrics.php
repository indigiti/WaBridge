<?php
declare(strict_types=1);

/**
 * Read-only, no-shell performance sample for this WaBridge installation.
 * Never return paths, command lines, environment variables, credentials,
 * phone identifiers, or database contents.
 */
function runtime_directory_usage(string $root,int $maxEntries=20000): array {
    $result=['bytes'=>0,'files'=>0,'dirs'=>0,'truncated'=>false,'available'=>false];
    if (is_link($root)||!is_dir($root)) return $result;
    $result['available']=true;
    $stack=[[$root,0]];
    $seen=0;
    while($stack) {
        [$path,$depth]=array_pop($stack);
        try{$it=new DirectoryIterator($path);}catch(Throwable $e){$result['truncated']=true;continue;}
        foreach($it as $entry) {
            if($entry->isDot()||$entry->isLink())continue;
            if(++$seen>$maxEntries){$result['truncated']=true;break 2;}
            try{
                if($entry->isDir()){
                    $result['dirs']++;
                    if($depth<8)$stack[]=[$entry->getPathname(),$depth+1];
                    else $result['truncated']=true;
                }elseif($entry->isFile()){
                    $size=$entry->getSize();
                    if($size>=0){$result['bytes']+=$size;$result['files']++;}
                }
            }catch(Throwable $e){$result['truncated']=true;}
        }
    }
    return $result;
}
function runtime_process_status(string $expectedExecutable): array {
    $none=['available'=>false,'rss_bytes'=>null,'peak_rss_bytes'=>null,
           'virtual_bytes'=>null,'threads'=>null,'cpu_percent'=>null,'cpu_time'=>'unavailable'];
    if(!is_dir('/proc')||!is_file($expectedExecutable)||is_link($expectedExecutable))return $none;
    $exe=realpath($expectedExecutable);
    if($exe===false)return $none;
    $uid=@fileowner($expectedExecutable);
    $dirs=@scandir('/proc');
    if(!is_array($dirs))return $none;
    $candidates=0;
    foreach($dirs as $item){
        if(!ctype_digit($item)||strlen($item)>10)continue;
        if(++$candidates>8192)break;
        $proc='/proc/'.$item;
        if($uid!==false&&@fileowner($proc)!==$uid)continue;
        $found=@readlink($proc.'/exe');
        if(!is_string($found)||$found!==$exe)continue;
        $raw=@file_get_contents($proc.'/status');
        if(!is_string($raw))continue;
        $result=$none;$result['available']=true;
        foreach(['VmRSS'=>'rss_bytes','VmHWM'=>'peak_rss_bytes','VmSize'=>'virtual_bytes'] as $field=>$out){
            if(preg_match('/^'.preg_quote($field,'/').':\s+(\d+)\s+kB$/m',$raw,$m))
                $result[$out]=(int)$m[1]*1024;
        }
        if(preg_match('/^Threads:\s+(\d+)$/m',$raw,$m))$result['threads']=(int)$m[1];
        // schedstat counts CPU-on-processor nanoseconds. Two local samples
        // are required for a *rate*; this is an on-demand ~200ms probe.
        $first=@file_get_contents($proc.'/schedstat');
        $begin=hrtime(true);
        if(is_string($first)&&preg_match('/^(\d+)\s+/',$first,$m1)){
            usleep(200000);
            $second=@file_get_contents($proc.'/schedstat');
            $end=hrtime(true);
            if(is_string($second)&&preg_match('/^(\d+)\s+/',$second,$m2)&&$end>$begin){
                $delta=(int)$m2[1]-(int)$m1[1];
                if($delta>=0 && $delta<=($end-$begin)*1024){
                    $result['cpu_percent']=round(100*$delta/($end-$begin),1);
                    $result['cpu_time']='200ms sample';
                }
            }
        }
        return $result;
    }
    return $none;
}
function runtime_metrics_snapshot(?string $appRoot=null): array {
    $root=$appRoot??dirname(__DIR__);
    $root=rtrim($root,'/');
    return [
        'observed_at'=>gmdate('c'),
        'rust'=>runtime_process_status($root.'/runtime/waxum-provision/waxum'),
        'disk'=>[
            'runtime_binary'=>runtime_directory_usage($root.'/runtime/waxum-provision'),
            'whatsapp_sessions'=>runtime_directory_usage($root.'/runtime/waxum-data'),
            'php_json'=>runtime_directory_usage($root.'/runtime/json'),
        ],
        'scope'=>'one Cloudways application; on-demand estimates only',
    ];
}
