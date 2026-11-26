<?php
/** Revisión básica sin base de datos ni escritura (opcional). */
declare(strict_types=1);
$root=realpath(__DIR__.'/../..');
if(!$root){fwrite(STDERR,"No se localizó el ERP\n");exit(2);}
$js=$root.'/public/assets/js/gyd_catalogo_global.js';
$api=$root.'/public/erp_catalogo_global_api.php';
$files=[$js,$api];
foreach($files as $file){if(!is_file($file)){fwrite(STDERR,"FALTA: $file\n");exit(2);}}
if(!str_contains((string)file_get_contents($js),'gyd:catalogo-producto-seleccionado')){fwrite(STDERR,"FALTA: evento de selección\n");exit(2);}
if(!str_contains((string)file_get_contents($api),'requireLogin()')){fwrite(STDERR,"FALTA: protección de sesión\n");exit(2);}
echo "OK: assets presentes, selección y control de sesión detectados.\n";
