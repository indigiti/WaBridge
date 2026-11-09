<?php
declare(strict_types=1);
require_once __DIR__.'/pilot-guard.php';
require_once __DIR__.'/identity.php';

/**
 * W2 internal invitation and password verification contract.
 * No public sign-in, email delivery or tenant activation is exposed.
 * Never mix this with the legacy platform administrator password.
 */
final class VNextInvitations {
    public function __construct(private VNextJsonStore $store) {}

    public function issue(string $tenantId,string $userId,int $ttl=86400,?int $now=null):array {
        $now??=time();
        if(!VNextJsonStore::uuid($tenantId)||!VNextJsonStore::uuid($userId)
           ||$ttl<300||$ttl>86400)throw new InvalidArgumentException('Invalid invite request');
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $digest=hash('sha256',$token);
        $id=VNextPilotGuard::id();
        $this->store->mutate('system','identity',
            static function(array &$items)use($tenantId,$userId,$ttl,$now,$id,$digest):void {
                if(!isset($items['organizations'][$tenantId])
                    ||!isset($items['users'][$userId])
                    ||!isset($items['memberships'][$tenantId.':'.$userId])
                    ||($items['memberships'][$tenantId.':'.$userId]['status']??'')!=='pending'
                    ||($items['users'][$userId]['status']??'')!=='invitation_only') {
                    throw new RuntimeException('Not a pending invited member');
                }
                $items['invites']??=[];
                if(count($items['invites'])>=40)throw new RuntimeException('Invitation capacity reached');
                foreach($items['invites'] as $invite) {
                    if(($invite['tenant_id']??'')===$tenantId
                       &&($invite['user_id']??'')===$userId
                       &&($invite['state']??'')==='issued'
                       &&($invite['expires_at']??0)>$now) {
                        throw new RuntimeException('Existing unexpired invitation');
                    }
                }
                $items['invites'][$id]=['id'=>$id,'tenant_id'=>$tenantId,
                    'user_id'=>$userId,'sha256'=>$digest,'state'=>'issued',
                    'created_at'=>$now,'expires_at'=>$now+$ttl];
            });
        return ['token'=>$token,'invitation_id'=>$id,'expires_at'=>$now+$ttl];
    }

    public function accept(string $token,string $password,?int $now=null):array {
        $now??=time();
        if(!preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$token)
           ||strlen($password)<12||strlen($password)>128){
            throw new InvalidArgumentException('Invalid invitation or password');
        }
        $digest=hash('sha256',$token);
        // Hash before entering the file lock to minimize critical section time.
        $hash=password_hash($password,PASSWORD_DEFAULT);
        if(!is_string($hash)||$hash==='')throw new RuntimeException('Password hashing unavailable');
        return $this->store->mutate('system','identity',
            static function(array &$items)use($digest,$now,$hash):array {
                foreach($items['invites']??[] as $id=>$invite) {
                    if(!hash_equals((string)($invite['sha256']??''),$digest))continue;
                    if(($invite['state']??'')!=='issued'||($invite['expires_at']??0)<=$now){
                        throw new RuntimeException('Invitation expired or consumed');
                    }
                    $uid=$invite['user_id'];
                    $tenant=$invite['tenant_id'];
                    $member=$items['memberships'][$tenant.':'.$uid]??null;
                    $user=$items['users'][$uid]??null;
                    if(!is_array($member)||($member['status']??'')!=='pending'
                       ||!is_array($user)||($user['status']??'')!=='invitation_only') {
                        throw new RuntimeException('Invitation membership no longer valid');
                    }
                    $items['users'][$uid]['password_hash']=$hash;
                    $items['users'][$uid]['password_changed_at']=$now;
                    $items['users'][$uid]['status']='active';
                    $items['users'][$uid]['version']++;
                    $items['invites'][$id]['state']='consumed';
                    $items['invites'][$id]['consumed_at']=$now;
                    // Membership remains PENDING until audited authorization
                    // and active tenant provisioning. No login session created.
                    return ['user_id'=>$uid,'tenant_id'=>$tenant,
                        'membership_state'=>'pending','can_access'=>false];
                }
                throw new RuntimeException('Invitation unknown');
            });
    }

    /**
     * Internal authentication primitive. Caller must add session hardening,
     * IP throttling, CSRF, audit, channel membership and account activation.
     */
    public function verifyPassword(string $tenantId,string $userId,string $password):bool {
        if(!VNextJsonStore::uuid($tenantId)||!VNextJsonStore::uuid($userId)
           ||strlen($password)>128)return false;
        $hash=$this->store->read('system','identity',
            static function(array $items)use($userId):?string{
                $user=$items['users'][$userId]??null;
                if(!is_array($user)||($user['status']??'')!=='active')return null;
                return (string)($user['password_hash']??'');
            });
        return is_string($hash)&&$hash!==''&&password_verify($password,$hash)
            &&(new VNextIdentity($this->store))->can($tenantId,$userId,'inbox.read');
    }
}
