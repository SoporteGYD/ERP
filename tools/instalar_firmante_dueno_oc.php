<?php
declare(strict_types=1);
/** G&D: instala firma documental de Dueño en OC sin habilitar aprobación electrónica. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Ejecuta el instalador desde PowerShell.');}
$root=dirname(__DIR__);
$src=__DIR__.DIRECTORY_SEPARATOR.'gyd_oc_firma_dueno_src';
$mode=$argv[1]??'--help';
$manifestPath=(getenv('LOCALAPPDATA')?:sys_get_temp_dir()).DIRECTORY_SEPARATOR.'GYD'.DIRECTORY_SEPARATOR.'Respaldos_OC_Firma'.DIRECTORY_SEPARATOR.'ultima_instalacion.json';
$targets=['public/erp_oc_win.php','app/helpers/erp_oc_win.php','app/helpers/erp_oc_win_pdf.php'];
$helperRel='app/helpers/erp_oc_firma_dueno.php';
function ocfi_err(string $message):never{fwrite(STDERR,"ERROR: ".$message.PHP_EOL);exit(1);}
function ocfi_read(string $path):string{$s=@file_get_contents($path);if($s===false)throw new RuntimeException('No pude leer '.$path);return $s;}
function ocfi_conn(string $root):PDO{
    require_once $root.'/app/config/database.php';
    $pdo=(new Database())->connect();
    $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $port=(int)$pdo->query('SELECT @@port')->fetchColumn();
    echo "Base conectada: {$db} | puerto {$port}".PHP_EOL;
    if($db!=='prueba'||$port!==3307)throw new RuntimeException('Se exige base de pruebas prueba:3307. No se aplicarán cambios en otra base.');
    return $pdo;
}
function ocfi_table(PDO $pdo,string $table='erp_oc_firmante_dueno'):bool{
    if(!in_array($table,['erp_oc_firmante_dueno','erp_oc_firmante_historial'],true))throw new RuntimeException('Tabla no permitida');
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$table]);
    return (int)$q->fetchColumn()===1;
}
function ocfi_schema(PDO $pdo):void{
    if(!ocfi_table($pdo))return;
    $cols=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='erp_oc_firmante_dueno'")->fetchAll(PDO::FETCH_COLUMN);
    foreach(['orden_id','usuario_id','nombre_snapshot','asignado_por','creado_en','actualizado_en'] as $col){
        if(!in_array($col,$cols,true))throw new RuntimeException('La tabla existente erp_oc_firmante_dueno no tiene la columna '.$col.'; revisión manual necesaria.');
    }
    if(ocfi_table($pdo,'erp_oc_firmante_historial')){
        $cols=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='erp_oc_firmante_historial'")->fetchAll(PDO::FETCH_COLUMN);
        foreach(['id','orden_id','usuario_id','nombre_snapshot','registrado_por','registrado_en'] as $col){
            if(!in_array($col,$cols,true))throw new RuntimeException('Tabla de historial incompatible: falta '.$col);
        }
    }
}
function ocfi_replacements(string $src,array $targets):array{
    $all=json_decode(ocfi_read($src.'/replacements.json'),true,512,JSON_THROW_ON_ERROR);
    $a=array_keys($all);$b=$targets;sort($a);sort($b);
    if($a!==$b)throw new RuntimeException('Listado de archivos alterado en replacements.json.');
    return $all;
}
/** Firma PDF compatible con variaciones de espacios, acentos y ajustes previos en rótulos.
 * Sólo inyecta variables justo antes de la sección de firmas existente; no toca el PDF restante.
 */
