<?php
declare(strict_types=1);
require_once __DIR__ . '/json-store.php';

/**
 * W2 foundation: atomically maintain tenant/user/membership metadata in ONE
 * system-scoped JSON document. This does not activate multi-user login.
 */
final class VNextIdentity {
    private const ROLES = ['owner', 'admin', 'manager', 'agent', 'viewer'];
    private VNextJsonStore $store;

    public function __construct(VNextJsonStore $store) { $this->store = $store; }

    private static function id(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    private static function validateName(string $value): string {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 100 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Invalid display name');
        }
        return $value;
    }

    public function createOrganization(string $name): array {
        $name = self::validateName($name);
        $id = self::id(); $now = gmdate('c');
        $org = ['id'=>$id, 'name'=>$name, 'status'=>'pilot_disabled',
            'created_at'=>$now, 'updated_at'=>$now, 'version'=>1];
        $this->store->mutate('system','identity',static function(array &$items)use($org):void {
            $items['organizations'] ??= [];
            if (count($items['organizations']) >= 3) throw new RuntimeException('Pilot organization limit');
            $items['organizations'][$org['id']] = $org;
        });
        return $org;
    }

    public function createUser(string $email, string $displayName): array {
        $email = mb_strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address');
        }
        $name = self::validateName($displayName);
        $id = self::id();
        $user = ['id'=>$id, 'email'=>$email, 'name'=>$name, 'status'=>'invitation_only',
            'created_at'=>gmdate('c'), 'version'=>1];
        $this->store->mutate('system','identity',static function(array &$items)use($user):void {
            $items['users'] ??= [];
            if (count($items['users']) >= 20) throw new RuntimeException('Pilot user limit');
            foreach ($items['users'] as $existing) {
                if ($existing['email'] === $user['email']) throw new InvalidArgumentException('Email already registered');
            }
            $items['users'][$user['id']] = $user;
        });
        return $user;
    }

    public function grant(string $tenantId, string $userId, string $role): array {
        if (!VNextJsonStore::uuid($tenantId) || !VNextJsonStore::uuid($userId)
            || !in_array($role,self::ROLES,true)) throw new InvalidArgumentException('Invalid membership');
        $membership = ['tenant_id'=>$tenantId,'user_id'=>$userId,
            'role'=>$role,'status'=>'pending','version'=>1,'created_at'=>gmdate('c')];
        $this->store->mutate('system','identity',static function(array &$items)use($membership):void {
            $items['organizations'] ??= []; $items['users'] ??= []; $items['memberships'] ??= [];
            if (!isset($items['organizations'][$membership['tenant_id']])
                || !isset($items['users'][$membership['user_id']])) {
                throw new InvalidArgumentException('Unknown tenant or user');
            }
            $key = $membership['tenant_id'].':'.$membership['user_id'];
            if (isset($items['memberships'][$key])) throw new InvalidArgumentException('Membership already exists');
            $items['memberships'][$key] = $membership;
        });
        return $membership;
    }

    /**
     * No browser/UI can activate these pending records yet. Explicit provision
     * method is reserved for a future authenticated + audited admin service.
     */
    public function can(string $tenantId, string $userId, string $permission): bool {
        if (!VNextJsonStore::uuid($tenantId) || !VNextJsonStore::uuid($userId)) return false;
        $rules = [
            'inbox.read'=>['owner','admin','manager','agent','viewer'],
            'inbox.reply'=>['owner','admin','manager','agent'],
            'campaign.create'=>['owner','admin','manager'],
            'campaign.approve'=>['owner','admin'],
            'tenant.manage'=>['owner','admin'],
        ];
        if (!isset($rules[$permission])) return false;
        return $this->store->read('system','identity',
            static function(array $items)use($tenantId,$userId,$permission,$rules):bool {
                $org=$items['organizations'][$tenantId]??null;
                $user=$items['users'][$userId]??null;
                $membership=$items['memberships'][$tenantId.':'.$userId]??null;
                return is_array($org) && ($org['status']??'')==='active'
                    && is_array($user) && ($user['status']??'')==='active'
                    && is_array($membership) && ($membership['status']??'')==='active'
                    && in_array($membership['role'],$rules[$permission],true);
            });
    }


    /** Allowlisted administrative pilot projection; excludes auth secrets and messages. */
    public function adminPreview(): array {
        return $this->store->read('system','identity',static function(array $items):array {
            $organizations=[];
            foreach(($items['organizations']??[]) as $row){
                $organizations[]=['id'=>(string)$row['id'],'name'=>(string)$row['name'],
                    'status'=>(string)$row['status'],'version'=>(int)($row['version']??1)];
            }
            $users=[];
            foreach(($items['users']??[]) as $row){
                $users[]=['id'=>(string)$row['id'],'name'=>(string)$row['name'],
                    'email'=>(string)$row['email'],'status'=>(string)$row['status']];
            }
            $members=[];
            foreach(($items['memberships']??[]) as $row){
                $members[]=['tenant_id'=>(string)$row['tenant_id'],
                    'user_id'=>(string)$row['user_id'],'role'=>(string)$row['role'],
                    'status'=>(string)$row['status']];
            }
            return ['organizations'=>$organizations,'users'=>$users,'memberships'=>$members,
                'channels'=>count($items['channels']??[])];
        });
    }

    public function snapshot(): array {
        return $this->store->read('system','identity',static function(array $items):array {
            return ['organizations'=>count($items['organizations']??[]),
                    'users'=>count($items['users']??[]),
                    'memberships'=>count($items['memberships']??[])];
        });
    }
}
