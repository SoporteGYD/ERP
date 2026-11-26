<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
require_once __DIR__.'/../app/helpers/gyd_oc_flujo.php';
requireLogin();
$u=currentUser()?:[];
$uid=(int)($u['id']??0);
if($uid<1||!gyf_allowed_buy($u)&&!gyf_is_manager($u)){http_response_code(403);exit('Acceso no autorizado.');}
$db=(new Database())->connect();
$error='';$ok='';$viewOrder=(int)($_GET['orden_id']??0);
try {
 if($_SERVER['REQUEST_METHOD']==='POST') {
  ocw_check_token($_POST['csrf']??null);
  $action=(string)($_POST['accion']??'');$oid=(int)($_POST['orden_id']??0);
  if($oid<1)throw new RuntimeException('Selecciona una orden válida.');
  $db->beginTransaction();
  $st=$db->prepare('SELECT o.*, a.estado AS autorizacion FROM erp_ordenes_compra o LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id WHERE o.id=? FOR UPDATE');
  $st->execute([$oid]);$oc=$st->fetch(PDO::FETCH_ASSOC);
  if(!$oc)throw new RuntimeException('No existe esa orden.');
  if($action==='enviar') {
   if(!gyf_allowed_buy($u)||!ocw_can_edit($u))throw new RuntimeException('Solo Compras y Dirección pueden distribuir órdenes.');
   if(!gd_oc_esta_lista((string)$oc['estado'],$oc['autorizacion']??null))throw new RuntimeException('Primero debe estar emitida la orden.');
   $costos=(string)($_POST['costos']??'');if(!in_array($costos,['si','no'],true))throw new RuntimeException('Selecciona la visualización de costos.');
   $s=$db->prepare("SELECT u.id FROM erp_oc_win_gerentes g JOIN usuarios u ON u.id=g.usuario_id WHERE g.destino=? AND g.activo=1 AND u.estado=1 AND u.rol='GERENTE'");
   $s->execute([$oc['destino']]);$dest=$s->fetchAll(PDO::FETCH_COLUMN);
   if(!$dest)throw new RuntimeException('No hay gerente configurado en ese destino. Asignarlo en Gerentes destinatarios.');
   $duenos=$db->query("SELECT id FROM usuarios WHERE rol='DIRECCION' AND estado=1")->fetchAll(PDO::FETCH_COLUMN);
   if(!$duenos)throw new RuntimeException('No existe usuario DIRECCION activo para enviar la copia obligatoria con costos.');
   $ins=$db->prepare("INSERT INTO erp_oc_flujo_mensajes(orden_id,remitente_id,destinatario_id,tipo,con_costos,nota) VALUES (?,?,?,'ORDEN',?,NULL)");
   foreach($dest as $rid)$ins->execute([$oid,$uid,(int)$rid,$costos==='si'?1:0]);
   foreach($duenos as $rid)$ins->execute([$oid,$uid,(int)$rid,1]);
   $ok='La orden fue enviada dentro del ERP a '.count($dest).' gerente(s) y '.count($duenos).' usuario(s) de Dirección, estos últimos siempre con costos.';
  } elseif($action==='llegada') {
   if(!gyf_is_manager($u))throw new RuntimeException('La llegada de mercancía la notifica el gerente.');
   if(!gyf_has_order($db,$oid,$u))throw new RuntimeException('No te han enviado esta orden.');
   $s=$db->prepare("SELECT COUNT(*) FROM erp_oc_flujo_mensajes WHERE orden_id=? AND remitente_id=? AND tipo='LLEGADA' AND creado_en>DATE_SUB(NOW(),INTERVAL 5 MINUTE)");$s->execute([$oid,$uid]);
   if((int)$s->fetchColumn()>0)throw new RuntimeException('Ya notificaste esta llegada recientemente.');
   $note=trim((string)($_POST['nota']??''));if(mb_strlen($note)>500)throw new RuntimeException('La nota es demasiado larga.');
   $compras=$db->query("SELECT id FROM usuarios WHERE rol IN ('COMPRAS','DIRECCION','ADMINISTRADOR') AND estado=1")->fetchAll(PDO::FETCH_COLUMN);
   if(!$compras)throw new RuntimeException('No hay personal activo de Compras o Dirección para recibir el aviso.');
   $ins=$db->prepare("INSERT INTO erp_oc_flujo_mensajes(orden_id,remitente_id,destinatario_id,tipo,con_costos,nota) VALUES (?,?,?,'LLEGADA',1,?)");
   foreach(array_unique($compras) as $rid)$ins->execute([$oid,$uid,(int)$rid,$note?:null]);
   $ok='Notificaste la llegada. Compras verificará factura y piezas; no se modificó el inventario.';
  } else throw new RuntimeException('Acción no reconocida.');
  $db->commit();$viewOrder=$oid;
 }
} catch(Throwable $e) {if($db->inTransaction())$db->rollBack();$error=$e instanceof RuntimeException?$e->getMessage():'No se completó la operación. Revisa registros del servidor.';error_log('GYD OC flujo: '.$e->getMessage());}
$inbox=[];$ordenes=[];
try {
 $s=$db->prepare("SELECT m.id,m.orden_id,m.tipo,m.con_costos,m.nota,m.creado_en,m.leido_en,o.folio,o.destino,p.nombre proveedor,r.nombre remitente FROM erp_oc_flujo_mensajes m JOIN erp_ordenes_compra o ON o.id=m.orden_id JOIN proveedores p ON p.id=o.proveedor_id JOIN usuarios r ON r.id=m.remitente_id WHERE m.destinatario_id=? ORDER BY m.id DESC LIMIT 100");$s->execute([$uid]);$inbox=$s->fetchAll(PDO::FETCH_ASSOC);
 if(gyf_allowed_buy($u)){$s=$db->query("SELECT o.id,o.folio,o.destino,o.estado,p.nombre proveedor,a.estado autorizacion FROM erp_ordenes_compra o JOIN proveedores p ON p.id=o.proveedor_id LEFT JOIN erp_oc_autorizaciones a ON a.orden_id=o.id WHERE o.estado='EMITIDA' ORDER BY o.id DESC LIMIT 120");$ordenes=$s->fetchAll(PDO::FETCH_ASSOC);}
 if($viewOrder>0){$s=$db->prepare("SELECT d.codigo_snapshot,d.descripcion_snapshot,d.cantidad_piezas AS pedido,COALESCE(f.capturadas,0) AS facturadas,COALESCE(a.aplicadas,0) AS aplicadas FROM erp_ordenes_compra_detalle d LEFT JOIN (SELECT fd.producto_id,f.orden_id,SUM(fd.cantidad_facturada) capturadas FROM erp_facturas f JOIN erp_facturas_detalle fd ON fd.factura_id=f.id GROUP BY f.orden_id,fd.producto_id) f ON f.orden_id=d.orden_id AND f.producto_id=d.producto_id LEFT JOIN (SELECT fd.producto_id,f.orden_id,SUM(sa.cantidad) aplicadas FROM erp_facturas_stock_aplicaciones sa JOIN erp_facturas f ON f.id=sa.factura_id JOIN erp_facturas_revision_detalle rd ON rd.id=sa.revision_detalle_id JOIN erp_facturas_detalle fd ON fd.id=rd.factura_detalle_id GROUP BY f.orden_id,fd.producto_id) a ON a.orden_id=d.orden_id AND a.producto_id=d.producto_id WHERE d.orden_id=? ORDER BY d.id");$s->execute([$viewOrder]);$detalle=$s->fetchAll(PDO::FETCH_ASSOC);if(!gyf_has_order($db,$viewOrder,$u)){$detalle=[];$viewOrder=0;}}
} catch(Throwable $e){$error='No fue posible consultar el flujo. Comprueba que se instaló la tabla.';error_log('GYD OC bandeja: '.$e->getMessage());}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Órdenes y avisos de llegada</title><style>body{margin:0;font:15px system-ui,Segoe UI,sans-serif;background:#eff4fa;color:#153858}header{background:#0e3159;color:white;padding:24px}main{max-width:1150px;margin:auto;padding:20px}section{background:white;padding:20px;border-radius:12px;margin-bottom:18px;box-shadow:0 2px 10px #14385b13}h2{margin:0 0 14px}a{color:#0967a4}button,select,textarea{font:inherit;padding:9px;border-radius:6px;border:1px solid #a4b9ca}button{background:#125181;color:white;cursor:pointer}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid #dce5ee}th{background:#e9f2fa}.scroll{overflow-x:auto}.msg{padding:13px;border-radius:8px;background:#e3f4e7}.error{background:#ffe4e4}.small{color:#657888;font-size:13px}form.inline{display:inline-flex;gap:7px;align-items:center;flex-wrap:wrap}textarea{width:240px;height:38px;vertical-align:middle}</style></head><body><header><h1>Órdenes de compra y avisos de llegada</h1><div>ERP G&D · <?=gyf_esc((string)($u['nombre']??''))?> · <a style="color:white" href="<?=gyf_allowed_buy($u)?'erp_compras_inicio.php':'dashboard.php'?>">Inicio</a></div></header><main><?php if($error):?><div class="msg error"><?=gyf_esc($error)?></div><?php endif;?><?php if($ok):?><div class="msg"><?=gyf_esc($ok)?></div><?php endif;?>
<?php if(gyf_allowed_buy($u)):?><section><h2>Distribuir órdenes desde Compras</h2><p class="small">Se envían dentro del ERP. Elige cómo la verá el gerente; Dirección recibe siempre con costos.</p><div class="scroll"><table><tr><th>OC</th><th>Proveedor</th><th>Destino</th><th>Acción</th></tr><?php foreach($ordenes as $o):?><tr><td><?=gyf_esc($o['folio'])?></td><td><?=gyf_esc($o['proveedor'])?></td><td><?=gyf_esc(ocw_destinos()[$o['destino']]??$o['destino'])?></td><td><form method="post" class="inline"><input type="hidden" name="csrf" value="<?=gyf_esc(ocw_token())?>"><input type="hidden" name="accion" value="enviar"><input type="hidden" name="orden_id" value="<?=(int)$o['id']?>"><select name="costos"><option value="no">Gerente sin costos</option><option value="si">Gerente con costos</option></select><button>Enviar en ERP</button></form></td></tr><?php endforeach;?></table></div></section><?php endif;?>
<section><h2>Mi bandeja de notificaciones</h2><p class="small">Los avisos se guardan en el ERP y no afectan existencias. La solicitud de ingreso no sustituye la factura.</p><div class="scroll"><table><tr><th>Fecha</th><th>Tipo</th><th>Orden / proveedor</th><th>De</th><th>Detalle / acción</th></tr><?php foreach($inbox as $m):?><tr><td><?=gyf_esc($m['creado_en'])?></td><td><?=($m['tipo']==='LLEGADA'?'Mercancía llegó':'Orden enviada')?></td><td><?=gyf_esc($m['folio'].' · '.$m['proveedor'])?></td><td><?=gyf_esc($m['remitente'])?></td><td><a href="?orden_id=<?=(int)$m['orden_id']?>">Ver pendientes</a><?php if($m['tipo']==='ORDEN'):?> · <a href="erp_oc_win_pdf.php?id=<?=(int)$m['orden_id']?>&amp;costos=<?=$m['con_costos']?'si':'no'?>" target="_blank" rel="noopener">Ver PDF <?=$m['con_costos']?'con costos':'sin costos'?></a><?php endif;?><?php if($m['nota']):?> <div class="small"><?=gyf_esc($m['nota'])?></div><?php endif;?><?php if(gyf_is_manager($u)&&$m['tipo']==='ORDEN'):?><form method="post"><input type="hidden" name="csrf" value="<?=gyf_esc(ocw_token())?>"><input type="hidden" name="accion" value="llegada"><input type="hidden" name="orden_id" value="<?=(int)$m['orden_id']?>"><textarea name="nota" maxlength="500" placeholder="Ej. Llegó factura F123; confirmar piezas"></textarea><button>Notificar llegada a Compras</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$inbox):?><tr><td colspan="5">No tienes avisos aún.</td></tr><?php endif;?></table></div></section>
<?php if(!empty($detalle)&&$viewOrder>0):?><section><h2>Seguimiento de la orden #<?=$viewOrder?></h2><p class="small">Pendiente = pedido menos piezas con entrada aplicada desde factura. La captura documental por sí sola no incrementa existencias.</p><div class="scroll"><table><tr><th>Código</th><th>Producto</th><th>Pedido</th><th>Facturado</th><th>Ingresado</th><th>Pendiente</th></tr><?php foreach($detalle as $d):?><tr><td><?=gyf_esc($d['codigo_snapshot'])?></td><td><?=gyf_esc($d['descripcion_snapshot'])?></td><td><?=(int)$d['pedido']?></td><td><?=(int)$d['facturadas']?></td><td><?=(int)$d['aplicadas']?></td><td><b><?=max(0,(int)$d['pedido']-(int)$d['aplicadas'])?></b></td></tr><?php endforeach;?></table></div></section><?php endif;?></main></body></html>
