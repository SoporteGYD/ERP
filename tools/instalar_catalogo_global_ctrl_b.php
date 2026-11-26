<?php
declare(strict_types=1);
/** Instalador incremental - catálogo Ctrl+B para ERP G&D. No toca tablas ni existencias. */
ini_set('display_errors','1');error_reporting(E_ALL);
$mode=$argv[1]??'--help';
$root=realpath(__DIR__.'/..');
if(!$root){fwrite(STDERR,"No se encontró la raíz del ERP.\n");exit(2);}
$root=str_replace('\\','/',$root);
$src=__DIR__.'/gyd_catalogo_global_src';
$jsRel='public/assets/js/gyd_catalogo_global.js';
$apiRel='public/erp_catalogo_global_api.php';
$jsMarker='gyd_catalogo_global.js';
$jsTag="\n<!-- GYD_CATALOGO_GLOBAL_CTRL_B -->\n<script defer src=\"assets/js/gyd_catalogo_global.js\"></script>\n";
// La plantilla principal se ejecuta con un path público relativo desde /public.
$home=rtrim((string)(getenv('LOCALAPPDATA')?:getenv('USERPROFILE')?:(sys_get_temp_dir())),"\\/");
if(strtoupper(substr(PHP_OS,0,3))==='WIN' && !getenv('LOCALAPPDATA')) $home.='/AppData/Local';
$backupHome=str_replace('\\','/',$home).'/GYD/RespaldosCatalogoCtrlB';
$last=$backupHome.'/ultima_instalacion.json';

