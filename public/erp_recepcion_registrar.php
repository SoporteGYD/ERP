<?php
declare(strict_types=1);
// GYD_OC_FACTURA_UNICA_FASE_PREPARATORIA_V1
// El formulario antiguo de recepción NO debe capturar más movimientos.
// La lectura histórica permanece disponible por erp_recepciones_historial.php.
require_once __DIR__.'/../app/helpers/auth.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('La captura de recepción por OC fue desactivada. Usa Entradas por factura.');
}
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
header('Location: erp_facturas.php'.($id?'?orden_id='.$id:''), true, 303);
exit;
