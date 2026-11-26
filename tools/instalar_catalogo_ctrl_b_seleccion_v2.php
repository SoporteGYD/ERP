<?php
declare(strict_types=1);
/** G&D ERP - corrección incremental de selección Ctrl+B. No modifica base de datos. */
ini_set('display_errors', '1');
error_reporting(E_ALL);
$mode=$argv[1]??'--help';
if(!in_array($mode,['--help','--check','--apply','--verify','--rollback'],true)){
    fwrite(STDERR,"Parámetro inválido. Usa --check, --apply, --verify o --rollback.\n");exit(2);
}
$root=realpath(__DIR__.'/..');
if(!$root){fwrite(STDERR,"No se encontró la raíz del ERP.\n");exit(2);}
$root=str_replace('\\','/',$root);
$rel='public/assets/js/gyd_catalogo_global.js';
$target=$root.'/'.$rel;
$source=__DIR__.'/gyd_catalogo_correccion_src/gyd_catalogo_global.js';
$base=getenv('LOCALAPPDATA') ?: (getenv('USERPROFILE')?rtrim(getenv('USERPROFILE'),'\\/').'/AppData/Local':sys_get_temp_dir());
$home=str_replace('\\','/',rtrim($base,'\\/')).'/GYD/RespaldoCatalogoSeleccionV2';
$manifestPath=$home.'/ultima_correccion.json';
function gcs_fail(string $message):never{fwrite(STDERR,'ERROR: '.$message."\n");exit(2);}
function gcs_read(string $file):string{$s=@file_get_contents($file);if($s===false)gcs_fail('No se pudo leer: '.$file);return $s;}
function gcs_write(string $file,string $s):void{
 if(!is_dir(dirname($file))&&!mkdir(dirname($file),0700,true)&&!is_dir(dirname($file)))gcs_fail('No se pudo crear carpeta de respaldo.');
 $tmp=$file.'.tmp.'.bin2hex(random_bytes(4));
 if(file_put_contents($tmp,$s,LOCK_EX)===false)gcs_fail('No se pudo escribir archivo temporal.');
 if(!@rename($tmp,$file)){if(!@copy($tmp,$file)){@unlink($tmp);gcs_fail('No se pudo instalar '.$file);}@unlink($tmp);}
}
function gcs_meta(string $path):array{
 if(!is_file($path))gcs_fail('Aún no hay una corrección registrada.');
 $x=json_decode(gcs_read($path),true);if(!is_array($x)||!isset($x['root'],$x['before'],$x['after'],$x['backup']))gcs_fail('Registro de instalación inválido.');
 return $x;
}
if($mode==='--help') {echo "G&D Catálogo Ctrl+B - integración de selección con los buscadores existentes.\n--check, --apply, --verify, --rollback\n";exit;}
if(!is_file($target)||!is_file($source))gcs_fail('Falta el JavaScript del catálogo global original o el archivo del paquete. Instala primero la actualización global Ctrl+B.');
$old=gcs_read($target);$new=gcs_read($source);
$patched=str_contains($old,"version:'global-integracion-v2'");
$original=str_contains($old,"version:'global'")&&str_contains($old,'GYD_CatalogoGlobal')&&str_contains($old,'erp_catalogo_global_api.php');
if(!$patched&&!$original)gcs_fail('El catálogo instalado fue personalizado o no se reconoce. No se sobrescribió.');
if($mode==='--check'){
 echo "ERP: $root\n";
 echo $patched?"CORREGIDO: ya se detectó la versión de selección v2.\n":"COMPATIBLE: se detectó el modal Ctrl+B original.\n";
 echo "Único archivo que puede cambiar: $rel\n";
 echo "Conserva el backend y el instalador anteriores. No cambia existencias, facturas ni pedidos.\n";
 if(is_file($manifestPath))echo "Ya hay una corrección registrada. Usa --verify o --rollback.\n";
 exit;
}
if($mode==='--verify'){
 $m=gcs_meta($manifestPath);
 if($m['root']!==$root)gcs_fail('El registro pertenece a otro ERP.');
 if(!hash_equals($m['after'],hash('sha256',$old)))gcs_fail('El JS cambió después de instalar la corrección. Revisa modificaciones antes de continuar.');
 echo "VERIFICADO: Ctrl+B puede seleccionar campos de búsqueda y lanzar los filtros existentes.\n";
 echo "Comprueba el resultado en Chrome con tu catálogo y tu base local.\n";
 exit;
}
if($mode==='--rollback'){
 $m=gcs_meta($manifestPath);
 if($m['root']!==$root||!hash_equals($m['after'],hash('sha256',$old)))gcs_fail('Los archivos no corresponden al respaldo. No se restauró nada.');
 if(!is_file($m['backup'])||!hash_equals($m['before'],hash_file('sha256',$m['backup'])))gcs_fail('El respaldo no está íntegro.');
 gcs_write($target,gcs_read($m['backup']));
 @rename($manifestPath,$home.'/restaurado_'.date('Ymd_His').'.json');
 echo "RESTAURADO: se recuperó el JavaScript del catálogo anterior.\n";exit;
}
// --apply
if(is_file($manifestPath))gcs_fail('Ya se instaló esta corrección. Usa --verify o --rollback.');
if($patched){echo "Ya está instalado el mismo código v2, sin cambios.\n";exit;}
$backup=$home.'/anteriores/'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'/gyd_catalogo_global.js';
gcs_write($backup,$old);
if(!hash_equals(hash('sha256',$old),hash_file('sha256',$backup)))gcs_fail('No se pudo verificar el respaldo.');
gcs_write($target,$new);
$m=['root'=>$root,'file'=>$rel,'backup'=>$backup,'before'=>hash('sha256',$old),'after'=>hash('sha256',$new),'created_at'=>date(DATE_ATOM)];
gcs_write($manifestPath,json_encode($m,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo "INSTALADO: selección desde Ctrl+B hacia el buscador existente y búsqueda automática compatible.\n";
echo "RESPALDO: $backup\n";
echo "No se alteró inventario ni la base de datos. Ejecuta --verify y Ctrl+F5 en Chrome.\n";
