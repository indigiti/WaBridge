<?php
declare(strict_types=1);
// Regression test for Cloudways/DigiOps split public/private directory layout.
// All files are temporary; no real session data or configuration is accessed.
$base=sys_get_temp_dir().'/wabridge-loader-'.bin2hex(random_bytes(6));
$public=$base.'/public_html/wabridge';
$private=$base.'/private_html/wabridge';
if (!mkdir($public,0700,true)||!mkdir($private.'/src',0700,true)) throw new RuntimeException('Failed to create test fixture');
copy(dirname(__DIR__).'/public/_loader.php',$public.'/_loader.php');
file_put_contents($private.'/src/store.php',"<?php echo 'WABRIDGE_FIXTURE_LOADED';");
$original=$_SERVER['DOCUMENT_ROOT']??null;
$_SERVER['DOCUMENT_ROOT']=$base.'/public_html';
ob_start();
try { require $public.'/_loader.php'; $result=ob_get_clean(); }
catch (Throwable $e) { ob_end_clean(); throw $e; }
finally {
    if ($original===null) unset($_SERVER['DOCUMENT_ROOT']); else $_SERVER['DOCUMENT_ROOT']=$original;
    @unlink($public.'/_loader.php');
    @unlink($private.'/src/store.php');
    @rmdir($private.'/src');@rmdir($private);@rmdir($public);@rmdir($base.'/public_html');@rmdir($base.'/private_html');@rmdir($base);
}
if ($result!=='WABRIDGE_FIXTURE_LOADED') throw new RuntimeException('DigiOps split private loader failed');
echo "DigiOps private runtime resolver: PASS\n";
