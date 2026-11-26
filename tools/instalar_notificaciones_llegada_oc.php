<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: php tools/instalar_notificaciones_llegada_oc.php --check|--apply|--verify\n");exit(2);}
$root=dirname(__DIR__);$source=__DIR__.'/fuentes_avisos_llegada';
$files=['app/helpers/erp_centro_notificaciones.php','public/erp_notificaciones.php','public/erp_oc_flujo.php'];
function stopInstall(string $s):never{fwrite(STDERR,"ERROR: $s\n");exit(1);}
foreach($files as $rel)if(!is_file($source.'/'.$rel))stopInstall('Falta archivo del instalador: '.$rel);
if(!is_file($root.'/app/config/database.php')||!is_file($root.'/app/helpers/auth.php'))stopInstall('No se reconoce la carpeta raíz del ERP.');
foreach($files as $rel) {
 $path=$root.'/'.$rel;
 if(!is_file($path))stopInstall('No existe en el ERP: '.$rel);
 $text=file_get_contents($path);
 if($rel==='public/erp_oc_flujo.php' && (strpos($text,"'LLEGADA'")===false || strpos($text,"'accion'")===false))stopInstall('Primero instala la Fase 1 de OC y el diseño del portal del gerente.');
 if($rel==='app/helpers/erp_centro_notificaciones.php' && strpos($text,'function gdnt_items(')===false)stopInstall('Centro de notificaciones incompatible.');
 if($rel==='public/erp_notificaciones.php' && strpos($text,'erp_notificaciones_lecturas')===false)stopInstall('Página de notificaciones incompatible.');
}
foreach($files as $rel){$cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($source.'/'.$rel).' 2>&1';exec($cmd,$lines,$status);if($status!==0)stopInstall('Error PHP en '.$rel.': '.implode(' ',$lines));}
if($mode==='--check'){
 echo "COMPATIBLE: módulos de OC y centro de notificaciones reconocidos; no se modificaron archivos.\n";
 echo "AVISO: antes de --apply haz respaldo de tu base de datos; la actualización no modifica existencias.\n";exit(0);
}
if($mode==='--verify'){
 foreach($files as $rel)if(hash_file('sha256',$root.'/'.$rel)!==hash_file('sha256',$source.'/'.$rel))stopInstall('Archivo diferente o pendiente: '.$rel);
 echo "VERIFICADO: avisos de llegadas integrados en la campana y portal del gerente.\n";exit(0);
}
$backup=$root.'/tools/respaldos_notificaciones_llegada_oc/'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
foreach($files as $rel){$dst=$backup.'/'.$rel;if(!is_dir(dirname($dst))&&!mkdir(dirname($dst),0775,true))stopInstall('No se creó la carpeta de respaldo.');if(!copy($root.'/'.$rel,$dst))stopInstall('No se respaldó '.$rel);}
echo "RESPALDO: $backup\n";
try{
 foreach($files as $rel){if(!copy($source.'/'.$rel,$root.'/'.$rel))throw new RuntimeException('No se pudo instalar '.$rel);}
 echo "APLICADO: campana Compras/Admin/Dirección muestra avisos LLEGADA individuales y conserva historial de lectura.\n";
 echo "No se registraron entradas, ni se modificaron facturas, costos, inventario o históricos.\n";
}catch(Throwable $e){foreach($files as $rel){copy($backup.'/'.$rel,$root.'/'.$rel);}stopInstall('Restaurados los respaldos por error: '.$e->getMessage());}
