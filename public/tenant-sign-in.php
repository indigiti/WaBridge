<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
$src=dirname((new ReflectionFunction('store'))->getFileName());
require_once $src.'/vnext/member-auth.php';
require_once $src.'/ui-shell.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
if((config()['enable_vnext_tenant_login']??false)!==true) {
    http_response_code(404);exit('Not found');
}
$auth=new VNextMemberAuth(new VNextJsonStore());
$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        assert_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='logout') {
            $auth->signOut();
            header('Location: /wabridge/tenant-sign-in',true,303);exit;
        }
        if($action!=='login')throw new InvalidArgumentException('Invalid operation');
        $actor=$auth->signIn((string)($_POST['tenant_id']??''),
            (string)($_POST['email']??''),(string)($_POST['password']??''),
            (string)($_SERVER['REMOTE_ADDR']??'unknown'));
        if($actor===null){
            usleep(150000);
            $error='Unable to sign in. Check your details or contact the workspace administrator.';
        }else{
            header('Location: /wabridge/tenant-workspace',true,303);exit;
        }
    }catch(Throwable $e){
        http_response_code($e instanceof InvalidArgumentException?422:403);
        $error='Sign-in unavailable. Confirm your account has been activated.';
        error_log('WaBridge vNext member login '.get_class($e));
    }
}elseif(!in_array(($_SERVER['REQUEST_METHOD']??'GET'),['GET','HEAD'],true)){
    header('Allow: GET, HEAD, POST');http_response_code(405);exit;
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>WaBridge · Tenant Sign-in</title>
<?php ui_head_assets(); ?></head><body class="auth-page">
<div class="site-shell"><header class="site-header"><a href="/wabridge/" class="brand-lockup">
<span class="brand-mark" aria-hidden="true"><span class="brand-mark-inner">W</span></span>
<span class="brand-copy"><strong>Wa<span>Bridge</span></strong><small>Secure tenant workspace</small></span></a>
<span class="environment-chip">Pilot access</span></header>
<main class="auth-main" id="main-content">
<section class="auth-card" aria-labelledby="member-title">
<div class="eyebrow">TENANT MEMBER · RESTRICTED PORTAL</div>
<h1 id="member-title">Your workspace</h1>
<p class="intro">Sign in to the read-only tenant access preview. This account cannot access the legacy administrator console or send WhatsApp messages.</p>
<?php if($error!==''):?><div class="notice notice-warning" role="alert"><?= escape($error) ?></div><?php endif; ?>
<form method="post" class="auth-form">
<input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
<input type="hidden" name="action" value="login">
<div class="field"><label for="tenant-id">Organization ID</label>
<input id="tenant-id" name="tenant_id" autocomplete="organization" maxlength="36" placeholder="Organization UUID" required></div>
<div class="field"><label for="member-email">Email</label>
<input id="member-email" type="email" name="email" autocomplete="username" maxlength="254" required></div>
<div class="field"><label for="member-password">Password</label>
<input id="member-password" type="password" name="password" autocomplete="current-password" required></div>
<button class="button button-primary button-wide" type="submit">Sign in to read-only preview</button>
</form>
<p class="fine-print">Tenant access requires an activated membership and individual channel grants. Invitation acceptance alone never grants access.</p>
</section></main></div></body></html>
