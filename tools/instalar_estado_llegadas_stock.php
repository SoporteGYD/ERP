<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: php tools/instalar_estado_llegadas_stock.php --check|--apply|--verify\n");exit(2);}
$root=dirname(__DIR__);$src=__DIR__.'/fuentes_estado_llegadas_stock';
$files=['app/helpers/erp_centro_notificaciones.php','public/erp_notificaciones.php'];
function failEstado(string $reason):never{fwrite(STDERR,"ERROR: $reason\n");exit(1);}
foreach($files as $rel){
  $target=$root.'/'.$rel;$source=$src.'/'.$rel;
  if(!is_file($target)||!is_file($source))failEstado('No se encuentra '.$rel.' en el ERP o el instalador.');
  $now=file_get_contents($target);
  if($rel==='app/helpers/erp_centro_notificaciones.php' && (!str_contains($now,'GYD_OC_LLEGADAS_CAMPANA_V1') || !str_contains($now,'function gdnt_items(')))failEstado('Necesitas instalar primero el módulo de campana de llegadas. No se sobrescribió nada.');
  if($rel==='public/erp_notificaciones.php' && (!str_contains($now,"'OC_LLEGADA'") || !str_contains($now,'erp_notificaciones_lecturas')))failEstado('Pantalla actual de notificaciones incompatible. No se sobrescribió nada.');
  exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($source).' 2>&1',$lint,$code);
  if($code!==0)failEstado('Error de sintaxis en '.$rel.': '.implode(' ',$lint));
}
if($mode==='--check'){
  echo "COMPATIBLE: avisos de llegada y centro de notificaciones reconocidos. No se alteraron archivos.\n";
  echo "Verifica respaldos de la base de datos antes de instalar. Esta actualización NO escribe stock ni cambia facturas.\n";exit(0);
}
if($mode==='--verify'){
  foreach($files as $rel)if(!hash_equals(hash_file('sha256',$src.'/'.$rel),hash_file('sha256',$root.'/'.$rel)))failEstado('Diferente o no actualizado: '.$rel);
  echo "VERIFICADO: los avisos se cierran por aplicación de inventario/POS y las órdenes parciales quedan en Historial con mercancía pendiente.\n";exit(0);
}
$backup=$root.'/tools/respaldos_estado_llegadas_stock/'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
foreach($files as $rel){
 $dest=$backup.'/'.$rel;
 if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0775,true))failEstado('No fue posible crear el respaldo.');
 if(!copy($root.'/'.$rel,$dest))failEstado('No se pudo respaldar '.$rel);
}
echo "RESPALDO: $backup\n";
try{
 foreach($files as $rel){if(!copy($src.'/'.$rel,$root.'/'.$rel))throw new RuntimeException('No se pudo reemplazar '.$rel);}
 echo "APLICADO: estados de llegada automáticos por entrada de factura confirmada; sin modificar inventario ni BD.\n";
}catch(Throwable $e){foreach($files as $rel){if(is_file($backup.'/'.$rel))copy($backup.'/'.$rel,$root.'/'.$rel);}failEstado('Se restauraron archivos: '.$e->getMessage());}
