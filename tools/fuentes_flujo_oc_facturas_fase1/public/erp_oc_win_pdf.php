<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/erp_oc_win_pdf.php';
require_once __DIR__.'/../app/helpers/gyd_oc_flujo.php';
requireLogin();$u=currentUser()?:[];ocw_guard($u);
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id){http_response_code(400);exit('Orden incorrecta.');}
$modo=(string)($_GET['costos']??'si');
if(!in_array($modo,['si','no'],true)){http_response_code(400);exit('Selecciona una modalidad de PDF valida.');}
$mostrarCostos=($modo==='si');
// La URL del PDF no puede elevar permisos de visualización del gerente.
if(gyf_is_manager($u)){
 try{$authDb=(new Database())->connect();if(!gyf_has_order($authDb,(int)$id,$u)){http_response_code(403);exit('Orden no enviada a este usuario.');}if($mostrarCostos&&!gyf_view_costs($authDb,(int)$id,$u)){http_response_code(403);exit('La copia asignada no permite costos.');}}
 catch(Throwable $e){error_log('OC permisos PDF: '.$e->getMessage());http_response_code(403);exit('No fue posible verificar permiso de la copia.');}
}

try{$db=(new Database())->connect();$pdf=ocw_pdf_build($db,$id,$u,$mostrarCostos);}catch(Throwable $e){error_log('OC PDF Win: '.$e->getMessage());http_response_code(500);exit('No fue posible generar el documento. Revisa la configuración.');}
header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="OC-'.ocw_numero((int)$id).($mostrarCostos?'-CON-COSTOS':'-SIN-COSTOS').'.pdf"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');echo $pdf;
