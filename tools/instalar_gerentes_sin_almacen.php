<?php
declare(strict_types=1);
/** Separar rol GERENTE de asignacion de almacenes para OC. */
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: --check | --apply | --verify\n");exit(2);}
$root=dirname(__DIR__);$src=__DIR__.'/fuentes_gerentes_sin_almacen/';
$expected=['public/usuario.php'=>'f616f8cd766e50c8f032a985c8ccc7f9ef59d8f621364d86b1200d5f1eb85e53','app/controllers/UsuarioController.php'=>'687d1526bd7e78498a11da0a327151b25bdffd11d109dfeeed75457ad9e53605'];
$ready=true;$need=[];
foreach($expected as $rel=>$hash){
 $target=$root.'/'.$rel;$new=$src.$rel;
 if(!is_file($target)||!is_file($new)){echo "ERROR: Falta archivo $rel\n";$ready=false;continue;}
 $curr=hash_file('sha256',$target);$updated=hash_file('sha256',$new);
 $need[$rel]=($curr!==$updated);
 if($curr!==$hash && $curr!==$updated){echo "ERROR: $rel tiene modificaciones diferentes de la version revisada. No se sobrescribira.\n";$ready=false;}
}
if($mode==='--verify'){
 if($ready && !in_array(true,$need,true)){echo "VERIFICADO: Ajuste GERENTE instalado.\n";exit(0);}
 echo "NO VERIFICADO.\n";exit(1);
}
if(!$ready){echo "NO COMPATIBLE: no se modifico ningun archivo.\n";exit(1);}
if($mode==='--check'){echo "COMPATIBLE: excepcion GERENTE sin almacen obligatorio.\n";exit(0);}
$backup=__DIR__.'/respaldos_gerentes/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
foreach($need as $rel=>$apply){
 if(!$apply)continue;
 $target=$root.'/'.$rel;$new=$src.$rel;$bak=$backup.'/'.$rel;
 if(!is_dir(dirname($bak))&&!mkdir(dirname($bak),0775,true)&&!is_dir(dirname($bak))){echo "ERROR: No se pudo crear carpeta de respaldo.\n";exit(1);}
 if(!copy($target,$bak)){echo "ERROR: No se pudo respaldar $rel\n";exit(1);}
 if(file_put_contents($target,file_get_contents($new),LOCK_EX)===false){echo "ERROR: Escritura fallida para $rel; revisa respaldo $bak\n";exit(1);}
 echo "ACTUALIZADO: $rel\n";
}
echo "APLICADO. Respaldo: $backup\n";
