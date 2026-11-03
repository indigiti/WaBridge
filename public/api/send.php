<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST'); http_response_code(405); echo json_encode(['error'=>'method_not_allowed']); exit;
}
try {
    $expected = (string)(config()['external_token_sha256'] ?? '');
    $provided = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/\ABearer ([^\s]{20,256})\z/D', $provided, $m) ||
        !preg_match('/\A[a-f0-9]{64}\z/D', $expected) ||
        !hash_equals($expected, hash('sha256', $m[1]))) {
        http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit;
    }
    if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 8192) {
        http_response_code(413); echo json_encode(['error'=>'payload_too_large']); exit;
    }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) throw new InvalidArgumentException('Payload too large');
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Object expected');
    [$sid, $to, $text] = validate_message($input);
    $response = gateway('POST', '/api/v1/sessions/' . $sid . '/messages/text', ['to'=>$to, 'text'=>$text]);
    http_response_code($response['http']);
    echo json_encode($response['data'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (JsonException | InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['error'=>'invalid_request','detail'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('WaBridge API error: ' . get_class($e));
    http_response_code(503); echo json_encode(['error'=>'service_unavailable']);
}
