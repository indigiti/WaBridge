<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/platform/modules.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/worker-health.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ops/connection-watchdog.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex,nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
if(!admin_authenticated()){http_response_code(403);exit('Sign in to WaBridge first.');}
$pages=[
    'contacts'=>['Contacts','Store documented consent; withdrawal blocks scheduled sends'],
    'templates'=>['Message templates','Reusable text with approved variables and local previews'],
    'schedules'=>['Scheduled messages','Enqueue consented future notifications via the existing PHP worker'],
    'media'=>['Media messages','One-shot media requests; gateway acceptance is not delivery'],
    'automation'=>['Automation studio','Deterministic keyword workflows in review-only mode'],
    'developer-tools'=>['Developer operations','API examples, webhook queue visibility and safe dead-letter replay'],
    'security-center'=>['Security & readiness','Operational checks without exposing private configuration'],
    'workspaces'=>['Workspaces','Prepare isolated workspace definitions; SaaS provisioning is not enabled'],
    'cloud-api'=>['Official WhatsApp Cloud API','Connect approved Meta credentials through the private Rust gateway'],
];
$module=(string)($_GET['module']??'contacts');
if(!isset($pages[$module])){http_response_code(404);exit('Module unavailable');}
$page=max(1,min(1000,(int)($_GET['page']??1)));
$notice='';$error='';$preview=[];$mediaOutcome=null;
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        assert_csrf();
        if(!limit_client(store(),['id'=>'platform-admin','limit'=>30]))
            throw new InvalidArgumentException('Too many administrative actions; try again shortly');
        $action=(string)($_POST['action']??'');
        if($action==='contact_save'&&$module==='contacts'){
            $consented=($_POST['consented']??'')==='yes';
            $row=platform_contact_save((string)($_POST['name']??''),
                (string)($_POST['phone']??''),(string)($_POST['evidence']??''),$consented);
            audit_event('contact_consent_updated','admin',$row['id'],['consented'=>$consented]);
            $notice='Contact saved. Consent: '.($consented?'documented':'not granted').'.';
        }elseif($action==='contact_revoke'&&$module==='contacts'){
            $id=(string)($_POST['contact_id']??'');
            if(!platform_contact_revoke($id))throw new InvalidArgumentException('Contact not found');
            audit_event('contact_opted_out','admin',$id);
            $notice='Consent withdrawn. Scheduled jobs will be blocked at dispatch.';
        }elseif($action==='template_create'&&$module==='templates'){
            $row=platform_template_create((string)($_POST['name']??''),(string)($_POST['body']??''));
            audit_event('message_template_created','admin',$row['id']);
            $notice='Reusable template saved.';
        }elseif($action==='schedule_create'&&$module==='schedules'){
            $nonce=(string)($_POST['nonce']??'');
            if(!hash_equals((string)($_SESSION['platform_schedule_nonce']??''),$nonce)||strlen($nonce)!==48)
                throw new InvalidArgumentException('Schedule already submitted or expired; refresh before trying again');
            unset($_SESSION['platform_schedule_nonce']);
            $date=(string)($_POST['send_at']??'');
            if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/D',$date))
                throw new InvalidArgumentException('Choose an exact UTC date/time');
            $when=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date,new DateTimeZone('UTC'));
            if($when===false||$when->format('Y-m-d\TH:i')!==$date)
                throw new InvalidArgumentException('Invalid UTC date/time');
            $row=platform_schedule((string)($_POST['contact_id']??''),
                (string)($_POST['template_id']??''),(string)($_POST['session_id']??''),
                $when->getTimestamp());
            $notice='Scheduled job '.$row['job_id'].' queued for '.gmdate('Y-m-d H:i',$row['send_at']).' UTC. No WhatsApp delivery is implied.';
        }elseif($action==='rule_create'&&$module==='automation'){
            $row=platform_rule_create((string)($_POST['session_id']??''),
                (string)($_POST['keyword']??''),(string)($_POST['suggestion']??''));
            audit_event('automation_draft_created','admin',$row['id']);
            $notice='Review-only workflow saved. Automatic replies remain disabled.';
        }elseif($action==='rule_preview'&&$module==='automation'){
            $preview=platform_rule_preview((string)($_POST['session_id']??''),
                (string)($_POST['sample_text']??''));
            $notice='Rule preview: '.count($preview).' matching suggestion(s). Nothing was sent.';
        }elseif($action==='workspace_create'&&$module==='workspaces'){
            $row=platform_workspace_create((string)($_POST['name']??''));
            audit_event('workspace_configuration_created','admin',$row['id']);
            $notice='Workspace configuration created. Tenant isolation and billing are not active.';
        }elseif($action==='webhook_replay'&&$module==='developer-tools'){
            $event=(string)($_POST['event_id']??'');
            $subscriber=(string)($_POST['subscriber_id']??'');
            if(!preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D',$event)||
               !preg_match('/\A[a-zA-Z0-9_-]{3,64}\z/D',$subscriber))
                throw new InvalidArgumentException('Invalid event or subscriber reference');
            if(!storage_event_replay($event,$subscriber))
                throw new InvalidArgumentException('Replay refused; only a known dead-letter delivery can be replayed');
            audit_event('webhook_deadletter_requeued','admin',$event);
            $notice='Dead-letter webhook delivery requeued. This is not a WhatsApp message resend.';
        }elseif($action==='media_send'&&$module==='media'){
            $nonce=(string)($_POST['nonce']??'');
            if(!hash_equals((string)($_SESSION['platform_media_nonce']??''),$nonce)||strlen($nonce)!==48)
                throw new InvalidArgumentException('Media form already submitted or expired');
            unset($_SESSION['platform_media_nonce']);
            $file=$_FILES['media_file']??null;
            if(!is_array($file)||($file['error']??-1)!==UPLOAD_ERR_OK)
                throw new InvalidArgumentException('Select a valid media file');
            $size=(int)($file['size']??0);
            if($size<1||$size>1048576||!is_uploaded_file((string)($file['tmp_name']??'')))
                throw new InvalidArgumentException('Upload must be private and no larger than 1 MiB');
            $data=file_get_contents((string)$file['tmp_name']);
            if(!is_string($data)||strlen($data)!==$size)
                throw new InvalidArgumentException('Cannot read private upload');
            $type=(new finfo(FILEINFO_MIME_TYPE))->buffer($data);
            $mediaOutcome=platform_media_send((string)($_POST['session_id']??''),
                (string)($_POST['contact_id']??''),(string)($_POST['media_type']??''),
                $data,(string)$type,(string)($_POST['caption']??''),
                basename((string)($file['name']??'')));
            $notice='Media request '.$mediaOutcome['id'].' status: '.$mediaOutcome['status'].
                '. Delivery is not confirmed. Do not automatically retry uncertain outcomes.';
        }elseif($action==='cloud_connect'&&$module==='cloud-api'){
            $nonce=(string)($_POST['nonce']??'');
            if(!hash_equals((string)($_SESSION['platform_cloud_nonce']??''),$nonce)||strlen($nonce)!==48)
                throw new InvalidArgumentException('Cloud form already submitted or expired');
            unset($_SESSION['platform_cloud_nonce']);
            $r=platform_cloud_connect((string)($_POST['session_id']??''),$_POST);
            $notice=$r['accepted']?'Cloud connection accepted by the private Rust gateway. Verify Meta account status.':
                'Cloud connection was not accepted (HTTP '.$r['http'].'). No secret was saved by PHP.';
        }elseif($action==='cloud_template'&&$module==='cloud-api'){
            $nonce=(string)($_POST['nonce']??'');
            if(!hash_equals((string)($_SESSION['platform_cloud_nonce']??''),$nonce)||strlen($nonce)!==48)
                throw new InvalidArgumentException('Cloud form already submitted or expired');
            unset($_SESSION['platform_cloud_nonce']);
            $r=platform_approved_template_send((string)($_POST['session_id']??''),
                (string)($_POST['contact_id']??''),(string)($_POST['template_name']??''),
                (string)($_POST['language_code']??'en_US'));
            $notice=$r['accepted']?'Approved template accepted by gateway; delivery is unconfirmed.':
                'Approved template result uncertain or rejected; do not automatically retry.';
        }else{
            throw new InvalidArgumentException('Unsupported operation');
        }
    }
}catch(InvalidArgumentException $e){$error=$e->getMessage();}
catch(Throwable $e){
    error_log('WaBridge platform module '.get_class($e));
    $error='Operation unavailable. Review private diagnostics.';
}
$collection=match($module){
    'contacts'=>'contacts','templates'=>'templates','schedules'=>'outbox',
    'media'=>'media_attempts','automation'=>'rules','workspaces'=>'workspaces',
    default=>null,
};
$listing=['rows'=>[],'page'=>$page,'has_next'=>false];
$contacts=[];$templates=[];
try{
    if($collection!==null)$listing=platform_page($collection,$page,10);
    if(in_array($module,['schedules','media','cloud-api'],true)){
        $contacts=platform_page('contacts',1,25)['rows'];
        $contacts=array_values(array_filter($contacts,static fn(array $r):bool=>($r['consented']??false)===true));
    }
    if($module==='schedules')$templates=platform_page('templates',1,25)['rows'];
}catch(Throwable $e){$error='Cannot load module records. Review private storage.';}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= escape($pages[$module][0]) ?> · WaBridge</title>
<?php ui_head_assets(); ?></head><body class="dashboard-page">
<?php ui_shell_start($module); ?>
<main class="workspace" id="main-content">
<?php ui_breadcrumb('WaBridge platform',$pages[$module][0]); ?>
<div class="workspace-top"><div><div class="eyebrow">PRODUCT PLATFORM / <?= escape(strtoupper($module)) ?></div><h1><?= escape($pages[$module][0]) ?></h1><p class="lead"><?= escape($pages[$module][1]) ?></p></div>
<a class="button button-ghost" href="/wabridge/<?= escape($module) ?>">Refresh</a></div>
<nav class="platform-hub-subnav" aria-label="Product modules">
<?php foreach($pages as $slug=>$entry):?><a href="/wabridge/<?= escape($slug) ?>" <?= $slug===$module?'aria-current="page"':'' ?>><?= escape($entry[0]) ?></a><?php endforeach;?>
</nav>
<?php if($notice!==''):?><p class="notice notice-success" role="status"><?= escape($notice) ?></p><?php endif;?>
<?php if($error!==''):?><p class="notice notice-warning" role="alert"><?= escape($error) ?></p><?php endif;?>
<div class="platform-panels">
<?php if($module==='contacts'):?>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">P1 / CONSENT</div><h2>Add or update contact</h2></div></div>
<form method="post" class="platform-form-grid">
<input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="contact_save">
<div class="field"><label>Contact name<input name="name" maxlength="120" required></label></div>
<div class="field"><label>International number<input name="phone" pattern="[1-9][0-9]{7,14}" maxlength="15" placeholder="919876543210" required></label></div>
<div class="field full"><label>Consent evidence reference<input name="evidence" maxlength="120" placeholder="Opt-in form record / dated request" required></label></div>
<div class="field full"><label><input type="checkbox" name="consented" value="yes"> I have documented this person's permission to receive messages</label></div>
<div class="platform-button-row"><button class="button button-primary" type="submit">Save contact</button></div>
</form></section>
<?php elseif($module==='templates'):?>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">P1 / TEMPLATE LIBRARY</div><h2>Create reusable text template</h2></div></div>
<form method="post" class="platform-form-grid">
<input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="template_create">
<div class="field"><label>Template name<input name="name" maxlength="90" required></label></div>
<div class="field full"><label>Message body<textarea name="body" rows="5" maxlength="2000" placeholder="Hello {{name}}, your appointment is on {{date}}." required></textarea></label></div>
<div class="field full platform-help">Only <code>{{name}}</code> and <code>{{date}}</code> are replaced. This is a local reusable text library, not Meta-approved template creation.</div>
<div class="platform-button-row"><button class="button button-primary" type="submit">Create template</button></div>
</form></section>
<?php elseif($module==='schedules'):?>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">P1 / SCHEDULED QUEUE</div><h2>Schedule one consented text</h2></div></div>
<form method="post" class="platform-form-grid">
<input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="schedule_create">
<input type="hidden" name="nonce" value="<?= escape($_SESSION['platform_schedule_nonce']??=bin2hex(random_bytes(24))) ?>">
<div class="field"><label>Opted-in contact<select name="contact_id" required><option value="">Select contact</option><?php foreach($contacts as $row):?><option value="<?= escape($row['id']) ?>"><?= escape($row['name']) ?> · <?= escape(substr($row['phone'],-4)) ?></option><?php endforeach;?></select></label></div>
<div class="field"><label>Text template<select name="template_id" required><option value="">Select template</option><?php foreach($templates as $row):?><option value="<?= escape($row['id']) ?>"><?= escape($row['name']) ?></option><?php endforeach;?></select></label></div>
<div class="field"><label>Connected session ID<input name="session_id" maxlength="64" required></label></div>
<div class="field"><label>Send time — UTC<input type="datetime-local" name="send_at" required></label></div>
<div class="field full platform-help">1 minute to 30 days in advance. Requires the separate PHP CLI worker. Consent is checked again at dispatch.</div>
<div class="platform-button-row"><button class="button button-primary" type="submit" <?= !$contacts||!$templates?'disabled':'' ?>>Create scheduled job</button></div>
</form></section>
<?php elseif($module==='media'):?>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">P1 / MEDIA</div><h2>Send one media message</h2></div></div>
<form method="post" enctype="multipart/form-data" class="platform-form-grid">
<input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="media_send">
<input type="hidden" name="nonce" value="<?= escape($_SESSION['platform_media_nonce']??=bin2hex(random_bytes(24))) ?>">
<div class="field"><label>Opted-in contact<select name="contact_id" required><option value="">Select contact</option><?php foreach($contacts as $row):?><option value="<?= escape($row['id']) ?>"><?= escape($row['name']) ?> · <?= escape(substr($row['phone'],-4)) ?></option><?php endforeach;?></select></label></div>
<div class="field"><label>Connected session ID<input name="session_id" maxlength="64" required></label></div>
<div class="field"><label>Media type<select name="media_type"><option value="image">Image</option><option value="document">Document</option><option value="audio">Audio</option><option value="video">Video</option></select></label></div>
<div class="field"><label>Upload file (max 1 MiB)<input type="file" name="media_file" required></label></div>
<div class="field full"><label>Caption (not available for audio)<input name="caption" maxlength="300"></label></div>
<div class="field full platform-help">MIME types checked server-side. No remote URL fetching. Submit once; unknown outcomes are not retried automatically.</div>
<div class="platform-button-row"><button class="button button-primary" type="submit" <?= !$contacts?'disabled':'' ?>>Send consented media</button></div>
</form></section>
<?php elseif($module==='automation'):?>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">P3 / RULE BUILDER</div><h2>Keyword workflow — review only</h2></div></div>
<form method="post" class="platform-form-grid"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="rule_create">
<div class="field"><label>Session ID<input name="session_id" maxlength="64" required></label></div>
<div class="field"><label>Incoming keyword<input name="keyword" maxlength="80" required></label></div>
<div class="field full"><label>Suggested reply<textarea name="suggestion" maxlength="800" rows="3" required></textarea></label></div>
<div class="platform-button-row"><button class="button button-primary">Save review-only rule</button></div>
</form><hr>
<form method="post" class="platform-form-grid"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="rule_preview">
<div class="field"><label>Session ID<input name="session_id" maxlength="64" required></label></div>
<div class="field full"><label>Sample incoming message<textarea name="sample_text" rows="2" maxlength="2000" required></textarea></label></div>
<div class="platform-button-row"><button class="button button-ghost">Preview matching suggestions</button></div></form>
<?php foreach($preview as $match):?><p class="notice notice-success"><?= escape($match['keyword']) ?>: <?= escape($match['suggestion']) ?></p><?php endforeach;?>
<p class="platform-help">Automatic replies and external AI inference remain disabled until consent and inbound-event safety are certified.</p>
</section>
<?php elseif($module==='developer-tools'):?>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">P2 / DEVELOPER</div><h2>API playground</h2></div></div>
<p class="platform-help">Use the scoped token from API Applications. Never place a real token in a URL, screenshot, report or test message.</p>
<pre class="table-scroll"><code><?= escape("curl -X POST https://stage.digiti.in/wabridge/api/v1/messages.php \\\n -H 'Authorization: Bearer YOUR_SCOPED_TOKEN' \\\n -H 'Idempotency-Key: YOUR_UNIQUE_OPERATION_KEY' \\\n -H 'Content-Type: application/json' \\\n -d '{\"session_id\":\"YOUR_SESSION\",\"to\":\"919876543210\",\"text\":\"Hello\"}'") ?></code></pre>
<p><a href="/wabridge/api/v1/capabilities.php">Versioned capabilities</a> · <a href="/wabridge/api-applications">API applications</a></p>
</section>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">WEBHOOKS / DEAD-LETTER</div><h2>Replay a failed outbound event</h2></div></div>
<form method="post" class="platform-form-grid"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="webhook_replay">
<div class="field"><label>Event reference<input name="event_id" maxlength="128" required></label></div>
<div class="field"><label>Configured subscriber ID<input name="subscriber_id" maxlength="64" required></label></div>
<div class="platform-button-row"><button class="button button-primary">Replay known dead-letter event</button></div></form>
<p class="platform-help">Only an already recorded dead event can be requeued. WhatsApp message sends are never replayed by this action.</p></section>
<?php elseif($module==='security-center'):?>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">P4 / SECURITY</div><h2>Readiness overview</h2></div></div>
<?php $worker=worker_health_read();$watchdog=watchdog_read(); ?>
<div class="stats-grid">
<article class="stat-card"><p class="stat-label">Worker heartbeat</p><div class="stat-value"><?= escape($worker['state']) ?></div></article>
<article class="stat-card"><p class="stat-label">Connection watchdog</p><div class="stat-value"><?= escape($watchdog['state']) ?></div></article>
<article class="stat-card"><p class="stat-label">PHP state</p><div class="stat-value"><?= escape(storage_mode()) ?></div></article>
<article class="stat-card"><p class="stat-label">Admin session</p><div class="stat-value">Protected</div></article>
</div>
<p class="notice notice-warning">Additional role-based access is not enabled. Only the existing administrator account is authorized. Production SaaS requires complete per-route authorization, encrypted backup and a restore drill.</p>
<p><a href="/wabridge/audit">Audit history</a> · <a href="/wabridge/verification">Acceptance checks</a> · <a href="/wabridge/diagnostics">Diagnostics</a></p>
</section>
<?php elseif($module==='workspaces'):?>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">P5 / WORKSPACE CATALOG</div><h2>Create workspace configuration</h2></div><span class="platform-flag">Non-billable pilot</span></div>
<form method="post" class="platform-form-grid"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="workspace_create">
<div class="field"><label>Workspace name<input name="name" maxlength="90" required></label></div>
<div class="platform-button-row"><button class="button button-primary">Create configuration</button></div></form>
<p class="notice notice-warning">This does not create an isolated messaging tenant. Shared queue, API scope, billing and role isolation must be implemented and tested on a production transactional database before commercial activation.</p>
</section>
<?php elseif($module==='cloud-api'):?>
<section class="glass-panel workspace-section"><div class="panel-heading"><div><div class="eyebrow">P5 / META INTEGRATION</div><h2>Official Cloud session onboarding</h2></div><span class="platform-flag"><?= !empty(config()['enable_official_cloud_connect'])?'Privately enabled':'Disabled by default' ?></span></div>
<p class="platform-help">A secure operator submits credentials directly to the private Rust engine. WaBridge PHP does not write the tokens into its metadata store. Enable <code>enable_official_cloud_connect</code> only after reviewing Meta setup and runtime protection.</p>
<form method="post" class="platform-form-grid" autocomplete="off"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="cloud_connect">
<input type="hidden" name="nonce" value="<?= escape($_SESSION['platform_cloud_nonce']??=bin2hex(random_bytes(24))) ?>">
<div class="field"><label>Existing session ID<input name="session_id" maxlength="64" required></label></div>
<div class="field"><label>WABA ID<input name="waba_id" pattern="[0-9]{8,25}" required></label></div>
<div class="field"><label>Phone number ID<input name="phone_number_id" pattern="[0-9]{8,25}" required></label></div>
<div class="field"><label>Access token<input type="password" name="access_token" required></label></div>
<div class="field"><label>App secret<input type="password" name="app_secret" required></label></div>
<div class="field"><label>Webhook verify token<input type="password" name="webhook_verify_token" required></label></div>
<div class="platform-button-row"><button class="button button-primary" <?= empty(config()['enable_official_cloud_connect'])?'disabled':'' ?>>Connect approved account</button></div>
</form><hr>
<h2>Send an approved Meta template</h2>
<form method="post" class="platform-form-grid"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="cloud_template">
<input type="hidden" name="nonce" value="<?= escape($_SESSION['platform_cloud_nonce']??=bin2hex(random_bytes(24))) ?>">
<div class="field"><label>Official session ID<input name="session_id" maxlength="64" required></label></div>
<div class="field"><label>Opted-in recipient<select name="contact_id" required><option value="">Choose</option><?php foreach($contacts as $row):?><option value="<?= escape($row['id']) ?>"><?= escape($row['name']) ?> · <?= escape(substr($row['phone'],-4)) ?></option><?php endforeach;?></select></label></div>
<div class="field"><label>Approved template name<input name="template_name" pattern="[a-z0-9_]{1,90}" required></label></div>
<div class="field"><label>Language code<input name="language_code" value="en_US" maxlength="5" required></label></div>
<div class="platform-button-row"><button class="button button-primary" <?= empty(config()['enable_official_cloud_connect'])||!$contacts?'disabled':'' ?>>Send approved template</button></div></form>
<p class="platform-help">Approved, parameter-free Meta templates only. Full Embedded Signup and webhook certification require live Meta setup.</p>
</section>
<?php endif;?>
<?php if($collection!==null):?>
<section class="glass-panel workspace-section"><div class="panel-heading platform-list-header"><div><div class="eyebrow">BOUNDed RECORDS</div><h2><?= escape($pages[$module][0]) ?> history</h2></div><span class="subtle-tag">Page <?= $page ?> · 10 records</span></div>
<div class="table-scroll"><table class="platform-table"><thead><tr><th>Reference</th><th>Details</th><th>Created (UTC)</th><th>State / action</th></tr></thead><tbody>
<?php foreach($listing['rows'] as $row):?>
<tr><td><code><?= escape((string)$row['id']) ?></code></td>
<td><?php if($module==='contacts'):?><?= escape((string)$row['name']) ?> · ••••<?= escape(substr((string)$row['phone'],-4)) ?>
<?php elseif($module==='templates'):?><?= escape((string)$row['name']) ?>
<?php elseif($module==='schedules'):?>Job <a href="/wabridge/trace?id=<?= rawurlencode((string)$row['job_id']) ?>"><?= escape((string)$row['job_id']) ?></a> · <?= escape(gmdate('Y-m-d H:i',(int)$row['send_at'])) ?> UTC
<?php elseif($module==='media'):?><?= escape((string)$row['type']) ?> · Session <?= escape((string)$row['session_id']) ?>
<?php elseif($module==='automation'):?>Keyword <?= escape((string)$row['keyword']) ?> · <?= escape((string)$row['suggestion']) ?>
<?php else:?><?= escape((string)$row['name']) ?><?php endif;?></td>
<td><?= escape(gmdate('Y-m-d H:i',(int)$row['created_at'])) ?></td>
<td><?php if($module==='contacts'):?>
<?= !empty($row['consented'])?'Consented':'Opted out' ?>
<?php if(!empty($row['consented'])):?><form method="post"><input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="contact_revoke"><input type="hidden" name="contact_id" value="<?= escape($row['id']) ?>"><button type="submit" class="button button-ghost button-small">Withdraw consent</button></form><?php endif;?>
<?php elseif($module==='media'):?><?= escape((string)$row['state']) ?> · Delivery unknown
<?php elseif($module==='automation'):?>Manual review only
<?php elseif($module==='workspaces'):?>Configuration only
<?php else:?>Stored<?php endif;?></td></tr>
<?php endforeach;?>
<?php if(!$listing['rows']):?><tr><td colspan="4" class="empty-cell">No records available.</td></tr><?php endif;?></tbody></table></div>
<div class="platform-button-row">
<?php if($page>1):?><a class="button button-ghost" href="/wabridge/<?= escape($module) ?>?page=<?= $page-1 ?>">Previous</a><?php endif;?>
<?php if($listing['has_next']):?><a class="button button-primary" href="/wabridge/<?= escape($module) ?>?page=<?= $page+1 ?>">Next</a><?php endif;?>
</div></section>
<?php endif;?>
</div></main><?php ui_shell_end(); ?></body></html>
