<?php
declare(strict_types=1);
// Run loader regression in a separate process: loader calls exit on errors.
$base=sys_get_temp_dir().'/wabridge-loader-'.bin2hex(random_bytes(6));
$public=$base.'/public_html/wabridge';
$private=$base.'/private_html/wabridge';
if (!mkdir($public,0700,true) || !mkdir($private.'/src',0700,true)) throw new RuntimeException('Cannot create test fixture');
copy(dirname(__DIR__).'/public/_loader.php',$public.'/_loader.php');
file_put_contents($private.'/src/store.php',"<?php echo 'WABRIDGE_PRIVATE_FIXTURE_OK';");
$runner=$base.'/runner.php';
file_put_contents($runner,'<?php $_SERVER["DOCUMENT_ROOT"]='.var_export($base.'/public_html',true).'; require '.var_export($public.'/_loader.php',true).';');
$exitCode=-1;$stdout='';$stderr='';
try {
    $process=proc_open([PHP_BINARY,$runner],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to run fixture');
    fclose($pipes[0]);
    $stdout=(string)stream_get_contents($pipes[1]);fclose($pipes[1]);
    $stderr=(string)stream_get_contents($pipes[2]);fclose($pipes[2]);
    $exitCode=proc_close($process);
} finally {
    foreach ([$runner,$public.'/_loader.php',$private.'/src/store.php'] as $file) @unlink($file);
    foreach ([$private.'/src',$private,$public,$base.'/public_html',$base.'/private_html',$base] as $dir) @rmdir($dir);
}
if ($exitCode!==0 || $stdout!=='WABRIDGE_PRIVATE_FIXTURE_OK')
    throw new RuntimeException('Private resolver failed (status='.$exitCode.', output='.substr($stdout,0,90).', stderr='.substr($stderr,0,90).')');
echo "DigiOps private runtime resolver: PASS\n";
