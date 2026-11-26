<?php
declare(strict_types=1);
/** Ajuste focalizado del centrado de las firmas en PDF de OC. No necesita ni modifica BD. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo línea de comandos.'); }
$root = dirname(__DIR__);
$target = $root.'/app/helpers/erp_oc_win_pdf.php';
$backupRoot = (getenv('LOCALAPPDATA') ?: sys_get_temp_dir()).DIRECTORY_SEPARATOR.'GYD'.DIRECTORY_SEPARATOR.'Respaldos_Firmas_OC_Centradas';
$manifest = $backupRoot.DIRECTORY_SEPARATOR.'ultima_aplicacion.json';
$mode = $argv[1] ?? '--help';
function godie(string $message): never { fwrite(STDERR, 'ERROR: '.$message.PHP_EOL); exit(1); }
function goread(string $file): string { $content=@file_get_contents($file); if ($content===false) throw new RuntimeException('No se pudo leer '.$file); return $content; }
function gocenter_apply_to_string(string $src): string {
    if (str_contains($src, 'GYD_OC_FIRMAS_CENTRADAS_V1')) throw new RuntimeException('El centrado ya está integrado en el PDF; utiliza --verify.');
    if (substr_count($src,'function ocw_pdf_compose(')!==1 || substr_count($src,'function ocw_pdf_t(')!==1) throw new RuntimeException('Esta versión del generador PDF no coincide con la estructura esperada.');
    if (!str_contains($src, 'Autorizacion (firma)') || !str_contains($src, 'SIN DESIGNAR')) throw new RuntimeException('Primero instala correctamente el firmante Dueño de las órdenes (V3). El PDF no contiene los marcadores esperados.');
    $positions=[
        ['label Emitió','~ocw_pdf_t\(\s*[0-9]+(?:\.[0-9]+)?\s*,\s*\$sigY\s*\+\s*18\s*,\s*(\x27Emitio\x27|\x27Emitió\x27)\s*,\s*9\s*,\s*true\s*\)~u',static fn(array $m):string=>'gyd_oc_pdf_centrar_texto(175.0,$sigY+18,'.$m[1].',9,true)'],
        ['label Autorización','~ocw_pdf_t\(\s*[0-9]+(?:\.[0-9]+)?\s*,\s*\$sigY\s*\+\s*18\s*,\s*\$tituloSegundaFirma\s*,\s*9\s*,\s*true\s*\)~',static fn(array $m):string=>'gyd_oc_pdf_centrar_texto(403.5,$sigY+18,$tituloSegundaFirma,9,true)'],
        ['nombre Emitió','~ocw_pdf_t\(\s*[0-9]+(?:\.[0-9]+)?\s*,\s*\$sigY\s*,\s*mb_substr\(\s*\$emitio\s*,\s*0\s*,\s*40\s*\)\s*,\s*9\s*,\s*true\s*\)~',static fn(array $m):string=>'gyd_oc_pdf_centrar_texto(175.0,$sigY,mb_substr($emitio,0,40),9,true)'],
        ['nombre del dueño','~ocw_pdf_t\(\s*[0-9]+(?:\.[0-9]+)?\s*,\s*\$sigY\s*,\s*mb_substr\(\s*\$autorizo\s*,\s*0\s*,\s*40\s*\)\s*,\s*9\s*,\s*true\s*\)~',static fn(array $m):string=>'gyd_oc_pdf_centrar_texto(403.5,$sigY,mb_substr($autorizo,0,40),9,true)'],
        ['nota de firma','~ocw_pdf_t\(\s*[0-9]+(?:\.[0-9]+)?\s*,\s*\$sigY\s*-\s*15\s*,\s*(\x27Nombre\s+(?:designado|asignado);\s*firma\s+manuscrita\s+pendiente\x27)\s*,\s*7\s*\)~u',static fn(array $m):string=>'gyd_oc_pdf_centrar_texto(403.5,$sigY-15,'.$m[1].',7)'],
        ['Vo.Bo.','~ocw_pdf_t\(\s*[0-9]+(?:\.[0-9]+)?\s*,\s*55\s*,\s*(\x27Vo\.Bo\.\x27)\s*,\s*9\s*,\s*true\s*\)~',static fn(array $m):string=>'gyd_oc_pdf_centrar_texto(291.0,55,'.$m[1].',9,true)'],
    ];
    foreach($positions as [$label,$pattern,$replacement]) {
        $matches=preg_match_all($pattern,$src);
        if($matches!==1) throw new RuntimeException('No se encontró una referencia única para «'.$label.'» ('.$matches.'). No se sobrescribe el PDF.');
        $src=preg_replace_callback($pattern,$replacement,$src,1,$applied);
        if(!is_string($src) || $applied!==1) throw new RuntimeException('Error al centrar «'.$label.'».');
    }
    $helper = <<<'CODE'
// GYD_OC_FIRMAS_CENTRADAS_V1: centrado exacto según las métricas WinAnsi de Helvetica y Helvetica-Bold.
function gyd_oc_pdf_centrar_texto(float $centro, float $y, string $txt, int $size=9, bool $bold=false): string {
    static $wRegular=METRICS_REGULAR;
    static $wBold=METRICS_BOLD;
    $normalizada=preg_replace('/\s+/u',' ',trim($txt))??'';
    $win=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$normalizada);
    if($win===false) $win=$normalizada;
    $metrics=$bold?$wBold:$wRegular;
    $ancho=0;
    for($i=0,$n=strlen($win);$i<$n;$i++) {
        $idx=ord($win[$i])-32;
        $ancho+=($idx>=0 && $idx<count($metrics)) ? $metrics[$idx] : 556;
    }
    return ocw_pdf_t($centro-$size*$ancho/2000.0,$y,$txt,$size,$bold);
}
CODE;
    $regular='[278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500]';
    $bold='[278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556]';
    // Las métricas se generan en el paquete a partir de las fuentes estándar PDF.
    $helper=str_replace(['METRICS_REGULAR','METRICS_BOLD'],[$regular,$bold],$helper);
    $anchor='function ocw_pdf_compose(';
    $idx=strpos($src,$anchor);
    if($idx===false) throw new RuntimeException('Falta función de composición del PDF.');
    return substr($src,0,$idx).$helper."\n\n".substr($src,$idx);
}
function gocenter_lint(string $code): void {
    $temp=tempnam(sys_get_temp_dir(),'gydocphp_');
    if($temp===false) throw new RuntimeException('No se pudo reservar un archivo temporal.');
    file_put_contents($temp,$code);
    $cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temp).' 2>&1';
    $lines=[];$exit=1;exec($cmd,$lines,$exit);@unlink($temp);
    if($exit!==0) throw new RuntimeException('El código generado no pasó la sintaxis PHP: '.implode(' ', $lines));
}
try {
    if(!in_array($mode,['--check','--apply','--verify','--rollback','--help'],true)) throw new RuntimeException('Opción desconocida.');
    if($mode==='--help'){echo "Uso: php tools/ajustar_centrado_firmas_oc.php --check|--apply|--verify|--rollback\n";exit;}
    if(!is_file($target))throw new RuntimeException('No existe '.$target);
    $actual=goread($target);
    if($mode==='--verify') {
        if(!str_contains($actual,'GYD_OC_FIRMAS_CENTRADAS_V1')) throw new RuntimeException('Aún no está instalado el centrado.');
        if(substr_count($actual,'gyd_oc_pdf_centrar_texto(')!==7) throw new RuntimeException('Faltan puntos de centrado en el PDF.');
        gocenter_lint($actual);
        echo "VERIFICADO: 6 textos de firma centrados; sintaxis PHP correcta.\n";exit;
    }
    if($mode==='--rollback') {
        if(!is_file($manifest)) throw new RuntimeException('No hay respaldo registrado para revertir.');
        $m=json_decode(goread($manifest),true,512,JSON_THROW_ON_ERROR);
        $backup=$m['backup']??'';
        if(!is_string($backup)||!is_file($backup))throw new RuntimeException('No se encuentra el archivo respaldado.');
        if(!hash_equals((string)($m['after_sha256']??''),hash('sha256',$actual)))throw new RuntimeException('Se detectaron cambios posteriores: no se revierte automáticamente.');
        $original=goread($backup);gocenter_lint($original);
        if(!@copy($backup,$target))throw new RuntimeException('No se pudo restaurar el archivo.');
        echo "RESTAURADO: PDF original, otros módulos intactos.\n";exit;
    }
    $modificado=gocenter_apply_to_string($actual);
    gocenter_lint($modificado);
    if($mode==='--check') {echo "COMPATIBLE: 6 textos del bloque de firmas, exclusivamente en PDF. No se modificó ningún archivo.\n";exit;}
    if(!is_dir($backupRoot) && !@mkdir($backupRoot,0700,true) && !is_dir($backupRoot)) throw new RuntimeException('No se pudo crear carpeta de respaldos.');
    $backup=$backupRoot.DIRECTORY_SEPARATOR.'erp_oc_win_pdf_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.php';
    if(!@copy($target,$backup))throw new RuntimeException('No se pudo respaldar PDF; instalación cancelada.');
    $tmp=$target.'.gyd_oc_center_'.bin2hex(random_bytes(5)).'.tmp';
    if(@file_put_contents($tmp,$modificado)===false)throw new RuntimeException('No se pudo crear archivo temporal.');
    if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('No se pudo reemplazar el PDF.');}
    $data=['backup'=>$backup,'after_sha256'=>hash('sha256',$modificado),'original_sha256'=>hash('sha256',$actual),'updated_at'=>date(DATE_ATOM)];
    if(@file_put_contents($manifest,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false)throw new RuntimeException('Se instaló pero no se pudo registrar el respaldo: '.$backup);
    echo "APLICADO: se centraron 6 textos del PDF. Respaldo: {$backup}\n";
} catch(Throwable $e) { godie($e->getMessage()); }
