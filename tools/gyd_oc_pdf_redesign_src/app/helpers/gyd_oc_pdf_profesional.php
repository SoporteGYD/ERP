<?php
declare(strict_types=1);
/**
 * G&D ERP: presentación profesional para órdenes de compra.
 * Solo compone documentos PDF; no crea/modifica órdenes, facturas ni inventarios.
 * A4 horizontal. Dos variantes: con costos y sin costos.
 */

/** Obtiene un único gerente activo asociado al destino; no presume recepción física. */
function gyd_oc_pdf_nombre_gerente(PDO $db, string $destino): string {
    if ($destino === '') return '';
    $q = $db->prepare("SELECT DISTINCT u.nombre FROM erp_oc_win_gerentes g JOIN usuarios u ON u.id=g.usuario_id WHERE g.destino=? AND g.activo=1 AND u.estado=1 AND u.rol='GERENTE' ORDER BY u.nombre LIMIT 2");
    $q->execute([$destino]);
    $nombres = $q->fetchAll(PDO::FETCH_COLUMN);
    return count($nombres) === 1 ? trim((string)$nombres[0]) : '';
}

final class GydOcPdfCanvas {
    public const W=842.0;
    public const H=595.0;
    private array $pages=[];
    private string $ops='';
    private string $logoDir;
    public function __construct(string $logoDir) { $this->logoDir=$logoDir; }
    public function start(): void { $this->ops="q\n"; }
    public function end(): void { $this->pages[]=$this->ops."Q\n"; $this->ops=''; }
    public function pagesCount(): int { return count($this->pages); }
    private static function RGB(string $hex):string {
        $hex=ltrim($hex,'#'); if(strlen($hex)!==6) $hex='000000';
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex,0,2))/255,hexdec(substr($hex,2,2))/255,hexdec(substr($hex,4,2))/255);
    }
    public function box(float $x,float $y,float $w,float $h,string $fill,string $stroke='',float $strokeWidth=.6): void {
        $this->ops.=self::RGB($fill)." rg\n";
        if ($stroke!=='') $this->ops.=self::RGB($stroke)." RG\n".sprintf("%.2F w\n",$strokeWidth);
        $this->ops.=sprintf("%.2F %.2F %.2F %.2F re %s\n",$x,$y,$w,$h,$stroke!==''?'B':'f');
    }
    public function line(float $x1,float $y1,float $x2,float $y2,string $color='#B7C7D6',float $weight=.7): void {
        $this->ops.=self::RGB($color)." RG\n".sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n",$weight,$x1,$y1,$x2,$y2);
    }
    public function poly(array $coords,string $fill):void {
        if(count($coords)<3)return;
        $this->ops.=self::RGB($fill)." rg\n";
        foreach($coords as $i=>$pt) $this->ops.=sprintf("%.2F %.2F %s\n",(float)$pt[0],(float)$pt[1],$i===0?'m':'l');
        $this->ops.="h f\n";
    }
    private static function winansi(string $t):string {
        $t=preg_replace('/\s+/u',' ',trim($t))??trim($t);
        $s=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$t);
        return $s===false?preg_replace('/[^\x20-\x7e]/','?', $t):$s;
    }
    private static function escape(string $t):string {
        $b=self::winansi($t);
        return str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)',' ',' '],$b);
    }
    public static function measure(string $t,float $size,bool $bold=false):float {
        // Helvetica approximada de manera conservadora para centrar y limitar textos.
        $s=self::winansi($t);$sum=0;
        for($i=0;$i<strlen($s);$i++) {
            $o=ord($s[$i]);
            if($o===32)$w=278;
            elseif(str_contains('.,:;!|iIl1', $s[$i]))$w=$bold?310:275;
            elseif(str_contains('MW@%',$s[$i]))$w=800;
            elseif($o>=65&&$o<=90)$w=$bold?695:665;
            elseif($o>=48&&$o<=57)$w=556;
            else $w=$bold?570:530;
            $sum+=$w;
        }
        return $sum*$size/1000;
    }
    public static function clip(string $t,float $maxWidth,float $size,bool $bold=false):string {
        $t=trim(preg_replace('/\s+/u',' ',$t)??$t);
        if(self::measure($t,$size,$bold)<=$maxWidth)return $t;
        $chars=preg_split('//u',$t,-1,PREG_SPLIT_NO_EMPTY)?:str_split($t);
        while($chars && self::measure(implode('',$chars).'…',$size,$bold)>$maxWidth)array_pop($chars);
        return implode('',$chars).'…';
    }
    public static function wrap(string $text,float $width,float $size,bool $bold=false,int $maxLines=3):array {
        $text=trim(preg_replace('/\s+/u',' ',$text)??$text);
        if($text==='')return [];
        $words=preg_split('/\s+/u',$text)?:[];
        $lines=[];$part='';
        foreach($words as $word){
            $proposed=$part===''?$word:$part.' '.$word;
            if(self::measure($proposed,$size,$bold)>$width&&$part!==''){
                $lines[]=self::clip($part,$width,$size,$bold);$part=$word;
            }else{$part=$proposed;}
        }
        if($part!=='')$lines[]=self::clip($part,$width,$size,$bold);
        if(count($lines)>$maxLines){
            $lines=array_slice($lines,0,$maxLines);
            $lines[$maxLines-1]=self::clip($lines[$maxLines-1],$width-8,$size,$bold).'…';
        }
        return $lines;
    }
    public function text(float $x,float $y,string $s,float $size=10,bool $bold=false,string $color='#152F52'):void {
        $this->ops.=self::RGB($color)." rg\n";
        $this->ops.=sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",$bold?'F2':'F1',$size,$x,$y,self::escape($s));
    }
    public function centered(float $center,float $y,string $s,float $size=10,bool $bold=false,string $color='#152F52'):void {
        $this->text($center-self::measure($s,$size,$bold)/2,$y,$s,$size,$bold,$color);
    }
    public function right(float $right,float $y,string $s,float $size=10,bool $bold=false,string $color='#152F52'):void {
        $this->text($right-self::measure($s,$size,$bold),$y,$s,$size,$bold,$color);
    }
    public function image(string $name,float $x,float $y,float $w,float $h):void {
        $this->ops.=sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",$w,$h,$x,$y,$name);
    }
    public function toPdf():string {
        if(!$this->pages)throw new RuntimeException('Documento sin páginas.');
        $objects=[];
        $add=static function(string $data)use(&$objects):int{$objects[]=$data;return count($objects);};
        $catalog=$add('');$pages=$add('');
        $f1=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $f2=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $xobjs=[];
        foreach(['Logo'=>'gyd_farmacia.jpg','Mascota'=>'gyd_mascota.jpg'] as $name=>$file){
            $path=$this->logoDir.DIRECTORY_SEPARATOR.$file;
            if(!is_file($path))throw new RuntimeException('Falta el logotipo corporativo '.$file.'. Revisa la instalación del PDF.');
            $img=@getimagesize($path);
            if(!is_array($img)||($img[2]??0)!==IMAGETYPE_JPEG)throw new RuntimeException('Logotipo JPEG inválido: '.$file);
            $bytes=@file_get_contents($path);if($bytes===false)throw new RuntimeException('No se pudo leer '.$file);
            $ref=$add('<< /Type /XObject /Subtype /Image /Width '.(int)$img[0].' /Height '.(int)$img[1].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($bytes)." >>\nstream\n".$bytes."\nendstream");
            $xobjs[]='/'.$name.' '.$ref.' 0 R';
        }
        $refs=[];
        foreach($this->pages as $i=>$page) {
            // Un pie uniforme en todas las páginas.
            $footer=sprintf("q 0.12 0.29 0.49 rg BT /F1 7 Tf 36 18 Td (ERP G&D  |  Página %d de %d  |  Documento de compra) Tj ET Q\n",$i+1,count($this->pages));
            $stream=$page.$footer;
            $streamRef=$add('<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream");
            $pageRef=$add('<< /Type /Page /Parent '.$pages.' 0 R /MediaBox [0 0 '.self::W.' '.self::H.'] /Resources << /Font << /F1 '.$f1.' 0 R /F2 '.$f2.' 0 R >> /XObject << '.implode(' ',$xobjs).' >> >> /Contents '.$streamRef.' 0 R >>');
            $refs[]=$pageRef.' 0 R';
        }
        $objects[$pages-1]='<< /Type /Pages /Kids ['.implode(' ',$refs).'] /Count '.count($refs).' >>';
        $objects[$catalog-1]='<< /Type /Catalog /Pages '.$pages.' 0 R >>';
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$positions=[0];
        foreach($objects as $i=>$obj){$positions[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$obj."\nendobj\n";}
        $xref=strlen($pdf);
        $pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=count($objects);$i++)$pdf.=sprintf('%010d 00000 n ', $positions[$i])."\n";
        return $pdf."trailer\n<< /Size ".(count($objects)+1).' /Root '.$catalog." 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }
}

function gyd_oc_pdf_profesional(array $o,array $items,bool $mostrarCostos=true):string {
    $d=new GydOcPdfCanvas(__DIR__.'/../assets/oc_pdf');
    $azul='#113F72';$azulProf='#0B2E55';$rojo='#D71B2B';$tinta='#172B45';$gris='#E9F1F9';
    $dest=(string)($o['destino']??'');
    $destLabel=function_exists('ocw_destinos')?(ocw_destinos()[$dest]??$dest):$dest;
    $date=function(?string $t):string { $s=strtotime($t??'');return $s!==false?date('d/m/Y',$s):'—'; };
    $folio=(string)($o['folio']??'');
    if($folio==='') $folio='OC-'.str_pad((string)($o['id']??''),7,'0',STR_PAD_LEFT);
    $numeroDocumento= function_exists('ocw_numero')?ocw_numero((int)($o['id']??0)):str_pad((string)($o['id']??0),12,'0',STR_PAD_LEFT);
    $proveedor=trim((string)($o['proveedor']??''));
    $emitio=trim((string)($o['emitio']??''))?:'SIN REGISTRAR';
    $firma=trim((string)($o['oc_firmante_nombre']??''));
    // La firma del Dueño es documental; no representa autorización electrónica.
    $autorizo=$firma!==''?$firma:'SIN DESIGNAR';
    $gerente=trim((string)($o['oc_gerente_destino_nombre']??''));
    $gerenteMostrar=$gerente!==''?$gerente:'NOMBRE POR CONFIRMAR';
    $rows=[];
    foreach($items as $item){
        $nombre=(string)($item['descripcion_snapshot']??$item['descripcion']??'');
        $wrap=GydOcPdfCanvas::wrap($nombre,$mostrarCostos?324:450,8.5,false,3);
        $h=max(23,12*count($wrap)+7);
        $rows[]=['it'=>$item,'nombre'=>$wrap,'h'=>$h];
    }
    $maxFinalY=235.0;
    $newPage=static function(GydOcPdfCanvas $pdf,int $page)use($o,$mostrarCostos,$folio,$destLabel,$date,$azul,$rojo,$azulProf,$tinta,$proveedor,$numeroDocumento):float {
        $pdf->start();
        // Encabezado corporativo.
        $pdf->box(0,586,842,9,$azulProf);
        $pdf->poly([[410,586],[842,586],[842,580],[487,580]],$rojo);
        $pdf->image('Logo',31,507,89,78);
        $pdf->line(131,518,131,578,'#9FB4CA',1);
        $pdf->text(146,554,'DISTRIBUCIÓN G&D',17,true,$azulProf);
        $pdf->text(146,536,'S.A. DE C.V.',10.4,true,$azulProf);
        $pdf->text(146,522,'DOCUMENTO OFICIAL DE COMPRAS',7.4,false,'#607A98');
        $pdf->image('Mascota',740,504,72,83.4);
        // Título y número/fecha.
        $pdf->box(29,467,454,38,$azulProf);
        $pdf->poly([[29,467],[78,467],[97,505],[29,505]],$rojo);
        $pdf->text(51,479,'OC',13,true,'#FFFFFF');
        $pdf->text(108,479,'ORDEN DE COMPRA',20.2,true,'#FFFFFF');
        $pdf->box(488,467,251,38,'#F0F3F7');
        $pdf->text(500,491,'NÚMERO',7.2,true,$azulProf);
        $pdf->text(500,474,GydOcPdfCanvas::clip($numeroDocumento,121,12.7,true),12.7,true,$tinta);
        $pdf->line(626,472,626,502,'#C0CDD9',.8);
        $pdf->text(635,491,'FECHA',7.2,true,$azulProf);
        $pdf->text(635,474,$date((string)($o['fecha']??'')),12.7,true,$tinta);
        // Datos del proveedor.
        $pdf->box(30,379,782,81,'#FFFFFF','#B2C4D6',.85);
        $pdf->box(30,379,154,81,$azul);
        $pdf->poly([[173,379],[184,379],[194,460],[184,460]],$rojo);
        $pdf->text(45,423,'DATOS DEL',12,true,'#FFFFFF');
        $pdf->text(45,406,'PROVEEDOR',12,true,'#FFFFFF');
        $pdf->text(201,442,'Proveedor:',8.4,true,$azulProf);
        $pdf->text(265,442,GydOcPdfCanvas::clip($proveedor,310,9),9,false,$tinta);
        $pdf->text(201,425,'Dirección:',8.4,true,$azulProf);
        $pdf->text(265,425,GydOcPdfCanvas::clip((string)($o['direccion_proveedor']??$o['p_direccion']??''),465,8.6),8.6,false,$tinta);
        $pdf->text(201,408,'Ciudad:',8.4,true,$azulProf);
        $pdf->text(243,408,GydOcPdfCanvas::clip((string)($o['ciudad_proveedor']??''),160,8.2),8.2,false,$tinta);
        $pdf->text(431,408,'Colonia:',8.4,true,$azulProf);
        $pdf->text(478,408,GydOcPdfCanvas::clip((string)($o['colonia_proveedor']??''),170,8.2),8.2,false,$tinta);
        $pdf->text(652,408,'Tel.:',8.4,true,$azulProf);
        $pdf->text(680,408,GydOcPdfCanvas::clip((string)($o['telefono_proveedor']??$o['p_telefono']??''),128,8.2),8.2,false,$tinta);
        $pdf->text(201,390,'Sucursal / almacén:',8.4,true,$azulProf);
        $pdf->text(307,390,GydOcPdfCanvas::clip((string)$destLabel,200,8.2),8.2,false,$tinta);
        $pdf->text(546,390,'Entrega prevista:',8.4,true,$azulProf);
        $pdf->text(643,390,$date((string)($o['fecha_entrega']??$o['fecha']??'')),8.4,false,$tinta);
        // Encabezado de tabla.
        $pdf->box(30,342,782,29,$azulProf);
        if($mostrarCostos){
            $headers=[['ARTÍCULO',38],['NOMBRE DEL PRODUCTO',150],['CANTIDAD',508],['UNID.',588],['COSTO UNIT.',644],['IMPORTE',742]];
        } else {
            $headers=[['ARTÍCULO',38],['NOMBRE DEL PRODUCTO',150],['CANTIDAD',613],['UNID.',739]];
        }
        foreach($headers as [$label,$x])$pdf->text((float)$x,353,$label,8.7,true,'#FFFFFF');
        return 342.0;
    };
    $i=0;$rowTotal=count($rows);$page=1;$y=$newPage($d,$page);
    while($i<$rowTotal){
        $r=$rows[$i];$h=$r['h'];
        // No se dibuja la última línea en una página sin espacio para los totales y las firmas.
        $restanteTotal=0.0;
        for($j=$i;$j<$rowTotal;$j++) $restanteTotal+=$rows[$j]['h'];
        $cabeCierre=($y-$restanteTotal>=$maxFinalY);
        if ($y-$h<62.0 || (!$cabeCierre && $i===$rowTotal-1)) {
            $d->text(37,53,'Continúa en la página siguiente',7.6,false,'#49647E');
            $d->end();$page++;$y=$newPage($d,$page);
            continue;
        }
        $it=$r['it'];$y-=$h;
        $d->box(30,$y,782,$h,($i%2===0)?'#FFFFFF':'#EAF2F9','#D8E2ED',.3);
        $baseline=$y+$h/2-3.4;
        $d->text(38,$baseline,GydOcPdfCanvas::clip((string)($it['codigo_snapshot']??$it['codigo']??''),103,8.1),8.1,false,$tinta);
        $lines=$r['nombre'];foreach($lines as $n=>$txt){$start=$y+$h/2+((count($lines)-1)*5)-3.2;$d->text(150,$start-$n*10,GydOcPdfCanvas::clip($txt,$mostrarCostos?334:450,8.4),8.4,false,$tinta);}
        $qty=number_format((float)($it['cantidad_piezas']??0),2,'.',',');
        $d->right($mostrarCostos?575:706,$baseline,$qty,8.7,false,$tinta);
        $d->text($mostrarCostos?592:749,$baseline,GydOcPdfCanvas::clip((string)($it['unidad']??'PZA'),52,8.4),8.4,false,$tinta);
        if($mostrarCostos){
            $d->right(729,$baseline,number_format((float)($it['costo_unitario']??0),2,'.',','),8.6,false,$tinta);
            $d->right(805,$baseline,number_format((float)($it['importe']??0),2,'.',','),8.6,false,$tinta);
        }
        $i++;
    }
    // Pie de última página: observaciones y totales.
    $top=min(260.0,$y-9);$bottom=144.0;$height=max(60,$top-$bottom);
    $d->box(30,$bottom,490,$height,'#FFFFFF','#B4C5D9',.7);
    $d->box(30,$top-24,195,24,$azulProf);
    $d->box(30,$top-24,35,24,$rojo);
    $d->text(70,$top-16,'OBSERVACIONES',10.3,true,'#FFFFFF');
    $observaciones=$mostrarCostos?trim((string)($o['observaciones']??'')):'';
    if($observaciones!==''){
        $maxLines=max(1,(int)floor(($height-38)/12));
        $lines=GydOcPdfCanvas::wrap($observaciones,455,8.1,false,$maxLines);
        foreach($lines as $n=>$line) $d->text(44,$top-39-$n*12,$line,8.1,false,$tinta);
    }else{
        for($z=$top-44;$z>$bottom+8;$z-=18) $d->line(43,$z,506,$z,'#CFDAE5',.55);
    }
    $d->box(534,$bottom,278,$height,'#FFFFFF','#B4C5D9',.7);
    if($mostrarCostos){
        $costos=[['SUBTOTAL',(float)($o['subtotal_bruto']??$o['subtotal']??0)],['DESCUENTO',(float)($o['descuento_total']??0)],['IEPS',(float)($o['ieps_total']??0)],['IVA',(float)($o['iva_total']??0)],['FPGC',(float)($o['fpgc_total']??0)]];
        $step=min(15.6,max(10.6,($height-29)/5));
        foreach($costos as $k=>[$key,$val]){
            $yy=$top-13-$k*$step;
            if($k%2===0) $d->box(542,$yy-4.0,260,$step,'#ECF2F7');
            $d->text(549,$yy,$key,8.7,true,$azulProf);
            $d->right(799,$yy,number_format($val,2,'.',','),9.0,false,$tinta);
        }
        $d->box(534,$bottom,278,23,$rojo);
        $d->text(550,$bottom+7,'TOTAL',12,true,'#FFFFFF');
        $d->right(798,$bottom+6,number_format((float)($o['total_neto']??$o['subtotal']??0),2,'.',','),13,true,'#FFFFFF');
    } else {
        $d->text(551,$top-23,'COPIA SIN COSTOS',12,true,$azulProf);
        $d->text(551,$top-46,'Documento para recepción',9,false,$tinta);
        $d->text(551,$top-60,'y seguimiento de mercancía.',9,false,$tinta);
    }
    $piezas=0.0;$cajas=0.0;
    foreach($items as $it){$piezas+=(float)($it['cantidad_piezas']??0);$cajas+=(float)($it['cantidad_cajas']??0);}
    $d->box(30,112,782,25,$gris);
    $d->text(42,120,number_format(count($items),0).' ARTÍCULOS',9.5,true,$azulProf);
    $d->line(234,116,234,132,'#AFC1D5',1);
    $d->text(253,120,number_format($cajas,2).' CAJAS',9.5,true,$azulProf);
    $d->line(485,116,485,132,'#AFC1D5',1);
    $d->text(505,120,number_format($piezas,2).' PIEZAS',9.5,true,$azulProf);
    // Tres firmas requeridas: Compras, Dueño, Gerente receptor.
    $cards=[
        [30.0,248.0,'EMITIÓ', $emitio],
        [297.0,248.0,'AUTORIZÓ', $autorizo],
        [564.0,248.0,'RECIBIÓ (GERENTE)', $gerenteMostrar],
    ];
    foreach($cards as [$x,$w,$titulo,$nombre]){
        $d->box($x,38,$w,66,'#FFFFFF','#B7C6D8',.75);
        $d->text($x+12,88,$titulo,9.7,true,$azulProf);
        $d->line($x+16,63,$x+$w-16,63,$azulProf,.8);
        $d->centered($x+$w/2,50,GydOcPdfCanvas::clip($nombre,$w-26,8.7,true),8.7,true,$tinta);
    }
    // Nota breve (no aprobación electrónica ni recepción confirmada).
    $d->text(35,29,'Firmas manuscritas pendientes; el nombre designado no acredita autorización ni recepción por sí solo.',7,false,'#5B738C');
    $d->end();
    $pdf=$d->toPdf();
    if(!str_starts_with($pdf,'%PDF-'))throw new RuntimeException('No se pudo crear el PDF de la orden.');
    return $pdf;
}
