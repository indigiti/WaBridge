<?php
declare(strict_types=1);

/**
 * Safe, deterministic UI fixture. It never reads application messages,
 * credentials, contacts, provider sessions, or writes runtime data.
 */
final class VNextDemo {
    public static function accounts(): array {
        return [
            ['id'=>'8aab4000-0000-4000-8000-000000000001',
             'name'=>'Demo · Acme Admissions','industry'=>'Education',
             'users'=>4,'channels'=>2,'status'=>'Simulation only','workload'=>'Sample enquiries'],
            ['id'=>'8aab4000-0000-4000-8000-000000000002',
             'name'=>'Demo · Greenfield Retail','industry'=>'Retail',
             'users'=>3,'channels'=>1,'status'=>'Simulation only','workload'=>'Sample order updates'],
            ['id'=>'8aab4000-0000-4000-8000-000000000003',
             'name'=>'Demo · Regional Support','industry'=>'Services',
             'users'=>5,'channels'=>1,'status'=>'Simulation only','workload'=>'Sample support cases'],
        ];
    }

    public static function overview(): array {
        $accounts=self::accounts();
        return ['accounts'=>$accounts,'tenant_count'=>count($accounts),
            'user_count'=>array_sum(array_column($accounts,'users')),
            'channel_count'=>array_sum(array_column($accounts,'channels')),
            'is_synthetic'=>true,'live_sends_enabled'=>false];
    }

    /**
     * Explicit admin-only seed service for ISOLATED pilot metadata.
     * No credentials, phone numbers, messages, runtime sessions or sends.
     * Rejects existing non-demo identity state instead of replacing it.
     */
    public static function seedPilot(VNextJsonStore $store): array {
        return $store->mutate('system','identity',static function(array &$items):array {
            if(($items['demo_marker']??null)==='vnext-synthetic-1') {
                return ['seeded'=>false,'organizations'=>count($items['organizations']??[])];
            }
            foreach(['organizations','users','memberships','channels'] as $collection) {
                if(!empty($items[$collection])) {
                    throw new RuntimeException('Existing pilot identity data: demo seed refused');
                }
            }
            $items['organizations']=[]; $items['users']=[];
            $items['memberships']=[]; $items['channels']=[];
            $now=gmdate('c');$userNum=0;$channelNum=0;
            foreach(self::accounts() as $org){
                $items['organizations'][$org['id']]=[
                    'id'=>$org['id'],'name'=>$org['name'],'status'=>'pilot_disabled',
                    'version'=>1,'created_at'=>$now,'updated_at'=>$now];
                for($i=0;$i<$org['users'];$i++){
                    $userNum++;
                    $uid=sprintf('aa550000-0000-4000-8000-%012d',$userNum);
                    $user=['id'=>$uid,'email'=>sprintf('demo-user-%02d@example.invalid',$userNum),
                        'name'=>'Synthetic Team Member '.$userNum,
                        'status'=>'invitation_only','version'=>1,'created_at'=>$now];
                    $items['users'][$uid]=$user;
                    $items['memberships'][$org['id'].':'.$uid]=[
                        'tenant_id'=>$org['id'],'user_id'=>$uid,
                        'role'=>$i===0?'owner':($i===1?'manager':'agent'),
                        'status'=>'pending','version'=>1,'created_at'=>$now];
                }
                for($i=0;$i<$org['channels'];$i++){
                    $channelNum++;
                    $cid=sprintf('bb660000-0000-4000-8000-%012d',$channelNum);
                    $items['channels'][$cid]=['id'=>$cid,'tenant_id'=>$org['id'],
                        'provider'=>'fixture',
                        'external_id'=>sprintf('demo_fixture_%02d',$channelNum),
                        'state'=>'fixture_only','created_at'=>$now];
                }
            }
            $items['demo_marker']='vnext-synthetic-1';
            return ['seeded'=>true,'organizations'=>count($items['organizations'])];
        });
    }

    /**
     * Render-only static examples. These are NOT persisted, cannot be
     * approved, and never feed the live provider or send queue.
     */
    public static function studioExamples(string $module):array {
        $id='cc880000-0000-4000-8000-000000000001';
        return match($module){
            'automation-studio'=>[[
                'id'=>$id,'title'=>'Example · Enquiry routing','status'=>'example_only',
                'version'=>1,'published_versions'=>[],
                'nodes'=>[
                    ['id'=>'n1','type'=>'trigger.fixture_message','label'=>'Synthetic inbound message','next'=>'n2'],
                    ['id'=>'n2','type'=>'condition.sample_consent','label'=>'Check fictional consent','next'=>'n3'],
                    ['id'=>'n3','type'=>'action.assign_demo','label'=>'Suggest team assignment','next'=>'n4'],
                    ['id'=>'n4','type'=>'action.review','label'=>'Queue human review','next'=>'n5'],
                    ['id'=>'n5','type'=>'action.stop','label'=>'End workflow','next'=>null],
                ],
            ]],
            'campaign-manager'=>[[
                'id'=>$id,'title'=>'Example · Opt-in test audience',
                'state'=>'example_only','template'=>'sample_update',
                'audience'=>'sample_all','version'=>1,'preview'=>[
                    'total'=>4,'eligible'=>2,'suppressed'=>2
                ],'live_enabled'=>false,
            ]],
            'ai-assistant'=>[[
                'id'=>$id,'topic'=>'support','state'=>'example_only',
                'text'=>'Suggested draft: Fictional support requests must be assigned to a human support team.',
                'source'=>'Demo support policy','language'=>'en',
                'auto_reply_enabled'=>false,'provider_requests'=>0,
            ]],
            'flows-studio'=>[[
                'id'=>$id,'title'=>'Example · Enquiry qualification',
                'state'=>'example_only','version'=>1,
                'fields'=>[
                    ['id'=>'interest','label'=>'Area of interest',
                        'choices'=>['Product information','Service information']],
                    ['id'=>'priority','label'=>'Priority',
                        'choices'=>['General','Follow-up requested']],
                ],
                'provider_published'=>false,
            ]],
            default=>throw new InvalidArgumentException('Unknown studio example'),
        };
    }
}
