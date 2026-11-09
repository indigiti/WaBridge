<?php
declare(strict_types=1);
require_once __DIR__.'/pilot-guard.php';

/** W6 WhatsApp-like Flows dry-run authoring; not Meta Flow publishing. */
final class VNextFlows {
    private const BLUEPRINTS=[
        'sample_enquiry'=>[
            'name'=>'Demo enquiry qualification',
            'fields'=>[
                ['id'=>'interest','label'=>'Area of interest','choices'=>['Product information','Service information']],
                ['id'=>'priority','label'=>'Priority','choices'=>['General','Follow-up requested']],
            ],
        ],
        'sample_support'=>[
            'name'=>'Demo support triage',
            'fields'=>[
                ['id'=>'category','label'=>'Support type','choices'=>['General help','Technical help']],
                ['id'=>'contact','label'=>'Preferred response','choices'=>['In-app preview','No follow-up']],
            ],
        ],
    ];
    public function __construct(private VNextJsonStore $store) {}
    public static function blueprints():array{return self::BLUEPRINTS;}

    public function createDraft(string $tenantId,string $blueprint):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        if(!isset(self::BLUEPRINTS[$blueprint])) {
            throw new InvalidArgumentException('Unknown Flow blueprint');
        }
        return $this->store->mutate($tenantId,'studio',
            static function(array &$items)use($blueprint):array {
                $items['flows']??=[];
                if(count($items['flows'])>=20)throw new RuntimeException('Flow capacity reached');
                $id=VNextPilotGuard::id();
                $row=['id'=>$id,'blueprint'=>$blueprint,'title'=>self::BLUEPRINTS[$blueprint]['name'],
                    'fields'=>self::BLUEPRINTS[$blueprint]['fields'],
                    'version'=>1,'state'=>'draft',
                    'provider_published'=>false,'created_at'=>gmdate('c')];
                $items['flows'][$id]=$row;
                return $row;
            });
    }

    public function review(string $tenantId,string $id,int $version):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        if(!VNextJsonStore::uuid($id)||$version<1)throw new InvalidArgumentException('Invalid Flow');
        return $this->store->mutate($tenantId,'studio',
            static function(array &$items)use($id,$version):array{
                $row=$items['flows'][$id]??null;
                if(!is_array($row)||$row['version']!==$version||$row['state']!=='draft')
                    throw new RuntimeException('Flow review conflict');
                $row['state']='ready_for_simulation';
                $row['version']++;
                $row['reviewed_at']=gmdate('c');
                $items['flows'][$id]=$row;
                return $row;
            });
    }

    public function submitFixture(string $tenantId,string $id,array $answers):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        if(!VNextJsonStore::uuid($id)||count($answers)>8)throw new InvalidArgumentException('Invalid Flow');
        return $this->store->mutate($tenantId,'studio',
            static function(array &$items)use($id,$answers):array {
                $row=$items['flows'][$id]??null;
                if(!is_array($row)||$row['state']!=='ready_for_simulation')
                    throw new RuntimeException('Flow not reviewed');
                $expected=[];
                foreach($row['fields'] as $field) {
                    $fieldId=$field['id'];
                    $answer=$answers[$fieldId]??null;
                    if(!is_string($answer)||!in_array($answer,$field['choices'],true))
                        throw new InvalidArgumentException('Answers must match synthetic choices');
                    $expected[$fieldId]=$answer;
                }
                if(count($answers)!==count($expected))throw new InvalidArgumentException('Unexpected Flow fields');
                $items['flow_submissions']??=[];
                if(count($items['flow_submissions'])>=40)throw new RuntimeException('Fixture submissions full');
                $submission=['id'=>VNextPilotGuard::id(),'flow_id'=>$id,
                    'choices'=>$expected,'mode'=>'fixture','state'=>'simulated',
                    'created_at'=>gmdate('c'),'external_requests'=>0];
                $items['flow_submissions'][$submission['id']]=$submission;
                return $submission;
            });
    }

    public function list(string $tenantId):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        return $this->store->read($tenantId,'studio',
            static fn(array $items):array=>array_values($items['flows']??[]));
    }
}
