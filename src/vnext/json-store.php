<?php
declare(strict_types=1);

/**
 * W0 JSON repository for additive, single-host staging only.
 * An atomic transaction covers ONE (scope, collection) document.
 * It does not provide transactions across files or distributed workers.
 */
final class VNextJsonStore {
    private const MAX_BYTES = 2097152;
    private const MAX_RECORDS = 1000;
    private const COLLECTIONS = ['identity', 'events', 'intents'];
    private string $root;

    public function __construct(?string $root = null) {
        $this->root = rtrim($root ?? dirname(__DIR__, 2) . '/runtime/vnext', '/');
        if ($this->root === '' || is_link($this->root)) {
            throw new RuntimeException('Invalid vNext storage root');
        }
    }

    public static function uuid(string $id): bool {
        return preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $id) === 1;
    }

    private function directory(string $scope, string $collection): string {
        if (!in_array($collection, self::COLLECTIONS, true)) {
            throw new InvalidArgumentException('Unknown vNext collection');
        }
        if ($scope !== 'system' && !self::uuid($scope)) {
            throw new InvalidArgumentException('Invalid tenant scope');
        }
        if ($collection === 'identity' && $scope !== 'system') {
            throw new InvalidArgumentException('Identity is system scoped');
        }
        if (in_array($collection, ['events','intents'], true) && $scope === 'system') {
            throw new InvalidArgumentException('Events must be tenant scoped');
        }
        $parts = $scope === 'system' ? ['system'] : ['tenants', $scope];
        $path = $this->root;
        foreach (array_merge([''], $parts) as $part) {
            if ($part !== '') $path .= '/' . $part;
            if (is_link($path)) throw new RuntimeException('Storage symlink refused');
            if (!is_dir($path) && !mkdir($path, 0700) && !is_dir($path)) {
                throw new RuntimeException('Cannot create private storage directory');
            }
            if (!is_dir($path)) throw new RuntimeException('Invalid storage directory');
            @chmod($path, 0700);
        }
        return $path;
    }

    /** Shared flock protects readers from concurrent writers and lost updates. */
    private function transaction(
        string $scope, string $collection, bool $write,
        callable $callback, ?int $expectedRevision = null
    ): mixed {
        $dir = $this->directory($scope, $collection);
        $file = $dir . '/' . $collection . '.json';
        $lockFile = $dir . '/' . $collection . '.lock';
        if (is_link($file) || is_link($lockFile)) throw new RuntimeException('Storage symlink refused');
        $lock = fopen($lockFile, 'c+b');
        if ($lock === false) throw new RuntimeException('Cannot acquire storage lock');
        @chmod($lockFile, 0600);
        try {
            if (!flock($lock, $write ? LOCK_EX : LOCK_SH)) {
                throw new RuntimeException('Storage lock failed');
            }
            try {
                if (is_link($file)) throw new RuntimeException('Storage symlink refused');
                $state = ['schema' => 1, 'revision' => 0, 'items' => []];
                if (is_file($file)) {
                    $length = filesize($file);
                    if ($length === false || $length < 1 || $length > self::MAX_BYTES) {
                        throw new RuntimeException('Invalid JSON storage capacity');
                    }
                    $raw = file_get_contents($file);
                    $decoded = json_decode((string)$raw, true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($decoded) || ($decoded['schema'] ?? null) !== 1
                        || !is_int($decoded['revision'] ?? null)
                        || $decoded['revision'] < 0 || !is_array($decoded['items'] ?? null)) {
                        throw new RuntimeException('Invalid JSON storage schema');
                    }
                    $state = $decoded;
                }
                if ($expectedRevision !== null && $state['revision'] !== $expectedRevision) {
                    throw new RuntimeException('VNext revision conflict');
                }
                $result = $callback($state['items'], $state['revision']);
                if ($write) {
                    if (count($state['items']) > self::MAX_RECORDS) {
                        throw new RuntimeException('VNext collection capacity reached');
                    }
                    $state['revision']++;
                    $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    if (strlen($encoded) > self::MAX_BYTES) {
                        throw new RuntimeException('VNext JSON byte capacity reached');
                    }
                    $temp = tempnam($dir, '.vnext-');
                    if ($temp === false) throw new RuntimeException('Cannot create private temp file');
                    try {
                        if (!chmod($temp, 0600)) throw new RuntimeException('Cannot protect temp file');
                        $out = fopen($temp, 'wb');
                        if ($out === false) throw new RuntimeException('Cannot open temp file');
                        try {
                            $bytes = strlen($encoded);
                            $offset = 0;
                            while ($offset < $bytes) {
                                $written = fwrite($out, substr($encoded, $offset));
                                if ($written === false || $written === 0) {
                                    throw new RuntimeException('Incomplete JSON write');
                                }
                                $offset += $written;
                            }
                            if (!fflush($out)) throw new RuntimeException('JSON flush failed');
                            if (function_exists('fsync') && !fsync($out)) {
                                throw new RuntimeException('JSON sync failed');
                            }
                        } finally {
                            fclose($out);
                        }
                        if (!rename($temp, $file)) throw new RuntimeException('JSON rename failed');
                    } finally {
                        if (is_file($temp)) unlink($temp);
                    }
                }
                return $result;
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    public function read(string $scope, string $collection, callable $callback): mixed {
        return $this->transaction($scope, $collection, false, $callback);
    }

    public function mutate(
        string $scope, string $collection, callable $callback,
        ?int $expectedRevision = null
    ): mixed {
        return $this->transaction($scope, $collection, true, $callback, $expectedRevision);
    }

    public function revision(string $scope, string $collection): int {
        return $this->read($scope, $collection, static fn(array $items, int $revision): int => $revision);
    }
}
