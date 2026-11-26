<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo se permite CLI.');}
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: --check | --apply | --verify\n");exit(2);}
$root=dirname(__DIR__);
$dest=$root.'/public/erp_oc_flujo.php';
$src=__DIR__.'/fuentes_diseno_portal_gerente/public/erp_oc_flujo.php';
$originalHash='f73cf6b89e4ad84665ae3d732b557a2fdd6839e16e065fd83eae1e1777b3b981';
$updatedHash='7f39459d5815f03c9625ac4b170b2b4c892cb79e36724c59f2f646575e859304';
function failNow(string $reason): never {fwrite(STDERR,"ERROR: $reason\n");exit(1);}
if(!is_file($dest))failNow('No existe public/erp_oc_flujo.php. Instala primero la Fase 1 del flujo de órdenes de compra.');
if(!is_file($src))failNow('No se encontró el archivo de actualización en tools.');
$hash=hash_file('sha256',$dest);
if($mode==='--verify'){
 if($hash===$updatedHash){echo "VERIFICADO: nuevo diseño del portal de gerente instalado.\n";exit(0);}
 failNow('El diseño aún no está instalado o se modificó el archivo posteriormente.');
}
if($hash!==$originalHash && $hash!==$updatedHash)failNow('El módulo tiene cambios distintos a la versión base. No se sobrescribió; comparte el PHP actual para adaptarlo.');
if($mode==='--check'){echo "COMPATIBLE: portal de órdenes identificado. ".($hash===$updatedHash?'Diseño ya instalado.':'Listo para actualizar.')."\n";exit(0);}
if($hash===$updatedHash){echo "YA INSTALADO: no es necesario aplicar de nuevo.\n";exit(0);}
$backup=__DIR__.'/backups_portal_gerente/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!is_dir($backup)&&!mkdir($backup,0775,true))failNow('No fue posible crear la carpeta de respaldo.');
if(!copy($dest,$backup.'/erp_oc_flujo.php'))failNow('No se pudo crear el respaldo, no se aplicaron cambios.');
if(!copy($src,$dest)){copy($backup.'/erp_oc_flujo.php',$dest);failNow('Error de copia; se restauró el respaldo.');}
if(hash_file('sha256',$dest)!==$updatedHash){copy($backup.'/erp_oc_flujo.php',$dest);failNow('Verificación de integridad fallida; restaurado respaldo.');}
echo "APLICADO: nuevo diseño del portal para gerentes y Compras.\n";
echo "RESPALDO: $backup\n";
echo "No se modificaron tablas, registros ni cantidades de inventario.\n";
