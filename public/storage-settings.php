<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
require_once dirname((new ReflectionFunction('store'))->getFileName()).'/storage-migration.php';
header('Cache-Control: no-store, private');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'none'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'");
if (!admin_authenticated()) { http_response_code(403);exit('Sign in to WaBridge first.'); }
$notice=(string)($_SESSION['storage_flash']??'');
unset($_SESSION['storage_flash']);
$error='';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        assert_csrf();
        if (($_POST['action']??'')!=='migrate_json' || ($_POST['confirm']??'')!=='yes')
            throw new InvalidArgumentException('Confirm the JSON testing migration');
        $result=storage_migrate_to_json();
        if ($result['status']==='already_json') $notice='JSON testing mode is already active.';
        else $notice='Migration completed. '.$result['clients'].' API clients and '.$result['jobs'].' messages were preserved. The original SQLite file was retained as a private backup.';
        (new JsonStore())->audit('storage_json_migration','admin','json',['status'=>$result['status']]);
        $_SESSION['storage_flash']=$notice;
        header('Location: storage-settings.php',true,303);
        exit;
    } catch (Throwable $e) {
        $error=$e instanceof InvalidArgumentException ? $e->getMessage() : 'Migration could not complete: '.$e->getMessage();
        error_log('WaBridge storage migration error: '.get_class($e));
    }
}
$mode=storage_mode();
$counts=['jobs'=>0,'clients'=>0,'pending_jobs'=>0,'pending_events'=>0];
if ($mode==='sqlite') {
    try {
        $db=store();
        $counts['jobs']=(int)$db->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
        $counts['clients']=(int)$db->query('SELECT COUNT(*) FROM api_clients')->fetchColumn();
        $counts['pending_jobs']=(int)$db->query("SELECT COUNT(*) FROM jobs WHERE state IN ('queued','processing')")->fetchColumn();
        $counts['pending_events']=(int)$db->query("SELECT COUNT(*) FROM event_deliveries WHERE state IN ('queued','processing')")->fetchColumn();
    } catch (Throwable $e) { $error='Unable to inspect legacy SQLite data. Keep the existing storage mode until database access is restored.'; }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Storage Settings · WaBridge</title>
<link rel="stylesheet" href="/wabridge/assets/wabridge-premium-v2.css?v=3"></head>
<body class="dashboard-page"><div class="site-shell">
<header class="site-header"><a class="brand-lockup" href="./"><span class="brand-mark"><span class="brand-mark-inner">W</span></span><span class="brand-copy"><strong>Wa<span>Bridge</span></strong><small>MESSAGING INFRASTRUCTURE</small></span></a><a href="./" class="button button-ghost button-small">Back to dashboard</a></header>
<main class="workspace">
<div class="workspace-top"><div><div class="eyebrow">WABRIDGE / DATA LAYER</div><h1>Storage settings</h1><p class="lead">Control low-volume testing storage in your browser. No SSH or external database is needed for JSON mode.</p></div></div>
<?php if ($notice!==''): ?><div class="notice notice-success" role="status"><?= escape($notice) ?></div><?php endif; ?>
<?php if ($error!==''): ?><div class="notice notice-warning" role="alert"><?= escape($error) ?></div><?php endif; ?>
<div class="stats-grid workspace-section">
<article class="stat-card"><p class="stat-label">Active WaBridge data driver</p><p class="stat-value"><?= escape(strtoupper($mode)) ?></p><p class="stat-foot"><?= $mode==='json' ? 'Private, locked JSON file · test mode' : 'Legacy SQLite database · existing data preserved' ?></p></article>
<article class="stat-card"><p class="stat-label">API clients</p><p class="stat-value"><?= $mode==='json' ? count(storage_clients()) : (int)$counts['clients'] ?></p><p class="stat-foot">Credentials are stored as SHA-256 hashes</p></article>
<article class="stat-card"><p class="stat-label">Queued or processing work</p><p class="stat-value"><?= $mode==='json' ? 'See reports' : ($counts['pending_jobs']+$counts['pending_events']) ?></p><p class="stat-foot">Active work blocks migration to prevent duplicate messages</p></article>
</div>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">JSON-FIRST TESTING</div><h2><?= $mode==='json' ? 'JSON storage is enabled' : 'Migrate existing WaBridge data to JSON' ?></h2></div></div>
<p class="panel-help">JSON mode uses a private file with cross-request file locking, atomic writes, and bounded queues. It is intended only for one low-volume Cloudways test installation. It is not a replacement for a transactional production database.</p>
<?php if ($mode==='json'): ?>
<div class="notice notice-success">JSON test storage is active. Your database-free PHP test workflow is ready. Existing legacy SQLite files, if any, have not been deleted.</div>
<?php else: ?>
<div class="notice notice-warning">This is a one-way test-mode cutover. The original SQLite file stays untouched for rollback by an administrator, but JSON and SQLite must not be operated simultaneously. Pause sending and scheduled workers before migration.</div>
<p class="panel-help">Existing API clients: <?= (int)$counts['clients'] ?> · Historical messages: <?= (int)$counts['jobs'] ?>. Migration is blocked when queued/processing messages or webhook deliveries exist.</p>
<form method="post" class="form-stack">
<input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
<input type="hidden" name="action" value="migrate_json">
<label><input type="checkbox" name="confirm" value="yes" required style="width:auto;min-height:0;margin-right:8px">I understand JSON mode is for limited testing and the SQLite file will be retained privately.</label>
<button type="submit" class="button button-primary" <?= ($counts['pending_jobs']+$counts['pending_events']>0 || $error!=='') ? 'disabled' : '' ?>>Migrate to JSON testing</button>
</form>
<?php endif; ?>
</section>
<section class="glass-panel workspace-section">
<div class="panel-heading"><div><div class="eyebrow">PRODUCTION</div><h2>MariaDB-ready architecture</h2></div></div>
<p class="panel-help">WaBridge now uses a storage facade so its REST API and dashboard do not depend directly on SQL. MariaDB remains the recommended production database, but its driver and migration are not yet implemented or certified. Waxum's separate Rust session storage is not changed by this setting.</p>
</section>
</main><footer class="site-footer">WaBridge · Private storage configuration</footer></div></body></html>
