<?php
declare(strict_types=1);
/** Nombre designado para la firma física de Órdenes de Compra; NO es aprobación electrónica. */
function ocfd_usuarios(PDO $db): array {
    $sql="SELECT id,nombre,rol FROM usuarios WHERE estado=1 AND UPPER(TRIM(rol)) IN ('DUENO','DUEÑO','PROPIETARIO','DIRECCION') ORDER BY nombre,id";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function ocfd_usuario(PDO $db,int $id): array {
    if($id<1)throw new RuntimeException('Selecciona un usuario Dueño para la firma.');
    $st=$db->prepare("SELECT id,nombre,rol FROM usuarios WHERE id=? AND estado=1 AND UPPER(TRIM(rol)) IN ('DUENO','DUEÑO','PROPIETARIO','DIRECCION') LIMIT 1");
    $st->execute([$id]);$u=$st->fetch(PDO::FETCH_ASSOC);
    if(!$u)throw new RuntimeException('El firmante debe ser un usuario Dueño o Dirección activo.');
    if(trim((string)$u['nombre'])==='')throw new RuntimeException('El firmante no tiene nombre configurado.');
    return $u;
}
function ocfd_guardar(PDO $db,int $ordenId,array $firmante,int $responsableId): void {
    if($ordenId<1||$responsableId<1)throw new RuntimeException('Datos incompletos de la orden.');
    $nombre=trim((string)($firmante['nombre']??''));$uid=(int)($firmante['id']??0);
    if($uid<1||$nombre==='')throw new RuntimeException('Firmante inválido.');
    $st=$db->prepare('INSERT INTO erp_oc_firmante_dueno (orden_id,usuario_id,nombre_snapshot,asignado_por) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE usuario_id=VALUES(usuario_id),nombre_snapshot=VALUES(nombre_snapshot),asignado_por=VALUES(asignado_por),actualizado_en=CURRENT_TIMESTAMP');
    $st->execute([$ordenId,$uid,mb_substr($nombre,0,160),$responsableId]);
    $bit=$db->prepare('INSERT INTO erp_oc_firmante_historial (orden_id,usuario_id,nombre_snapshot,registrado_por) VALUES (?,?,?,?)');
    $bit->execute([$ordenId,$uid,mb_substr($nombre,0,160),$responsableId]);
}
