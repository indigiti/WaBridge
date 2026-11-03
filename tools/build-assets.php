<?php
declare(strict_types=1);
/**
 * Single browser CSS bundle built from intentionally ordered source modules.
 * Preserve source modules for auditing; serve only the compiled bundle.
 * No Node.js or third-party bundler.
 */
$root=dirname(__DIR__);
$modules=[
  'public/assets/wabridge-premium-v2.css',
  'public/assets/wabridge-console-v3.css',
  'public/assets/wabridge-glass-v4.css',
  'public/assets/wabridge-typography-v5.css',
];
$out="/* WaBridge unified UI v5 — generated; edit source modules above, not this file. */\n";
foreach($modules as $path){
 $source=$root.'/'.$path;
 if(!is_file($source)||is_link($source))throw new RuntimeException('Missing CSS source: '.$path);
 $css=file_get_contents($source);
 if(!is_string($css)||strlen($css)<1000)throw new RuntimeException('Invalid CSS source: '.$path);
 if(str_contains($css,'@import')||str_contains($css,'javascript:'))
     throw new RuntimeException('External CSS imports or JS URLs forbidden: '.$path);
 $out.="/* start: ".$path." */\n".$css."\n/* end: ".$path." */\n";
}
$target=$root.'/public/assets/wabridge-ui-v5.css';
if(file_put_contents($target,$out,LOCK_EX)!==strlen($out))
    throw new RuntimeException('Could not generate unified CSS');
echo 'WaBridge UI bundle ready: '.strlen($out)." bytes; four source modules, one stylesheet request\n";
