<?php
declare(strict_types=1);
/** Read-only bounded webhook history; event payloads and secrets excluded. */
function platform_webhook_page(int $page=1,int $limit=25,string $status=''):array {
    $page=max(1,min(2000,$page));$limit=max(1,min(50,$limit));
    if(!in_array($status,['','queued','processing','delivered','dead'],true))
        throw new InvalidArgumentException('Invalid status');
    $offset=($page-1)*$limit;$db=store();
    if($db instanceof JsonStore){
        return $db->read(static function(array $s)use($status,$offset,$limit,$page):array{
            $rows=[];
            foreach($s['deliveries'] as $r){
                if(!is_array($r)||($status!==''&&($r['state']??'')!==$status))continue;
                $rows[]=array_intersect_key($r,array_flip([
                    'event_id','subscriber_id','state','attempts','updated_at','last_http'
                ]));
            }
            usort($rows,static fn(array $a,array $b):int=>
                ((int)($b['updated_at']??0)<=>(int)($a['updated_at']??0))
                ?:strcmp((string)($b['event_id']??''),(string)($a['event_id']??'')));
            $slice=array_slice($rows,$offset,$limit+1);
            return ['rows'=>array_slice($slice,0,$limit),
                'has_next'=>count($slice)>$limit,'page'=>$page];
        });
    }
    $sql='SELECT event_id,subscriber_id,state,attempts,updated_at,last_http FROM event_deliveries';
    if($status!=='')$sql.=' WHERE state=?';
    $sql.=' ORDER BY updated_at DESC,event_id DESC LIMIT ? OFFSET ?';
    $st=$db->prepare($sql);$p=1;
    if($status!=='')$st->bindValue($p++,$status,PDO::PARAM_STR);
    $st->bindValue($p++,$limit+1,PDO::PARAM_INT);
    $st->bindValue($p,$offset,PDO::PARAM_INT);
    $st->execute();$rows=$st->fetchAll(PDO::FETCH_ASSOC);
    return ['rows'=>array_slice($rows,0,$limit),
        'has_next'=>count($rows)>$limit,'page'=>$page];
}
