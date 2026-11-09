<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/ui-shell.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/vnext/identity.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/vnext/demo.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
require_admin_html_page();
$identity=new VNextIdentity(new VNextJsonStore());
$canProvision=(config()['enable_vnext_pilot_admin']??false)===true;
$error='';$notice='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        assert_csrf();
        if(!$canProvision)throw new RuntimeException('Pilot provisioning is disabled');
        $action=(string)($_POST['action']??'');
        if($action==='create_organization'){
            $identity->createOrganization((string)($_POST['name']??''));
        }elseif($action==='create_user'){
            $identity->createUser((string)($_POST['email']??''),(string)($_POST['name']??''));
        }elseif($action==='grant_membership'){
            $identity->grant((string)($_POST['tenant_id']??''),
                (string)($_POST['user_id']??''),(string)($_POST['role']??''));
        }else{
            throw new InvalidArgumentException('Unsupported action');
        }
        header('Location: /wabridge/account-admin?created=1',true,303);exit;
    }catch(Throwable $e){
        http_response_code($e instanceof InvalidArgumentException?422:403);
        $error=$e instanceof InvalidArgumentException ? 'Invalid pilot record or duplicate entry.' :
            'Pilot provisioning is disabled or unavailable.';
        error_log('WaBridge vNext pilot provision '.get_class($e));
    }
}
try{
    $pilot=$identity->adminPreview();
}catch(Throwable $e){
    $pilot=['organizations'=>[],'users'=>[],'memberships'=>[],'channels'=>0];
    $error='Pilot storage unavailable. See private application logs.';
    error_log('WaBridge vNext pilot overview '.get_class($e));
}
$demo=VNextDemo::overview();
if(($_GET['created']??'')==='1')$notice='Pending pilot record created. No user access or WhatsApp connection was activated.';
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>WaBridge — Multi-Account Preview</title>
<?php ui_head_assets(); ?>
</head><body class="dashboard-page">
<?php ui_shell_start('account-admin',null); ?>
<main class="console-main">
<?php ui_breadcrumb('Administration','Multi-Account Preview'); ?>
<section class="console-hero workspace-section">
  <div><span class="eyebrow">VNEXT · SAFE STAGING</span><h1>Multi-account control center</h1>
  <p>Explore isolated fictional accounts and pending pilot configurations. This is not live tenant access or messaging.</p></div>
</section>
<?php if($error!==''):?><div class="notice notice-error" role="alert"><?= escape($error) ?></div><?php endif; ?>
<?php if($notice!==''):?><div class="notice notice-success" role="status"><?= escape($notice) ?></div><?php endif; ?>
<section class="stats-grid log-stats workspace-section">
 <article class="stat-card"><p class="stat-label">Demo organizations</p><div class="stat-value"><?= (int)$demo['tenant_count'] ?></div><p class="stat-foot">Fictional, read only</p></article>
 <article class="stat-card"><p class="stat-label">Demo team members</p><div class="stat-value"><?= (int)$demo['user_count'] ?></div><p class="stat-foot">No login access</p></article>
 <article class="stat-card"><p class="stat-label">Demo connections</p><div class="stat-value"><?= (int)$demo['channel_count'] ?></div><p class="stat-foot">Not linked to WhatsApp</p></article>
 <article class="stat-card"><p class="stat-label">Pilot organizations</p><div class="stat-value"><?= count($pilot['organizations']) ?></div><p class="stat-foot">Disabled until certified</p></article>
