<?php
declare(strict_types=1);
require_once __DIR__ . '/json-store.php';

/**
 * W1 contract for verified, upstream-normalized event metadata.
 * This class NEVER verifies network signatures: the existing authenticated
 * webhook boundary must perform signature verification before invoking it.
 * No new public ingress route is exposed in this increment.
 */
final class VNextEventJournal {
    private VNextJsonStore $store;
    private const TYPES = ['message.inbound', 'message.sent', 'message.delivered',
        'message.read', 'message.failed', 'session.connected', 'session.disconnected'];
    public function __construct(VNextJsonStore $store) { $this->store = $store; }

    public static function normalize(string $tenantId, array $event): array {
        if (!VNextJsonStore::uuid($tenantId)) throw new InvalidArgumentException('Invalid tenant');
        $type=(string)($event['type']??'');
        if (!in_array($type,self::TYPES,true)) throw new InvalidArgumentException('Unsupported event type');
        $provider=(string)($event['provider']??'');
        if (!in_array($provider,['waxum','official_cloud','fixture'],true)) {
            throw new InvalidArgumentException('Unknown provider');
        }
        $channel=(string)($event['channel_id']??'');
        if (!VNextJsonStore::uuid($channel)) throw new InvalidArgumentException('Invalid channel');
        $external=(string)($event['provider_event_id']??'');
        if (!preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D',$external)) {
            throw new InvalidArgumentException('Invalid provider event ID');
        }
        $providerMessage=(string)($event['provider_message_id']??'');
        if ($providerMessage!=='' && !preg_match('/\A[a-zA-Z0-9._:-]{1,200}\z/D',$providerMessage)) {
            throw new InvalidArgumentException('Invalid provider message ID');
        }
        $occurred=(int)($event['occurred_at']??0);
        if ($occurred<1 || $occurred>time()+300) throw new InvalidArgumentException('Invalid event timestamp');
        return [
            'schema_version'=>1,'tenant_id'=>$tenantId,'type'=>$type,
            'provider'=>$provider,'channel_id'=>$channel,
            'provider_event_id'=>$external,'provider_message_id'=>$providerMessage,
            'occurred_at'=>$occurred,
        ];
    }

    /**
     * Internal-only verified metadata insertion; HTTP endpoints must never
     * accept a client-provided "verified" flag in place of signature checking.
     */
    public function recordVerified(array $normalized): bool {
        $tenant=(string)($normalized['tenant_id']??'');
        // Revalidate before persistence, even when already normalized.
        $event=self::normalize($tenant,$normalized);
        $key=hash('sha256',$event['provider']."\0".$event['channel_id']."\0".$event['provider_event_id']);
        return $this->store->mutate($tenant,'events',
            static function(array &$items)use($key,$event):bool {
                if (isset($items[$key])) {
                    if ($items[$key]['event'] !== $event) {
                        throw new RuntimeException('Conflicting duplicate provider event');
                    }
                    return false;
                }
                $items[$key]=['event'=>$event,'recorded_at'=>gmdate('c'),'state'=>'recorded'];
                return true;
            });
    }

    public function count(string $tenantId): int {
        if (!VNextJsonStore::uuid($tenantId)) throw new InvalidArgumentException('Invalid tenant');
        return $this->store->read($tenantId,'events',static fn(array $items):int => count($items));
    }
}
