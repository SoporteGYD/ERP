<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo línea de comandos.'); }
$root = dirname(__DIR__);
$target = $root.'/app/helpers/erp_oc_win_pdf.php';
$backupRoot = (getenv('LOCALAPPDATA') ?: sys_get_temp_dir()).DIRECTORY_SEPARATOR.'GYD'.DIRECTORY_SEPARATOR.'Respaldos_Autorizo_OC';
$manifest = $backupRoot.DIRECTORY_SEPARATOR.'ultima_aplicacion.json';
$mode = $argv[1] ?? '--help';
function dieerr(string $message): never { fwrite(STDERR, 'ERROR: '.$message.PHP_EOL); exit(1); }
function readf(string $file): string { $c=@file_get_contents($file); if($c===false) throw new RuntimeException('No se pudo leer '.$file); return $c; }
function lintphp(string $code): void {
    $tmp=tempnam(sys_get_temp_dir(),'gydoc_');
    if($tmp===false) throw new RuntimeException('No se pudo crear archivo temporal.');
    file_put_contents($tmp,$code);
    $cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1';
    $out=[]; $rc=1; exec($cmd,$out,$rc); @unlink($tmp);
    if($rc!==0) throw new RuntimeException('El código resultante no pasó sintaxis PHP: '.implode(' ', $out));
}
function apply_changes(string $src): string {
    if (!str_contains($src, 'erp_oc_win_pdf.php')) {
        // no-op, only a light sanity clue not mandatory
    }
    if (!str_contains($src, 'Autorizacion (firma)') && !str_contains($src, 'Autorizó')) {
        throw new RuntimeException('No se encontró el bloque esperado de firma de autorización en el PDF.');
    }
    $changed = $src;

    // Cambiar título a Autorizó.
    $count = 0;
    $changed = str_replace("'Autorizacion (firma)'", "'Autorizó'", $changed, $count);
    if ($count === 0) {
        // posibilidad con doble comilla
        $changed2 = str_replace('"Autorizacion (firma)"', '"Autorizó"', $changed, $count2);
        if ($count2 > 0) { $changed = $changed2; $count = $count2; }
    }
    if ($count === 0 && !str_contains($changed, 'Autorizó')) {
        throw new RuntimeException('No se pudo localizar el título “Autorizacion (firma)” para reemplazarlo.');
    }

    // Eliminar la leyenda inferior completa.
    $patterns = [
        '/\s*\$body\.\=gyd_oc_pdf_centrar_texto\(403\.5,\$sigY-15,\'Nombre designado; firma manuscrita pendiente\',7\);\R?/',
        '/\s*\$body\.\=gyd_oc_pdf_centrar_texto\(403\.5,\$sigY-15,\'Nombre asignado; firma manuscrita pendiente\',7\);\R?/',
        '/\s*\$body\.\=ocw_pdf_t\([^\n]*Nombre designado; firma manuscrita pendiente[^\n]*\);\R?/',
        '/\s*\$body\.\=ocw_pdf_t\([^\n]*Nombre asignado; firma manuscrita pendiente[^\n]*\);\R?/',
    ];
    $removed = 0;
    foreach ($patterns as $p) {
        $changedNew = preg_replace($p, "\n", $changed, 1, $cnt);
        if (!is_string($changedNew)) throw new RuntimeException('Error al procesar la eliminación de la leyenda.');
        if ($cnt > 0) {
            $changed = $changedNew;
            $removed += $cnt;
            break;
        }
    }
    if ($removed === 0 && preg_match('/Nombre\s+(designado|asignado);\s*firma\s+manuscrita\s+pendiente/u', $changed)) {
        throw new RuntimeException('La leyenda inferior sigue presente y no pudo eliminarse automáticamente.');
    }

    if ($changed === $src) {
        throw new RuntimeException('No se detectaron cambios para aplicar.');
    }
    return $changed;
}
try {
    if(!in_array($mode,['--check','--apply','--verify','--rollback','--help'],true)) throw new RuntimeException('Opción desconocida.');
    if($mode==='--help') { echo "Uso: php tools/ajustar_autorizo_sin_nota_oc.php --check|--apply|--verify|--rollback\n"; exit; }
    if(!is_file($target)) throw new RuntimeException('No existe '.$target);
    $actual = readf($target);

    if($mode==='--verify') {
        if (!str_contains($actual, 'Autorizó')) throw new RuntimeException('Aún no se cambió el título a “Autorizó”.');
        if (preg_match('/Nombre\s+(designado|asignado);\s*firma\s+manuscrita\s+pendiente/u', $actual)) throw new RuntimeException('La leyenda inferior todavía existe.');
        lintphp($actual);
        echo "VERIFICADO: el PDF ya muestra “Autorizó” y no contiene la leyenda inferior.\n";
        exit;
    }

    if($mode==='--rollback') {
        if(!is_file($manifest)) throw new RuntimeException('No hay respaldo registrado para revertir.');
        $m = json_decode(readf($manifest), true, 512, JSON_THROW_ON_ERROR);
        $backup = $m['backup'] ?? '';
        if(!is_string($backup) || !is_file($backup)) throw new RuntimeException('No se encuentra el archivo respaldado.');
        if(!hash_equals((string)($m['after_sha256'] ?? ''), hash('sha256', $actual))) throw new RuntimeException('Se detectaron cambios posteriores; no se revierte automáticamente.');
        $orig = readf($backup);
        lintphp($orig);
        if(!@copy($backup, $target)) throw new RuntimeException('No se pudo restaurar el archivo.');
        echo "RESTAURADO: volvió el PDF anterior.\n";
        exit;
    }

    $mod = apply_changes($actual);
    lintphp($mod);
    if($mode==='--check') {
        echo "COMPATIBLE: se puede cambiar el título a “Autorizó” y quitar la leyenda inferior.\n";
        exit;
    }

    if(!is_dir($backupRoot) && !@mkdir($backupRoot,0700,true) && !is_dir($backupRoot)) throw new RuntimeException('No se pudo crear carpeta de respaldos.');
    $backup = $backupRoot.DIRECTORY_SEPARATOR.'erp_oc_win_pdf_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.php';
    if(!@copy($target, $backup)) throw new RuntimeException('No se pudo generar respaldo; instalación cancelada.');
    $tmp = $target.'.gyd_autorizo_'.bin2hex(random_bytes(5)).'.tmp';
    if(@file_put_contents($tmp, $mod)===false) throw new RuntimeException('No se pudo crear temporal.');
    if(!@rename($tmp, $target)) { @unlink($tmp); throw new RuntimeException('No se pudo reemplazar el archivo destino.'); }
    $data = [
        'backup' => $backup,
        'after_sha256' => hash('sha256', $mod),
        'original_sha256' => hash('sha256', $actual),
        'updated_at' => date(DATE_ATOM),
    ];
    if(@file_put_contents($manifest, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false) throw new RuntimeException('Se aplicó, pero no se pudo registrar el respaldo.');
    echo "APLICADO: ahora el PDF muestra “Autorizó” y ya no imprime la leyenda inferior. Respaldo: {$backup}\n";
} catch(Throwable $e) {
    dieerr($e->getMessage());
}
