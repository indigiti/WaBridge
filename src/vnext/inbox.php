<?php
declare(strict_types=1);
require_once __DIR__ . '/identity.php';

/**
 * Offline W3 Inbox preview. This class is deliberately restricted to
 * deterministic fictional pilot tenants marked by VNextDemo::seedPilot().
 * No real contacts, gateway sessions, external messages or sends are read.
 *
 * One tenant inbox.json is the atomic boundary for state, assignments, notes,
 * and conversation history; production ingress requires a separate contract.
 */
final class VNextInbox {
    private const STATES = ['new', 'open', 'pending', 'resolved'];
    private const NOTES = [
        'Follow-up required',
        'Sample quotation requested',
        'Escalate to demo manager',
        'Awaiting simulated customer response',
    ];
    public function __construct(private VNextJsonStore $store) {}

    private function fixtureTenant(string $tenantId): array {
        if (!VNextJsonStore::uuid($tenantId)) {
            throw new InvalidArgumentException('Invalid tenant ID');
        }
        return $this->store->read('system', 'identity',
            static function(array $items) use ($tenantId): array {
                if (($items['demo_marker'] ?? '') !== 'vnext-synthetic-1') {
                    throw new RuntimeException('Only synthetic seeded tenants are supported');
                }
                $org = $items['organizations'][$tenantId] ?? null;
                if (!is_array($org) || ($org['status'] ?? '') !== 'pilot_disabled'
                    || !str_starts_with((string)($org['name'] ?? ''), 'Demo · ')) {
                    throw new RuntimeException('Tenant is not a disabled synthetic pilot');
                }
                $channels = array_values(array_filter($items['channels'] ?? [],
                    static fn(array $channel): bool =>
                        ($channel['tenant_id'] ?? '') === $tenantId
                        && ($channel['provider'] ?? '') === 'fixture'
                        && ($channel['state'] ?? '') === 'fixture_only'
                ));
                if (!$channels) throw new RuntimeException('Synthetic channel missing');
                $users = array_values(array_filter($items['memberships'] ?? [],
                    static fn(array $m): bool =>
                        ($m['tenant_id'] ?? '') === $tenantId && ($m['status'] ?? '') === 'pending'
                ));
                return ['org' => $org, 'channel' => $channels[0], 'memberships' => $users,
                    'users' => $items['users'] ?? []];
            });
    }

    public static function defaultThreads(string $tenantId, string $channelId): array {
        if (!VNextJsonStore::uuid($tenantId) || !VNextJsonStore::uuid($channelId)) {
            throw new InvalidArgumentException('Invalid synthetic identities');
        }
        $topics = [
            ['Sample enquiry #101','Hello, I would like details about the demo service.','Thank you. This is a simulated team response.','new'],
            ['Sample quotation #102','Could you share an example quotation?','Our demo team will prepare a sample quotation.','open'],
            ['Sample support #103','I need help with the example order.','We have recorded your fictional support request.','pending'],
        ];
        $threads = [];
        foreach ($topics as $i => $topic) {
            $n = $i + 1;
            $id = sprintf('bb770000-0000-4000-8000-%012d', $n);
            $threads[$id] = [
                'id' => $id, 'tenant_id' => $tenantId, 'channel_id' => $channelId,
                'contact_label' => 'Fictional Customer ' . sprintf('%02d',$n),
                'subject' => $topic[0], 'state' => $topic[3],
                'assignee_id' => null, 'version' => 1,
                'messages' => [
                    ['id' => $id . '-in', 'direction' => 'inbound_fixture',
                        'text' => $topic[1], 'status' => 'simulation'],
                    ['id' => $id . '-out', 'direction' => 'outbound_fixture',
                        'text' => $topic[2], 'status' => 'simulation'],
                ],
                'notes' => [],
                'updated_at' => gmdate('c'),
            ];
        }
        return $threads;
    }

    /** Never replace existing tenant inbox data. */
    public function seed(string $tenantId): array {
        $ownership = $this->fixtureTenant($tenantId);
        return $this->store->mutate($tenantId, 'inbox',
            static function(array &$items) use ($tenantId, $ownership): array {
                if (isset($items['seed_marker'])) {
                    if ($items['seed_marker'] !== 'fixture-inbox-v1') {
                        throw new RuntimeException('Unrecognized inbox data: seed refused');
                    }
                    return ['seeded' => false, 'count' => count($items['threads'] ?? [])];
                }
                if ($items !== []) throw new RuntimeException('Existing inbox data: seed refused');
                $items['seed_marker'] = 'fixture-inbox-v1';
                $items['threads'] = self::defaultThreads($tenantId, $ownership['channel']['id']);
                return ['seeded' => true, 'count' => count($items['threads'])];
            });
    }

