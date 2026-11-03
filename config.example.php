<?php
declare(strict_types=1);
// Copy to config.php outside source control. Use environment variables in production.
return [
    'gateway_url' => getenv('WABRIDGE_GATEWAY_URL') ?: 'http://127.0.0.1:3451',
    'gateway_token' => getenv('WABRIDGE_GATEWAY_TOKEN') ?: '',
    'admin_user' => getenv('WABRIDGE_ADMIN_USER') ?: 'admin',
    // Generate with: php -r "echo password_hash('your-unique-password', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_password_hash' => getenv('WABRIDGE_ADMIN_PASSWORD_HASH') ?: '',
    'external_token_sha256' => getenv('WABRIDGE_EXTERNAL_TOKEN_SHA256') ?: '',
    'allowed_sessions' => array_values(array_filter(explode(',', getenv('WABRIDGE_ALLOWED_SESSIONS') ?: ''))),
    'max_message_chars' => 2000,
];
