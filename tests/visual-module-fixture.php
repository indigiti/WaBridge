<?php
declare(strict_types=1);
/**
 * CI-only visual module: authentic shared shell + module CSS contract.
 * Never packaged; no private customer record, gateway call or credential.
 */
function admin_authenticated():bool{return true;}
function csrf():string{return 'visual-fixture-token';}
function escape(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
require dirname((string)$_SERVER['DOCUMENT_ROOT']).'/src/ui-shell.php';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WaBridge media · Module visual QA</title>
<?php ui_head_assets(); ?>
</head><body class="dashboard-page">
<div class="ambient ambient-one" aria-hidden="true"></div>
<div class="ambient ambient-two" aria-hidden="true"></div>
<div class="ambient ambient-three" aria-hidden="true"></div>
<?php ui_shell_start('media',true); ?>
<main class="workspace" id="main-content">
<?php ui_breadcrumb('Messaging','Media messages'); ?>
<div class="workspace-top"><div><div class="eyebrow">PRODUCT PLATFORM / MEDIA</div>
<h1>Media messages</h1><p class="lead">One-shot media requests; gateway acceptance is not delivery.</p></div>
<a class="button button-ghost" href="/wabridge/messages">Message activity</a></div>
<div class="platform-context" aria-label="Related actions">
<span class="platform-context-label">Messaging tools</span>
<div class="platform-context-links">
<a class="platform-context-link" href="/wabridge/contacts">Manage contacts</a>
<a class="platform-context-link" href="/wabridge/messages">Delivery activity</a>
</div></div>
<div class="platform-panels">
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">P1 / MEDIA</div><h2>Send one media message</h2></div></div>
<form class="platform-form-grid">
<div class="field"><label>Opted-in contact
<select name="contact_id" required><option value="">Select contact</option>
<option>Example contact · 7890</option></select></label></div>
<div class="field"><label>Connected session
<select name="session_id"><option>support-desk</option></select></label></div>
<div class="field"><label>Media type
<select name="media_type"><option>Image</option><option>Document</option></select></label></div>
<div class="field"><label>Choose a file<input type="file" name="media_file"></label></div>
<div class="field full"><label>Caption<input name="caption" placeholder="Optional caption"></label></div>
<div class="field full platform-help">Media uploads are validated server-side. No unknown message outcomes are automatically retried.</div>
<div class="platform-button-row"><button class="button button-primary" type="button">Send media</button></div>
</form></section>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">RECENT ACTIVITY</div><h2>Media history</h2></div><span class="subtle-tag">10 per page</span></div>
<div class="table-scroll"><table><thead><tr><th>Reference</th><th>Type</th><th>Status</th></tr></thead><tbody>
<tr><td><code>07bca93c-0347-488e-9974-d9dab153c61b</code></td><td>Image</td><td>Gateway accepted</td></tr>
</tbody></table></div></section></div>
</main><?php ui_shell_end(); ?>
<script>
window.addEventListener('load',()=>{
 const body=document.body;
 const context=document.querySelector('.platform-context');
 const links=[...document.querySelectorAll('.platform-context-link')];
 const form=document.querySelector('.platform-form-grid');
 const field=document.querySelector('.platform-form-grid .field');
 const input=field.querySelector('select');
 const nav=document.querySelector('.console-nav-link[aria-current="page"]');
 const wide=document.documentElement.scrollWidth>document.documentElement.clientWidth+5;
 const gap=links.length>=2?links[1].getBoundingClientRect().left-links[0].getBoundingClientRect().right:0;
 body.dataset.moduleReady='true';
 body.dataset.moduleNav=String(Boolean(nav)&&!document.querySelector('.platform-hub-subnav'));
 body.dataset.moduleContext=String(getComputedStyle(context).display==='flex'&&gap>=7);
 body.dataset.moduleGrid=String(getComputedStyle(form).display==='grid');
 body.dataset.moduleFormFit=String(input.getBoundingClientRect().right<=field.getBoundingClientRect().right+3);
 body.dataset.moduleOverflow=String(wide);
 body.dataset.moduleFont=getComputedStyle(form).fontFamily.includes('WaBridge Inter')?'inter':'fallback';
});
</script></body></html>
