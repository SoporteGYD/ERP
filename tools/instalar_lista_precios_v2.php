<?php
declare(strict_types=1);
/* GYD_INSTALL_PRECIOS_V2 */
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: php tools/instalar_lista_precios_v2.php --check|--apply|--verify\n");exit(2);}
$root=dirname(__DIR__);$catalog=$root.'/public/erp_catalogo_maestro.php';$dest=$root.'/public/erp_lista_precios.php';$src=__DIR__.'/paquete_lista_precios/erp_lista_precios.php';
foreach([$catalog,$src,$root.'/app/helpers/auth.php',$root.'/app/config/database.php'] as $f){if(!is_file($f)){fwrite(STDERR,"INCOMPATIBLE: falta $f\n");exit(1);}}
$old=file_get_contents($catalog);$before=is_file($dest)?file_get_contents($dest):'';
if($before!=='' && !str_contains($before,'GYD_LISTA_PRECIOS_MODULE_V1') && !str_contains($before,'GYD_LISTA_PRECIOS_MODULE_V2')){fwrite(STDERR,"INCOMPATIBLE: módulo existente desconocido, no se reemplazará.\n");exit(1);}
$report='<a href="erp_catalogo_reportes.php" style="background:#dbeafb;color:#153c65">📊 Reportes personalizados</a>';
$prices='<a href="erp_lista_precios.php" style="background:#e9f5ed;color:#145c3c">Lista de precios · Cajas</a>';
// Reparación específica del HTML corrupto que dejó el instalador anterior.
$pattern='~<a\s*(?:<!--\s*GYD_LISTA_PRECIOS_V1\s*-->\s*)?<a\s+href="erp_lista_precios\.php"[^>]*>.*?</a>\s*href="erp_catalogo_reportes\.php"[^>]*>.*?</a>~su';
$repaired=preg_replace($pattern,$prices.$report,$old,-1,$repairedCount);
if($repaired===null){fwrite(STDERR,"INCOMPATIBLE: expresión de reparación falló.\n");exit(1);}
if($repairedCount===0){
 // Alternativa para la variante en la que el marcador aparece antes de la etiqueta rota.
 $pattern2='~<a\s*<!--\s*GYD_LISTA_PRECIOS_V1\s*-->\s*<a\s+href="erp_lista_precios\.php"[^>]*>.*?</a>\s*href="erp_catalogo_reportes\.php"[^>]*>.*?</a>~su';
 $repaired=preg_replace($pattern2,$prices.$report,$repaired,-1,$count2);$repairedCount+=$count2;
}
if($repairedCount===0){
 if(!str_contains($repaired,'href="erp_catalogo_reportes.php"')){fwrite(STDERR,"INCOMPATIBLE: no se encontró el enlace de reportes; no se toca el catálogo.\n");exit(1);}
 if(!str_contains($repaired,'href="erp_lista_precios.php"')){
  $repaired=str_replace($report,$prices.$report,$repaired,$hits);
  if(!$hits){fwrite(STDERR,"INCOMPATIBLE: encabezado desconocido.\n");exit(1);}
 }
}
// Limpieza del marcador que quedó en algunos proyectos tras la primera instalación.
$repaired=str_replace('<!-- GYD_LISTA_PRECIOS_V1 -->','',$repaired);
if(str_contains($repaired,'href="erp_catalogo_reportes.php" style=' ) && str_contains($repaired,'>href="erp_catalogo_reportes.php"')){fwrite(STDERR,"INCOMPATIBLE: el HTML aún parece dañado.\n");exit(1);}
if($mode==='--check'){echo "COMPATIBLE: corregirá encabezado y actualizará Lista de precios, sin tocar BD.\n";exit(0);}
if($mode==='--verify'){
 $ok=str_contains((string)file_get_contents($catalog),$prices) && str_contains((string)file_get_contents($catalog),$report) && str_contains((string)file_get_contents($dest),'GYD_LISTA_PRECIOS_MODULE_V2');
 echo $ok?"VERIFICADO: encabezado correcto y columnas personalizables.\n":"PENDIENTE: falta instalar la V2.\n";exit($ok?0:1);
}
$backup=__DIR__.'/respaldos_lista_precios_v2/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));if(!is_dir($backup) && !mkdir($backup,0775,true)){fwrite(STDERR,"No se pudo crear carpeta de respaldo.\n");exit(1);}
if(!copy($catalog,$backup.'/erp_catalogo_maestro.php')){fwrite(STDERR,"No se pudo respaldar catálogo.\n");exit(1);}
if(is_file($dest)&&!copy($dest,$backup.'/erp_lista_precios.php')){fwrite(STDERR,"No se pudo respaldar módulo.\n");exit(1);}
if(file_put_contents($catalog,$repaired,LOCK_EX)===false || file_put_contents($dest,file_get_contents($src),LOCK_EX)===false){fwrite(STDERR,"Error al escribir. Respaldo: $backup\n");exit(1);}
echo "APLICADO: encabezado reparado y Lista de precios V2 instalada. Respaldo: $backup\n";
