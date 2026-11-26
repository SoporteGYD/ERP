<?php
// Instalador seguro del menu extendido. No cambia permisos, SQL ni inventarios.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }
$op = $argv[1] ?? '';
if (!in_array($op, ['--check','--apply','--verify'], true)) { fwrite(STDERR, "Uso: --check | --apply | --verify\n"); exit(2); }
$root=dirname(__DIR__);
$target=$root.'/app/views/layouts/header.php';
if (!is_file($target)) { fwrite(STDERR,"INCOMPATIBLE: falta app/views/layouts/header.php\n"); exit(1); }
$src=file_get_contents($target);
$start='// GYD_MENU_EXTRA_OPERATIVO_V1';
$end='// FIN_GYD_MENU_EXTRA_OPERATIVO_V1';
$anchor='// El ENCARGADO conserva todos sus accesos permitidos';
$extra=<<<'PHP_BLOCK'
// GYD_MENU_EXTRA_OPERATIVO_V1 - Accesos adicionales, sin cambiar permisos de cada pagina.
$gydMenuExtras = [
    ['🏠 General', 'Centro de Notificaciones', 'erp_notificaciones.php', ($menuEsAdmin || $menuEsCompras || $esGerente)],
    ['🧾 Compras y proveedores', 'Lista de Precios para Cajas', 'erp_lista_precios.php', ($menuEsAdmin || $menuEsCompras)],
    ['🧾 Compras y proveedores', 'Órdenes y avisos de llegada', 'erp_oc_flujo.php', ($menuEsAdmin || $menuEsCompras || $esGerente)],
    ['🧾 Compras y proveedores', 'Seguimiento de facturas por OC', 'erp_oc_facturas_seguimiento.php', ($menuEsAdmin || $menuEsCompras)],
    ['🧾 Compras y proveedores', 'Conciliación de facturas', 'erp_facturas_conciliacion.php', ($menuEsAdmin || $menuEsCompras)],
    ['🧾 Compras y proveedores', 'Integración OC y facturas', 'erp_oc_integracion_facturas.php', ($menuEsAdmin || $menuEsCompras)],
    ['🧾 Compras y proveedores', 'Control de cierre de OC', 'erp_oc_cierre_controlado.php', ($menuEsAdmin || $menuEsCompras)],
    ['🧾 Compras y proveedores', 'Órdenes pendientes WinCaja', 'erp_oc_win.php', ($menuEsAdmin || $menuEsCompras)],
    ['🧾 Compras y proveedores', 'Destinatarios de OC por gerente', 'erp_oc_win_gerentes.php', ($menuEsAdmin || $menuEsCompras)],
    ['🧾 Compras y proveedores', 'Historial de precios', 'erp_historial_precios.php', ($menuEsAdmin || $menuEsCompras)],
    ['🧾 Compras y proveedores', 'Cambios de precios', 'erp_cambio_precios.php', ($menuEsAdmin || $menuEsCompras)],
    ['🧾 Compras y proveedores', 'Alertas de inventario', 'erp_alertas_inventario.php', ($menuEsAdmin || $menuEsCompras)],
    ['🛍️ Ventas y gerencia', 'Conciliación diaria de cajas', 'erp_conciliacion_diaria_cajas.php', ($menuEsAdmin)],
    ['🛍️ Ventas y gerencia', 'Eficiencia de cajeros', 'erp_eficiencia_cajeros.php', ($menuEsAdmin)],
    ['🛍️ Ventas y gerencia', 'Supervisión de retiros', 'erp_retiros_supervision.php', ($menuEsAdmin)],
    ['🛍️ Ventas y gerencia', 'Retiros diarios', 'erp_retiros_diarios.php', ($menuEsAdmin)],
    ['🛍️ Ventas y gerencia', 'Cortes y bóveda', 'erp_cortes_boveda.php', ($menuEsAdmin)],
    ['🛍️ Ventas y gerencia', 'Verificación de bóveda', 'erp_boveda_verificacion.php', ($menuEsAdmin)],
    ['🧮 Control y conciliación', 'Auditoría de stock', 'erp_stock_auditoria.php', ($menuEsAdmin)],
    ['🧮 Control y conciliación', 'Conciliación entre sistemas', 'erp_conciliacion_tt.php', ($menuEsAdmin)],
    ['🧮 Control y conciliación', 'Inventarios de órdenes de compra', 'erp_oc_inventarios.php', ($menuEsAdmin || $menuEsCompras)],
    ['⚙️ Administración', 'Alta de productos', 'erp_producto_alta.php', ($menuEsAdmin || $menuEsCompras)],
];
$gydRutasYaEnMenu = [];
foreach ($menuSecciones as $gydLinks) {
    foreach ($gydLinks as $gydLink) {
        if (isset($gydLink['ruta'])) $gydRutasYaEnMenu[(string)$gydLink['ruta']] = true;
    }
}
foreach ($gydMenuExtras as $gydMenuExtra) {
    [$gydGrupo, $gydTitulo, $gydRuta, $gydPermitido] = $gydMenuExtra;
    if (!$gydPermitido || isset($gydRutasYaEnMenu[$gydRuta])) continue;
    if (!is_file(__DIR__ . '/../../../public/' . $gydRuta)) continue;
    $menuAgregar($gydGrupo, $gydTitulo, $gydRuta, true);
    $gydRutasYaEnMenu[$gydRuta] = true;
}
// FIN_GYD_MENU_EXTRA_OPERATIVO_V1
PHP_BLOCK;
if ($op==='--check') {
    if (str_contains($src,$start)) { echo "COMPATIBLE: menu extendido ya instalado; puede verificar.\n"; exit(0); }
    if (!str_contains($src,$anchor) || !str_contains($src,'$menuAgregar') || !str_contains($src,'$menuSecciones')) { fwrite(STDERR,"INCOMPATIBLE: estructura del menu distinta; no se modifica.\n"); exit(1); }
    echo "COMPATIBLE: menu identificado. Se agregaran solo rutas existentes y permitidas; sin duplicados.\n"; exit(0);
}
if ($op==='--verify') {
    if (!str_contains($src,$start) || !str_contains($src,$end)) { fwrite(STDERR,"NO INSTALADO: faltan marcadores.\n"); exit(1); }
    $o=[];$code=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($target).' 2>&1',$o,$code);
    echo implode("\n",$o)."\n";
    if ($code!==0) exit(1);
    echo "VERIFICADO: menu extendido presente, PHP valido.\n"; exit(0);
}
if (str_contains($src,$start)) { echo "Ya instalado. No se duplican enlaces.\n"; exit(0); }
if (!str_contains($src,$anchor) || !str_contains($src,'$menuAgregar')) { fwrite(STDERR,"INCOMPATIBLE: menu no reconocido.\n"); exit(1); }
$updated=str_replace($anchor,$extra.$anchor,$src,$count);
if ($count!==1) { fwrite(STDERR,"INCOMPATIBLE: no hay ancla unica.\n"); exit(1); }
$dir=$root.'/tools/backups_menu_modulos';
if (!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) { fwrite(STDERR,"No se pudo crear respaldo\n"); exit(1); }
$backup=$dir.'/header_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.php';
if (!copy($target,$backup)) { fwrite(STDERR,"Fallo respaldo, no se modifica.\n"); exit(1); }
$tmp=$target.'.gyd-menu-tmp';
if (file_put_contents($tmp,$updated)===false) { fwrite(STDERR,"No se pudo escribir temporal.\n"); exit(1); }
$o=[];$code=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$o,$code);
if ($code!==0) { unlink($tmp);fwrite(STDERR,"Error de sintaxis; archivo original intacto: ".implode("; ",$o)."\n");exit(1); }
if (!rename($tmp,$target)) { unlink($tmp);fwrite(STDERR,"Error reemplazando archivo, original intacto.\n");exit(1); }
echo "APLICADO. Respaldo: $backup\n";
