<?php
declare(strict_types=1);
require __DIR__.'/_loader.php';
$src=dirname((new ReflectionFunction('store'))->getFileName());
require_once $src.'/ui-shell.php';
require_once $src.'/vnext/workflows.php';
require_once $src.'/vnext/campaigns.php';
require_once $src.'/vnext/assistant.php';
require_once $src.'/vnext/flows.php';
require_once $src.'/vnext/demo.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; object-src \'none\'; frame-ancestors \'none\'; base-uri \'none\'; form-action \'self\'');
require_admin_html_page();

$modules=[
    'automation-studio'=>['title'=>'Visual Automation Builder','section'=>'Automation'],
    'campaign-manager'=>['title'=>'Campaign Manager','section'=>'Campaigns'],
    'ai-assistant'=>['title'=>'AI Assistant · Human Review','section'=>'AI'],
    'flows-studio'=>['title'=>'Flows Studio','section'=>'Flows'],
];
$module=(string)($_GET['studio']??'automation-studio');
if(!isset($modules[$module])){http_response_code(404);exit('Not found');}
$tenants=VNextDemo::accounts();
$tenantIds=array_column($tenants,'id');
$tenantId=(string)($_GET['tenant']??$tenantIds[0]);
if(!in_array($tenantId,$tenantIds,true))$tenantId=$tenantIds[0];
$enabled=(config()['enable_vnext_studio_preview']??false)===true;
$store=new VNextJsonStore();
$workflow=new VNextWorkflows($store);
$campaign=new VNextCampaigns($store);
$assistant=new VNextAssistant($store);
$flows=new VNextFlows($store);
$error='';$unseeded=false;
$base='/wabridge/'.$module;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        assert_csrf();
        if(!$enabled)throw new RuntimeException('Studio preview disabled');
        $action=(string)($_POST['action']??'');
        $id=(string)($_POST['record_id']??'');
        $revision=(string)($_POST['version']??'');
        $version=ctype_digit($revision)&&strlen($revision)<9?(int)$revision:0;
        switch($module) {
            case 'automation-studio':
                if($action==='create') {
                    $types=['trigger.fixture_message'];
                    if(isset($_POST['steps'])) {
                        $steps=$_POST['steps'];
                        if(!is_array($steps)||count($steps)<1||count($steps)>9)
                            throw new InvalidArgumentException('Invalid sequence length');
                        foreach($steps as $type) {
                            if(!is_string($type))throw new InvalidArgumentException('Invalid node type');
                            $types[]=$type;
                        }
                    }else{
                        // No-JavaScript fallback still uses server-verified options.
                        $condition=(string)($_POST['condition']??'');
                        if($condition!=='')$types[]=$condition;
                        $types[]=(string)($_POST['step']??'');
                    }
                    $types[]='action.review';
                    $types[]='action.stop';
                    $workflow->saveDraft($tenantId,(string)($_POST['title']??''),$types);
                }elseif($action==='review'){$workflow->publishDemo($tenantId,$id,$version);}
                elseif($action==='simulate'){$workflow->simulate($tenantId,$id,$version);}
                else throw new InvalidArgumentException('Unknown workflow action');
                break;
            case 'campaign-manager':
                if($action==='create'){$campaign->createDraft($tenantId,
                    (string)($_POST['title']??''),(string)($_POST['template']??''),
                    (string)($_POST['audience']??''));}
                else{$campaign->transition($tenantId,$id,$action,$version);}
                break;
            case 'ai-assistant':
                if($action==='draft'){$assistant->draft($tenantId,(string)($_POST['topic']??''),
                    ($_POST['privacy_confirmed']??'')==='yes');}
                elseif($action==='review'){$assistant->review($tenantId,$id,(string)($_POST['decision']??''));}
                else throw new InvalidArgumentException('Unknown draft operation');
                break;
            case 'flows-studio':
                if($action==='create'){$flows->createDraft($tenantId,(string)($_POST['blueprint']??''));}
                elseif($action==='review'){$flows->review($tenantId,$id,$version);}
                elseif($action==='simulate'){$flows->submitFixture($tenantId,$id,
                    is_array($_POST['answers']??null)?$_POST['answers']:[]);}
                else throw new InvalidArgumentException('Unknown Flow operation');
                break;
        }
        header('Location: '.$base.'?'.http_build_query(['tenant'=>$tenantId,'saved'=>'1']),true,303);
        exit;
    }catch(Throwable $e) {
        $error=$e instanceof InvalidArgumentException
            ? 'Invalid demo request. Use only supported synthetic options.'
            : 'Update unavailable or changed concurrently. Review the current state.';
        http_response_code(422);
        error_log('WaBridge studio '.get_class($e));
    }
}
try {
    $records=match($module) {
        'automation-studio'=>$workflow->list($tenantId),
        'campaign-manager'=>$campaign->list($tenantId),
        'ai-assistant'=>$assistant->list($tenantId),
        'flows-studio'=>$flows->list($tenantId),
    };
}catch(Throwable $e) {
    $records=[];$unseeded=true;
}
$persistedCount=count($records);
$staticSamples=$persistedCount===0;
if($staticSamples)$records=VNextDemo::studioExamples($module);
$info=$modules[$module];
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>WaBridge — <?= escape($info['title']) ?></title>
<?php ui_head_assets(); ?>
<?php if($module==='automation-studio'):?><script src="/wabridge/assets/vnext-studio.js?v=<?= escape(ui_asset_version()) ?>" defer></script><?php endif; ?>
</head><body class="dashboard-page">
<?php ui_shell_start($module,null); ?>
<main class="console-main">
<?php ui_breadcrumb($info['section'],$info['title']); ?>
<section class="console-hero workspace-section">
 <div><span class="eyebrow">VNEXT · CODE-FIRST DEMO</span>
 <h1><?= escape($info['title']) ?></h1>
 <p>Author, review and test bounded workflows using fictional tenant data. No live message, campaign, external AI call or WhatsApp Flow publication is possible.</p></div>
