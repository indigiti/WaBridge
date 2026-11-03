<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
if (!admin_authenticated()) { http_response_code(403);exit('Forbidden'); }
$id=(string)($_GET['sid']??'');
if (!permitted_session($id)) { http_response_code(400);exit('Invalid session'); }
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
$ready=gateway_diagnostics();
$response=$ready['ready']?gateway('GET','/api/v1/sessions/'.$id.'/qr'):['http'=>503,'data'=>[]];
$codes=$response['data']['qr_codes']??[];
$qr=is_array($codes) ? (string)($codes[0]??'') : '';
$state=(string)($response['data']['status']??'unavailable');
if (strlen($qr)>8192) $qr='';
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta http-equiv="refresh" content="50">
<title>Pair WhatsApp · WaBridge</title>
<link rel="stylesheet" href="/wabridge/assets/wabridge-premium-v2.css?v=3">
<style>body{display:grid;place-items:center;padding:28px}.pair-card{width:min(100%,460px);background:#ffffffdd;border:1px solid white;box-shadow:0 20px 70px #173d6620;padding:32px;border-radius:22px;text-align:center}.pair-qr{width:260px;min-height:260px;max-width:100%;margin:22px auto;display:grid;place-items:center;background:white;border:12px solid white;border-radius:12px}.pair-qr canvas,.pair-qr img{max-width:100%}.pair-help{color:#64748b;font-size:13px;line-height:1.7}</style>
</head><body><main class="pair-card">
<a href="./">← Back to dashboard</a><h1>Pair WhatsApp device</h1>
<p class="pair-help">Session <?= escape($id) ?> · <?= escape($state) ?></p>
<?php if($qr!==''): ?>
<div id="pair-qr" class="pair-qr" data-value="<?= escape($qr) ?>" aria-label="Temporary WhatsApp pairing QR code"></div>
<p class="pair-help">Open WhatsApp → Linked devices → Link a device. QR code refreshes automatically.</p>
<script src="/wabridge/assets/vendor/qrcode.min.js" defer></script>
<script src="/wabridge/assets/qr-init.js" defer></script>
<?php else: ?>
<p class="notice notice-warning">QR code is not available. <?= escape($ready['ready']?'The session may still be connecting. Refresh in a few seconds.':$ready['message']) ?></p>
<?php endif; ?>
<p class="pair-help">Only scan this QR on a device you control. Do not share screenshots or pairing data.</p>
</main></body></html>
