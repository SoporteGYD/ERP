<?php
declare(strict_types=1);
// Instalador de transicion - NO migra stock ni borra datos.
$root=dirname(__DIR__);$payload=__DIR__.'/payload_oc_factura';
$manifest=json_decode((string)file_get_contents(__DIR__.'/manifest_oc_factura.json'),true);
$mode=$argv[1]??'';
if(!in_array($mode,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: php instalar_transicion_oc_factura.php --check|--apply|--verify\n");exit(2);}
function sha(string $file):string{return is_file($file)?hash_file('sha256',$file):'';}
$ok=true;$changed=[];
foreach($manifest as $rel=>$expected){
 $dest=$root.'/'.$rel;$src=$payload.'/'.$rel;$current=sha($dest);$wanted=sha($src);
 if(!$wanted){echo "FALTA PAYLOAD: $rel\n";$ok=false;continue;}
 if($mode==='--verify'){$pass=$current===$wanted;}else{$pass=$current===$wanted||$current===$expected;}
 if(!$pass){echo "INCOMPATIBLE: $rel - archivo distinto, no sobrescribir.\n";$ok=false;}
 else {echo "OK: $rel".($current===$wanted?' (ya actualizado)':'')."\n";if($current!==$wanted)$changed[]=$rel;}
}
if(!$ok){echo "ABORTADO. Ningun archivo modificado.\n";exit(1);}
if($mode==='--check'){echo "COMPATIBLE. Respaldar base y archivos antes de aplicar.\n";exit(0);}
if($mode==='--verify'){echo "VERIFICADO.\n";exit(0);}
$dir=__DIR__.'/backups/transicion_oc_factura_'.date('Ymd_His');
foreach($changed as $rel){$dst=$root.'/'.$rel;$back=$dir.'/'.$rel;if(!is_dir(dirname($back)))mkdir(dirname($back),0775,true);if(!copy($dst,$back))throw new RuntimeException("No se pudo respaldar: $rel");}
foreach($changed as $rel){$dst=$root.'/'.$rel;$src=$payload.'/'.$rel;if(!copy($src,$dst))throw new RuntimeException("Error al instalar $rel, respaldo en $dir");echo "INSTALADO: $rel\n";}
echo "APLICADO. Respaldo: $dir\n";
