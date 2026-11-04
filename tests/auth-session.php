<?php
declare(strict_types=1);
// Verify independent browser session scope for a shared Cloudways hostname.
$base=sys_get_temp_dir().'/wabridge-auth-'.bin2hex(random_bytes(6));
if (!mkdir($base,0700,true)) throw new RuntimeException('Session test fixture unavailable');
register_shutdown_function(static function()use($base) {
    foreach (glob($base.'/sess_*') ?: [] as $path) @unlink($path);
    @rmdir($base);
});
ini_set('session.save_path',$base);
$_SERVER['HTTPS']='on';
$_SERVER['SERVER_PORT']='443';
require dirname(__DIR__).'/src/bootstrap.php';
start_admin_session();
if (session_name()!=='WABRIDGESESSID') throw new RuntimeException('Session cookie not namespace-isolated');
$p=session_get_cookie_params();
if ($p['path']!=='/wabridge/' || !$p['secure'] || !$p['httponly'] || $p['samesite']!=='Lax')
    throw new RuntimeException('Incorrect cookie path or browser security attributes');
if (admin_authenticated()) throw new RuntimeException('Anonymous request should not be authorized');
$_SESSION['admin']=true;
$_SESSION['login_at']=time();
$_SESSION['last_seen']=time();
$sid=session_id();
session_write_close();
session_id($sid);
if (!admin_authenticated()) throw new RuntimeException('Authenticated session lost between PHP requests');
$_SESSION['last_seen']=time()-3601;
if (admin_authenticated()) throw new RuntimeException('Expired session accepted');
session_write_close();
echo "Admin cookie isolation, authenticated re-entry and timeout: PASS\n";
