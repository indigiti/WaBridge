<?php
declare(strict_types=1);
require_once __DIR__.'/pilot-guard.php';

/**
 * W4 visual workflow domain: immutable reviewed versions, bounded linear DAGs,
 * manual dry-runs only. No workers, dynamic PHP, HTTP calls, or message sends.
 */
final class VNextWorkflows {
    private const TYPES=[
        'trigger.fixture_message'=>'Synthetic inbound message',
        'condition.sample_consent'=>'Check fictional consent',
        'condition.sample_tag'=>'Check fictional tag',
        'action.assign_demo'=>'Suggest team assignment',
        'action.note_demo'=>'Suggest internal note',
        'action.review'=>'Queue human review',
        'action.stop'=>'End workflow',
    ];
    public function __construct(private VNextJsonStore $store) {}

    public static function palette():array {return self::TYPES;}

    private static function nodes(array $types):array {
        if(count($types)<2||count($types)>12||array_values($types)!==$types) {
            throw new InvalidArgumentException('Workflow must have 2–12 ordered nodes');
        }
        if($types[0]!=='trigger.fixture_message'||end($types)!=='action.stop') {
            throw new InvalidArgumentException('Workflow requires a synthetic trigger and stop node');
        }
        $nodes=[];
        foreach($types as $i=>$type) {
            if(!is_string($type)||!isset(self::TYPES[$type])) {
                throw new InvalidArgumentException('Unsupported workflow step');
            }
            if($i>0&&$type==='trigger.fixture_message') {
                throw new InvalidArgumentException('Only one trigger allowed');
            }
            if($i<count($types)-1&&$type==='action.stop') {
                throw new InvalidArgumentException('Stop must be terminal');
            }
            $nodes[]=['id'=>'n'.($i+1),'type'=>$type,
                'label'=>self::TYPES[$type],
                'next'=>isset($types[$i+1])?'n'.($i+2):null];
        }
        return $nodes;
    }

    public function saveDraft(string $tenantId,string $title,array $types,
                              ?string $id=null,?int $expectedVersion=null):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        $title=VNextPilotGuard::title($title,70);
        $nodes=self::nodes($types);
        $id??=VNextPilotGuard::id();
        if(!VNextJsonStore::uuid($id))throw new InvalidArgumentException('Invalid workflow ID');
        return $this->store->mutate($tenantId,'studio',
            static function(array &$items) use ($id,$title,$nodes,$expectedVersion):array {
                $items['workflows']??=[];
                $existing=$items['workflows'][$id]??null;
                if($existing===null){
                    if($expectedVersion!==null)throw new RuntimeException('Unexpected version');
                    if(count($items['workflows'])>=30)throw new RuntimeException('Workflow limit');
                    $row=['id'=>$id,'title'=>$title,'version'=>1,'status'=>'draft',
                        'nodes'=>$nodes,'published_versions'=>[],'created_at'=>gmdate('c')];
                }else{
                    if($expectedVersion===null||$existing['version']!==$expectedVersion) {
                        throw new RuntimeException('Stale workflow revision');
                    }
                    $row=$existing;
                    $row['version']++;
                    $row['status']='draft';
                    $row['title']=$title;
                    $row['nodes']=$nodes;
                }
                $row['updated_at']=gmdate('c');
                $items['workflows'][$id]=$row;
                return $row;
            });
    }

    /** Review/publish only a frozen demo version, not an executable worker. */
    public function publishDemo(string $tenantId,string $id,int $version):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        if(!VNextJsonStore::uuid($id)||$version<1)throw new InvalidArgumentException('Invalid workflow');
        return $this->store->mutate($tenantId,'studio',
            static function(array &$items)use($id,$version):array{
                $row=$items['workflows'][$id]??null;
                if(!is_array($row)||$row['version']!==$version
                    ||$row['status']!=='draft')throw new RuntimeException('Workflow review conflict');
                $snapshot=['version'=>$version,'title'=>$row['title'],
                    'nodes'=>$row['nodes'],'published_at'=>gmdate('c'),
                    'mode'=>'dry_run_only'];
                if(count($row['published_versions'])>=12) {
                    throw new RuntimeException('Published version capacity');
                }
                $row['published_versions'][]=$snapshot;
                $row['status']='published_demo';
                $items['workflows'][$id]=$row;
                return $row;
            });
    }

    public function simulate(string $tenantId,string $id,int $version):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        if(!VNextJsonStore::uuid($id)||$version<1)throw new InvalidArgumentException('Invalid workflow');
        return $this->store->mutate($tenantId,'studio',
            static function(array &$items)use($id,$version):array {
                $row=$items['workflows'][$id]??null;
                if(!is_array($row)||$row['version']!==$version) {
                    throw new RuntimeException('Workflow not found or stale');
                }
                // Explicitly never execute the graph; generate a safe explanation.
                $trace=[];
                foreach($row['nodes'] as $node) {
                    $trace[]=['node'=>$node['id'],'label'=>$node['label'],
                        'outcome'=>'preview_only'];
                }
                $items['workflow_runs']??=[];
                if(count($items['workflow_runs'])>=60)throw new RuntimeException('Dry-run history limit');
                $run=['id'=>VNextPilotGuard::id(),'workflow_id'=>$id,'version'=>$version,
                    'status'=>'simulated','trace'=>$trace,'at'=>gmdate('c'),
                    'external_calls'=>0];
                $items['workflow_runs'][$run['id']]=$run;
                return $run;
            });
    }

    public function list(string $tenantId):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        return $this->store->read($tenantId,'studio',
            static function(array $items):array {
                return array_values($items['workflows']??[]);
            });
    }
}
