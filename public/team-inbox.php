<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
$privateSource=dirname((new ReflectionFunction('store'))->getFileName());
require_once $privateSource.'/ui-shell.php';
require_once $privateSource.'/vnext/inbox.php';
require_once $privateSource.'/vnext/demo.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
require_admin_html_page();
$pilotDemo=VNextDemo::accounts();
$allowedIds=array_column($pilotDemo,'id');
$tenantId=(string)($_GET['tenant']??$allowedIds[0]);
if(!in_array($tenantId,$allowedIds,true))$tenantId=$allowedIds[0];
$store=new VNextJsonStore();
$inbox=new VNextInbox($store);
$allowChanges=(config()['enable_vnext_inbox_preview']??false)===true;
$error='';$message='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        assert_csrf();
        if(!$allowChanges)throw new RuntimeException('Demo changes disabled');
        $action=(string)($_POST['action']??'');
        $threadId=(string)($_POST['thread_id']??'');
        $version=(string)($_POST['version']??'0');
        if(!ctype_digit($version)||strlen($version)>9)throw new InvalidArgumentException('Invalid revision');
        $expectedVersion=(int)$version;
        if($action==='seed') {
            $inbox->seed($tenantId);
        }elseif($action==='assign') {
            $inbox->assign($tenantId,$threadId,(string)($_POST['user_id']??''),$expectedVersion);
        }elseif($action==='state') {
            $inbox->changeState($tenantId,$threadId,(string)($_POST['state']??''),$expectedVersion);
        }elseif($action==='note') {
            $inbox->addPresetNote($tenantId,$threadId,(string)($_POST['note']??''),$expectedVersion);
        }else{
            throw new InvalidArgumentException('Unknown demo operation');
        }
        $query=['tenant'=>$tenantId,'success'=>'1'];
        if(VNextJsonStore::uuid($threadId))$query['thread']=$threadId;
        header('Location: /wabridge/team-inbox?'.http_build_query($query),true,303);
        exit;
    }catch(Throwable $e) {
        http_response_code($e instanceof InvalidArgumentException?422:409);
        $error=$e instanceof InvalidArgumentException
            ? 'Invalid selection; use the listed demo actions.'
            : 'Demo update unavailable or stale. Refresh before retrying.';
        error_log('WaBridge W3 demo inbox '.get_class($e));
    }
}
try {
    $overview=$inbox->overview($tenantId);
}catch(Throwable $e) {
    // Never fall through to real customer stores or another tenant.
    $overview=['tenant'=>['id'=>$tenantId,'name'=>'Demo tenant'],
        'threads'=>[],'team'=>[],'seeded'=>false];
    $error='Synthetic pilot account not seeded. Seed from Multi-account preview first.';
}
$activeId=(string)($_GET['thread']??'');
$selected=null;
if(VNextJsonStore::uuid($activeId)) {
    try{$selected=$inbox->detail($tenantId,$activeId);}catch(Throwable $e){$selected=null;}
}
if($selected===null && $overview['threads']) {
    $selected=$inbox->detail($tenantId,$overview['threads'][0]['id']);
}
$subject=(string)($overview['tenant']['name']??'Fictional Demo');
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>WaBridge — Team Inbox (Simulation)</title>
<?php ui_head_assets(); ?></head><body class="dashboard-page">
<?php ui_shell_start('team-inbox',null); ?>
<main class="console-main">
<?php ui_breadcrumb('Messaging','Team Inbox'); ?>
<section class="console-hero workspace-section">
  <div><span class="eyebrow">W3 · OFFLINE DEMONSTRATION</span>
  <h1>Shared Team Inbox</h1>
  <p>Explore assignment, conversation states, notes and optimistic-concurrency safeguards using fictional messages only.</p></div>
</section>
<?php if($error!==''):?><div class="notice notice-warning" role="alert"><?= escape($error) ?></div><?php endif; ?>
<?php if(($_GET['success']??'')==='1'):?><div class="notice notice-success" role="status">Synthetic inbox updated. No WhatsApp message was sent.</div><?php endif; ?>
<div class="notice notice-warning" role="status">Simulation only · No real contacts · No live incoming messages · No send capability · <?= $allowChanges?'Demo state changes enabled':'Read-only preview' ?></div>
<section class="glass-panel workspace-section">
 <div class="panel-heading"><div><span class="eyebrow">ISOLATED DEMO ACCOUNT</span><h2><?= escape($subject) ?></h2></div>
 <a class="button button-ghost button-small" href="/wabridge/account-admin">Multi-account admin</a></div>
 <nav class="section-links" aria-label="Select synthetic organization">
  <?php foreach($pilotDemo as $org): ?>
  <a <?= $tenantId===$org['id']?'aria-current="page"':'' ?> href="/wabridge/team-inbox?tenant=<?= escape(rawurlencode($org['id'])) ?>"><?= escape($org['name']) ?></a>
  <?php endforeach; ?>
 </nav>
 <?php if(!$overview['seeded']): ?>
 <p class="panel-help">No conversation records exist in this synthetic account. The demo directory in Multi-account admin is a static preview; persisted test conversations are seeded explicitly.</p>
 <?php if($allowChanges):?>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
 <input type="hidden" name="action" value="seed"><button class="button button-primary" type="submit">Create three fictional conversations</button>
 </form>
 <?php endif; endif; ?>
