<?php
declare(strict_types=1);
/**
 * G&D ERP - A4 vertical en el PDF existente de Órdenes de Compra.
 * Operaciones exclusivamente sobre plantillas e imágenes; no se conecta a MySQL.
 * Modos: --check, --apply, --verify, --rollback
 */
if (PHP_SAPI !== 'cli') {http_response_code(403);exit('Sólo línea de comandos.');}
$root=dirname(__DIR__);
$mode=$argv[1]??'--help';
$relHelper='app/helpers/gyd_oc_pdf_profesional.php';
$pathHelper=$root.'/'.$relHelper;
$pathOrigin=$root.'/app/helpers/erp_oc_win_pdf.php';
$sourceHelper=$root.'/tools/gyd_oc_vertical_src/app/helpers/gyd_oc_pdf_profesional.php';
$logoRelative=['app/assets/oc_pdf/gyd_farmacia.jpg','app/assets/oc_pdf/gyd_mascota.jpg'];
$backupRoot=(getenv('LOCALAPPDATA') ?: sys_get_temp_dir()).DIRECTORY_SEPARATOR.'GYD'.DIRECTORY_SEPARATOR.'Respaldos_Diseno_OC_Vertical';
$manifest=$backupRoot.DIRECTORY_SEPARATOR.'ultima_instalacion.json';
function vFail(string $msg):never{fwrite(STDERR,'ERROR: '.$msg.PHP_EOL);exit(1);}
function vRead(string $path):string{$data=@file_get_contents($path);if($data===false)throw new RuntimeException('No se puede leer: '.$path);return $data;}
function vLint(string $code):void{
    $path=tempnam(sys_get_temp_dir(),'gyda4_');if($path===false)throw new RuntimeException('No se pudo crear archivo temporal de sintaxis.');
    file_put_contents($path,$code);
    $lines=[];$status=1;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1',$lines,$status);
    @unlink($path);
    if($status!==0)throw new RuntimeException('Error de sintaxis: '.implode(' ',$lines));
}
function vValidate(string $old, string $pdfOrigin, string $fresh):void{
    // Reutilizar el enlace ya instalado; no reemplazar el código de la OC ni cambiar datos.
    if(!str_contains($pdfOrigin,"require_once __DIR__.'/gyd_oc_pdf_profesional.php';") ||
       !str_contains($pdfOrigin,'return gyd_oc_pdf_profesional($o,$items,$mostrarCostos);')){
        throw new RuntimeException('Falta la integración previa del PDF profesional. Instala primero el diseño con gerente o comparte el archivo erp_oc_win_pdf.php. No se sobrescribe.');
    }
    if(!str_contains($old,'class GydOcPdfCanvas') ||
       !preg_match('/function\s+gyd_oc_pdf_profesional\s*\(\s*array\s+\$o\s*,\s*array\s+\$items\s*,\s*bool\s+\$mostrarCostos\s*=\s*true\s*\)/', $old)){
        throw new RuntimeException('No se reconoce la plantilla profesional existente. No se sobrescribe.');
    }
    if(!str_contains($fresh,'GYD_OC_A4_VERTICAL_V3_15_ARTICULOS') ||
       !str_contains($fresh,'public const W=595.0;') ||
       !str_contains($fresh,'public const H=842.0;') ||
       !str_contains($fresh,"'RECIBIÓ (GERENTE)'")){
        throw new RuntimeException('El archivo vertical del paquete está incompleto.');
    }
    vLint($fresh);vLint($pdfOrigin);
}
try{
    if(!in_array($mode,['--check','--apply','--verify','--rollback','--help'],true))throw new RuntimeException('Argumento no reconocido.');
    if($mode==='--help'){
        echo "Uso: php tools/instalar_diseno_oc_vertical.php --check|--apply|--verify|--rollback\n";
        exit;
    }
    if(!is_file($pathOrigin)||!is_file($pathHelper)) throw new RuntimeException('Falta un archivo del PDF actual. No se modifica ningún archivo.');
    $origin=vRead($pathOrigin);
    $old=vRead($pathHelper);
    if($mode==='--verify'){
        if(!str_contains($old,'GYD_OC_A4_VERTICAL_V3_15_ARTICULOS'))throw new RuntimeException('Aún no está instalada la plantilla A4 vertical.');
        if(!str_contains($old,'public const W=595.0;')||!str_contains($old,'public const H=842.0;'))throw new RuntimeException('El tamaño de página no es A4 vertical.');
        if(!str_contains($origin,'gyd_oc_pdf_profesional'))throw new RuntimeException('El ERP no está conectado a la plantilla.');
        foreach($logoRelative as $rel){
            if(!is_file($root.'/'.$rel)||@getimagesize($root.'/'.$rel)===false)throw new RuntimeException('Logotipo inexistente o inválido: '.$rel);
        }
        vLint($old);vLint($origin);
        echo "VERIFICADO: PDF A4 vertical, encabezado centrado, logotipos ampliados, 15 artículos y tres firmas; con/sin costos.\n";
        exit;
    }
    if($mode==='--rollback'){
        if(!is_file($manifest))throw new RuntimeException('No hay respaldo de instalación registrado.');
        $m=json_decode(vRead($manifest),true,512,JSON_THROW_ON_ERROR);
        if(($m['root']??'')!==$root)throw new RuntimeException('El respaldo corresponde a otra carpeta de proyecto.');
        foreach(($m['after']??[]) as $rel=>$hash){
            if(!is_file($root.'/'.$rel)||!hash_equals((string)$hash,hash_file('sha256',$root.'/'.$rel)))throw new RuntimeException('Cambios posteriores detectados en '.$rel.'. No se restaura automáticamente.');
        }
        foreach(($m['before']??[]) as $rel=>$backup){
            $dest=$root.'/'.$rel;
            if($backup===null){if(is_file($dest)&&!@unlink($dest))throw new RuntimeException('No se pudo retirar '.$rel);}
            else{if(!is_file($backup)||!@copy($backup,$dest))throw new RuntimeException('No se pudo restaurar '.$rel);}
        }
        echo "RESTAURADO: PDF anterior y logos previos; los otros módulos permanecen intactos.\n";
        exit;
    }
    if(!is_file($sourceHelper))throw new RuntimeException('Falta generador vertical en el paquete; copia toda la carpeta tools.');
    $fresh=vRead($sourceHelper);
    if(str_contains($old,'GYD_OC_A4_VERTICAL_V3_15_ARTICULOS'))throw new RuntimeException('La versión compacta de 15 artículos ya está instalada. Utiliza --verify.');
    vValidate($old,$origin,$fresh);
    foreach($logoRelative as $rel){
        $dest=$root.'/'.$rel;
        $src=$root.'/tools/gyd_oc_vertical_src/'.$rel;
        if(!is_file($src)||@getimagesize($src)===false)throw new RuntimeException('Falta logotipo en el paquete: '.$rel);
        if(is_file($dest)&&@getimagesize($dest)===false)throw new RuntimeException('El logotipo instalado está dañado; no se reemplaza automáticamente: '.$rel);
    }
    if($mode==='--check'){
        echo "COMPATIBLE: el ERP ya está integrado al PDF profesional. Se actualizará sólo la plantilla a A4 vertical compacta para 15 artículos, sin tocar SQL, OC ni inventarios.\n";
        foreach($logoRelative as $rel){if(!is_file($root.'/'.$rel))echo "Se instalará el logo faltante: {$rel}\n";}
        exit;
    }
    if(!is_dir($backupRoot)&&!@mkdir($backupRoot,0700,true)&&!is_dir($backupRoot))throw new RuntimeException('No se pudo crear la carpeta de respaldos.');
    $backupDir=$backupRoot.DIRECTORY_SEPARATOR.date('Ymd_His').'_'.bin2hex(random_bytes(4));
    if(!@mkdir($backupDir,0700,true))throw new RuntimeException('No se pudo crear el respaldo.');
    $changes=[$relHelper];
    foreach($logoRelative as $rel){if(!is_file($root.'/'.$rel))$changes[]=$rel;}
    $before=[];$after=[];
    foreach($changes as $rel){
        $file=$root.'/'.$rel;
        if(is_file($file)){
            $backup=$backupDir.DIRECTORY_SEPARATOR.str_replace('/','__',$rel);
            if(!@copy($file,$backup))throw new RuntimeException('No se pudo respaldar '.$rel);
            $before[$rel]=$backup;
        }else{$before[$rel]=null;}
    }
    try{
        foreach($changes as $rel){
            $source=$rel===$relHelper?$sourceHelper:$root.'/tools/gyd_oc_vertical_src/'.$rel;
            $file=$root.'/'.$rel;
            $dir=dirname($file);
            if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear la ruta de destino '.$dir);
            $temp=$file.'.gyd_vert_'.bin2hex(random_bytes(5)).'.tmp';
            if(!@copy($source,$temp))throw new RuntimeException('No se pudo preparar temporal de '.$rel);
            if(!@rename($temp,$file)){@unlink($temp);throw new RuntimeException('No se pudo reemplazar '.$rel);}
            $after[$rel]=hash_file('sha256',$file);
        }
        $data=['root'=>$root,'before'=>$before,'after'=>$after,'applied_at'=>date(DATE_ATOM)];
        if(@file_put_contents($manifest,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))===false)throw new RuntimeException('No se pudo registrar el manifiesto de respaldo.');
    }catch(Throwable $e){
        foreach($before as $rel=>$original){
            $file=$root.'/'.$rel;if($original===null)@unlink($file);elseif(is_file($original))@copy($original,$file);
        }
        throw $e;
    }
    echo "APLICADO: la OC ahora se imprime en A4 VERTICAL con al menos 15 productos, logotipos grandes y tres firmas.\n";
    echo "Respaldo: {$backupDir}\n";
    echo "Sin cambios de base de datos, existencias ni órdenes almacenadas.\n";
}catch(Throwable $e){vFail($e->getMessage());}