function ocfi_pdf_flexible(string $original):string {
    if(str_contains($original,'GYD_OC_FIRMANTE_DUENO_V2') || str_contains($original,'GYD_OC_FIRMANTE_DUENO:')){
        throw new RuntimeException('La firma documental ya parece instalada en el PDF. No se duplicará.');
    }
    if(substr_count($original,'function ocw_pdf_compose(')!==1){
        throw new RuntimeException('No se reconoció el constructor del PDF de OC; revisión manual necesaria.');
    }
    $start=strpos($original,'function ocw_pdf_compose(');
    $part=substr($original,$start);
    // Deben existir ambas variables usadas para imprimir el nombre y el título de la firma.
    if(!str_contains($part,'$autorizo') || !str_contains($part,'$tituloSegundaFirma') ||
       !str_contains($part,'ocw_pdf_t(') || !str_contains($part,'$sigY')){
        throw new RuntimeException('Firma del PDF no reconocida. No se sobrescribe el archivo.');
    }
    $count=preg_match_all('/(?<![A-Za-z0-9_])\$sigY\s*=\s*[0-9]+\s*;/', $part);
    if($count!==1){
        throw new RuntimeException('No se encontró un único bloque de firmas en el PDF de OC. No se sobrescribe.');
    }
    $insert=<<<'PHP'

 // GYD_OC_FIRMANTE_DUENO_V2: nombre documental para firma física, sin aprobación electrónica.
 $firmanteDueno=trim((string)($o['oc_firmante_nombre']??''));
 $autorizo=$firmanteDueno!==''?$firmanteDueno:'SIN DESIGNAR';
 $tituloSegundaFirma='Autorizacion (firma)';
 $body.=ocw_pdf_t(306,$sigY-15,'Nombre designado; firma manuscrita pendiente',7);
PHP;
    // Insertar después de inicializar la coordenada de firmas, antes de imprimir los textos.
    $part=preg_replace_callback('/(?<![A-Za-z0-9_])\$sigY\s*=\s*[0-9]+\s*;/',static fn(array $m):string=>$m[0].$insert,$part,1,$replaced);
    if($replaced!==1)throw new RuntimeException('No fue posible insertar la firma en el PDF.');
    return substr($original,0,$start).$part;
}
/** Inserción focalizada en la estructura PHP/HTML actual (V3), no en texto idéntico de una versión.
 * Tolera cambios de espacios y scripts añadidos, pero exige anclas únicas y aborta si faltan.
 */
