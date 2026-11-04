<?php
declare(strict_types=1);
/**
 * WaBridge Design v6: one authored stylesheet, one generated asset.
 * No dependency on legacy v2/v3/v4 cascades.
 * PHP-only, deterministic build. Fonts remain local and OFL-licensed.
 */
$root=dirname(__DIR__);
$source=$root.'/public/assets/wabridge-design-v6.css';
$target=$root.'/public/assets/wabridge-ui-v6.css';
if(!is_file($source)||is_link($source))throw new RuntimeException('Missing canonical design v6 stylesheet');
$css=file_get_contents($source);
if(!is_string($css)||strlen($css)<15000)throw new RuntimeException('Incomplete canonical design v6');
if(str_contains($css,'@import')||str_contains($css,'javascript:'))
    throw new RuntimeException('External CSS imports and JS URLs are forbidden');
foreach(['.console-hero','.console-overview-grid','.console-sidebar','.glass-panel',
         '.stats-grid','@media(max-width:760px)','@font-face'] as $required)
    if(!str_contains($css,$required))throw new RuntimeException('Missing required UI component: '.$required);
$out="/* Generated from a single reviewed design source: wabridge-design-v6.css */\n".$css."\n";
if(file_put_contents($target,$out,LOCK_EX)!==strlen($out))
    throw new RuntimeException('Unable to build canonical CSS');
echo "WaBridge design v6: one authored source, one browser CSS request (".strlen($out)." bytes)\n";
