<?php
declare(strict_types=1);
require_once __DIR__.'/identity.php';

/**
 * Tenant member authorization is independent of the legacy platform-admin
 * session. No tenant ID supplied by a URL grants authority.
 */
final class VNextMemberPolicy {
    private const CHANNEL_ROLES = [
        'channel.read'=>['owner','admin','manager','agent','viewer'],
        'channel.reply'=>['owner','admin','manager','agent'],
        'channel.manage'=>['owner','admin'],
    ];

    public function __construct(private VNextJsonStore $store) {}

    /** Re-checks current identity state, not a claim cached at login. */
    public function member(string $tenantId,string $userId): ?array {
        if(!VNextJsonStore::uuid($tenantId)||!VNextJsonStore::uuid($userId))return null;
        return $this->store->read('system','identity',
            static function(array $items)use($tenantId,$userId):?array {
                $org=$items['organizations'][$tenantId]??null;
                $user=$items['users'][$userId]??null;
                $membership=$items['memberships'][$tenantId.':'.$userId]??null;
                if(!is_array($org)||($org['status']??'')!=='active'
                    ||!is_array($user)||($user['status']??'')!=='active'
                    ||!is_array($membership)||($membership['status']??'')!=='active')return null;
                $role=(string)($membership['role']??'');
                if(!in_array($role,['owner','admin','manager','agent','viewer'],true))return null;
                return [
                    'tenant_id'=>$tenantId,'user_id'=>$userId,'role'=>$role,
                    'display_name'=>(string)$user['name'],
                    'organization'=>(string)$org['name'],
                    'channels'=>array_values(array_filter(
                        $membership['channel_ids']??[],
                        static fn(mixed $v):bool=>is_string($v)&&VNextJsonStore::uuid($v))),
                ];
            });
    }

    /**
     * Channel grants must be explicitly provisioned. Empty allowlist means
     * DENY, not all channels. No gateway or provider access is performed.
     */
    public function channel(string $tenantId,string $userId,
                            string $channelId,string $permission):bool {
        if(!isset(self::CHANNEL_ROLES[$permission])
            ||!VNextJsonStore::uuid($channelId))return false;
        $actor=$this->member($tenantId,$userId);
        if($actor===null
            ||!in_array($actor['role'],self::CHANNEL_ROLES[$permission],true)
            ||!in_array($channelId,$actor['channels'],true))return false;
        return $this->store->read('system','identity',
            static function(array $items)use($channelId,$tenantId):bool {
                $channel=$items['channels'][$channelId]??null;
                return is_array($channel)
                    &&($channel['tenant_id']??'')===$tenantId
                    &&in_array(($channel['state']??''),['fixture_only','verified'],true)
                    &&in_array(($channel['provider']??''),['fixture','waxum','official_cloud'],true);
            });
    }

    /** Simulates an operator provisioned grant; NEVER called from public UI. */
    public function grantFixtureChannel(string $tenantId,string $userId,string $channelId):void {
        if(!VNextJsonStore::uuid($tenantId)||!VNextJsonStore::uuid($userId)
            ||!VNextJsonStore::uuid($channelId))throw new InvalidArgumentException('Invalid channel scope');
        $this->store->mutate('system','identity',
            static function(array &$items)use($tenantId,$userId,$channelId):void {
                $org=$items['organizations'][$tenantId]??null;
                $member=$items['memberships'][$tenantId.':'.$userId]??null;
                $channel=$items['channels'][$channelId]??null;
                if(($org['status']??'')!=='pilot_disabled'
                    ||!is_array($member)||($member['status']??'')!=='pending'
                    ||!is_array($channel)||($channel['tenant_id']??'')!==$tenantId
                    ||($channel['provider']??'')!=='fixture'
                    ||($channel['state']??'')!=='fixture_only'){
                    throw new RuntimeException('Only pending synthetic channel grants allowed');
                }
                $key=$tenantId.':'.$userId;
                $list=$member['channel_ids']??[];
                if(!in_array($channelId,$list,true))$list[]=$channelId;
                if(count($list)>8)throw new RuntimeException('Channel grant capacity');
                $items['memberships'][$key]['channel_ids']=$list;
            });
    }
}
