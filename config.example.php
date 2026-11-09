<?php
declare(strict_types=1);
return [
    // JSON is for low-volume single-server testing. Keep MariaDB for production.
    // Existing private configs without this option retain their SQLite data until
    // explicitly migrated using the web-only Storage Settings screen.
    'storage_driver' => 'json',
    // P5 official-provider connection and approved template operations; off until reviewed.
    'enable_official_cloud_connect' => false,
    'enable_ai_drafts' => false,
    // Staging-only: allow existing authenticated admin to create disabled pilot records.
    'enable_vnext_pilot_admin' => false,
    // Staging-only: permitted edits of synthetic team inbox data (no sends).
    'enable_vnext_inbox_preview' => false,
    // Offline W4-W6 studios: synthetic workflows/campaigns/assistant/Flows only.
    // Never enables an outbound worker or third-party API calls.
    'enable_vnext_studio_preview' => false,
    // Staging-only synthetic webhook endpoint. Never enable on production.
    'enable_vnext_fixture_webhook' => false,
    'vnext_fixture_webhook_secret' => getenv('WABRIDGE_VNEXT_FIXTURE_SECRET') ?: '',
    'ai_api_key' => getenv('WABRIDGE_AI_API_KEY') ?: '',
    'ai_model' => getenv('WABRIDGE_AI_MODEL') ?: '',
    'gateway_url' => getenv('WABRIDGE_GATEWAY_URL') ?: 'http://127.0.0.1:3451',
    'gateway_token' => getenv('WABRIDGE_GATEWAY_TOKEN') ?: '',
    'admin_user' => getenv('WABRIDGE_ADMIN_USER') ?: 'admin',
    'admin_password_hash' => getenv('WABRIDGE_ADMIN_PASSWORD_HASH') ?: '',
    'allowed_sessions' => array_values(array_filter(explode(',',getenv('WABRIDGE_ALLOWED_SESSIONS')?:''))),
    'max_message_chars'=>2000,
    // Per-application token and explicit allowed sessions, for example:
    // 'schoolflow' => ['token_sha256'=>'...64 hex...', 'sessions'=>['school-01'], 'per_minute'=>20]
    'api_clients'=>[],
    'external_token_sha256'=>getenv('WABRIDGE_EXTERNAL_TOKEN_SHA256')?:'',
    'webhook_secret'=>getenv('WABRIDGE_WEBHOOK_SECRET')?:'',
    // Registered outbound webhooks: configure only HTTPS endpoints controlled by clients.
    // 'schoolflow'=>['url'=>'https://crm.example.com/webhooks/wabridge',
    //   'secret'=>'long-random-hmac-secret-at-least-32-characters',
    //   'sessions'=>['school-01'], 'events'=>['message','receipt']]
    'webhook_subscribers'=>[],
    // P5: restricted small-batch queue. Keep disabled until consent and engine reliability are verified.
    'enable_batches'=>false,
];
