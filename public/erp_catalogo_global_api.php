<?php
declare(strict_types=1);
/** Consulta de SOLO LECTURA: catálogo para el selector global Ctrl+B. */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, no-cache, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405); header('Allow: GET');
        echo json_encode(['ok'=>false,'error'=>'Método no permitido.']); exit;
    }
    $user = currentUser() ?: [];
    $rol = strtoupper(trim((string)($user['rol_original'] ?? $user['rol'] ?? '')));
    $permitidos = ['ADMIN','ADMINISTRADOR','DIRECCION','DUENO','DUEÑO','COMPRAS','GERENTE','ENCARGADO','JEFE_ALMACEN','ALMACEN','CAJERO','CONSULTA'];
    if ((int)($user['id'] ?? 0) < 1 || !in_array($rol, $permitidos, true)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'No tienes permiso para consultar productos.']); exit;
    }
    $verInactivos = in_array($rol, ['ADMIN','ADMINISTRADOR','DIRECCION','DUENO','DUEÑO','COMPRAS'], true);
    $estado = trim((string)($_GET['estado'] ?? 'activos'));
    if (!in_array($estado,['activos','todos','inactivos'],true)) $estado='activos';
    if (!$verInactivos) $estado='activos';
    $q = trim((string)($_GET['q'] ?? ''));
    $q = preg_replace('/\s+/u', ' ', $q) ?? '';
    $q = mb_substr($q, 0, 110, 'UTF-8');
    $pagina = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>100000]]) ?: 1;
    $limite = 40;

    $cond=[]; $params=[];
    if ($estado==='activos') $cond[]='p.estado=1';
    elseif ($estado==='inactivos') $cond[]='p.estado=0';
    if ($q!=='') {
        $partes = preg_split('/\s+/u', $q, 6, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($partes as $i=>$parte) {
            $term = '%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$parte).'%';
            $campos=['codigo','codigo_barras','descripcion','laboratorio'];
            $or=[];
            foreach ($campos as $c) {
                $k=':t'.$i.'_'.$c;
                $or[]="p.$c LIKE $k ESCAPE '\\\\'";
                $params[$k]=$term;
            }
            $cond[]='('.implode(' OR ',$or).')';
        }
    }
    $where=$cond?' WHERE '.implode(' AND ',$cond):'';
    $db=(new Database())->connect();
    $count=$db->prepare('SELECT COUNT(*) FROM productos p'.$where);
    foreach($params as $key=>$value) $count->bindValue($key,$value,PDO::PARAM_STR);
    $count->execute();
    $total=(int)$count->fetchColumn();
    $paginas=max(1,(int)ceil($total/$limite));
    $pagina=min($pagina,$paginas);
    $offset=($pagina-1)*$limite;
    $orden='p.estado DESC,p.descripcion ASC,p.id ASC';
    $extra=[];
    if ($q!=='') {
        $orden="CASE WHEN p.codigo=:exact_code OR p.codigo_barras=:exact_bar THEN 0 "
             ."WHEN p.codigo LIKE :pre_code ESCAPE '\\\\' OR p.codigo_barras LIKE :pre_bar ESCAPE '\\\\' THEN 1 "
             ."WHEN p.descripcion LIKE :pre_desc ESCAPE '\\\\' THEN 2 ELSE 3 END, ".$orden;
        $escaped=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q);
        $extra=[':exact_code'=>$q,':exact_bar'=>$q,':pre_code'=>$escaped.'%',':pre_bar'=>$escaped.'%',':pre_desc'=>$escaped.'%'];
    }
    // No enviar costos, precios, existencias o información de otra sucursal.
    $sql='SELECT p.id,p.codigo,p.codigo_barras,p.descripcion,p.laboratorio,p.unidad_medida,p.estado FROM productos p'
        .$where.' ORDER BY '.$orden.' LIMIT :limit OFFSET :offset';
    $st=$db->prepare($sql);
    foreach ($params+$extra as $key=>$value) $st->bindValue($key,$value,PDO::PARAM_STR);
    $st->bindValue(':limit',$limite,PDO::PARAM_INT);
    $st->bindValue(':offset',$offset,PDO::PARAM_INT);
    $st->execute();
    $items=[];
    while($row=$st->fetch(PDO::FETCH_ASSOC)) {
        $items[]=['id'=>(int)$row['id'],'codigo'=>(string)($row['codigo']??''),
          'codigo_barras'=>(string)($row['codigo_barras']??''),
          'descripcion'=>(string)($row['descripcion']??''),
          'laboratorio'=>(string)($row['laboratorio']??''),
          'unidad'=>(string)($row['unidad_medida']??''),
          'activo'=>(int)($row['estado']??0)===1];
    }
    echo json_encode(['ok'=>true,'items'=>$items,'total'=>$total,'pagina'=>$pagina,'paginas'=>$paginas,
      'limite'=>$limite,'puede_inactivos'=>$verInactivos],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch(Throwable $e) {
    error_log('ERP Catalogo Global: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No fue posible consultar el catálogo. Comprueba la conexión y permisos.'],JSON_UNESCAPED_UNICODE);
}
