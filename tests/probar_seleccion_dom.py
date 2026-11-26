from playwright.sync_api import sync_playwright
from pathlib import Path
import json
js=Path('/mnt/data/gyd_catalogo_correccion/tools/gyd_catalogo_correccion_src/gyd_catalogo_global.js').read_text()
product={'id':2100,'codigo':'7502001166066','codigo_barras':'7502001166066','descripcion':'BARMICIL 40 GR','laboratorio':'PRUEBA','unidad':'PZA','activo':True}
api={'ok':True,'items':[product],'total':1,'pagina':1,'paginas':1,'limite':40,'puede_inactivos':True}
forms={
 'erp_facturas.php':'<div class="field"><label for="buscar">Buscar por código o descripción</label><input id="buscar" placeholder="Escribe al menos dos caracteres" /></div><div class="field"><button id="buscarBoton" type="button" onclick="window.searchCount=(window.searchCount||0)+1">Buscar producto</button></div><input id="numero_factura" placeholder="Número de factura" />',
 'erp_oc_win.php':'<div class="search-row"><div class="field"><label for="q">Buscar producto por código o descripción</label><input id="q" type="search"></div><button id="buscar" type="button" onclick="window.searchCount=(window.searchCount||0)+1">Buscar artículos</button></div><input placeholder="Nombre de proveedor" id="proveedor" />',
 'none.php':'<input id="editarCodigo" placeholder="Código de barras"/><input id="numeroFactura" placeholder="Factura"/>',
}
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
 for path,html in forms.items():
  page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.set_content('<html><body>'+html+'</body></html>')
  page.evaluate('(api)=>{window.fetch=async()=>({ok:true,status:200,json:async()=>api})}',api)
  test_js=js.replace("location.pathname.split('/').pop().toLowerCase()",json.dumps(path))
  page.add_script_tag(content=test_js);page.keyboard.press('Control+b')
  page.locator('#gyd-catalogo-global[data-open="1"]').wait_for();page.locator('.cg-row').first.wait_for();page.locator('.cg-row').first.click()
  if path=='erp_facturas.php':
   assert page.locator('#buscar').input_value()==product['codigo'];assert page.evaluate('window.searchCount')==1;assert page.locator('#gyd-catalogo-global').get_attribute('data-open')=='0'
  elif path=='erp_oc_win.php':
   assert page.locator('#q').input_value()==product['codigo'];assert page.evaluate('window.searchCount')==1
  else:
   assert page.locator('#editarCodigo').input_value()=='';assert page.locator('#gyd-catalogo-global').get_attribute('data-open')=='1';assert 'No se encontró' in page.locator('.cg-caption').inner_text()
  assert not errors,errors
  print('PASS',path);page.close()
 browser.close()