</section>
<div class="notice notice-warning" role="status">Safe simulation only · All real-provider operations disabled · <?= $enabled?'Synthetic editing enabled':'Read-only overview' ?></div>
<?php if($error!==''):?><div class="notice notice-error" role="alert"><?= escape($error) ?></div><?php endif; ?>
<?php if(($_GET['saved']??'')==='1'):?><div class="notice notice-success" role="status">Demo operation recorded locally. Nothing was sent.</div><?php endif; ?>
<section class="glass-panel workspace-section">
 <div class="panel-heading"><div><span class="eyebrow">TENANT SCOPE</span><h2>Fictional organization</h2></div><a class="button button-ghost button-small" href="/wabridge/account-admin">Accounts</a></div>
 <nav class="section-links" aria-label="Select demo tenant">
 <?php foreach($tenants as $t): ?>
 <a href="<?= escape($base) ?>?tenant=<?= escape(rawurlencode($t['id'])) ?>" <?= $t['id']===$tenantId?'aria-current="page"':'' ?>><?= escape($t['name']) ?></a>
 <?php endforeach; ?>
 </nav>
 <?php if($unseeded):?><p class="panel-help">This module has no private synthetic pilot identity yet. Use Multi-account preview to seed fictional organizations; existing static examples remain visible in the other console pages.</p><?php endif; ?>
</section>
<section class="stats-grid log-stats workspace-section">
 <article class="stat-card"><p class="stat-label">Saved pilot records</p><div class="stat-value"><?= $persistedCount ?></div><p class="stat-foot">Isolated JSON per tenant</p></article>
 <article class="stat-card"><p class="stat-label">Live provider calls</p><div class="stat-value">0</div><p class="stat-foot">No outbound integration</p></article>
 <article class="stat-card"><p class="stat-label">Automatic replies</p><div class="stat-value">Off</div><p class="stat-foot">Human review only</p></article>
</section>
<?php if($module==='automation-studio'): ?>
<section class="glass-panel workspace-section">
 <div class="panel-heading"><div><span class="eyebrow">VISUAL GRAPH</span><h2>Sample no-send workflow</h2></div></div>
 <div class="vnext-flow-graph" aria-label="Demo automation graph">
 <?php foreach(['Synthetic message trigger','Check fictional consent','Suggest team assignment','Human review gate','End – no send'] as $node): ?>
 <div class="vnext-flow-node"><?= escape($node) ?></div>
 <?php endforeach; ?>
 </div>
 <p class="panel-help">The graph is validated as a bounded, acyclic sequence. Even a reviewed version runs exclusively in simulation mode.</p>
 <?php if($enabled&&!$unseeded): ?>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
 <input type="hidden" name="action" value="create">
 <div class="field"><label>Workflow title<input name="title" maxlength="70" required placeholder="Sample routing flow"></label></div>
 <div class="vnext-builder" data-vnext-builder aria-label="Editable automation steps"></div>
 <div class="vnext-fallback">
 <div class="field"><label>Condition<select name="condition">
 <option value="">No condition</option>
 <option value="condition.sample_consent">Check fictional consent</option>
 <option value="condition.sample_tag">Check fictional tag</option></select></label></div>
 <div class="field"><label>Next action<select name="step">
 <option value="action.assign_demo">Suggest team assignment</option>
 <option value="action.note_demo">Suggest a preset internal note</option></select></label></div>
 </div>
 <button class="button button-primary" type="submit">Save visual draft</button>
 </form>
 <?php endif; ?>