</section>
<section class="glass-panel workspace-section">
 <div class="panel-heading"><div><span class="eyebrow">SYNTHETIC DATA</span><h2>Demo account directory</h2></div><span class="environment-chip">Simulation only</span></div>
 <div class="table-scroll"><table><thead><tr><th>Organization</th><th>Industry</th><th>People</th><th>Channels</th><th>Example workload</th><th>Status</th></tr></thead><tbody>
 <?php foreach($demo['accounts'] as $org): ?>
 <tr><td><strong><?= escape($org['name']) ?></strong></td><td><?= escape($org['industry']) ?></td>
 <td><?= (int)$org['users'] ?></td><td><?= (int)$org['channels'] ?></td>
 <td><?= escape($org['workload']) ?></td><td><?= escape($org['status']) ?></td></tr>
 <?php endforeach; ?>
 </tbody></table></div>
 <p class="panel-help">These entries are generated from static test fixtures. They never read customer contacts, real sessions, messages, API tokens or provider state.</p>
</section>
<section class="glass-panel workspace-section">
 <div class="panel-heading"><div><span class="eyebrow">PRIVATE JSON PILOT</span><h2>Provisioned pilot organizations</h2></div>
 <span class="environment-chip"><?= $canProvision?'Pending-only provisioning enabled':'Read-only' ?></span></div>
 <div class="table-scroll"><table><thead><tr><th>Organization</th><th>ID</th><th>Status</th><th>Members</th></tr></thead><tbody>
 <?php foreach($pilot['organizations'] as $org): ?>
 <?php $n=0;foreach($pilot['memberships'] as $m)if($m['tenant_id']===$org['id'])$n++; ?>
 <tr><td><?= escape($org['name']) ?></td><td><code><?= escape($org['id']) ?></code></td><td><?= escape($org['status']) ?></td><td><?= $n ?></td></tr>
 <?php endforeach; ?>
 <?php if(!$pilot['organizations']):?><tr><td colspan="4" class="empty-cell">No pilot accounts have been provisioned. Demo accounts above are always available.</td></tr><?php endif; ?>
 </tbody></table></div>
 <p class="panel-help">Pilot records are disabled by design. Pending members cannot sign in and no customer data is stored here.</p>
</section>
<?php if($canProvision): ?>
<section class="glass-panel workspace-section">
 <div class="panel-heading"><div><span class="eyebrow">OPERATOR ONLY</span><h2>Create pending pilot records</h2></div></div>
 <p class="panel-help">Creates only disabled organizations and invitation-only users. This does not send invitations or connect live channels.</p>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="create_organization">
 <div class="field"><label>Organization name<input name="name" maxlength="100" required></label></div>
 <div class="platform-button-row"><button type="submit" class="button button-primary">Create disabled pilot organization</button></div>
 </form>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="create_user">
 <div class="field"><label>Display name<input name="name" maxlength="100" required></label></div>
 <div class="field"><label>Email<input type="email" name="email" maxlength="254" required></label></div>
 <div class="platform-button-row"><button type="submit" class="button button-ghost">Create invitation-only user</button></div>
 </form>
 <?php if($pilot['organizations']&&$pilot['users']): ?>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>"><input type="hidden" name="action" value="grant_membership">
 <div class="field"><label>Organization<select name="tenant_id" required>
 <?php foreach($pilot['organizations'] as $org):?><option value="<?= escape($org['id']) ?>"><?= escape($org['name']) ?></option><?php endforeach; ?>
 </select></label></div>
 <div class="field"><label>User<select name="user_id" required>
 <?php foreach($pilot['users'] as $user):?><option value="<?= escape($user['id']) ?>"><?= escape($user['name']) ?></option><?php endforeach; ?>
 </select></label></div>
 <div class="field"><label>Pending role<select name="role"><option value="viewer">Viewer</option><option value="agent">Agent</option><option value="manager">Manager</option><option value="admin">Admin</option><option value="owner">Owner</option></select></label></div>
 <div class="platform-button-row"><button type="submit" class="button button-ghost">Create pending membership</button></div>
 </form>
 <?php endif; ?>
</section>
<?php endif; ?>
<div class="notice notice-warning">Live campaigns, AI auto-replies, customer inbox access, account activation and provider sends are disabled in this preview.</div>
</main><?php ui_shell_end(); ?></body></html>
