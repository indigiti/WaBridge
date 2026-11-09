<?php
declare(strict_types=1);
require_once __DIR__.'/pilot-guard.php';

/**
 * W6 privacy-confirmed assistant sandbox. Knowledge is fixed synthetic text.
 * Outputs must be manually reviewed; AI provider and autopilot are absent.
 */
final class VNextAssistant {
    private const SOURCES=[
        'admissions'=>[
            'title'=>'Demo admissions policy',
            'text'=>'Fictional admissions enquiries are handled by a human admissions representative.',
            'language'=>'en',
        ],
        'orders'=>[
            'title'=>'Demo order policy',
            'text'=>'Fictional orders require a human representative to confirm any promised date.',
            'language'=>'en',
        ],
        'support'=>[
            'title'=>'Demo support policy',
            'text'=>'Fictional support requests must be assigned to a human support team.',
            'language'=>'en',
        ],
    ];
    public function __construct(private VNextJsonStore $store) {}
    public static function sourceOptions():array{return array_keys(self::SOURCES);}

    public function draft(string $tenantId,string $topic,bool $privacyConfirmed):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        if(!$privacyConfirmed)throw new RuntimeException('Privacy confirmation required');
        if(!isset(self::SOURCES[$topic]))throw new InvalidArgumentException('Unknown synthetic topic');
        $source=self::SOURCES[$topic];
        return $this->store->mutate($tenantId,'studio',
            static function(array &$items)use($topic,$source):array {
                $items['ai_drafts']??=[];
                if(count($items['ai_drafts'])>=40)throw new RuntimeException('Review queue capacity');
                $id=VNextPilotGuard::id();
                $row=['id'=>$id,'topic'=>$topic,
                    'text'=>'Suggested draft: '.$source['text'],
                    'source'=>$source['title'],'language'=>$source['language'],
                    'state'=>'awaiting_human_review','created_at'=>gmdate('c'),
                    'generation_mode'=>'deterministic_fixture',
                    'auto_reply_enabled'=>false,'provider_requests'=>0];
                $items['ai_drafts'][$id]=$row;
                return $row;
            });
    }

    public function review(string $tenantId,string $id,string $decision):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        if(!VNextJsonStore::uuid($id)
            || !in_array($decision,['approve_for_copy','reject'],true)) {
            throw new InvalidArgumentException('Invalid review decision');
        }
        return $this->store->mutate($tenantId,'studio',
            static function(array &$items)use($id,$decision):array {
                $row=$items['ai_drafts'][$id]??null;
                if(!is_array($row)||$row['state']!=='awaiting_human_review') {
                    throw new RuntimeException('Review state conflict');
                }
                $row['state']=$decision==='approve_for_copy'?'copy_approved':'rejected';
                $row['reviewed_at']=gmdate('c');
                $items['ai_drafts'][$id]=$row;
                return $row;
            });
    }

    public function list(string $tenantId):array {
        (new VNextPilotGuard($this->store))->tenant($tenantId);
        return $this->store->read($tenantId,'studio',
            static fn(array $items):array=>array_values($items['ai_drafts']??[]));
    }

    public static function sources():array{return self::SOURCES;}
}
