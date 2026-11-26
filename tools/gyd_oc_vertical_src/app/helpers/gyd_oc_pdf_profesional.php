<?php
declare(strict_types=1);
/**
 * G&D ERP: presentación profesional para órdenes de compra.
 * Solo compone documentos PDF; no crea/modifica órdenes, facturas ni inventarios.
 * A4 vertical. Dos variantes: con costos y sin costos.
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
    public const W=595.0;
    public const H=842.0;
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
            // Pie uniforme en todas las páginas.
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

/**
 * GYD_OC_A4_VERTICAL_V3_15_ARTICULOS
 * Plantilla corporativa vertical: tres firmas, gerente destinatario y mascotita bajada.
 * No registra autorizaciones ni recepciones. Sin efectos de escritura SQL.
 */
function gyd_oc_pdf_profesional(array $o, array $items, bool $mostrarCostos=true): string {
    $pdf=new GydOcPdfCanvas(__DIR__.'/../assets/oc_pdf');
    $azul='#0B3B70';$azulOscuro='#082C56';$rojo='#D5162D';$claro='#E9F3FC';
    $gris='#F3F7FB';$linea='#B3C8DD';$tinta='#17314F';
    $fmt=static fn(float $n):string=>number_format($n,2,'.',',');
    $fecha=static function(string $date):string {
        $stamp=strtotime($date); return $stamp!==false?date('d/m/Y',$stamp):'-';
    };
    $dest=(string)($o['destino']??'');
    $destLabel=function_exists('ocw_destinos')?(ocw_destinos()[$dest]??$dest):$dest;
    $numeroDocumento=function_exists('ocw_numero')?ocw_numero((int)($o['id']??0)):str_pad((string)($o['id']??0),12,'0',STR_PAD_LEFT);
    $emitio=trim((string)($o['emitio']??''))?:'SIN REGISTRAR';
    $dueno=trim((string)($o['oc_firmante_nombre']??''))?:'SIN DESIGNAR';
    $gerente=trim((string)($o['oc_gerente_destino_nombre']??''))?:'NOMBRE POR CONFIRMAR';
    $proveedor=trim((string)($o['proveedor']??''));

    // Renglones compactos: 15 artículos completos más observaciones, importes y firmas
    // en cada hoja de cierre A4. Los nombres se recortan a una línea cuando exceden el ancho.
    $rows=array_values($items);
    $rowsCount=count($rows);
    $perPage=15;
    $pages=max(1,(int)ceil($rowsCount/$perPage));
    $piezas=0.0;$cajas=0.0;
    foreach($items as $it){$piezas+=(float)($it['cantidad_piezas']??0);$cajas+=(float)($it['cantidad_cajas']??0);}

    for($page=1;$page<=$pages;$page++){
        $pdf->start();
        // Cabecera compacta con razón social y leyenda alineadas sobre el mismo eje.
        $pdf->box(0,835,595,7,$azulOscuro);
        $pdf->box(0,830,595,5,$rojo);
        $pdf->image('Logo',25,740,96,84);
        $pdf->image('Mascota',485,735,83,87);
        $pdf->centered(298,788,'DISTRIBUCIÓN G&D',17.6,true,$azulOscuro);
        $pdf->centered(298,767,'S.A. DE C.V.',11.9,true,$azulOscuro);
        $pdf->line(169,756,427,756,$rojo,1.0);
        $pdf->box(25,704,545,37,$azulOscuro);
        $pdf->box(25,704,40,37,$rojo);
        $pdf->centered(298,716,'ORDEN DE COMPRA',16.8,true,'#FFFFFF');

        // Folio/fecha y datos originales del proveedor y entrega.
        $pdf->box(25,664,264,33,$claro,$linea,.5);
        $pdf->text(34,683,'NÚMERO DE ORDEN',7.4,true,$azulOscuro);
        $pdf->text(34,670,GydOcPdfCanvas::clip($numeroDocumento,242,11.1,true),11.1,true,$tinta);
        $pdf->box(296,664,274,33,$claro,$linea,.5);
        $pdf->text(307,683,'FECHA DE ORDEN',7.4,true,$azulOscuro);
        $pdf->text(307,670,$fecha((string)($o['fecha']??'')),11.1,true,$tinta);

        $pdf->box(25,553,331,104,'#FFFFFF',$linea,.7);
        $pdf->box(25,632,331,25,$azulOscuro);
        $pdf->text(36,640,'DATOS DEL PROVEEDOR',9.4,true,'#FFFFFF');
        $pdf->text(34,619,'Proveedor:',7.4,true,$azul);
        $pdf->text(86,619,GydOcPdfCanvas::clip($proveedor,257,7.8),7.8,false,$tinta);
        $pdf->text(34,602,'Dirección:',7.4,true,$azul);
        $direccion=(string)($o['direccion_proveedor']??$o['p_direccion']??'');
        $direccionLineas=GydOcPdfCanvas::wrap($direccion,259,7.0,false,2);
        foreach($direccionLineas as $j=>$txt)$pdf->text(86,603-$j*9,$txt,7,false,$tinta);
        $pdf->text(34,577,'Colonia:',7.0,true,$azul);
        $pdf->text(75,577,GydOcPdfCanvas::clip((string)($o['colonia_proveedor']??''),120,6.9),6.9,false,$tinta);
        $pdf->text(203,577,'Ciudad:',7.0,true,$azul);
        $pdf->text(241,577,GydOcPdfCanvas::clip((string)($o['ciudad_proveedor']??''),106,6.9),6.9,false,$tinta);
        $pdf->text(34,561,'Teléfonos:',7.0,true,$azul);
        $pdf->text(91,561,GydOcPdfCanvas::clip((string)($o['telefono_proveedor']??$o['p_telefono']??''),253,7.0),7,false,$tinta);
        $pdf->box(363,553,207,104,'#FFFFFF',$linea,.7);
        $pdf->box(363,632,207,25,$azulOscuro);
        $pdf->text(372,640,'INFORMACIÓN DE ENTREGA',8.7,true,'#FFFFFF');
        $pdf->box(373,590,187,35,$claro);
        $pdf->text(382,609,'ALMACÉN / SUCURSAL',7.4,true,$azulOscuro);
        $pdf->text(382,596,GydOcPdfCanvas::clip((string)$destLabel,169,8.6,true),8.6,true,$tinta);
        $pdf->box(373,559,187,27,$claro);
        $pdf->text(382,575,'FECHA DE ENTREGA',7.1,true,$azulOscuro);
        $pdf->text(382,563,$fecha((string)($o['fecha_entrega']??$o['fecha']??'')),8.5,true,$tinta);

        $pdf->text(28,538,'DETALLE DE ARTÍCULOS',9.3,true,$azulOscuro);
        $pdf->right(565,538,'Pág. '.$page.' / '.$pages,7.1,false,'#68819A');
        $pdf->box(25,508,545,23,$azulOscuro);
        if($mostrarCostos){
            $headers=[['ARTÍCULO',31],['NOMBRE',120],['CANTIDAD',364],['UNID.',419],['COSTO',456],['IMPORTE',513]];
        }else{
            $headers=[['ARTÍCULO',31],['NOMBRE DEL PRODUCTO',120],['CANTIDAD',452],['UNID.',523]];
        }
        foreach($headers as [$label,$x])$pdf->text($x,516,$label,7.0,true,'#FFFFFF');

        // La tabla ocupa sólo 243 pt para 15 productos (alto 16.2 por producto).
        for($j=0;$j<$perPage;$j++){
            $index=($page-1)*$perPage+$j;
            $y=508-($j+1)*16.2;
            $pdf->box(25,$y,545,16.2,$j%2===0?'#FFFFFF':'#EDF4FB','#D2DFEC',.35);
            if(!isset($rows[$index]))continue;
            $it=$rows[$index];$baseline=$y+5.2;
            $pdf->text(31,$baseline,GydOcPdfCanvas::clip((string)($it['codigo_snapshot']??$it['codigo']??''),86,7.15),7.15,false,$tinta);
            $nombre=(string)($it['descripcion_snapshot']??$it['descripcion']??'');
            $pdf->text(120,$baseline,GydOcPdfCanvas::clip($nombre,$mostrarCostos?241:320,7.05),7.05,false,$tinta);
            $qty=$fmt((float)($it['cantidad_piezas']??0));
            $pdf->right($mostrarCostos?409:513,$baseline,GydOcPdfCanvas::clip($qty,$mostrarCostos?44:58,7.0),7.0,false,$tinta);
            $pdf->text($mostrarCostos?419:523,$baseline,GydOcPdfCanvas::clip((string)($it['unidad']??'PZA'),34,7.0),7.0,false,$tinta);
            if($mostrarCostos){
                $pdf->right(505,$baseline,GydOcPdfCanvas::clip($fmt((float)($it['costo_unitario']??0)),46,7.0),7.0,false,$tinta);
                $pdf->right(565,$baseline,GydOcPdfCanvas::clip($fmt((float)($it['importe']??0)),51,7.0),7.0,false,$tinta);
            }
        }
        if($page!==$pages){
            $pdf->centered(298,237,'CONTINÚA EN LA SIGUIENTE PÁGINA',8.3,true,$azulOscuro);
        }else{
            // Reservas fijas: OBSERVACIONES + TOTALES + RESUMEN + TRES FIRMAS.
            $pdf->box(25,151,322,94,'#FFFFFF',$linea,.65);
            $pdf->box(25,222,322,23,$azulOscuro);
            $pdf->box(25,222,32,23,$rojo);
            $pdf->text(64,230,'OBSERVACIONES',8.9,true,'#FFFFFF');
            $obs=$mostrarCostos?trim((string)($o['observaciones']??'')):'';
            if($obs!==''){
                foreach(GydOcPdfCanvas::wrap($obs,297,7.1,false,6) as $k=>$line){
                    $pdf->text(35,208-$k*10,$line,7.1,false,$tinta);
                }
            }else{
                for($yy=208;$yy>=163;$yy-=12)$pdf->line(35,$yy,336,$yy,'#D2DFEC',.45);
            }
            $pdf->box(354,151,216,94,'#FFFFFF',$linea,.65);
            if($mostrarCostos){
                $moneyRows=[
                    ['SUBTOTAL',(float)($o['subtotal_bruto']??$o['subtotal']??0)],
                    ['DESCUENTO',(float)($o['descuento_total']??0)],
                    ['IEPS',(float)($o['ieps_total']??0)],
                    ['IVA',(float)($o['iva_total']??0)],
                    ['FPGC',(float)($o['fpgc_total']??0)],
                ];
                foreach($moneyRows as $k=>[$label,$amount]){
                    $yy=232-$k*14.0;
                    if($k%2===0)$pdf->box(357,$yy-4,210,13,$claro);
                    $pdf->text(363,$yy,$label,7.2,true,$azulOscuro);
                    $pdf->right(559,$yy,$fmt($amount),7.3,false,$tinta);
                }
                $pdf->box(354,151,216,22,$rojo);
                $pdf->text(363,158,'TOTAL',9.3,true,'#FFFFFF');
                $pdf->right(560,158,$fmt((float)($o['total_neto']??$o['subtotal']??0)),9.6,true,'#FFFFFF');
            }else{
                $pdf->centered(462,205,'COPIA SIN COSTOS',10.2,true,$azulOscuro);
                $pdf->centered(462,186,'Preparada para recepción',7.7,false,$tinta);
                $pdf->centered(462,173,'de mercancía',7.7,false,$tinta);
            }
            $pdf->box(25,122,545,24,$claro);
            $pdf->centered(110,131,(string)$rowsCount.' Artículos',8.5,true,$azulOscuro);
            $pdf->line(206,126,206,142,$linea,.7);
            $pdf->centered(295,131,$fmt($cajas).' Cajas',8.5,true,$azulOscuro);
            $pdf->line(389,126,389,142,$linea,.7);
            $pdf->centered(480,131,$fmt($piezas).' Piezas',8.5,true,$azulOscuro);
            $signatures=[
                [25.0,171.0,'EMITIÓ',$emitio],
                [211.0,171.0,'AUTORIZÓ',$dueno],
                [397.0,173.0,'RECIBIÓ (GERENTE)',$gerente],
            ];
            foreach($signatures as [$x,$width,$title,$person]){
                $pdf->box($x,38,$width,79,'#FFFFFF',$linea,.6);
                $pdf->centered($x+$width/2,102,$title,8.5,true,$azulOscuro);
                $pdf->line($x+12,75,$x+$width-12,75,$azulOscuro,.75);
                $pdf->centered($x+$width/2,57,GydOcPdfCanvas::clip($person,$width-17,8.0,true),8.0,true,$tinta);
            }
        }
        $pdf->box(0,0,595,8,$azulOscuro);
        $pdf->box(411,8,184,3,$rojo);
        $pdf->end();
    }
    $out=$pdf->toPdf();
    if(!str_starts_with($out,'%PDF-'))throw new RuntimeException('No se pudo componer el PDF de la OC.');
    return $out;
}
