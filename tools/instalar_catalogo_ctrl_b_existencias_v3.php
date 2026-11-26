<?php
declare(strict_types=1);
/** G&D ERP - Corrige Ctrl+B -> Buscar general (Existencias). Sin consultas ni escrituras DB. */
$mode = $argv[1] ?? '--help';
if (!in_array($mode, ['--help', '--check', '--apply', '--verify', '--rollback'], true)) {
    fwrite(STDERR, "Opción inválida.\n"); exit(2);
}
$root = realpath(__DIR__ . '/..');
if ($root === false) { fwrite(STDERR, "No se encontró la raíz del ERP.\n"); exit(2); }
$root = str_replace('\\', '/', $root);
$relative = 'public/assets/js/gyd_catalogo_global.js';
$dest = $root . '/' . $relative;
$src = __DIR__ . '/gyd_catalogo_existencias_src/gyd_catalogo_global.js';
$homeBase = getenv('LOCALAPPDATA') ?: (getenv('USERPROFILE') ? rtrim((string)getenv('USERPROFILE'), '/\\') . '/AppData/Local' : sys_get_temp_dir());
$home = str_replace('\\', '/', rtrim((string)$homeBase, '/\\')) . '/GYD/RespaldoCatalogoExistenciasV3';
$manifest = $home . '/ultima_correccion.json';
// Únicamente versiones oficiales Ctrl+B V1 y V2 se pueden sustituir.
$recognized = [
    'v1' => '306019a3e3cf1d066a6362cb728e1da55eacb8e275e1522872bb19edf221e009',
    'v2' => '4dd62c8583190edca22d1931fd743d0b9acd2e074388e36aaf107c7734725ed6',
];
function ce3_fail(string $message): never { fwrite(STDERR, "ERROR: $message\n"); exit(2); }
function ce3_read(string $file): string {
    $data = @file_get_contents($file);
    if ($data === false) ce3_fail('No se pudo leer: ' . $file);
    return $data;
}
function ce3_write(string $file, string $data): void {
    $parent = dirname($file);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) ce3_fail('No se pudo crear: ' . $parent);
    $temporary = $file . '.tmp_' . bin2hex(random_bytes(4));
    if (@file_put_contents($temporary, $data, LOCK_EX) === false) ce3_fail('No se pudo escribir: ' . $temporary);
    if (!@rename($temporary, $file)) {
        if (!@copy($temporary, $file)) { @unlink($temporary); ce3_fail('No se pudo actualizar: ' . $file); }
        @unlink($temporary);
    }
}
function ce3_manifest(string $file): array {
    if (!is_file($file)) ce3_fail('No existe una instalación V3 registrada.');
    $data = json_decode(ce3_read($file), true);
    if (!is_array($data) || !isset($data['root'], $data['backup'], $data['before'], $data['after'])) ce3_fail('Registro de instalación inválido.');
    return $data;
}
if ($mode === '--help') {
    echo "G&D ERP · Ctrl+B a Buscar general en Existencias\n--check, --apply, --verify, --rollback\n";
    exit;
}
if (!is_file($root . '/public/existencias.php')) ce3_fail('No existe public/existencias.php. Verifica la carpeta del proyecto.');
if (!is_file($dest)) ce3_fail('No existe el catálogo Ctrl+B global. Instala antes el catálogo general.');
if (!is_file($src)) ce3_fail('Falta la fuente JS del paquete V3. Copia toda la carpeta tools del ZIP.');
$page = ce3_read($root . '/public/existencias.php');
if (!preg_match('/class\s*=\s*["\'][^"\']*existencias-filter-form/i', $page) || !preg_match('/name\s*=\s*["\']buscar["\']/i', $page)) ce3_fail('La página de Existencias tiene un formulario distinto. No se modificó nada.');
$before = ce3_read($dest);
$hash = hash('sha256', $before);
$after = ce3_read($src);
$afterHash = hash('sha256', $after);
if (strpos($after, "version:'global-integracion-v3-existencias'") === false || strpos($after, 'isExistenciasSearch') === false) ce3_fail('El archivo del paquete no corresponde a V3.');
if ($mode === '--check') {
    echo "ERP: $root\nArchivo: $relative\n";
    if ($hash === $afterHash) echo "INSTALADO: ya está presente la corrección V3.\n";
    elseif (in_array($hash, $recognized, true)) echo "COMPATIBLE: se reconoció el catálogo V1/V2. Se cambiará solo el JS global.\n";
    else { echo "INCOMPATIBLE: el JS está modificado o no corresponde a V1/V2. No lo sobrescribas.\n"; exit(3); }
    echo "No cambia tablas, saldos, ventas ni formularios. Respaldo externo: $home\n"; exit;
}
if ($mode === '--verify') {
    $rec = ce3_manifest($manifest);
    if ($rec['root'] !== $root || !hash_equals((string)$rec['after'], $hash)) ce3_fail('No coincide el ERP o el JS fue editado después de instalar V3.');
    echo "VERIFICADO: Ctrl+B reconoce Buscar general en Existencias y mantiene el catálogo global.\n";
    echo "Comprueba en Chrome la consulta real contra tu base de datos.\n";
    exit;
}
if ($mode === '--rollback') {
    $rec = ce3_manifest($manifest);
    if ($rec['root'] !== $root || !hash_equals((string)$rec['after'], $hash)) ce3_fail('No se puede restaurar: el JS fue modificado después de V3.');
    if (!is_file($rec['backup']) || !hash_equals((string)$rec['before'], hash_file('sha256', $rec['backup']))) ce3_fail('Falta el respaldo íntegro.');
    ce3_write($dest, ce3_read($rec['backup']));
    @rename($manifest, $home . '/restaurado_' . date('Ymd_His') . '.json');
    echo "RESTAURADO: recuperado JS previo a V3. Ningún inventario fue afectado.\n"; exit;
}
if (is_file($manifest)) ce3_fail('La corrección V3 ya tiene una instalación registrada. Usa --verify.');
if ($hash === $afterHash) { echo "Ya está instalado el JS V3. No se hicieron cambios.\n"; exit; }
if (!in_array($hash, $recognized, true)) ce3_fail('El catálogo instalado no corresponde a V1/V2. Evitamos sobrescribir personalizaciones.');
$backup = $home . '/anteriores/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '/gyd_catalogo_global.js';
ce3_write($backup, $before);
if (!hash_equals($hash, hash_file('sha256', $backup))) ce3_fail('No se pudo verificar el respaldo.');
ce3_write($dest, $after);
if (!hash_equals($afterHash, hash_file('sha256', $dest))) {
    ce3_write($dest, $before);
    ce3_fail('Error al verificar la instalación; se restauró el original.');
}
$data = ['root'=>$root, 'file'=>$relative, 'backup'=>$backup, 'before'=>$hash, 'after'=>$afterHash, 'installed_at'=>date(DATE_ATOM)];
ce3_write($manifest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo "INSTALADO: Ctrl+B envía la selección a Buscar general y ejecuta Filtrar (GET).\nRESPALDO: $backup\n";
echo "No se modificó ninguna existencia. Reinicia Apache y actualiza Chrome con Ctrl+F5.\n";