</section>
<?php elseif($module==='campaign-manager'): ?>
<section class="glass-panel workspace-section">
 <div class="panel-heading"><div><span class="eyebrow">CONSENT-FIRST</span><h2>Campaign authoring</h2></div></div>
 <p class="panel-help">Four fictional recipients in the sample audience: two eligible, one opted out, one suppressed. Preview and simulated dispatch are arithmetic only.</p>
 <?php if($enabled&&!$unseeded): ?>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
 <input type="hidden" name="action" value="create">
 <div class="field"><label>Campaign title<input name="title" maxlength="70" required placeholder="Fictional outreach demo"></label></div>
 <div class="field"><label>Approved sample template<select name="template">
 <?php foreach(VNextCampaigns::templates() as $key=>$label):?><option value="<?= escape($key) ?>"><?= escape($label) ?></option><?php endforeach; ?>
 </select></label></div>
 <div class="field"><label>Fictional audience<select name="audience">
 <?php foreach(VNextCampaigns::audiences() as $a):?><option value="<?= escape($a) ?>"><?= escape($a) ?></option><?php endforeach; ?>
 </select></label></div>
 <button class="button button-primary" type="submit">Create draft campaign</button>
 </form>
 <?php endif; ?>
</section>
<?php elseif($module==='ai-assistant'): ?>
<section class="glass-panel workspace-section">
 <div class="panel-heading"><div><span class="eyebrow">SOURCE-GROUNDED REVIEW</span><h2>Human-reviewed drafts</h2></div></div>
 <p class="panel-help">The assistant currently uses deterministic, source-linked sample knowledge. It makes zero requests to external AI providers and never replies to contacts.</p>
 <?php if($enabled&&!$unseeded):?>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
 <input type="hidden" name="action" value="draft">
 <div class="field"><label>Fictional topic<select name="topic">
 <?php foreach(VNextAssistant::sources() as $topic=>$source):?><option value="<?= escape($topic) ?>"><?= escape($source['title']) ?></option><?php endforeach; ?>
 </select></label></div>
 <div class="field"><label><input type="checkbox" name="privacy_confirmed" value="yes" required> I confirm this contains only fictional test data</label></div>
 <button class="button button-primary" type="submit">Generate review-only sample draft</button>
 </form>
 <?php endif; ?>
</section>
<?php elseif($module==='flows-studio'): ?>
<section class="glass-panel workspace-section">
 <div class="panel-heading"><div><span class="eyebrow">STRUCTURED FORMS</span><h2>WhatsApp-like Flow prototypes</h2></div></div>
 <p class="panel-help">Fixed-choice forms are stored locally and do not register, encrypt or publish a Meta WhatsApp Flow. Production endpoint signing remains a separate gate.</p>
 <?php if($enabled&&!$unseeded):?>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
 <input type="hidden" name="action" value="create">
 <div class="field"><label>Sample blueprint<select name="blueprint">
 <?php foreach(VNextFlows::blueprints() as $id=>$flow):?><option value="<?= escape($id) ?>"><?= escape($flow['name']) ?></option><?php endforeach; ?>
 </select></label></div>
 <button class="button button-primary" type="submit">Create local Flow draft</button>
 </form>
 <?php endif; ?>
