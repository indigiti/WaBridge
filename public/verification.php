<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/message-log.php';
header('Cache-Control: private, no-store, max-age=0');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
if (!admin_authenticated()) {http_response_code(403);exit('Sign in to WaBridge first.');}
$storage=false;$gateway=['ready'=>false,'state'=>'unreachable','http'=>null];
$sessions=[];$messages=[];$error='';
try{
 $storage=storage_health();
 $gateway=gateway_diagnostics();
 if($gateway['ready'])$sessions=upstream_sessions();
 $messages=storage_admin_messages(100);
}catch(Throwable $e){
 $error='Some checks were unavailable; consult diagnostics and private server logs.';
 error_log('WaBridge acceptance verification: '.get_class($e));
}
$paired=0;$accepted=0;$delivered=0;$incomplete=0;
foreach($sessions as $session){
 if(!is_array($session))continue;
 $id=(string)($session['id']??$session['session_id']??'');
 if(!permitted_session($id))continue;
 if(in_array(strtolower((string)($session['status']??'')),['connected','online','ready'],true))$paired++;
}
foreach($messages as $job){
 if(!is_array($job)||!permitted_session((string)($job['session_id']??'')))continue;
 $state=message_log_effective_status($job);
 if(in_array($state,['accepted','sent','delivered','read'],true))$accepted++;
 if(in_array($state,['delivered','read'],true))$delivered++;
 if(in_array($state,['needs_review','failed'],true))$incomplete++;
}
$checks=[
 ['PHP control plane','PASS','A successful authenticated PHP page confirms this runtime is active.'],
 ['Private application storage',$storage?'PASS':'CHECK',$storage?'The configured PHP backend responded.':'Storage health check failed or was unavailable.'],
 ['Rust gateway',(bool)($gateway['ready']??false)?'PASS':'CHECK','Local liveness and storage readiness; not proof of WhatsApp pairing.'],
 ['WhatsApp account paired',$paired>0?'OBSERVED':'MANUAL',$paired.' connected session(s) reported by Waxum; verify the phone is still linked.'],
 ['Gateway accepted test message',$accepted>0?'OBSERVED':'MANUAL',$accepted.' accepted or later-state job(s); check message IDs and recipient consent.'],
 ['Provider-confirmed delivery',$delivered>0?'OBSERVED':'MANUAL',$delivered.' correlated delivered/read receipt(s). No receipt means delivery remains unknown.'],
 ['Worker processing & failure review',$incomplete===0?'CHECK':'REVIEW',$incomplete.' failed or uncertain job(s) in recent retained records; a continuously running PHP worker must be verified separately.'],
 ['Supervisor restart persistence','MANUAL','Only Cloudways restart and phone-link verification after restart can establish persistence.'],
];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Launch verification · WaBridge</title><?php ui_head_assets(); ?></head>
<body class="dashboard-page"><?php ui_shell_start('verification',(bool)($gateway['ready']??false)); ?>
<main class="workspace" id="main-content"><?php ui_breadcrumb('Workspace','Verification'); ?>
<div class="workspace-top"><div><div class="eyebrow">QUALITY / ACCEPTANCE</div><h1>Release verification</h1>
<p class="lead">Evidence-based checks before expanding beyond one consented WhatsApp test number.</p></div><a class="button button-ghost" href="/wabridge/verification">Run read-only checks again</a></div>
<?php if($error!==''): ?><p class="notice notice-warning" role="alert"><?= escape($error) ?></p><?php endif; ?>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">READ-ONLY OBSERVATIONS</div><h2>Current acceptance gates</h2></div></div>
<p class="panel-help">PASS indicates a verified local health check. OBSERVED indicates evidence found in the retained API state. MANUAL means a real phone or Cloudways action is required. CHECK does not imply success.</p>
<div class="table-scroll"><table><thead><tr><th>Gate</th><th>Evidence</th><th>Details / next action</th></tr></thead><tbody>
<?php foreach($checks as [$name,$status,$description]): ?>
<tr><td><?= escape($name) ?></td><td><span class="data-badge <?= in_array($status,['PASS','OBSERVED'],true)?'ok':'warn' ?>"><?= escape($status) ?></span></td><td><?= escape($description) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
</section>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">FINAL INTEGRATION</div><h2>Required real-device tests</h2></div></div>
<p class="panel-help">On a number you own, create a test session; scan its QR in WhatsApp Linked Devices; confirm Connected; send one consented text; inspect accepted provider message ID; wait for a matching delivered/read receipt when supported; then ask Cloudways to restart the existing Supervisor job and verify the number reconnects without a new pairing. Never use live campaigns or OTP as the initial test.</p>
<div class="section-links"><a href="/wabridge/devices">Connect test device</a><a href="/wabridge/messaging">Send a test message</a><a href="/wabridge/messages">Inspect message activity</a><a href="/wabridge/performance">Observe Rust resource usage</a><a href="/wabridge/diagnostics">Gateway health</a></div>
</section>
<p class="notice notice-warning">A read-only verification page cannot simulate the WhatsApp phone app or restart Cloudways Supervisor. No accepted message may be labeled delivered without a verified correlated receipt. This page performs no outgoing sends.</p>
</main><?php ui_shell_end(); ?></body></html>
