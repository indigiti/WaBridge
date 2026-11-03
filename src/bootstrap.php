<?php
declare(strict_types=1);
function config(): array {
    static $config = null;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('Configuration missing. Copy config.example.php to config.php.');
        }
        $config = require $path;
        if (!is_array($config) || empty($config['gateway_token']) || empty($config['admin_password_hash'])) {
            throw new RuntimeException('Missing gateway token or admin password hash.');
        }
    }
    return $config;
}
function start_admin_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
    session_start();
}
function csrf(): string {
    start_admin_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}
function assert_csrf(): void {
    start_admin_session();
    if (!hash_equals(csrf(), (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(403);
        throw new RuntimeException('Invalid CSRF token');
    }
}
function admin_authenticated(): bool {
    start_admin_session();
    return ($_SESSION['admin'] ?? false) === true;
}
function escape(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function valid_session_id(string $v): bool { return preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $v) === 1; }
function permitted_session(string $sid): bool {
    if (!valid_session_id($sid)) return false;
    $allowed = config()['allowed_sessions'] ?? [];
    return !$allowed || in_array($sid, $allowed, true);
}
function gateway(string $method, string $path, ?array $payload = null): array {
    $conf = config();
    $base = rtrim((string)$conf['gateway_url'], '/');
    // No user-provided URLs: only fixed internal path and validated session identifiers.
    if (!preg_match('~\A/(?:api/v1/[a-zA-Z0-9_/-]+|livez|readyz)\z~D', $path)) {
        throw new InvalidArgumentException('Invalid gateway path');
    }
    $url = $base . $path;
    if (!preg_match('~\Ahttps?://(?:127\.0\.0\.1|localhost|\[::1\]|[a-zA-Z0-9.-]+)(?::[0-9]{2,5})?\z~D', $base)) {
        throw new RuntimeException('Invalid configured gateway URL');
    }
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('cURL unavailable');
    $headers = ['Authorization: Bearer ' . $conf['gateway_token'], 'Accept: application/json'];
    if ($payload !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 18, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['http' => 503, 'data' => ['error' => 'Gateway unavailable', 'detail' => $err]];
    $parsed = json_decode($body, true);
    return ['http' => $code ?: 502, 'data' => is_array($parsed) ? $parsed : ['message' => mb_substr($body, 0, 300)]];
}
function validate_message(array $input): array {
    $sid = (string)($input['session_id'] ?? '');
    $to = (string)($input['to'] ?? '');
    $message = (string)($input['text'] ?? '');
    if (!permitted_session($sid)) throw new InvalidArgumentException('Invalid or unauthorized session');
    if (!preg_match('/\A[1-9][0-9]{7,14}\z/D', $to)) throw new InvalidArgumentException('Recipient must be international digits without plus');
    if (trim($message) === '' || mb_strlen($message) > (int)(config()['max_message_chars'] ?? 2000))
        throw new InvalidArgumentException('Message must be nonempty and within length limit');
    return [$sid, $to, $message];
}
