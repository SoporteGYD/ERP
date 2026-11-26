<?php
declare(strict_types=1);
/** Instalador ERP G&D: lista de precios, solo lectura. */
$modo=$argv[1]??'';
if(!in_array($modo,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: php tools/instalar_lista_precios.php --check|--apply|--verify\n");exit(2);}
$root=dirname(__DIR__);
$source=__DIR__.'/paquete_lista_precios/erp_lista_precios.php';
$dest=$root.'/public/erp_lista_precios.php';
$catalogo=$root.'/public/erp_catalogo_maestro.php';
$necesarios=[$source,$root.'/app/helpers/auth.php',$root.'/app/config/database.php',$catalogo,$root.'/public/erp_catalogo_reportes.php'];
foreach($necesarios as $p){if(!is_file($p)){fwrite(STDERR,"INCOMPATIBLE: Falta $p\n");exit(1);}}
$sourceCode=file_get_contents($source);$actual=is_file($dest)?file_get_contents($dest):null;
$marker='<!-- GYD_LISTA_PRECIOS_V1 -->';
$cCode=file_get_contents($catalogo);
if(strpos($cCode,'href="erp_catalogo_reportes.php"')===false && strpos($cCode,$marker)===false){fwrite(STDERR,"INCOMPATIBLE: no se reconoce navegación de Catálogo Maestro; no se sobrescribe.\n");exit(1);}
if($actual!==null && $actual!==$sourceCode && strpos($actual,'GYD_LISTA_PRECIOS_MODULE_V1')===false){fwrite(STDERR,"INCOMPATIBLE: ya existe un módulo distinto con el mismo nombre.\n");exit(1);}
if($modo==='--check'){echo "COMPATIBLE · Instalación de Lista de precios disponible. No se modificó nada.\n";exit(0);}
if($modo==='--verify'){
 $ok=is_file($dest) && strpos((string)file_get_contents($dest),'GYD_LISTA_PRECIOS_MODULE_V1')!==false && strpos((string)file_get_contents($catalogo),$marker)!==false;
 echo $ok?"VERIFICADO · Módulo y acceso desde Catálogo Maestro.\n":"PENDIENTE · Módulo o acceso no instalado.\n";exit($ok?0:1);
}
$backup=__DIR__.'/respaldos_lista_precios/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if(!is_dir($backup) && !mkdir($backup,0775,true)){fwrite(STDERR,"No se pudo crear respaldo.\n");exit(1);}
if(!copy($catalogo,$backup.'/erp_catalogo_maestro.php')){fwrite(STDERR,"No se pudo respaldar Catálogo Maestro.\n");exit(1);}
if(is_file($dest) && !copy($dest,$backup.'/erp_lista_precios.php')){fwrite(STDERR,"No se pudo respaldar Lista de precios.\n");exit(1);}
$pattern='href="erp_catalogo_reportes.php"';
if(strpos($cCode,$marker)===false){
 $cCode=str_replace($pattern,$marker.'<a href="erp_lista_precios.php" style="background:#e9f5ed;color:#145c3c">Lista de precios · Cajas</a> '.$pattern,$cCode);
}
if(file_put_contents($dest,$sourceCode,LOCK_EX)===false){fwrite(STDERR,"Error al escribir el módulo.\n");exit(1);}
if(file_put_contents($catalogo,$cCode,LOCK_EX)===false){fwrite(STDERR,"Error al actualizar navegación, consulta respaldos.\n");exit(1);}
echo "APLICADO · Lista de precios instalada. Respaldo: $backup\n";
echo "Abre: http://localhost/almacen-farmacia2/public/erp_lista_precios.php\n";