    /** A tenant's read model is intentionally bounded, including nested data. */
    public function overview(string $tenantId, string $state = ''): array {
        $owner = $this->fixtureTenant($tenantId);
        if ($state !== '' && !in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Invalid inbox status');
        }
        $data = $this->store->read($tenantId, 'inbox',
            static function(array $items) use ($state): array {
                if (($items['seed_marker'] ?? null) !== 'fixture-inbox-v1') {
                    return ['threads' => [], 'seeded' => false];
                }
                $rows = array_values($items['threads'] ?? []);
                if ($state !== '') {
                    $rows = array_values(array_filter($rows,
                        static fn(array $r): bool => $r['state'] === $state));
                }
                usort($rows, static fn(array $a, array $b): int =>
                    strcmp($a['id'], $b['id']));
                $safe = [];
                foreach (array_slice($rows, 0, 50) as $row) {
                    $safe[] = [
                        'id' => $row['id'], 'subject' => $row['subject'],
                        'contact_label' => $row['contact_label'],
                        'state' => $row['state'], 'version' => $row['version'],
                        'assignee_id' => $row['assignee_id'],
                        'message_count' => count($row['messages']),
                        'note_count' => count($row['notes']),
                    ];
                }
                return ['threads' => $safe, 'seeded' => true];
            });
        $data['tenant'] = ['id' => $tenantId, 'name' => $owner['org']['name']];
        $data['team'] = array_map(static function(array $m) use ($owner): array {
            $user = $owner['users'][$m['user_id']] ?? [];
            return ['user_id' => $m['user_id'], 'name' => (string)($user['name'] ?? 'Demo user'),
                'role' => $m['role']];
        }, $owner['memberships']);
        return $data;
    }

    public function detail(string $tenantId, string $threadId): ?array {
        $this->fixtureTenant($tenantId);
        if (!VNextJsonStore::uuid($threadId)) return null;
        return $this->store->read($tenantId, 'inbox',
            static function(array $items) use ($threadId): ?array {
                if (($items['seed_marker'] ?? '') !== 'fixture-inbox-v1') return null;
                $thread = $items['threads'][$threadId] ?? null;
                return is_array($thread) ? $thread : null;
            });
    }

    public function assign(string $tenantId, string $threadId,
                           string $userId, int $expectedVersion): array {
        $tenant = $this->fixtureTenant($tenantId);
        if (!VNextJsonStore::uuid($threadId) || !VNextJsonStore::uuid($userId)
            || $expectedVersion < 1) throw new InvalidArgumentException('Invalid assignment');
        $allowed = false;
        foreach ($tenant['memberships'] as $membership) {
            if ($membership['user_id'] === $userId && in_array($membership['role'],
                ['owner','admin','manager','agent'], true)) $allowed = true;
        }
        if (!$allowed) throw new InvalidArgumentException('Agent is not a member of this pilot tenant');
        return $this->modify($tenantId, $threadId, $expectedVersion,
            static function(array &$thread) use ($userId): void {
                $thread['assignee_id'] = $userId;
            });
    }

    public function changeState(string $tenantId, string $threadId,
                                string $state, int $expectedVersion): array {
        if (!in_array($state, self::STATES, true)) throw new InvalidArgumentException('Unknown inbox state');
        return $this->modify($tenantId, $threadId, $expectedVersion,
            static function(array &$thread) use ($state): void {
                $thread['state'] = $state;
            });
    }

    /** Fixed synthetic note options prevent PII from entering demo storage. */
    public function addPresetNote(string $tenantId, string $threadId,
                                  string $note, int $expectedVersion): array {
        if (!in_array($note, self::NOTES, true)) throw new InvalidArgumentException('Only synthetic notes allowed');
        return $this->modify($tenantId, $threadId, $expectedVersion,
            static function(array &$thread) use ($note): void {
                if (count($thread['notes']) >= 20) throw new RuntimeException('Note limit');
                $thread['notes'][] = [
                    'text' => $note, 'by' => 'demo_operator',
                    'created_at' => gmdate('c'),
                ];
            });
    }

    public static function noteOptions(): array { return self::NOTES; }

    private function modify(string $tenantId, string $threadId,
                            int $version, callable $mutator): array {
        $this->fixtureTenant($tenantId);
        if (!VNextJsonStore::uuid($threadId) || $version < 1) {
            throw new InvalidArgumentException('Invalid thread revision');
        }
        return $this->store->mutate($tenantId, 'inbox',
            static function(array &$items) use ($threadId, $version, $mutator): array {
                if (($items['seed_marker'] ?? '') !== 'fixture-inbox-v1'
                    || !isset($items['threads'][$threadId])) {
                    throw new InvalidArgumentException('Synthetic conversation not found');
                }
                $thread = &$items['threads'][$threadId];
                if (($thread['version'] ?? 0) !== $version) {
                    throw new RuntimeException('Conversation version conflict');
                }
                $mutator($thread);
                $thread['version']++;
                $thread['updated_at'] = gmdate('c');
                return $thread;
            });
    }
}
