<?php
declare(strict_types=1);
require_once __DIR__ . '/gateway-settings.php';
function config(): array {
    static $config = null;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('PRIVATE_CONFIG_MISSING');
        }
        $config = require $path;
        if (!is_array($config)) throw new RuntimeException('INVALID_PRIVATE_CONFIG');
        if (empty($config['admin_password_hash']) || !is_string($config['admin_password_hash'])) {
            throw new RuntimeException('ADMIN_CREDENTIALS_NOT_CONFIGURED');
        }
        if (empty($config['admin_user']) || !is_string($config['admin_user'])) {
            throw new RuntimeException('ADMIN_USERNAME_NOT_CONFIGURED');
        }
        // Only the exact loopback endpoint may be configured through the browser.
        // Preserve existing admin credentials and all other application settings.
        $local = gateway_settings_read();
        if ($local !== null) {
            $config['gateway_url'] = $local['gateway_url'];
            $config['gateway_token'] = $local['gateway_token'];
        }
    }
    return $config;
}
function start_admin_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // Multiple PHP applications share stage.digiti.in; isolate WaBridge's
    // session cookie so another application's PHPSESSID cannot replace it.
    session_name('WABRIDGESESSID');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    $https = ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT']??0)===443);
    session_set_cookie_params([
        'lifetime'=>0,
        'path'=>'/wabridge/',
        'domain'=>'',
        'secure'=>$https,
        'httponly'=>true,
        'samesite'=>'Strict',
    ]);
    if (!session_start()) throw new RuntimeException('Unable to start administrator session');
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
    if (($_SESSION['admin'] ?? false) !== true) return false;
    $now=time();
    if ($now-(int)($_SESSION['last_seen']??0)>3600 || $now-(int)($_SESSION['login_at']??0)>28800) {
        unset($_SESSION['admin'],$_SESSION['last_seen'],$_SESSION['login_at']);
        return false;
    }
    $_SESSION['last_seen']=$now;
    return true;
}
/** Generate RFC 4122 UUIDv4 for new internal records. */
function wabridge_guid(): string {
    $b=random_bytes(16);
    $b[6]=chr((ord($b[6])&0x0f)|0x40);
    $b[8]=chr((ord($b[8])&0x3f)|0x80);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
/** Legacy 32-hex IDs stay readable; UUIDv4 is used for new jobs. */
function valid_job_id(string $id): bool {
    return preg_match('/\\A(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})\\z/D',$id)===1;
}
function escape(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function valid_session_id(string $v): bool { return preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $v) === 1; }
function permitted_session(string $sid): bool {
    if (!valid_session_id($sid)) return false;
    $allowed = config()['allowed_sessions'] ?? [];
    return !$allowed || in_array($sid, $allowed, true);
}
/**
 * Fail fast on read-only health/listing requests when Rust is degraded.
 * Preserve 18 seconds for real sends and provider credential exchanges.
 * Timed-out sends remain uncertain and must never be auto-retried.
 */
function gateway_request_timeout(string $method,string $path):int {
    if($method!=='GET')return 18;
    if(in_array($path,['/livez','/readyz'],true))return 3;
    if(str_starts_with($path,'/api/v1/sessions'))return 5;
    return 8;
}
function gateway(string $method, string $path, ?array $payload = null): array {
    $conf = config();
    if (empty($conf['gateway_token']) || !is_string($conf['gateway_token'])) {
        return ['http'=>503,'data'=>['error'=>'gateway_not_configured']];
    }
    $base = rtrim((string)($conf['gateway_url']??''), '/');
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
    $timeout=gateway_request_timeout($method,$path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => min(3,$timeout),
        CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
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

require_once __DIR__ . '/gateway-provider.php';
