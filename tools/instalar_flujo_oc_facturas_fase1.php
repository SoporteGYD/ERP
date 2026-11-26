<?php
declare(strict_types=1);
// Ejecutar con PHP CLI: --check, --apply, --verify. No elimina datos.
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo CLI');}
$mode=$argv[1]??'';if(!in_array($mode,['--check','--apply','--verify'],true)){fwrite(STDERR,"Uso: php tools/instalar_flujo_oc_facturas_fase1.php --check|--apply|--verify\n");exit(2);}
$root=dirname(__DIR__);$payload=__DIR__.'/fuentes_flujo_oc_facturas_fase1';
$files=['app/helpers/gyd_oc_flujo.php','app/helpers/erp_compras_modulos.php','public/erp_oc_flujo.php','public/erp_oc_win.php','public/erp_oc_win_pdf.php','public/erp_compras_inicio.php'];
function stop(string $message): never{fwrite(STDERR,"ERROR: $message\n");exit(1);}
foreach($files as $file){if(!is_file($payload.'/'.$file))stop('Falta payload: '.$file);}
$existing=$root.'/public/erp_oc_win.php';if(!is_file($existing))stop('Proyecto ERP no localizado. Copia tools dentro de almacen-farmacia2.');
$check=file_get_contents($existing);
if(strpos($check,'ocw_order(')===false&&strpos($check,'erp_oc_flujo.php')===false)stop('Plantilla OC incompatible. No se sobrescribe.');
if($mode==='--check'){echo "COMPATIBLE: estructura OC reconocida. No se han realizado cambios.\n";echo "FASE 1: avisos internos, envío con/sin costos, solicitud de ingreso sin inventario.\n";exit(0);}
require_once $root.'/app/config/database.php';
try{$db=(new Database())->connect();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}catch(Throwable $e){stop('No se pudo conectar a MySQL. '.$e->getMessage());}
$sql="CREATE TABLE IF NOT EXISTS erp_oc_flujo_mensajes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 orden_id INT NOT NULL,
 remitente_id INT NOT NULL,
 destinatario_id INT NOT NULL,
 tipo ENUM('ORDEN','LLEGADA') NOT NULL,
 con_costos TINYINT(1) NOT NULL DEFAULT 0,
 nota VARCHAR(500) NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 leido_en DATETIME NULL,
 INDEX ix_destino_fecha(destinatario_id,id),
 INDEX ix_orden_tipo(orden_id,tipo),
 INDEX ix_remitente(remitente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if($mode==='--verify'){
 $st=$db->query("SHOW TABLES LIKE 'erp_oc_flujo_mensajes'");if(!$st->fetchColumn())stop('Falta tabla de avisos: ejecuta --apply.');
 foreach($files as $rel){$to=$root.'/'.$rel;$from=$payload.'/'.$rel;if(!is_file($to)||hash_file('sha256',$to)!==hash_file('sha256',$from))stop('Pendiente o distinto: '.$rel);}
 echo "VERIFICADO: tabla y seis archivos instalados.\n";exit(0);
}
$backup=$root.'/tools/backups_flujo_oc_facturas_fase1/'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
foreach($files as $rel){$to=$root.'/'.$rel;if(is_file($to)){$target=$backup.'/'.$rel;if(!is_dir(dirname($target))&&!mkdir(dirname($target),0775,true))stop('No se pudo crear carpeta de respaldo.');if(!copy($to,$target))stop('No se pudo respaldar: '.$rel);}}
echo "RESPALDO: $backup\n";
try{
 $db->exec($sql);
 foreach($files as $rel){$to=$root.'/'.$rel;$from=$payload.'/'.$rel;if(!is_dir(dirname($to))&&!mkdir(dirname($to),0775,true))throw new RuntimeException('Sin acceso a: '.$rel);if(!copy($from,$to))throw new RuntimeException('No se pudo instalar: '.$rel);}
 echo "APLICADO: aviso ERP, recepción notificada por gerente, visualización con/sin costos y menú de Compras.\n";
 echo "NO se han migrado ni eliminado datos de recepción ni se cambió el cálculo de existencias.\n";
}catch(Throwable $e){foreach($files as $rel){$orig=$backup.'/'.$rel;$dest=$root.'/'.$rel;if(is_file($orig))copy($orig,$dest);else if(is_file($dest))unlink($dest);}stop('Instalación revertida a respaldos de archivos. '.$e->getMessage());}