function ocfi_regex_once(string $source,string $pattern,callable $callback,string $desc):string {
    $n=preg_match_all($pattern,$source);
    if($n!==1)throw new RuntimeException('OC compatible V3: falta o es ambiguo «'.$desc.'» (coincidencias '.var_export($n,true).'). No se sobrescribe.');
    $result=preg_replace_callback($pattern,$callback,$source,1,$replaced);
    if(!is_string($result)||$replaced!==1)throw new RuntimeException('No se pudo integrar «'.$desc.'» en la OC.');
    return $result;
}
function ocfi_public_flexible(string $original,array $rules):string {
    if(count($rules)!==10)throw new RuntimeException('Se requieren diez puntos de integración de Orden de Compra.');
    $content=$original;
    foreach($rules as $i=>$rule){
        $find=$rule['find']??null;$replace=$rule['replace']??null;
        if(!is_string($find)||!is_string($replace)||$find==='')throw new RuntimeException('Regla de OC V3 dañada: '.$i);
        $hits=substr_count($content,$find);
        if($hits===1){$content=str_replace($find,$replace,$content);continue;}
        // Si el fragmento exacto fue modificado por mejoras posteriores, insertar en el ancla semántica.
        switch($i){
            case 0:
                $content=ocfi_regex_once($content,
                    '~(?m)^\s*require_once\s+__DIR__\s*\.\s*[\'\"]\x2f\.\.\x2fapp\x2fhelpers\x2ferp_oc_win\.php[\'\"]\s*;~',
                    static fn(array $m):string=>$m[0]."\nrequire_once __DIR__.'/../app/helpers/erp_oc_firma_dueno.php'; // GYD_OC_FIRMANTE_DUENO_V1",
                    'include del controlador de Órdenes de Compra');
                break;
            case 1:
                $content=ocfi_regex_once($content,
                    '~\bif\s*\(\s*\$act\s*===\s*[\'\"]crear[\'\"]\s*\)\s*\{~',
                    static fn(array $m):string=>$m[0]."\n   \$ocFirmaId=(int)(\$_POST['oc_firma_dueno_id']??0);\n   \$ocFirma=\$ocFirmaId>0?ocfd_usuario(\$db,\$ocFirmaId):null;",
                    'acción crear de la OC');
                break;
            case 2:
                // Debe ejecutarse antes del COMMIT de nueva OC: escritura del firmante en la misma transacción.
                $content=ocfi_regex_once($content,
                    '~\$db\s*->\s*commit\s*\(\s*\)\s*;\s*\$_SESSION\s*\[\s*[\'\"]ocw_flash[\'\"]\s*\]\s*=\s*[\'\"]Orden\s+[\'\"]\s*\.\s*\$folio~',
                    static fn(array $m):string=>"if(\$ocFirma!==null)ocfd_guardar(\$db,\$id,\$ocFirma,(int)\$user['id']); // Firma documental, no aprobación.\n   ".$m[0],
                    'confirmación transaccional al registrar la OC');
                break;
            case 3:
                $at=strpos($replace,$find);
                if($at===false)throw new RuntimeException('Regla guardar_firmante inválida.');
                $prefix=substr($replace,0,$at);
                $content=ocfi_regex_once($content,
                    '~\bif\s*\(\s*\$act\s*===\s*[\'\"]liberar[\'\"]\s*&&~',
                    static fn(array $m):string=>$prefix.$m[0],
                    'acción liberar / ubicación de guardar firmante');
                break;
            case 4:
                $content=ocfi_regex_once($content,
                    '~\$proveedores\s*=\s*\$editable\s*\?~',
                    static fn(array $m):string=>"\$firmantes=\$editable?ocfd_usuarios(\$db):[];\n ".$m[0],
                    'consulta de proveedores');
                break;
            case 5:
                // El catch puede tener nuevos campos. Inicializar por defecto ANTES del try exterior.
                $content=ocfi_regex_once($content,
                    '~(?m)^try\s*\{~',
                    static fn(array $m):string=>"\$firmantes=[]; // Respaldo de presentación si la consulta falla.\n".$m[0],
                    'inicio de consulta principal de la OC');
                break;
            case 6:
                if(!str_ends_with($replace,$find))throw new RuntimeException('Regla CSS OC mal formada.');
                $css=substr($replace,0,-strlen($find));
                $content=ocfi_regex_once($content,
                    '~</style\s*>~i',
                    static fn(array $m):string=>$css.$m[0],
                    'hoja de estilos del formulario');
                break;
            case 7:
                $at=strpos($replace,$find);
                if($at===false)throw new RuntimeException('Regla selector en OC guardada mal formada.');
                $before=substr($replace,0,$at);
                $content=ocfi_regex_once($content,
                    '~<div\b[^>]*\bclass\s*=\s*[\'\"][^\'\"]*\bsaved-actions\b[^\'\"]*[\'\"][^>]*>~i',
                    static fn(array $m):string=>$before.$m[0],
                    'acciones de órdenes guardadas');
                break;
            case 8:
                if(!str_starts_with($replace,$find))throw new RuntimeException('Regla selector nuevo mal formada.');
                $after=substr($replace,strlen($find));
                $n=preg_match_all('~\bid\s*=\s*[\'\"]autorizo_info[\'\"]~i',$content,$m,PREG_OFFSET_CAPTURE);
                if($n!==1)throw new RuntimeException('Campo Estado del trámite inexistente o duplicado.');
                $start=$m[0][0][1]+strlen($m[0][0][0]);
                $end=strpos($content,'</div>',$start);
                if($end===false||($end-$start)>1600)throw new RuntimeException('No se detectó el cierre del campo Estado del trámite.');
                $where=$end+strlen('</div>');
                $content=substr($content,0,$where).$after.substr($content,$where);
                break;
            case 9:
                if(!str_starts_with($find,'</main>')||!str_starts_with($replace,'</main>'))throw new RuntimeException('Regla modal mal formada.');
                $tail=substr($find,strlen('</main>'));
                if(!str_ends_with($replace,$tail))throw new RuntimeException('Cierre del modal mal formado.');
                $insertion=substr($replace,strlen('</main>'),-strlen($tail));
                $content=ocfi_regex_once($content,
                    '~</main\s*>~i',
                    static fn(array $m):string=>$m[0].$insertion,
                    'contenedor principal del formulario');
                break;
            default:throw new RuntimeException('Regla no reconocida en OC: '.$i);
        }
    }
    foreach(['ocfdModal','guardar_firmante','ocfdNewId','ocfdSavedId','ocfd_usuarios','ocfd_guardar','oc_firma_dueno_id','erp_oc_firma_dueno.php'] as $marker){
        if(!str_contains($content,$marker))throw new RuntimeException('Falló el ensamblado del selector: '.$marker);
    }
    return $content;
}
function ocfi_calc(string $original,array $rules,string $label):string{
    if($label==='app/helpers/erp_oc_win_pdf.php')return ocfi_pdf_flexible($original);
    if($label==='public/erp_oc_win.php')return ocfi_public_flexible($original,$rules);
    $content=$original;
    foreach($rules as $rule){
        $find=$rule['find']??null;$replace=$rule['replace']??null;
        if(!is_string($find)||!is_string($replace)||$find===''||substr_count($content,$find)!==1){
            throw new RuntimeException('Incompatibilidad en '.$label.' (no coincide un fragmento original). No se sobrescribe.');
        }
        $content=str_replace($find,$replace,$content);
    }
    return $content;
}
function ocfi_preflight(string $root,string $src,array $targets):array{
    $rules=ocfi_replacements($src,$targets);$changes=[];
    if(is_file($root.'/app/helpers/erp_oc_firma_dueno.php')){
        throw new RuntimeException('Ya existe app/helpers/erp_oc_firma_dueno.php. Revisa si la instalación fue aplicada previamente.');
    }
    foreach($rules as $rel=>$steps){
        $path=$root.'/'.$rel;$old=ocfi_read($path);
        if(str_contains($old,'GYD_OC_FIRMANTE_DUENO_V1'))throw new RuntimeException('El parche ya está presente en '.$rel);
        $new=ocfi_calc($old,$steps,$rel);
        $changes[$rel]=['old'=>$old,'new'=>$new];
    }
    return $changes;
}
function ocfi_write(string $path,string $data):void{
    $temp=$path.'.ocfi.tmp.'.getmypid();
    if(file_put_contents($temp,$data,LOCK_EX)===false)throw new RuntimeException('No pude escribir '.$temp);
    if(!rename($temp,$path)){@unlink($temp);throw new RuntimeException('No pude reemplazar '.$path);}
}
try{
    if(!in_array($mode,['--check','--apply','--verify','--rollback'],true)){
        echo "Uso: php tools/instalar_firmante_dueno_oc.php --check | --apply | --verify | --rollback".PHP_EOL;
        exit(0);
    }
    if($mode==='--rollback'){
        if(!is_file($manifestPath))throw new RuntimeException('No existe manifiesto de una instalación anterior.');
        $m=json_decode(ocfi_read($manifestPath),true,512,JSON_THROW_ON_ERROR);
        if(($m['root']??'')!==realpath($root))throw new RuntimeException('El manifiesto no corresponde a este proyecto.');
        foreach(($m['files']??[]) as $rel=>$v){
            $now=ocfi_read($root.'/'.$rel);
            if(hash('sha256',$now)!==($v['installed_sha256']??''))throw new RuntimeException('Archivo modificado después de instalar: '.$rel.'; no sobrescribiré tus cambios.');
        }
        $helper=$root.'/'.$helperRel;
        if(is_file($helper)&&hash_file('sha256',$helper)!==($m['helper_sha256']??''))throw new RuntimeException('El auxiliar fue modificado; no se eliminará.');
        foreach($m['files'] as $rel=>$v)ocfi_write($root.'/'.$rel,ocfi_read($v['backup']));
        if(is_file($helper))unlink($helper);
        echo "ROLLBACK OK: archivos restaurados. Se conserva la tabla documental y sus registros por seguridad.".PHP_EOL;
        exit(0);
    }
    if($mode==='--verify'){
        $pdo=ocfi_conn($root);if(!ocfi_table($pdo) || !ocfi_table($pdo,'erp_oc_firmante_historial'))throw new RuntimeException('Faltan las tablas de firmante e historial.');ocfi_schema($pdo);
        foreach($targets as $rel){
            $t=ocfi_read($root.'/'.$rel);
            if($rel==='app/helpers/erp_oc_win.php' && !str_contains($t,'oc_firmante_nombre'))throw new RuntimeException('Falta join en '.$rel);
            if($rel==='app/helpers/erp_oc_win_pdf.php' && !str_contains($t,'firmanteDueno'))throw new RuntimeException('Falta firma en PDF.');
            if($rel==='public/erp_oc_win.php' && (!str_contains($t,'ocfdModal') || !str_contains($t,'guardar_firmante')))throw new RuntimeException('Falta formulario/modal en la OC.');
        }
        if(!is_file($root.'/'.$helperRel))throw new RuntimeException('Falta auxiliar de firmantes.');
        echo "VERIFICADO: modal, selección validada, persistencia y PDF. No se han emitido aprobaciones digitales.".PHP_EOL;
        exit(0);
    }
    $changes=ocfi_preflight($root,$src,$targets);
    echo 'Archivos compatibles: '.count($changes).'.'.PHP_EOL;
    if($mode==='--check'){
        $pdo=ocfi_conn($root);ocfi_schema($pdo);
        $q=$pdo->query("SELECT COUNT(*) FROM usuarios WHERE estado=1 AND UPPER(TRIM(rol)) IN ('DUENO','DUEÑO','PROPIETARIO','DIRECCION')");
        echo 'Usuarios Dueño/Dirección activos para mostrar: '.(int)$q->fetchColumn().PHP_EOL;
        echo "COMPATIBLE. --apply creará una tabla de asignación documental; no cambiará inventario, órdenes ni permisos.".PHP_EOL;
        exit(0);
    }
    $pdo=ocfi_conn($root);ocfi_schema($pdo);
    $base=(getenv('LOCALAPPDATA')?:sys_get_temp_dir()).DIRECTORY_SEPARATOR.'GYD'.DIRECTORY_SEPARATOR.'Respaldos_OC_Firma';
    $backupDir=$base.DIRECTORY_SEPARATOR.date('Ymd_His').'_'.bin2hex(random_bytes(3));
    if(!is_dir($backupDir)&&!mkdir($backupDir,0770,true))throw new RuntimeException('No pude crear la carpeta de respaldo.');
    $fileMeta=[];
    foreach($changes as $rel=>$v){
        $path=$backupDir.'/'.str_replace('/','__',$rel);
        if(file_put_contents($path,$v['old'],LOCK_EX)===false)throw new RuntimeException('No pude respaldar '.$rel);
        $fileMeta[$rel]=['backup'=>$path,'installed_sha256'=>hash('sha256',$v['new'])];
    }
    $helperSrc=ocfi_read($src.'/erp_oc_firma_dueno.php');
    // La tabla documental es independiente de los saldos y se conserva aun al restaurar archivos.
    $statements=preg_split('/;\s*(?:\r?\n|$)/',trim(ocfi_read($src.'/erp_oc_firmante_dueno.sql')))?:[];
    foreach($statements as $statement){if(trim($statement)!=='')$pdo->exec(trim($statement));}
    ocfi_schema($pdo);
    $written=[];
    try{
        foreach($changes as $rel=>$v){ocfi_write($root.'/'.$rel,$v['new']);$written[]=$rel;}
        ocfi_write($root.'/'.$helperRel,$helperSrc);
        $manifest=['root'=>realpath($root),'installed_at'=>date('c'),'files'=>$fileMeta,'helper_sha256'=>hash('sha256',$helperSrc)];
        if(file_put_contents($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('No pude guardar el manifiesto de restauración.');
    }catch(Throwable $e){
        foreach($written as $rel)ocfi_write($root.'/'.$rel,$changes[$rel]['old']);
        if(is_file($root.'/'.$helperRel)&&hash_file('sha256',$root.'/'.$helperRel)===hash('sha256',$helperSrc))unlink($root.'/'.$helperRel);
        throw $e;
    }
    echo "INSTALADO: selector de Dueño para firma en OC y PDF. Respaldos: {$backupDir}".PHP_EOL;
    echo "Reinicia Apache, Ctrl+F5, luego ejecuta --verify.".PHP_EOL;
}catch(Throwable $e){ocfi_err($e->getMessage());}