function cgo_fail(string $msg,int $code=2):never{fwrite(STDERR,"ERROR: $msg\n");exit($code);}
function cgo_read(string $path):string{$content=file_get_contents($path);if($content===false)cgo_fail('No se puede leer '.$path);return $content;}
function cgo_mkdir(string $path):void{if(!is_dir($path)&&!mkdir($path,0700,true)&&!is_dir($path))cgo_fail('No se pudo crear '.$path);}
function cgo_save(string $path,string $data):void{
 cgo_mkdir(dirname($path));$tmp=$path.'.gydtmp.'.bin2hex(random_bytes(4));
 if(file_put_contents($tmp,$data,LOCK_EX)===false)cgo_fail('No se pudo escribir '.$tmp);
 if(!@rename($tmp,$path)){
  if(!@copy($tmp,$path)){@unlink($tmp);cgo_fail('No se pudo actualizar '.$path);}
  @unlink($tmp);
 }
}
/** Insertar solo en HTML literal, nunca dentro de strings PHP ni respuestas JSON. */
function cgo_insert(string $source,string $tag,string $closing):?string{
 if(str_contains($source,'GYD_CATALOGO_GLOBAL_CTRL_B')||str_contains($source,'gyd_catalogo_global.js'))return null;
 $p=0;$positions=[];$hasHtml=false;
 foreach(token_get_all($source) as $t){
  $segment=is_array($t)?$t[1]:$t;
  if(is_array($t)&&$t[0]===T_INLINE_HTML){
   if(preg_match('/<!doctype\s+html|<html\b|<body\b/i',$segment))$hasHtml=true;
   if(preg_match_all('/<\/'.preg_quote($closing,'/').'\s*>/i',$segment,$m,PREG_OFFSET_CAPTURE)){
    foreach($m[0] as $hit)$positions[]=$p+$hit[1];
   }
  }
  $p+=strlen($segment);
 }
 if(!$positions||!$hasHtml)return null;
 $offset=end($positions);
 return substr($source,0,$offset).$tag.substr($source,$offset);
}
function cgo_plan(string $root,string $jsTag):array{
 $planned=[];$skips=[];
 // Todas las pantallas clásicas que usan header.php.
 foreach(['app/views/layouts/header.php','app/helpers/erp_facturas.php'] as $rel){
  $file=$root.'/'.$rel;
  if(!is_file($file)){$skips[]="$rel (no existe)";continue;}
  $old=cgo_read($file);
  $new=cgo_insert($old,$jsTag,'head');
  if($new!==null){$planned[$rel]=$new;}
  elseif(str_contains($old,'gyd_catalogo_global.js')){$skips[]="$rel (ya integrado)";}
  else{$skips[]="$rel (sin punto de inserción seguro)";}
 }
 // Plantillas autónomas. No tocar controladores, APIs, descargas ni tickets imprimibles.
 $pages=glob($root.'/public/*.php')?:[];
 foreach($pages as $file){
  $name=basename($file);
  if(preg_match('/(^api_|_api\.php$|^exportar|_pdf\.php$|^pos_ticket|_ticket\.php$|^imprimir|^login|^logout|^create_admin|^session_|^erp_catalogo_global_api\.php$)/i',$name))continue;
  if(is_link($file))continue;
  $rel='public/'.$name;$old=cgo_read($file);
  // Para formularios reales o páginas ERP renderizadas; un JSON nunca tiene body HTML literal.
  $new=cgo_insert($old,$jsTag,'body');
  if($new!==null){$planned[$rel]=$new;}
  elseif(str_contains($old,'gyd_catalogo_global.js')){$skips[]="$rel (ya integrado)";}
 }
 return [$planned,$skips];
}
function cgo_recorded(string $file):array{
 if(!is_file($file))cgo_fail('No hay una instalación anterior registrada para verificar o restaurar.');
 $x=json_decode(cgo_read($file),true);if(!is_array($x)||!isset($x['dir'],$x['changes']))cgo_fail('Manifest inválido.');
 return $x;
}
function cgo_verify(string $root,string $last):void{
 $m=cgo_recorded($last);$error=0;
 foreach($m['changes'] as $rel=>$desc){$file=$root.'/'.$rel;
  if(!is_file($file)||hash_file('sha256',$file)!==($desc['after']??'')){
   echo "FALLO $rel: archivo ausente o fue editado después de instalar.\n";$error++;
  }
 }
 if($error)cgo_fail("Verificación incompleta ($error cambios distintos). No fuerces una restauración.",3);
 echo "VERIFICADO: ".count($m['changes'])." archivos; catálogo y atajo Ctrl+B instalados.\n";
 echo "Los resultados de búsqueda se verificarán al usar el ERP conectado a su base.\n";
}
if($mode==='--help'){
 echo "G&D - Instalar búsqueda de catálogo Ctrl+B\n";
 echo "--check · --apply · --verify · --rollback\n";
 exit;
}
if(!in_array($mode,['--check','--apply','--verify','--rollback'],true))cgo_fail('Parámetro desconocido.');
if($mode==='--verify'){cgo_verify($root,$last);exit;}
if($mode==='--rollback'){
 $m=cgo_recorded($last);$changed=$m['changes'];
 foreach($changed as $rel=>$desc){$dest=$root.'/'.$rel;
  if(!is_file($dest)||hash_file('sha256',$dest)!==$desc['after'])cgo_fail("No se restaura $rel porque se modificó después de instalar. Revisa antes de continuar.");
  if(($desc['created']??false)===false&&!is_file($m['dir'].'/originales/'.$rel))cgo_fail("No existe respaldo de $rel.");
 }
 foreach($changed as $rel=>$desc){$dest=$root.'/'.$rel;
  if($desc['created']??false){unlink($dest);echo "RETIRADO $rel\n";}
  else{cgo_save($dest,cgo_read($m['dir'].'/originales/'.$rel));echo "RESTAURADO $rel\n";}
 }
 @rename($last,$m['dir'].'/instalacion_restaurada.json');
 echo "RESTAURADO: ajustes Ctrl+B revertidos sin tocar la base de datos.\n";exit;
}
foreach([$src.'/erp_catalogo_global_api.php',$src.'/gyd_catalogo_global.js'] as $file)if(!is_file($file))cgo_fail('Falta archivo de instalación: '.$file);
[$planned,$skips]=cgo_plan($root,$jsTag);
$existing=[];
foreach([$jsRel,$apiRel] as $rel){if(is_file($root.'/'.$rel))$existing[]=$rel;}
if($mode==='--check'){
 echo "CARPETA ERP: $root\n";
 echo "Pantallas listas para integrar: ".count($planned)."\n";
 echo "Componentes de búsqueda: API autenticada + JS global.\n";
 if($existing)echo "NOTA: archivos de catálogo ya presentes: ".implode(', ',$existing)."\n";
 if(count($planned)<8){echo "INCOMPATIBLE: pocas plantillas detectadas; revisa si es el proyecto ERP correcto.\n";exit(3);}
 echo "COMPATIBLE: no se cambiarán tablas, existencias, compras, ventas ni permisos de usuarios.\n";
 echo "El instalador realizará respaldos fuera de htdocs: $backupHome\n";
 exit;
}
// --apply
if(is_file($last))cgo_fail('Ya existe una instalación Ctrl+B registrada. Usa --verify, --rollback, o revisa el manifiesto.');
if(count($planned)<8)cgo_fail('No se reconocieron suficientes plantillas. No se aplicó nada.');
foreach([$jsRel=>$src.'/gyd_catalogo_global.js',$apiRel=>$src.'/erp_catalogo_global_api.php'] as $rel=>$from){
 if(is_file($root.'/'.$rel)&&hash_file('sha256',$root.'/'.$rel)!==hash_file('sha256',$from))
  cgo_fail('Existe una versión diferente en '.$rel.'. Detenido para no sobrescribir personalizaciones.');
}
$dir=$backupHome.'/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));cgo_mkdir($dir.'/originales');
$manifest=['root'=>$root,'dir'=>$dir,'installed_at'=>date(DATE_ATOM),'changes'=>[]];
$write=$planned;
$write[$jsRel]=cgo_read($src.'/gyd_catalogo_global.js');
$write[$apiRel]=cgo_read($src.'/erp_catalogo_global_api.php');
// Primero conservar todo en disco. Si falla un respaldo NO se comienza a escribir.
foreach($write as $rel=>$new){$file=$root.'/'.$rel;$old=is_file($file)?cgo_read($file):null;
 if($old!==null)cgo_save($dir.'/originales/'.$rel,$old);
 $manifest['changes'][$rel]=['created'=>$old===null,'before'=>$old===null?null:hash('sha256',$old),'after'=>hash('sha256',$new)];
}
cgo_save($dir.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$written=[];
try{
 foreach($write as $rel=>$new){cgo_save($root.'/'.$rel,$new);$written[]=$rel;}
 cgo_save($last,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}catch(Throwable $e){
 foreach(array_reverse($written) as $rel){$file=$root.'/'.$rel;
  if($manifest['changes'][$rel]['created'])@unlink($file);
  else @copy($dir.'/originales/'.$rel,$file);
 }
 cgo_fail('La instalación falló y se intentó restaurar: '.$e->getMessage());
}
echo "INSTALADO: ".count($planned)." pantallas + API + JS.\n";
echo "RESGUARDO PRIVADO: $dir\n";
echo "No se modificó ninguna tabla ni existencia. Ejecuta --verify.\n";
