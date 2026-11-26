<?php
declare(strict_types=1);
require_once __DIR__.'/erp_oc_win.php';
function gyf_role(array $u): string {return ocw_role($u);}
function gyf_esc(mixed $v): string {return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gyf_allowed_buy(array $u): bool {return in_array(gyf_role($u),['COMPRAS','ADMINISTRADOR','ADMIN','DIRECCION'],true);}
function gyf_is_manager(array $u): bool {return gyf_role($u)==='GERENTE';}
function gyf_view_costs(PDO $db,int $order,array $u): bool {
 if(gyf_allowed_buy($u))return true;
 $s=$db->prepare("SELECT con_costos FROM erp_oc_flujo_mensajes WHERE orden_id=? AND destinatario_id=? AND tipo='ORDEN' ORDER BY id DESC LIMIT 1");
 $s->execute([$order,(int)$u['id']]);return (int)$s->fetchColumn()===1;
}
function gyf_has_order(PDO $db,int $order,array $u): bool {
 if(gyf_allowed_buy($u))return true;
 $s=$db->prepare("SELECT COUNT(*) FROM erp_oc_flujo_mensajes WHERE orden_id=? AND destinatario_id=? AND tipo='ORDEN'");
 $s->execute([$order,(int)$u['id']]);return (int)$s->fetchColumn()>0;
}
