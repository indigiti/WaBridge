<?php
declare(strict_types=1);
require_once __DIR__.'/member-policy.php';

/**
 * Independent tenant-member authentication for read-only pilot access.
 *
 * Security boundary:
 * - No identity inferred from URL or browser cookie without live repository checks
 * - Dedicated member claim; legacy admin claim never grants tenant authority
 * - Session regeneration on login/logout and idle/absolute expiry
 * - Bounded per-IP/tenant/email login attempts; no plaintext IP or password stored
 * - No message send or real provider connection is exposed
 */
final class VNextMemberAuth {
    private const MAX_ATTEMPTS=5;
    private const WINDOW=900;
    private const IDLE=1800;
    private const ABSOLUTE=28800;

    public function __construct(private VNextJsonStore $store) {}

    private static function email(string $email):string {
        $email=mb_strtolower(trim($email));
        if(strlen($email)>254||filter_var($email,FILTER_VALIDATE_EMAIL)===false) {
            throw new InvalidArgumentException('Invalid email');
        }
        return $email;
    }

    private static function rateKey(string $tenant,string $email,string $ip):string {
        $ip=substr($ip,0,80);
        return hash('sha256',$tenant."\0".$email."\0".$ip);
    }

    /** Count every attempted login, including successful ones until clearing. */
    private function attempt(string $key,int $now):bool {
        return $this->store->mutate('system','identity',
            static function(array &$items)use($key,$now):bool {
                $items['member_attempts']??=[];
                foreach($items['member_attempts'] as $id=>$entry) {
                    if(!is_array($entry)||($entry['since']??0)<=$now-self::WINDOW) {
                        unset($items['member_attempts'][$id]);
                    }
                }
                $row=$items['member_attempts'][$key]??null;
                if($row===null) {
                    if(count($items['member_attempts'])>=100) {
                        throw new RuntimeException('Login rate-limit capacity reached');
                    }
                    $row=['since'=>$now,'count'=>0];
                }
                if($row['count']>=self::MAX_ATTEMPTS)return false;
                $row['count']++;
                $items['member_attempts'][$key]=$row;
                return true;
            });
    }

    public function verify(string $tenantId,string $email,string $password,
                           string $ip='unknown',?int $now=null):?array {
        $now??=time();
        if(!VNextJsonStore::uuid($tenantId)
           ||strlen($password)>512||strlen($ip)>100) {
            return null;
        }
        try{$email=self::email($email);}catch(InvalidArgumentException){return null;}
        $key=self::rateKey($tenantId,$email,$ip);
        if(!$this->attempt($key,$now))return null;
        $user=$this->store->read('system','identity',
            static function(array $items)use($email):?array {
                foreach($items['users']??[] as $row) {
                    if(($row['email']??'')===$email && ($row['status']??'')==='active') {
                        return ['id'=>(string)($row['id']??''),
                            'password_hash'=>(string)($row['password_hash']??'')];
                    }
                }
                return null;
            });
        if(!is_array($user)||$user['password_hash']===''
            ||!password_verify($password,$user['password_hash']))return null;
        $member=(new VNextMemberPolicy($this->store))->member($tenantId,$user['id']);
        if($member===null)return null;
        $this->store->mutate('system','identity',
            static function(array &$items)use($key):void{
                unset($items['member_attempts'][$key]);
            });
        return $member;
    }

    public function signIn(string $tenantId,string $email,string $password,string $ip):?array {
        $member=$this->verify($tenantId,$email,$password,$ip);
        if($member===null)return null;
        if(!function_exists('start_admin_session')) {
            throw new RuntimeException('Browser session not initialized');
        }
        start_admin_session();
        session_regenerate_id(true);
        // Tenant identity MUST NOT coexist with the legacy super-admin grant.
        unset($_SESSION['admin'],$_SESSION['login_at'],$_SESSION['last_seen']);
        $_SESSION['vnext_member']=[
            'tenant_id'=>$member['tenant_id'],'user_id'=>$member['user_id'],
            'issued_at'=>time(),'last_seen'=>time()];
        return $member;
    }

    public function current():?array {
        if(!function_exists('start_admin_session'))return null;
        start_admin_session();
        if(($_SESSION['admin']??false)===true)return null;
        $claim=$_SESSION['vnext_member']??null;
        if(!is_array($claim))return null;
        $now=time();
        $issued=$claim['issued_at']??null;
        $last=$claim['last_seen']??null;
        if(!is_int($issued)||!is_int($last)
            ||$issued>$now||$last>$now
            ||$now-$issued>self::ABSOLUTE||$now-$last>self::IDLE) {
            unset($_SESSION['vnext_member']);
            return null;
        }
        $member=(new VNextMemberPolicy($this->store))->member(
            (string)($claim['tenant_id']??''),(string)($claim['user_id']??''));
        if($member===null) {
            unset($_SESSION['vnext_member']);
            return null;
        }
        $_SESSION['vnext_member']['last_seen']=$now;
        return $member;
    }

    public function signOut():void {
        if(!function_exists('start_admin_session'))return;
        start_admin_session();
        unset($_SESSION['vnext_member']);
        session_regenerate_id(true);
    }
}
