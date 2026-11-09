<?php
declare(strict_types=1);
require_once __DIR__.'/json-store.php';

/**
 * Central guard for vNext's offline feature suites.
 * No live tenant or public account is implicitly authorized.
 */
final class VNextPilotGuard {
    public function __construct(private VNextJsonStore $store) {}

    public function tenant(string $tenantId): array {
        if (!VNextJsonStore::uuid($tenantId)) {
            throw new InvalidArgumentException('Invalid tenant');
        }
        return $this->store->read('system','identity',
            static function(array $items) use ($tenantId): array {
                if (($items['demo_marker'] ?? '') !== 'vnext-synthetic-1') {
                    throw new RuntimeException('Unseeded synthetic environment');
                }
                $org=$items['organizations'][$tenantId]??null;
                if (!is_array($org)||($org['status']??'')!=='pilot_disabled'
                    || !str_starts_with((string)($org['name']??''),'Demo · ')) {
                    throw new RuntimeException('Tenant not an isolated demo');
                }
                $channels=array_values(array_filter($items['channels']??[],
                    static fn(array $c):bool=>($c['tenant_id']??'')===$tenantId
                    && ($c['provider']??'')==='fixture'
                    && ($c['state']??'')==='fixture_only'));
                if (!$channels) throw new RuntimeException('No fixture channel');
                $members=array_values(array_filter($items['memberships']??[],
                    static fn(array $m):bool=>($m['tenant_id']??'')===$tenantId
                    && ($m['status']??'')==='pending'
                    && in_array($m['role']??'', ['owner','admin','manager','agent','viewer'],true)));
                return ['organization'=>['id'=>$org['id'],'name'=>$org['name']],
                    'channels'=>$channels,'memberships'=>$members,'users'=>$items['users']??[]];
            });
    }

    public static function id(): string {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&15)|64);
        $bytes[8]=chr((ord($bytes[8])&63)|128);
        $h=bin2hex($bytes);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'
            .substr($h,16,4).'-'.substr($h,20);
    }

    public static function title(string $text,int $max=80): string {
        $text=trim($text);
        if ($text===''||mb_strlen($text)>$max||
            preg_match('/[\x00-\x1f\x7f]/',$text)===1) {
            throw new InvalidArgumentException('Invalid display title');
        }
        return $text;
    }
}