</section>
<?php endif; ?>
<section class="glass-panel workspace-section">
 <div class="panel-heading"><div><span class="eyebrow">TENANT RECORDS</span><h2>Review and simulate</h2></div><span class="environment-chip">No live execution</span></div>
 <?php if($staticSamples):?><p class="panel-help">The example below is a static, fictional record. It is not saved or executable. Seed a pilot to create editable versions.</p><?php endif; ?>
 <?php foreach($records as $record): ?>
 <article class="vnext-studio-record">
 <div class="panel-heading"><div><h3><?= escape((string)($record['title']??$record['topic']??'Demo record')) ?></h3>
 <p class="panel-help"><?= escape((string)($record['status']??$record['state']??'draft')) ?> · Fictional tenant only</p></div>
 <span class="environment-chip">Offline</span></div>
 <?php if($module==='automation-studio'): ?>
 <div class="vnext-flow-graph" aria-label="Workflow visual steps">
 <?php foreach($record['nodes'] as $node):?><div class="vnext-flow-node"><?= escape($node['label']) ?></div><?php endforeach; ?>
 </div>
 <p class="panel-help">Version <?= (int)$record['version'] ?> · Published snapshots: <?= count($record['published_versions']) ?></p>
 <?php elseif($module==='campaign-manager'): ?>
 <p class="panel-help">Template: <?= escape($record['template']) ?> · Consent-eligible: <?= (int)$record['preview']['eligible'] ?> · Excluded: <?= (int)$record['preview']['suppressed'] ?> · Version <?= (int)$record['version'] ?></p>
 <?php elseif($module==='ai-assistant'): ?>
 <blockquote><?= escape($record['text']) ?></blockquote>
 <p class="panel-help">Source: <?= escape($record['source']) ?> · Mode: deterministic fixture · Never auto-send</p>
 <?php elseif($module==='flows-studio'): ?>
 <p class="panel-help">Version <?= (int)$record['version'] ?> · <?= count($record['fields']) ?> sample fields · Provider published: No</p>
 <?php endif; ?>
 <?php if($enabled&&!$staticSamples): ?>
 <div class="vnext-studio-actions">
 <?php
 $actions=match($module) {
     'automation-studio'=>$record['status']==='draft'?['review'=>'Publish immutable demo version','simulate'=>'Simulate without sending']:['simulate'=>'Simulate without sending'],
     'campaign-manager'=>match($record['state']) {
         'draft'=>['review'=>'Review for simulation'],
         'reviewed_demo'=>['simulate'=>'Simulate consent-safe recipients','pause'=>'Pause demo'],
         'simulated'=>['pause'=>'Pause demo'],
         default=>[],
     },
     'ai-assistant'=>$record['state']==='awaiting_human_review'?['approve_for_copy'=>'Approve for manual copy','reject'=>'Reject draft']:[],
     'flows-studio'=>$record['state']==='draft'?['review'=>'Review Flow blueprint']:[],
 };
 ?>
 <?php foreach($actions as $action=>$label): ?>
 <form method="post">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
 <input type="hidden" name="record_id" value="<?= escape($record['id']) ?>">
 <input type="hidden" name="version" value="<?= (int)($record['version']??1) ?>">
 <input type="hidden" name="action" value="<?= $module==='ai-assistant'?'review':escape($action) ?>">
 <?php if($module==='ai-assistant'):?><input type="hidden" name="decision" value="<?= escape($action) ?>"><?php endif; ?>
 <button class="button button-ghost button-small" type="submit"><?= escape($label) ?></button>
 </form>
 <?php endforeach; ?>
 <?php if($module==='flows-studio'&&$record['state']==='ready_for_simulation'): ?>
 <form method="post" class="platform-form-grid">
 <input type="hidden" name="_csrf" value="<?= escape(csrf()) ?>">
 <input type="hidden" name="record_id" value="<?= escape($record['id']) ?>">
 <input type="hidden" name="action" value="simulate">
 <?php foreach($record['fields'] as $field):?>
 <div class="field"><label><?= escape($field['label']) ?><select name="answers[<?= escape($field['id']) ?>]">
 <?php foreach($field['choices'] as $choice):?><option value="<?= escape($choice) ?>"><?= escape($choice) ?></option><?php endforeach; ?>
 </select></label></div>
 <?php endforeach; ?>
 <button class="button button-ghost button-small" type="submit">Simulate Flow response</button>
 </form>
 <?php endif; ?>
 </div>
 <?php endif; ?>
 </article>
 <?php endforeach; ?>
 <?php if(!$records):?><p class="panel-help">No synthetic records yet. All authoring actions are disabled until a fictional pilot is seeded and the private preview feature flag is enabled.</p><?php endif; ?>
</section>
<p class="notice notice-warning">Certification for actual WhatsApp webhooks, authenticated tenant users, approved templates, live consent and official Flow encryption is deliberately not enabled by this demonstration.</p>
</main><?php ui_shell_end(); ?></body></html>
