<?php
declare(strict_types=1);
/* GYD_LISTA_PRECIOS_MODULE_V2 */
require_once __DIR__.'/../app/helpers/auth.php';
require_once __DIR__.'/../app/config/database.php';
requireLogin();
$usuario=currentUser()?:[];
$rol=strtoupper(trim((string)($usuario['rol_original']??$usuario['rol']??'')));
$permitidos=['ADMIN','ADMINISTRADOR','DIRECCION','DUENO','DUEÑO','COMPRAS','GERENTE','CONSULTA','CAJERO'];
if(!in_array($rol,$permitidos,true)){http_response_code(403);exit('Acceso no autorizado a la lista de precios.');}
header('Cache-Control: private, no-store, max-age=0');
function lp_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$sucursales=[1=>'Ciudad Hidalgo',2=>'Tuxtla Gutiérrez',3=>'Tapachula',4=>'Plaza Toscana'];
$sucursal=(int)($_GET['sucursal']??3);
if(!isset($sucursales[$sucursal]))$sucursal=3;
$q=mb_substr(trim((string)($_GET['q']??'')),0,100);
$estado=(string)($_GET['estado']??'activo');if(!in_array($estado,['activo','todos'],true))$estado='activo';
$precio=(string)($_GET['precio']??'todos');if(!in_array($precio,['todos','con','sin'],true))$precio='todos';
$formato=(string)($_GET['formato']??'');if(!in_array($formato,['','csv','xlsx','imprimir'],true))$formato='';
$pagina=max(1,(int)($_GET['pagina']??1));
$columnasDisponibles=['codigo'=>'Código','codigo_barras'=>'Código de barras','descripcion'=>'Descripción','laboratorio'=>'Laboratorio / marca','presentacion'=>'Presentación','precio_publico'=>'Precio público','estado'=>'Estado'];
$columnasPredeterminadas=['codigo','codigo_barras','descripcion','precio_publico'];
$elegidas=isset($_GET['cols'])?(array)$_GET['cols']:$columnasPredeterminadas;
$elegidas=array_values(array_filter(array_keys($columnasDisponibles),static fn($c)=>in_array($c,$elegidas,true)));
if(!$elegidas)$elegidas=$columnasPredeterminadas;
// La lista de cajas siempre incluye descripción y precio público.
foreach(['descripcion','precio_publico'] as $obligatoria){if(!in_array($obligatoria,$elegidas,true))$elegidas[]=$obligatoria;}

$where=[];$params=['sucursal'=>$sucursal];
if($estado==='activo')$where[]='p.estado=1';
if($q!==''){$where[]='(p.codigo LIKE :q1 OR p.codigo_barras LIKE :q2 OR p.descripcion LIKE :q3 OR p.laboratorio LIKE :q4)';foreach(['q1','q2','q3','q4'] as $k)$params[$k]='%'.$q.'%';}
if($precio==='con')$where[]='pp.precio_publico IS NOT NULL AND pp.precio_publico>0';
if($precio==='sin')$where[]='(pp.precio_publico IS NULL OR pp.precio_publico<=0)';
$from=' FROM productos p LEFT JOIN pos_precios pp ON pp.producto_id=p.id AND pp.sucursal_id=:sucursal LEFT JOIN pos_presentaciones pres ON pres.producto_id=p.id AND pres.sucursal_id=pp.sucursal_id ';
$conditions=$where?' WHERE '.implode(' AND ',$where):'';
$select='SELECT p.codigo,p.codigo_barras,p.descripcion,p.laboratorio,p.unidad_medida,p.estado,pp.precio_publico,pres.modalidad_presentacion,pres.piezas_por_caja ';
$error='';$total=0;$filas=[];
try{
 $pdo=(new Database())->connect();
 $st=$pdo->prepare('SELECT COUNT(*)'.$from.$conditions);$st->execute($params);$total=(int)$st->fetchColumn();
 $sql=$select.$from.$conditions.' ORDER BY p.descripcion ASC,p.id ASC';
 if($formato==='')$sql.=' LIMIT 40 OFFSET '.(max(0,$pagina-1)*40);
 $st=$pdo->prepare($sql);$st->execute($params);$filas=$st->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){error_log('Lista precios GYD: '.$e->getMessage());$error='No se pudo consultar el Catálogo Maestro o sus precios por sucursal.';}
