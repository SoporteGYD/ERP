<?php
declare(strict_types=1);
/** Centro de pendientes G&D. Todos los datos se derivan de la BD; sólo se guarda lectura individual. */
require_once __DIR__ . '/erp_oc_recepciones.php';
require_once __DIR__ . '/erp_oc_aviso_estado.php';
function gdnt_role(array $user): string {
    return strtoupper(trim((string)($user['rol_original'] ?? $user['rol'] ?? '')));
}
function gdnt_can_read(array $user): bool {
    return (int)($user['id']??0)>0 && in_array(gdnt_role($user),['ADMINISTRADOR','ADMIN','DIRECCION','COMPRAS','ALMACEN','ENCARGADO','GERENTE','CONSULTA'],true);
}
function gdnt_escape(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/**
 * Los avisos de Compras se basan en recepción + factura + aplicación y NO en el campo 'leído'.
 * El reparto cronológico entre varias recepciones es orientativo; no sustituye el vínculo documental.
 */
/**
 * Fuente de verdad del estado de una llegada: aplicaciones realmente asentadas
 * por factura en inventario operativo (POS o almacén que consolida al POS).
 * No toma la lectura del mensaje ni una factura sólo capturada como entrada.
 * Una llegada sin una aplicación posterior permanece pendiente.
 */
function gdnt_arrival_stock_states(PDO $pdo, array $messages): array {
    $states=[];$orders=[];
    foreach($messages as $m){
        $id=(int)$m['id'];$oid=(int)$m['orden_id'];
        if($id>0 && $oid>0){$orders[$oid]=$oid;$states[$id]=['estado'=>'Nueva llegada por atender','cerrado'=>false,'aplicadas'=>0,'pendientes'=>null,'mensaje'=>''];}
    }
    if(!$orders)return $states;
    $ids=array_values($orders);$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT orden_id,producto_id,SUM(cantidad_piezas) piezas FROM erp_ordenes_compra_detalle WHERE orden_id IN ($marks) GROUP BY orden_id,producto_id");
    $q->execute($ids);$ordered=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$ordered[(int)$r['orden_id']][(int)$r['producto_id']]=(int)$r['piezas'];
    // Aplicar inventario genera este asiento EN LA MISMA TRANSACCIÓN que el stock.
    // Tanto Farmacia como Almacén llevan un destino operativo verificable.
    $q=$pdo->prepare("SELECT f.orden_id,a.producto_id,a.cantidad,a.creado_en,a.destino,a.pos_sucursal_id,a.almacen_id
       FROM erp_facturas_stock_aplicaciones a JOIN erp_facturas f ON f.id=a.factura_id
       WHERE f.orden_id IN ($marks) AND a.cantidad>0
       AND ((a.destino='FARMACIA' AND a.pos_sucursal_id IS NOT NULL)
          OR (a.destino='ALMACEN' AND a.almacen_id IS NOT NULL))
       ORDER BY a.creado_en,a.id");
    $q->execute($ids);$applied=[];$events=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $oid=(int)$r['orden_id'];$pid=(int)$r['producto_id'];$qty=(int)$r['cantidad'];
        $applied[$oid][$pid]=($applied[$oid][$pid]??0)+$qty;
        $events[$oid][]=['fecha'=>(string)$r['creado_en'],'cantidad'=>$qty];
    }
    // Reversiones auditadas se restan del saldo para no dar por concluida una OC
    // que tuvo ajustes posteriores de faltantes.
    $adjust=[];
    $exists=$pdo->query("SHOW TABLES LIKE 'erp_oc_conciliacion_eventos'")->fetchColumn();
    if($exists){
        $q=$pdo->prepare("SELECT orden_id,producto_id,
          SUM(CASE WHEN tipo='AJUSTE_FALTANTE' THEN cantidad WHEN tipo='REPOSICION' THEN -cantidad ELSE 0 END) diferencia
          FROM erp_oc_conciliacion_eventos WHERE orden_id IN ($marks) GROUP BY orden_id,producto_id");
        $q->execute($ids);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$adjust[(int)$r['orden_id']][(int)$r['producto_id']]=(int)$r['diferencia'];
    }
    $remaining=[];
    foreach($ids as $oid){
        $left=0;
        foreach($ordered[$oid]??[] as $pid=>$qty){
            $net=max(0,($applied[$oid][$pid]??0)-($adjust[$oid][$pid]??0));
            $left+=max(0,$qty-$net);
        }
        $remaining[$oid]=$left;
    }
    foreach($messages as $m){
        $mid=(int)$m['id'];$oid=(int)$m['orden_id'];
        if(!isset($states[$mid]))continue;
        $after=0;
        foreach($events[$oid]??[] as $event){
            // Una aplicación anterior a la llegada no puede cerrar este aviso.
            if($event['fecha']>=(string)$m['creado_en'])$after+=$event['cantidad'];
        }
        $left=$remaining[$oid]??0;
        $states[$mid]['aplicadas']=$after;
        $states[$mid]['pendientes']=$left;
        if($after>0){
            $states[$mid]['cerrado']=true;
            $states[$mid]['estado']=$left>0?'Entrada realizada con mercancía pendiente':'Entrada realizada';
            $states[$mid]['mensaje']=' · '.number_format($after).' piezas aplicadas después del aviso · '.number_format($left).' pendientes de la OC';
        }else{
            $states[$mid]['mensaje']=' · Esperando entrada aplicada al inventario/POS';
        }
    }
    return $states;
}

function gdnt_items(PDO $pdo, array $user): array {
    $rol=gdnt_role($user);
    $isAdmin=in_array($rol,['ADMINISTRADOR','ADMIN','DIRECCION'],true);
    $items=[];
    if ($isAdmin || $rol==='COMPRAS') {
        $sql="SELECT a.id,a.orden_id,a.recepcion_id,a.estado,
             r.folio AS recepcion_folio,r.destino,r.fecha_recepcion,o.folio AS oc_folio,
             p.nombre AS proveedor
             FROM erp_oc_avisos_compras a
             JOIN erp_oc_recepciones r ON r.id=a.recepcion_id AND r.estado='REGISTRADA'
             JOIN erp_ordenes_compra o ON o.id=a.orden_id
             JOIN proveedores p ON p.id=o.proveedor_id
             ORDER BY a.id DESC LIMIT 500";
        $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        // La función de conciliación usa las aplicaciones confirmadas: no consulta ni cambia stock actual.
        $states=oc_status_batch($pdo,$rows);
        foreach ($rows as $r) {
            $s=$states[(int)$r['id']]??[];
            $rec=(int)($s['recibidas']??0);
            $fact=(int)($s['facturadas']??0);
            $ap=(int)($s['aplicadas']??0);
            if ($rec<=0) continue;
            $pendingFact=max(0,$rec-$fact);
            $pendingApply=max(0,$rec-$ap);
            $active=$pendingFact>0 || $pendingApply>0;
            $status=$pendingFact>0?'Pendiente de factura':($pendingApply>0?'Pendiente de aplicar':'Resuelto');
            $items[]=[
              'key'=>'RECEPCION:'.(int)$r['recepcion_id'],'tipo'=>'RECEPCION','source_id'=>(int)$r['recepcion_id'],
              'area'=>'Compras','titulo'=>(string)$r['oc_folio'].' · '.(string)$r['proveedor'],
              'detalle'=>(string)$r['recepcion_folio'].' · '.(string)$r['destino'].' · '.number_format($rec).' recibidas, '.number_format($fact).' facturadas, '.number_format($ap).' aplicadas',
              'estado'=>$status,'prioridad'=>$pendingFact>0?'amarillo':($pendingApply>0?'amarillo':'verde'),
              'pendiente'=>$active,'auto_atendido'=>($pendingFact===0),'url'=>'erp_compras_avisos.php',
              'fecha'=>(string)$r['fecha_recepcion']
            ];
        }
    }
    if ($isAdmin || in_array($rol,['ALMACEN','ENCARGADO','GERENTE'],true)) {
        $acc=erpr_acceso($user);
        $dest=$acc['destino'];
        // Sin asignación de bodega no se muestran pedidos de una bodega ajena.
        if ($dest!=='__SIN_DESTINO__') {
            $rows=erpr_resumen($pdo,$isAdmin?'':(string)$dest,500);
            foreach($rows as $r) {
                if (!in_array((string)$r['destino'],['HIDALGO_BODEGA','TUXTLA_BODEGA'],true))continue;
                $rest=max(0,(int)$r['solicitadas']-(int)$r['recibidas']);
                if($rest<=0)continue;
                $items[]=[
                    'key'=>'OC_BODEGA:'.(int)$r['id'],'tipo'=>'OC_BODEGA','source_id'=>(int)$r['id'],
                    'area'=>'Almacén','titulo'=>(string)$r['folio'].' · '.(string)$r['proveedor'],
                    'detalle'=>(string)$r['destino'].' · '.number_format($rest).' piezas pendientes de recepción',
                    'estado'=>(int)$r['recibidas']>0?'Recepción parcial':'Por recibir','prioridad'=>'amarillo',
                    'pendiente'=>true,'url'=>'erp_recepcion_registrar.php?id='.(int)$r['id'],
                    'fecha'=>(string)$r['fecha']
                ];
            }
        }
    }
    // GYD_SEGUIMIENTO_COMPRAS_NOTIFICACIONES_V1
    // Los avisos financieros sólo llegan a Administración/Compras; no se amplían permisos de Almacén ni POS.
    if ($isAdmin || $rol==='COMPRAS') {
        require_once __DIR__.'/erp_compras_alertas_seguimiento.php';
        try { $items=array_merge($items,gdca_notification_items($pdo,$user)); }
        catch(Throwable $e){error_log('G&D avisos financieros: '.$e->getMessage());}
    }

    // GYD_OC_LLEGADAS_CAMPANA_V1
    // Cada LLEGADA ya tiene una fila por destinatario: ninguna nueva captura ni cambio de stock.
    // Sólo lectura personal; una orden puede producir avisos por varias entregas.
    if ($isAdmin || $rol==='COMPRAS') {
        try {
            $hasTable=$pdo->query("SHOW TABLES LIKE 'erp_oc_flujo_mensajes'")->fetchColumn();
            if($hasTable){
                $q=$pdo->prepare("SELECT m.id, m.orden_id, m.nota, m.creado_en, m.leido_en,
                       o.folio, o.destino, p.nombre AS proveedor, u.nombre AS gerente
                    FROM erp_oc_flujo_mensajes m
                    JOIN erp_ordenes_compra o ON o.id=m.orden_id
                    JOIN proveedores p ON p.id=o.proveedor_id
                    JOIN usuarios u ON u.id=m.remitente_id
                    WHERE m.destinatario_id=? AND m.tipo='LLEGADA'
                    ORDER BY m.id DESC LIMIT 200");
                $q->execute([(int)$user['id']]);
                $arrivals=$q->fetchAll(PDO::FETCH_ASSOC);
                $stockStates=gdnt_arrival_stock_states($pdo,$arrivals);
                foreach($arrivals as $m){
                    $stock=$stockStates[(int)$m['id']]??['cerrado'=>false,'estado'=>'Nueva llegada por atender','mensaje'=>''];
                    $closed=(bool)$stock['cerrado'];
                    $wasRead=!empty($m['leido_en']);
                    $detail='Gerente: '.(string)$m['gerente'].' · Destino: '.(string)$m['destino'];
                    if(trim((string)($m['nota']??''))!=='')$detail.=' · Nota: '.(string)$m['nota'];
                    $detail.=(string)$stock['mensaje'];
                    $items[]=[
                      'key'=>'OC_LLEGADA:'.(int)$m['id'],'tipo'=>'OC_LLEGADA','source_id'=>(int)$m['id'],
                      'area'=>'Compras','titulo'=>'Mercancía reportada · '.(string)$m['folio'].' · '.(string)$m['proveedor'],
                      'detalle'=>$detail,'estado'=>(string)$stock['estado'],
                      'prioridad'=>$closed?'verde':'amarillo',
                      'pendiente'=>!$closed,'leido'=>$wasRead,'auto_atendido'=>$closed,
                      'url'=>'erp_oc_flujo.php?orden_id='.(int)$m['orden_id'].'#seguimiento',
                      'fecha'=>(string)$m['creado_en']
                    ];
                }
            }
        }catch(Throwable $e){error_log('G&D avisos OC LLEGADA: '.$e->getMessage());}
    }
    $userId=(int)$user['id'];
    $q=$pdo->prepare('SELECT tipo,origen_id,leido_en FROM erp_notificaciones_lecturas WHERE usuario_id=?');
    $q->execute([$userId]);$read=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $l) $read[(string)$l['tipo'].':'.(int)$l['origen_id']]=(string)$l['leido_en'];
    foreach($items as &$item){if(($item['tipo']??'')!=='OC_LLEGADA')$item['leido']=isset($read[$item['key']]) || !empty($item['auto_atendido']);}
    unset($item);
    usort($items,static function(array $a,array $b):int {
        if($a['pendiente']!==$b['pendiente'])return $a['pendiente']?-1:1;
        if($a['leido']!==$b['leido'])return $a['leido']?1:-1;
        return strcmp((string)$b['fecha'],(string)$a['fecha']);
    });
    return $items;
}
function gdnt_counts(array $items):array {
    $out=['total'=>0,'sin_leer'=>0,'compras'=>0,'almacen'=>0,'resueltas'=>0];
    foreach($items as $x) {
        if(!$x['pendiente']) {$out['resueltas']++;continue;}
        $out['total']++;
        if(!$x['leido'])$out['sin_leer']++;
        if($x['area']==='Compras')$out['compras']++; else $out['almacen']++;
    }
    return $out;
}
