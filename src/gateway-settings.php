<?php
declare(strict_types=1);

/**
 * Local-only, browser-managed connection settings.
 *
 * This file stores no public-facing URL and must never be copied into public_html.
 * It intentionally cannot install or start a process from a web request.
 */
function local_gateway_url(): string { return 'http://127.0.0.1:3451'; }

function gateway_settings_path(): string {
    return dirname(__DIR__) . '/runtime/gateway-settings.json';
}

function gateway_settings_read(): ?array {
    $path = gateway_settings_path();
    if (is_link($path)) throw new RuntimeException('Gateway settings symlink prohibited');
    if (!is_file($path)) return null;
    if (!is_readable($path)) throw new RuntimeException('Private gateway settings unreadable');
    $raw = file_get_contents($path);
    if (!is_string($raw) || strlen($raw) > 2048) throw new RuntimeException('Invalid gateway settings file');
    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['gateway_url'] ?? null) !== local_gateway_url()
        || !is_string($data['gateway_token'] ?? null)
        || !preg_match('/\A[\x21-\x7e]{32,256}\z/D', $data['gateway_token'])) {
        throw new RuntimeException('Invalid gateway settings');
    }
    return ['gateway_url' => local_gateway_url(), 'gateway_token' => $data['gateway_token']];
}

function gateway_settings_save(string $token): void {
    if (!preg_match('/\A[\x21-\x7e]{32,256}\z/D', $token)) {
        throw new InvalidArgumentException('Enter a Waxum token of 32–256 printable characters without spaces.');
    }
    $path = gateway_settings_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create private runtime directory');
    }
    if (is_link($dir) || is_link($path)) throw new RuntimeException('Runtime symlink prohibited');
    @chmod($dir, 0700);
    $file = tempnam($dir, '.gateway-');
    if ($file === false) throw new RuntimeException('Unable to create private settings file');
    try {
        if (!chmod($file, 0600)) throw new RuntimeException('Unable to protect gateway settings');
        $json = json_encode([
            'gateway_url' => local_gateway_url(),
            'gateway_token' => $token,
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($file, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('Unable to save private gateway settings');
        }
        if (!rename($file, $path)) throw new RuntimeException('Unable to activate gateway settings');
        @chmod($path, 0600);
    } finally {
        if (is_file($file)) @unlink($file);
    }
}

function gateway_settings_clear(): void {
    $path = gateway_settings_path();
    if (is_link($path)) throw new RuntimeException('Gateway settings symlink prohibited');
    if (is_file($path) && !unlink($path)) throw new RuntimeException('Unable to reset gateway settings');
}