function lp_precio($v):string{return $v===null || (float)$v<=0?'Sin configurar':'$'.number_format((float)$v,2,'.',',');}
function lp_presentacion(array $r):string{
 $m=trim((string)($r['modalidad_presentacion']??''));$n=(int)($r['piezas_por_caja']??0);
 return $m===''?'—':($m.($n>1?' · '.$n.' pzas/caja':''));
}
function lp_csv_seguro($v):string{$s=(string)$v;return preg_match('/^\s*[=+@\-]/u',$s)?"'".$s:$s;}
$headers=array_map(static fn($c)=>$columnasDisponibles[$c],$elegidas);
function lp_campos(array $r,array $cols):array{
 $values=['codigo'=>(string)($r['codigo']??''),'codigo_barras'=>(string)($r['codigo_barras']??''),'descripcion'=>(string)($r['descripcion']??''),'laboratorio'=>(string)($r['laboratorio']??''),'presentacion'=>lp_presentacion($r),'precio_publico'=>lp_precio($r['precio_publico']??null),'estado'=>((int)($r['estado']??0)===1?'Activo':'Inactivo')];
 return array_map(static fn($c)=>$values[$c],$cols);
}
$nombreArchivo='lista_precios_GYD_'.str_replace(' ','_',iconv('UTF-8','ASCII//TRANSLIT',$sucursales[$sucursal])?:'sucursal').'_'.date('Ymd');
if($formato==='csv' && !$error){
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$nombreArchivo.'.csv"');
 $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$headers);
 foreach($filas as $r)fputcsv($out,array_map('lp_csv_seguro',lp_campos($r,$elegidas)));fclose($out);exit;
}
if($formato==='xlsx' && !$error){
 $autoload=__DIR__.'/../vendor/autoload.php';
 if(!is_file($autoload)){http_response_code(503);exit('No está disponible Excel. Usa CSV.');}require_once $autoload;
 if(!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)){http_response_code(503);exit('No está disponible Excel. Usa CSV.');}
 $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Precios');
 foreach($headers as $i=>$t)$sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1).'1',$t,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
 $lastCol=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($elegidas));
 $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
 $sheet->getStyle('A1:'.$lastCol.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF143F69');
 foreach($filas as $i=>$r){
  $row=$i+2;$fields=lp_campos($r,$elegidas);
  foreach($fields as $j=>$v){
   $col=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j+1);
   if($elegidas[$j]==='precio_publico' && ($r['precio_publico']??null)!==null && (float)$r['precio_publico']>0){
    $sheet->setCellValue($col.$row,(float)$r['precio_publico']);$sheet->getStyle($col.$row)->getNumberFormat()->setFormatCode('"$"#,##0.00');
   }else{$sheet->setCellValueExplicit($col.$row,(string)$v,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);}
  }
 }
 foreach($elegidas as $i=>$key){$col=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1);$sheet->getColumnDimension($col)->setWidth(['descripcion'=>56,'laboratorio'=>34,'codigo_barras'=>24,'presentacion'=>26,'precio_publico'=>20][$key]??20);}
 $sheet->freezePane('A2');$sheet->setAutoFilter('A1:'.$lastCol.'1');
 header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$nombreArchivo.'.xlsx"');
 (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');$book->disconnectWorksheets();exit;
}
$base=['sucursal'=>$sucursal,'q'=>$q,'estado'=>$estado,'precio'=>$precio,'cols'=>$elegidas];
function lp_url(array $extra=[]):string{global $base;return '?'.http_build_query(array_merge($base,$extra));}
$paginas=max(1,(int)ceil($total/40));$pagina=min($pagina,$paginas);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G&D | Lista de precios</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#123558;background:#eef3f9}*{box-sizing:border-box}body{margin:0}.cab{background:#153c65;color:white;border-top:5px solid #d51232;padding:22px max(18px,calc((100% - 1250px)/2));display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}.cab h1{margin:0;font-size:25px}.cab p{margin:6px 0;color:#cbdff3;font-size:13px}.links{display:flex;gap:9px;flex-wrap:wrap}a.btn,.btn{display:inline-flex;align-items:center;justify-content:center;background:#1557a0;color:white;text-decoration:none;border:0;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;font-size:13px}.btn.soft{background:#e7eff9;color:#15406b}.btn.green{background:#158052}.btn.red{background:#b72343}.cab .btn{background:white;color:#17416a}main{max-width:1250px;margin:24px auto;padding:0 16px}.card{background:white;border:1px solid #dce5ef;border-radius:13px;box-shadow:0 3px 13px #183e6410;padding:19px;margin-bottom:17px}h2{font-size:17px;margin:0 0 13px}.muted{color:#57718b;font-size:13px}.columns{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-top:10px}.colcheck{display:flex;align-items:center;gap:8px;background:#f2f6fd;border:1px solid #dce6f3;border-radius:8px;padding:10px;font-size:12px}.colcheck input{width:auto;margin:0}.filters{display:grid;grid-template-columns:2fr repeat(3,1fr);gap:12px}label{display:block;font-size:12px;font-weight:700}input,select{display:block;width:100%;margin-top:6px;padding:11px;border:1px solid #bacce0;border-radius:7px;color:#163655;background:white}.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:15px}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.metric{background:white;border:1px solid #dce5ef;border-radius:12px;padding:18px}.metric strong{font-size:25px;display:block;color:#14528d}.metric span{color:#60758d;font-size:12px}.tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px}th{background:#1b4c7c;color:white;text-align:left;padding:12px 10px;white-space:nowrap}td{padding:10px;border-bottom:1px solid #e0e8f0}tr:nth-child(even){background:#f7faff}.price{font-weight:800;color:#12467b;white-space:nowrap}.missing{color:#a12b40;font-weight:700}.tag{font-size:11px;background:#e8f2fb;border-radius:5px;padding:4px 7px}.foot{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:15px}.notice{background:#fff5dc;border:1px solid #ead09a;border-radius:9px;padding:11px;color:#765214;margin:14px 0;font-size:13px}.printHead{display:none}@media(max-width:850px){.filters,.columns{grid-template-columns:1fr 1fr}.summary{grid-template-columns:1fr 1fr}}@media(max-width:540px){.filters,.summary,.columns{grid-template-columns:1fr}.cab h1{font-size:20px}}@media print{@page{size:letter landscape;margin:10mm}body{background:white}.cab,.filters-card,.actions,.foot,.metric{display:none!important}main{margin:0;max-width:none;padding:0}.card{border:0;box-shadow:none;padding:0}.printHead{display:block;margin:0 0 12px;font-weight:800}.tablewrap{overflow:visible}thead{display:table-header-group}tr{break-inside:avoid}table{font-size:10px}td,th{padding:6px}}
</style></head><body><header class="cab"><div><h1>G&amp;D · Lista de precios</h1><p>Precios de venta al público · Consulta para Línea de Cajas · Solo lectura</p></div><div class="links"><a class="btn" href="erp_catalogo_maestro.php">Catálogo Maestro</a><a class="btn" href="<?=lp_h($rol==='COMPRAS'?'erp_compras_inicio.php':'dashboard.php')?>">Inicio ERP</a></div></header><main>
<div class="card filters-card"><h2>Filtros de la lista</h2><form method="get"><div class="filters"><label>Buscar código, barras o descripción<input name="q" value="<?=lp_h($q)?>" placeholder="Nombre o código del producto"></label><label>Sucursal / precio público<select name="sucursal"><?php foreach($sucursales as $id=>$n):?><option value="<?=$id?>" <?=$sucursal===$id?'selected':''?>><?=lp_h($n)?></option><?php endforeach;?></select></label><label>Productos<select name="estado"><option value="activo" <?=$estado==='activo'?'selected':''?>>Solo activos</option><option value="todos" <?=$estado==='todos'?'selected':''?>>Todos</option></select></label><label>Precio configurado<select name="precio"><option value="todos" <?=$precio==='todos'?'selected':''?>>Todos</option><option value="con" <?=$precio==='con'?'selected':''?>>Con precio</option><option value="sin" <?=$precio==='sin'?'selected':''?>>Sin precio</option></select></label></div><h2 style="margin:18px 0 3px">Columnas que llevará la lista</h2><p class="muted">Selecciona la información que verá Línea de Cajas. Descripción y precio público siempre se incluyen. La selección se conserva al exportar.</p><div class="columns"><?php foreach($columnasDisponibles as $key=>$nombre):?><label class="colcheck"><input type="checkbox" name="cols[]" value="<?=lp_h($key)?>" <?=in_array($key,$elegidas,true)?'checked':''?> <?=in_array($key,['descripcion','precio_publico'],true)?'disabled':''?>><?=lp_h($nombre)?></label><?php endforeach;?><input type="hidden" name="cols[]" value="descripcion"><input type="hidden" name="cols[]" value="precio_publico"></div><div class="actions"><button class="btn" type="submit">Generar lista</button><a class="btn soft" href="erp_lista_precios.php">Limpiar filtros</a><button class="btn green" type="submit" name="formato" value="xlsx">Exportar Excel (.xlsx)</button><button class="btn soft" type="submit" name="formato" value="csv">Exportar CSV</button><button class="btn red" type="submit" name="formato" value="imprimir" formtarget="_blank">Imprimir / Guardar PDF</button></div></form></div>
<div class="summary"><div class="metric"><strong><?=number_format($total)?></strong><span>Productos en el filtro</span></div><div class="metric"><strong><?=lp_h($sucursales[$sucursal])?></strong><span>Sucursal seleccionada</span></div><div class="metric"><strong>Precio público</strong><span>Sin costos ni márgenes internos</span></div></div>
<div class="card" style="margin-top:17px"><div class="printHead">G&amp;D · LISTA DE PRECIOS AL PÚBLICO — <?=lp_h($sucursales[$sucursal])?> · <?=date('d/m/Y H:i')?></div><h2>Lista de precios — <?=lp_h($sucursales[$sucursal])?></h2><p class="muted">Precio vigente consultado del POS para la sucursal seleccionada. Los productos sin precio se muestran como «Sin configurar»; no se inventan importes.</p><?php if($error):?><p class="notice"><?=lp_h($error)?></p><?php endif;?><div class="tablewrap"><table><thead><tr><?php foreach($headers as $h):?><th><?=lp_h($h)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($filas as $r):?><tr><?php foreach(lp_campos($r,$elegidas) as $i=>$valor):?><td class="<?=$elegidas[$i]==='precio_publico'?'price':''?>"><?=lp_h($valor)?></td><?php endforeach;?></tr><?php endforeach;?><?php if(!$filas):?><tr><td colspan="<?=count($elegidas)?>">No se encontraron productos.</td></tr><?php endif;?></tbody></table></div><?php if($formato===''):?><div class="foot"><span class="muted">Página <?=$pagina?> de <?=$paginas?> · <?=number_format($total)?> resultados</span><div class="links"><?php if($pagina>1):?><a class="btn soft" href="<?=lp_h(lp_url(['pagina'=>$pagina-1]))?>">Anterior</a><?php endif;?><?php if($pagina<$paginas):?><a class="btn soft" href="<?=lp_h(lp_url(['pagina'=>$pagina+1]))?>">Siguiente</a><?php endif;?></div></div><?php endif;?></div>
<p class="muted">Documento informativo para Línea de Cajas. Verifica el precio vigente en el POS antes de cobrar; este reporte no cambia precios, existencias ni ventas.</p></main><?php if($formato==='imprimir'):?><script>window.addEventListener('load',()=>window.print());</script><?php endif;?></body></html>
