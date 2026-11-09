<?php
declare(strict_types=1);
require_once __DIR__.'/pilot-guard.php';

/** W5: Consent-gated fictional campaign lifecycle, permanently no-send. */
final class VNextCampaigns {
    private const TEMPLATES=[
        'sample_update'=>'Your fictional sample enquiry has been received.',
        'sample_event'=>'This is a fictional event invitation for a demo account.',
        'sample_followup'=>'This simulated follow-up requires no customer action.',
    ];
    private const AUDIENCES=[
        'sample_all'=>[
            ['id'=>'fictional-01','consent'=>true,'suppressed'=>false],
            ['id'=>'fictional-02','consent'=>true,'suppressed'=>false],
            ['id'=>'fictional-03','consent'=>false,'suppressed'=>false],
            ['id'=>'fictional-04','consent'=>true,'suppressed'=>true],
        ],
        'sample_opted_in'=>[
            ['id'=>'fictional-01','consent'=>true,'suppressed'=>false],
            ['id'=>'fictional-02','consent'=>true,'suppressed'=>false],
        ],
    ];

    public function __construct(private VNextJsonStore $store) {}
    public static function templates():array{return self::TEMPLATES;}
    public static function audiences():array{return array_keys(self::AUDIENCES);}

    private static function previewAudience(string $audience):array {
        $rows=self::AUDIENCES[$audience]??null;
        if($rows===null)throw new InvalidArgumentException('Unknown fictional audience');
        $eligible=[];
        $suppressed=[];
        foreach($rows as $row) {
            if($row['consent']&&!$row['suppressed'])$eligible[]=$row['id'];
            else $suppressed[]=$row['id'];
        }
        return ['total'=>count($rows),'eligible'=>$eligible,
            'suppressed'=>$suppressed,'consent_checked'=>true,'real_recipients'=>0];
    }

    public function createDraft(string $tenantId,string $title,
                                string $template,string $audience):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        $title=VNextPilotGuard::title($title,70);
        if(!isset(self::TEMPLATES[$template]))throw new InvalidArgumentException('Invalid template');
        $preview=self::previewAudience($audience);
        return $this->store->mutate($tenantId,'studio',
            static function(array &$items)use($title,$template,$audience,$preview):array {
                $items['campaigns']??=[];
                if(count($items['campaigns'])>=30)throw new RuntimeException('Campaign capacity reached');
                $id=VNextPilotGuard::id();
                $row=['id'=>$id,'title'=>$title,'template'=>$template,'audience'=>$audience,
                    'version'=>1,'state'=>'draft','created_at'=>gmdate('c'),
                    'preview'=>['total'=>$preview['total'],
                        'eligible'=>count($preview['eligible']),
                        'suppressed'=>count($preview['suppressed'])],
                    'live_enabled'=>false];
                $items['campaigns'][$id]=$row;
                return $row;
            });
    }

    public function transition(string $tenantId,string $id,string $action,int $version):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        if(!VNextJsonStore::uuid($id)||$version<1)throw new InvalidArgumentException('Invalid campaign');
        if(!in_array($action,['review','simulate','pause'],true))
            throw new InvalidArgumentException('Unsupported campaign action');
        return $this->store->mutate($tenantId,'studio',
            static function(array &$items)use($id,$action,$version):array {
                $row=$items['campaigns'][$id]??null;
                if(!is_array($row)||$row['version']!==$version)
                    throw new RuntimeException('Campaign conflict');
                $from=$row['state'];
                $next=match($action) {
                    'review'=>$from==='draft'?'reviewed_demo':null,
                    'simulate'=>$from==='reviewed_demo'?'simulated':null,
                    'pause'=>in_array($from,['reviewed_demo','simulated'],true)?'paused':null,
                };
                if($next===null)throw new RuntimeException('Invalid state transition');
                if($action==='simulate') {
                    $audience=self::previewAudience($row['audience']);
                    // Preview fan-out is arithmetic only. No outbox writes.
                    $row['simulated_count']=count($audience['eligible']);
                    $row['suppressed_count']=count($audience['suppressed']);
                    $row['external_requests']=0;
                }
                $row['state']=$next;
                $row['version']++;
                $row['updated_at']=gmdate('c');
                $items['campaigns'][$id]=$row;
                return $row;
            });
    }

    public function list(string $tenantId):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        return $this->store->read($tenantId,'studio',
            static fn(array $items):array=>array_values($items['campaigns']??[]));
    }
}
