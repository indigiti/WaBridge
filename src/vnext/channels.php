<?php
declare(strict_types=1);
require_once __DIR__.'/identity.php';

/**
 * VNext channel registry. Ownership is held in the same atomic identity
 * document as organizations. No real channel is activated in this phase.
 */
final class VNextChannels {
    public function __construct(private VNextJsonStore $store) {}

    public function registerFixture(string $tenantId, string $channelId, string $externalId): array {
        if (!VNextJsonStore::uuid($tenantId) || !VNextJsonStore::uuid($channelId)
            || !preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/D',$externalId)) {
            throw new InvalidArgumentException('Invalid fixture channel');
        }
        $record=['id'=>$channelId,'tenant_id'=>$tenantId,'provider'=>'fixture',
            'external_id'=>$externalId,'state'=>'fixture_only','created_at'=>gmdate('c')];
        $this->store->mutate('system','identity',static function(array &$items)use($record):void {
            $org=$items['organizations'][$record['tenant_id']]??null;
            if (!is_array($org) || ($org['status']??'')!=='pilot_disabled') {
                throw new InvalidArgumentException('Unknown or non-pilot tenant');
            }
            $items['channels']??=[];
            if(count($items['channels'])>=12) throw new RuntimeException('Pilot channel capacity reached');
            foreach($items['channels'] as $existing){
                if(($existing['provider']??'')===$record['provider']
                   && ($existing['external_id']??'')===$record['external_id']) {
                    throw new InvalidArgumentException('Channel external identity already assigned');
                }
            }
            if(isset($items['channels'][$record['id']])) {
                throw new InvalidArgumentException('Duplicate channel ID');
            }
            $items['channels'][$record['id']]=$record;
        });
        return $record;
    }

    /** Only synthetic fixture channels can be resolved in this release. */
    public function resolve(string $provider, string $externalId): ?array {
        if($provider!=='fixture'||!preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/D',$externalId))return null;
        return $this->store->read('system','identity',
            static function(array $items)use($externalId):?array {
                foreach(($items['channels']??[]) as $channel){
                    if(($channel['provider']??'')!=='fixture'
                       || ($channel['external_id']??'')!==$externalId
                       || ($channel['state']??'')!=='fixture_only')continue;
                    $tenant=$items['organizations'][$channel['tenant_id']]??null;
                    if(!is_array($tenant)||($tenant['status']??'')!=='pilot_disabled')return null;
                    return $channel;
                }
                return null;
            });
    }
}
