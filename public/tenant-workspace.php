<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
$src=dirname((new ReflectionFunction('store'))->getFileName());
require_once $src.'/vnext/member-auth.php';
require_once $src.'/ui-shell.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
if((config()['enable_vnext_tenant_login']??false)!==true) {
    http_response_code(404);exit('Not found');
}
$store=new VNextJsonStore();
$auth=new VNextMemberAuth($store);
try{$actor=$auth->current();}catch(Throwable $e){
    error_log('WaBridge tenant auth unavailable '.get_class($e));$actor=null;
}
if($actor===null) {
    header('Location: /wabridge/tenant-sign-in',true,302);
    exit;
}
if(!in_array(($_SERVER['REQUEST_METHOD']??'GET'),['GET','HEAD'],true)) {
    header('Allow: GET, HEAD');http_response_code(405);exit;
}
$policy=new VNextMemberPolicy($store);
$permitted=[];
foreach($actor['channels'] as $channel) {
    if($policy->channel($actor['tenant_id'],$actor['user_id'],$channel,'channel.read')) {
        $permitted[]=$channel;
    }
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>WaBridge · Tenant Workspace</title>
<?php ui_head_assets(); ?></head><body class="dashboard-page">
<div class="site-shell">
<header class="site-header console-global-header">
<a class="brand-lockup" href="/wabridge/tenant-workspace"><span class="brand-mark" aria-hidden="true"><span class="brand-mark-inner">W</span></span>
<span class="brand-copy"><strong>Wa<span>Bridge</span></strong><small>Tenant workspace</small></span></a>
<div class="header-right"><span class="console-user"><?= escape($actor['display_name']) ?></span>
<form method="post" action="/wabridge/tenant-sign-in">
<input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
<input type="hidden" name="action" value="logout">
<button class="button button-ghost button-small" type="submit">Sign out</button></form></div>
</header>
<main class="console-main" id="main-content">
<section class="console-hero workspace-section">
<div><span class="eyebrow">W2 · TENANT ACCESS PREVIEW</span>
<h1><?= escape($actor['organization']) ?></h1>
<p>Signed in as <?= escape($actor['display_name']) ?>. Your access is scoped to this organization and individually assigned channels.</p></div>
</section>
<div class="notice notice-warning" role="status">Read-only access · No real inbox messages · No live sending · No campaign publishing · No AI auto-replies</div>
<section class="stats-grid log-stats workspace-section">
<article class="stat-card"><p class="stat-label">Account role</p><div class="stat-value"><?= escape(ucfirst($actor['role'])) ?></div><p class="stat-foot">Rechecked on every request</p></article>
<article class="stat-card"><p class="stat-label">Authorized channels</p><div class="stat-value"><?= count($permitted) ?></div><p class="stat-foot">Explicit channel grants only</p></article>
<article class="stat-card"><p class="stat-label">Sending access</p><div class="stat-value">Off</div><p class="stat-foot">No connected message composer</p></article>
</section>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><span class="eyebrow">CHANNEL PERMISSIONS</span><h2>Assigned channels</h2></div></div>
<div class="table-scroll"><table><thead><tr><th>Channel reference</th><th>Read</th><th>Reply</th></tr></thead><tbody>
<?php foreach($permitted as $id):?>
<tr><td><code><?= escape($id) ?></code></td><td>Allowed</td>
<td><?= $policy->channel($actor['tenant_id'],$actor['user_id'],$id,'channel.reply')?'Role permits · disabled in preview':'Denied' ?></td></tr>
<?php endforeach;?>
<?php if(!$permitted):?><tr><td colspan="3" class="empty-cell">No channels are granted. Ask your organization administrator for access.</td></tr><?php endif;?>
</tbody></table></div>
<p class="panel-help">This access preview exposes only channel identifiers and membership metadata. Provider credentials, customer contacts and message bodies are not retrieved.</p>
</section>
</main></div></body></html>
