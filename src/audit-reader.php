<?php
declare(strict_types=1);

/**
 * Bounded read-only audit query service.
 * The dashboard fetches only 10 events; this page loads 25 per request.
 */
function storage_audit_page(int $page=1,int $perPage=25): array {
    $page=max(1,min(2000,$page));
    $perPage=max(1,min(50,$perPage));
    $offset=($page-1)*$perPage;
    $db=store();
    if($db instanceof JsonStore) {
        return $db->read(static function(array $s)use($offset,$page,$perPage):array {
            // Test-mode state retains a hard maximum of 500 audit rows.
            $slice=array_slice(array_reverse($s['audit']),$offset,$perPage+1);
            $rows=array_map(static fn(array $row):array =>
                array_intersect_key($row,array_flip(['guid','event','actor','target','at'])),$slice);
            return ['events'=>array_slice($rows,0,$perPage),
                'has_next'=>count($rows)>$perPage,'page'=>$page];
        });
    }
    $query=$db->prepare('SELECT guid,event,actor,target,at FROM audit_entries ORDER BY id DESC LIMIT ? OFFSET ?');
    $query->bindValue(1,$perPage+1,PDO::PARAM_INT);
    $query->bindValue(2,$offset,PDO::PARAM_INT);
    $query->execute();
    $rows=$query->fetchAll(PDO::FETCH_ASSOC);
    return ['events'=>array_slice($rows,0,$perPage),
        'has_next'=>count($rows)>$perPage,'page'=>$page];
}