</section>
<section class="stats-grid log-stats workspace-section">
 <article class="stat-card"><p class="stat-label">Fictional conversations</p><div class="stat-value"><?= count($overview['threads']) ?></div><p class="stat-foot">Tenant-scoped JSON</p></article>
 <article class="stat-card"><p class="stat-label">Demo team members</p><div class="stat-value"><?= count($overview['team']) ?></div><p class="stat-foot">Pending accounts, no login</p></article>
 <article class="stat-card"><p class="stat-label">Provider sends</p><div class="stat-value">0</div><p class="stat-foot">Disabled by code</p></article>
</section>
<div class="report-grid workspace-section">
<section class="glass-panel">
 <div class="panel-heading"><div><span class="eyebrow">TEAM QUEUE</span><h2>Conversations</h2></div></div>
 <div class="table-scroll"><table><thead><tr><th>Customer</th><th>Subject</th><th>Status</th></tr></thead><tbody>
 <?php foreach($overview['threads'] as $thread): ?>
 <tr><td><a href="/wabridge/team-inbox?tenant=<?= escape($tenantId) ?>&amp;thread=<?= escape($thread['id']) ?>"><?= escape($thread['contact_label']) ?></a></td>
 <td><?= escape($thread['subject']) ?></td><td><?= escape($thread['state']) ?></td></tr>
 <?php endforeach; ?>
 <?php if(!$overview['threads']):?><tr><td colspan="3" class="empty-cell">No synthetic conversation records.</td></tr><?php endif; ?>
 </tbody></table></div>
</section>
<section class="glass-panel">
 <div class="panel-heading"><div><span class="eyebrow">CONVERSATION</span><h2><?= escape((string)($selected['subject']??'Choose a conversation')) ?></h2></div></div>
 <?php if($selected!==null): ?>
 <p class="panel-help"><?= escape($selected['contact_label']) ?> · <?= escape($selected['state']) ?> · Revision <?= (int)$selected['version'] ?></p>
 <?php foreach($selected['messages'] as $msg): ?>
 <article class="workspace-section">
 <p class="eyebrow"><?= $msg['direction']==='inbound_fixture'?'FICTIONAL INBOUND':'FICTIONAL OUTBOUND' ?> · Not delivered</p>
 <p><?= escape($msg['text']) ?></p></article>
 <?php endforeach; ?>
 <div class="panel-heading"><div><span class="eyebrow">PRIVATE DEMO NOTES</span><h2>Collaboration</h2></div></div>
 <?php foreach($selected['notes'] as $note): ?>
 <p class="panel-help"><?= escape($note['text']) ?> · <?= escape($note['by']) ?></p>
 <?php endforeach; ?>
 <?php if($allowChanges): ?>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
 <input type="hidden" name="action" value="state">
 <input type="hidden" name="thread_id" value="<?= escape($selected['id']) ?>">
 <input type="hidden" name="version" value="<?= (int)$selected['version'] ?>">
 <div class="field"><label>Conversation status<select name="state">
 <?php foreach(['new','open','pending','resolved'] as $s):?><option <?= $selected['state']===$s?'selected':'' ?> value="<?= escape($s) ?>"><?= escape(ucfirst($s)) ?></option><?php endforeach; ?>
 </select></label></div><button class="button button-ghost" type="submit">Update demo status</button>
 </form>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
 <input type="hidden" name="action" value="assign">
 <input type="hidden" name="thread_id" value="<?= escape($selected['id']) ?>">
 <input type="hidden" name="version" value="<?= (int)$selected['version'] ?>">
 <div class="field"><label>Assign simulated agent<select name="user_id" required>
 <?php foreach($overview['team'] as $member):?>
 <option value="<?= escape($member['user_id']) ?>" <?= $selected['assignee_id']===$member['user_id']?'selected':'' ?>><?= escape($member['name']) ?> · <?= escape($member['role']) ?></option>
 <?php endforeach; ?></select></label></div>
 <button class="button button-ghost" type="submit">Assign demo agent</button>
 </form>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
 <input type="hidden" name="action" value="note">
 <input type="hidden" name="thread_id" value="<?= escape($selected['id']) ?>">
 <input type="hidden" name="version" value="<?= (int)$selected['version'] ?>">
 <div class="field"><label>Approved synthetic note<select name="note">
 <?php foreach(VNextInbox::noteOptions() as $note):?><option value="<?= escape($note) ?>"><?= escape($note) ?></option><?php endforeach; ?>
 </select></label></div><button class="button button-ghost" type="submit">Add demo note</button>
 </form>
 <?php else:?><p class="panel-help">Editing disabled. Enable the private preview flag to test collaboration on seeded fictional conversations.</p><?php endif; ?>
 <?php else: ?><p class="panel-help">Choose a seeded fictional conversation to view message history and notes.</p><?php endif; ?>
</section>
</div>
<p class="notice notice-warning">No message composer, automation trigger, real provider webhook or contact history is connected to this W3 simulation. Production tenant membership and channel authorization are separate release gates.</p>
</main><?php ui_shell_end(); ?></body></html>
