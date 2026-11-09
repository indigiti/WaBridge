<?php
declare(strict_types=1);
require_once __DIR__.'/identity.php';
require_once __DIR__.'/pilot-guard.php';

/**
 * W2 scoped tenant API key contract. No public issuance route is provided.
 * Tokens never bypass active organization/user/membership checks.
 * Real account activation and browser login are separately gated.
 */
final class VNextTenantTokens {
    private const SCOPES=[
        'inbox.read'=>'inbox.read',
        'inbox.reply'=>'inbox.reply',
        'tenant.manage'=>'tenant.manage',
        'campaign.create'=>'campaign.create',
        'campaign.approve'=>'campaign.approve',
    ];
    public function __construct(private VNextJsonStore $store) {}

    private function permission(string $scope):string {
        if(!isset(self::SCOPES[$scope]))throw new InvalidArgumentException('Unsupported API scope');
        return self::SCOPES[$scope];
    }

    /**
     * Caller must already have a separately authenticated trusted operator
     * context; never issue from GET, preview page or an unverified invite.
     */
    public function issue(string $tenantId,string $userId,array $scopes,
                          int $ttl=3600,?int $now=null):array {
        $now??=time();
        if(!VNextJsonStore::uuid($tenantId)||!VNextJsonStore::uuid($userId)
            ||$ttl<60||$ttl>86400||count($scopes)<1||count($scopes)>5
            ||array_values(array_unique($scopes))!==array_values($scopes)){
            throw new InvalidArgumentException('Invalid tenant token request');
        }
        $identity=new VNextIdentity($this->store);
        foreach($scopes as $scope){
            if(!is_string($scope)||!$identity->can($tenantId,$userId,$this->permission($scope))){
                throw new RuntimeException('User lacks requested tenant permission');
            }
        }
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $digest=hash('sha256',$token);
        $id=VNextPilotGuard::id();
        $record=['id'=>$id,'tenant_id'=>$tenantId,'user_id'=>$userId,
            'scopes'=>array_values($scopes),'sha256'=>$digest,
            'created_at'=>$now,'expires_at'=>$now+$ttl,'state'=>'active'];
        $this->store->mutate('system','identity',
            static function(array &$items)use($record):void {
                $items['api_tokens']??=[];
                if(count($items['api_tokens'])>=30)throw new RuntimeException('Token capacity reached');
                $items['api_tokens'][$record['id']]=$record;
            });
        return ['token'=>$token,'id'=>$id,'expires_at'=>$record['expires_at']];
    }

    /**
     * Tenant is mandatory; no token may choose the tenant context itself.
     * Authorization checks are repeated after the storage lock is released.
     */
    public function authenticate(string $token,string $tenantId,string $scope,
                                 ?int $now=null):?array {
        $now??=time();
        if(!VNextJsonStore::uuid($tenantId)||!isset(self::SCOPES[$scope])
            ||!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$token)){
            return null;
        }
        $digest=hash('sha256',$token);
        $match=$this->store->read('system','identity',
            static function(array $items)use($digest,$tenantId,$scope,$now):?array {
                foreach($items['api_tokens']??[] as $entry) {
                    if(($entry['tenant_id']??'')!==$tenantId
                        ||($entry['state']??'')!=='active'
                        ||(int)($entry['expires_at']??0)<=$now
                        ||!in_array($scope,$entry['scopes']??[],true))continue;
                    if(hash_equals((string)($entry['sha256']??''),$digest)){
                        return ['tenant_id'=>$tenantId,'user_id'=>$entry['user_id'],
                            'scope'=>$scope,'token_id'=>$entry['id']];
                    }
                }
                return null;
            });
        if($match===null)return null;
        // Deny if the tenant/user/membership was revoked since issuance.
        return (new VNextIdentity($this->store))->can($tenantId,$match['user_id'],
            self::SCOPES[$scope])?$match:null;
    }

    public function revoke(string $tokenId):bool {
        if(!VNextJsonStore::uuid($tokenId))throw new InvalidArgumentException('Invalid token ID');
        return $this->store->mutate('system','identity',
            static function(array &$items)use($tokenId):bool {
                if(!isset($items['api_tokens'][$tokenId])
                    ||$items['api_tokens'][$tokenId]['state']!=='active')return false;
                $items['api_tokens'][$tokenId]['state']='revoked';
                $items['api_tokens'][$tokenId]['revoked_at']=time();
                return true;
            });
    }
}
