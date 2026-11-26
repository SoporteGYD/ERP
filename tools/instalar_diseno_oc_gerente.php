<?php
declare(strict_types=1);
/**
 * Actualización de presentación PDF, sin acceso ni cambios a la base de datos.
 * --check | --apply | --verify | --rollback
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo consola.'); }
$root=dirname(__DIR__);
$src=$root.'/tools/gyd_oc_pdf_redesign_src/app';
$targets=[
  'app/helpers/gyd_oc_pdf_profesional.php',
  'app/assets/oc_pdf/gyd_farmacia.jpg',
  'app/assets/oc_pdf/gyd_mascota.jpg',
];
$origin=$root.'/app/helpers/erp_oc_win_pdf.php';
$backupDir=(getenv('LOCALAPPDATA')?:sys_get_temp_dir()).DIRECTORY_SEPARATOR.'GYD'.DIRECTORY_SEPARATOR.'Respaldos_Diseno_OC_Gerente';
$manifest=$backupDir.DIRECTORY_SEPARATOR.'ultima_instalacion.json';
$cmd=$argv[1]??'--help';
function fail(string $message):never{fwrite(STDERR,'ERROR: '.$message.PHP_EOL);exit(1);}
function content(string $path):string { $s=@file_get_contents($path);if($s===false)throw new RuntimeException('No se pudo leer '.$path);return $s; }
function lintcode(string $code):void {
 $tmp=tempnam(sys_get_temp_dir(),'gyd_oc_lint_');if($tmp===false)throw new RuntimeException('No se pudo crear temporal de sintaxis.');
 file_put_contents($tmp,$code);$r=[];$rc=1;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$r,$rc);@unlink($tmp);
 if($rc!==0)throw new RuntimeException('Error de sintaxis PHP: '.implode(' ',$r));
}
function mod_pdf(string $original):string {
 if(str_contains($original,'GYD_OC_PDF_PROFESIONAL_V1'))throw new RuntimeException('El rediseño ya está instalado. Usa --verify.');
 $new=$original;
 $marker='// GYD_OC_PDF_PROFESIONAL_V1: plantillas de compra A4 horizontal, firmas Compras/Dueño/Gerente.';
 $n=0;
 $new=preg_replace("~(require_once\s+__DIR__\s*\.\s*['\"]/erp_oc_win\\.php['\"];\s*)~",'$1'."\n".$marker."\nrequire_once __DIR__.'/gyd_oc_pdf_profesional.php';\n",$new,1,$n);
 if(!is_string($new)||$n!==1)throw new RuntimeException('No se reconoció la carga del generador actual en app/helpers/erp_oc_win_pdf.php.');
 $func='return gyd_oc_pdf_profesional($o,$items,$mostrarCostos);';
 $n=0;
 $new=preg_replace('~(function\s+ocw_pdf_compose\s*\(\s*array\s+\$o\s*,\s*array\s+\$items\s*,\s*bool\s+\$mostrarCostos\s*=\s*true\s*\)\s*:\s*string\s*\{)~',"$1\n // Plantilla profesional; el cuerpo anterior queda conservado para restauración.\n ".$func."\n",$new,1,$n);
 if(!is_string($new)||$n!==1)throw new RuntimeException('No se reconoció la función principal ocw_pdf_compose. No se sobrescribe.');
 // Designa el gerente asociado a la sucursal; si no es único, se deja espacio para firmar.
 $n=0;
 $new=preg_replace('~(\$data\s*=\s*ocw_order\s*\(\s*\$db\s*,\s*\$id\s*,\s*\$usuario\s*\)\s*;\s*\$o\s*=\s*\$data\s*\[\s*[\'\"]orden[\'\"]\s*\]\s*;)~',"$1\n // Nombre para firma física del gerente destinatario; no significa que recibió.\n try { \$o['oc_gerente_destino_nombre']=gyd_oc_pdf_nombre_gerente(\$db,(string)(\$o['destino']??'')); }\n catch (Throwable \$e) { error_log('Consulta gerente OC para PDF: '.\$e->getMessage()); \$o['oc_gerente_destino_nombre']=''; }\n",$new,1,$n);
 if(!is_string($new)||$n!==1)throw new RuntimeException('No se reconoció el lugar de consulta de la orden para obtener gerente. No se sobrescribe.');
 return $new;
}
try {
 if(!in_array($cmd,['--check','--apply','--verify','--rollback','--help'],true))throw new RuntimeException('Modo no reconocido.');
 if($cmd==='--help'){echo "Instalar plantilla profesional OC G&D: --check|--apply|--verify|--rollback\n";exit;}
 if(!is_file($origin))throw new RuntimeException('No se encuentra '.$origin);
 $existing=content($origin);
 if($cmd==='--verify'){
  if(!str_contains($existing,'GYD_OC_PDF_PROFESIONAL_V1')||!str_contains($existing,'gyd_oc_pdf_profesional($o,$items,$mostrarCostos)'))throw new RuntimeException('El PDF profesional no está conectado al ERP.');
  foreach($targets as $rel){if(!is_file($root.'/'.$rel))throw new RuntimeException('Falta '.$rel);}
  lintcode($existing);lintcode(content($root.'/'.$targets[0]));
  echo "VERIFICADO: diseño profesional integrado, logos presentes y firmas Emitió / Autorizó / Recibió (Gerente).\n";exit;
 }
 if($cmd==='--rollback'){
  if(!is_file($manifest))throw new RuntimeException('No se encontró el manifiesto de respaldo.');
  $m=json_decode(content($manifest),true,512,JSON_THROW_ON_ERROR);
  if(($m['root']??null)!==$root)throw new RuntimeException('El respaldo corresponde a otro proyecto.');
  foreach(($m['after']??[]) as $rel=>$hash){if(!is_file($root.'/'.$rel)||!hash_equals($hash,hash_file('sha256',$root.'/'.$rel)))throw new RuntimeException('El archivo '.$rel.' se modificó después. Cancelado para conservar los cambios.');}
  foreach(($m['originals']??[]) as $rel=>$backup){
    $path=$root.'/'.$rel;
    if($backup===null){if(is_file($path)&&!@unlink($path))throw new RuntimeException('No se pudo retirar archivo nuevo '.$rel);}
    else{if(!is_file($backup)||!@copy($backup,$path))throw new RuntimeException('No se pudo restaurar '.$rel);}
  }
  echo "RESTAURADO: archivos anteriores del PDF; no se modificaron órdenes ni inventario.\n";exit;
 }
 foreach($targets as $rel){if(!is_file($src.'/'.substr($rel,4)))throw new RuntimeException('Falta archivo del paquete: '.$rel);}
 lintcode(content($src.'/helpers/gyd_oc_pdf_profesional.php'));
 $modified=mod_pdf($existing);lintcode($modified);
 if($cmd==='--check'){echo "COMPATIBLE: PDF horizontal con logos, PDF con/sin costos, firma de Compras, Dueño y Gerente. Solo archivos, sin cambios SQL.\n";exit;}
 if(!is_dir($backupDir)&&!@mkdir($backupDir,0700,true)&&!is_dir($backupDir))throw new RuntimeException('No se pudo crear carpeta de respaldos.');
 $unique=date('Ymd_His').'_'.bin2hex(random_bytes(4));$bak=$backupDir.DIRECTORY_SEPARATOR.$unique;
 if(!@mkdir($bak,0700,true))throw new RuntimeException('No se pudo crear respaldo de esta instalación.');
 $all=array_merge(['app/helpers/erp_oc_win_pdf.php'],$targets);
 $originals=[];$after=[];
 // Respaldar todos antes de tocar cualquier archivo.
 foreach($all as $rel){$dst=$root.'/'.$rel;if(is_file($dst)){$f=$bak.DIRECTORY_SEPARATOR.str_replace('/','__',$rel);if(!@copy($dst,$f))throw new RuntimeException('No se pudo respaldar '.$rel);$originals[$rel]=$f;}else{$originals[$rel]=null;}}
 try {
  foreach($targets as $rel){
   $dst=$root.'/'.$rel;$dir=dirname($dst);
   if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear '.$dir);
   $from=$src.'/'.substr($rel,4);
   if(!@copy($from,$dst))throw new RuntimeException('No se pudo instalar '.$rel);
   $after[$rel]=hash_file('sha256',$dst);
  }
  if(@file_put_contents($origin,$modified,LOCK_EX)===false)throw new RuntimeException('No se pudo modificar el generador original de PDF.');
  $after['app/helpers/erp_oc_win_pdf.php']=hash_file('sha256',$origin);
  $m=['root'=>$root,'backup'=>$bak,'originals'=>$originals,'after'=>$after,'applied_at'=>date(DATE_ATOM)];
  if(@file_put_contents($manifest,json_encode($m,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false)throw new RuntimeException('No se pudo registrar el manifiesto.');
 }catch(Throwable $e){
  foreach($originals as $rel=>$back){$dst=$root.'/'.$rel;if($back===null)@unlink($dst);elseif(is_file($back))@copy($back,$dst);}
  throw $e;
 }
 echo "APLICADO: PDF de OC rediseñado (A4 horizontal), logos G&D y tres firmas. Respaldo: $bak\n";
 echo "Las órdenes, las existencias y la base de datos no se modificaron.\n";
}catch(Throwable $e){fail($e->getMessage());}
