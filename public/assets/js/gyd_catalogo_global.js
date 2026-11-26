/* G&D · Catálogo global Ctrl+B. Solo lectura hasta que el módulo consumidor elija la acción. */
(()=>{'use strict';
 if(window.GYD_CatalogoGlobal) return;
 const endpoint='erp_catalogo_global_api.php';
 const isPos=!!(document.getElementById('catalog_modal')&&document.getElementById('abrir_catalogo'));
 const excluded=/(cliente|proveedor|usuario|empleado|factura|folio|correo|mail|fecha|sucursal|gerente|telefono|contrase|password|razon.social|rfc|uuid|orden|lote|observacion|nota|motivo|direccion|ciudad|caducidad|cantidad|precio|costo)/i;
 const positive=/(buscar|busqueda|búsqueda|producto|articulo|artículo|codigo|código|barra|catalogo|catálogo|filtro|filtrar|sku)/i;
 const esc=s=>String(s??'');
 const fieldLabel=el=>{
   const labels=[el.id,el.name,el.placeholder,el.getAttribute('aria-label'),el.title,el.dataset?.gydCatalogo];
   if(el.labels)for(const l of el.labels)labels.push(l.textContent);
   const p=el.closest('.field,.find,.search,.searchbar,.busqueda,.search-box,.search-row,.twocol');
   if(p)labels.push(p.querySelector('label')?.textContent);
   return labels.filter(Boolean).join(' ');
 };
 function isVisible(el){return !!(el&&el.isConnected&&el.getClientRects().length&&getComputedStyle(el).visibility!=='hidden');}
 function isExistenciasSearch(el){
   // La barra "Buscar general" incluye proveedor y categoría entre sus criterios.
   // No debe descartarse como un selector de proveedores por el texto del placeholder.
   return location.pathname.split('/').pop().toLowerCase()==='existencias.php'
     && el?.matches?.('form.existencias-filter-form input[name="buscar"]');
 }
 function isProductInput(el){
   if(!el||!el.matches?.('input:not([type="hidden"]):not([type="password"]):not([type="number"]):not([type="date"]):not([type="checkbox"]),textarea'))return false;
   if(el.readOnly||el.disabled||!isVisible(el)||el.closest('[data-gyd-catalogo-modal]'))return false;
   if(isExistenciasSearch(el))return true;
   const label=fieldLabel(el);
   if(excluded.test(label)&&!el.dataset.gydCatalogo)return false;
   const buscador=/(buscar|búsqueda|busqueda|search|filtrar|escane|catalogo|catálogo|localizar)/i.test(label)||el.type==='search';
   return buscador&&(positive.test(label)||el.type==='search');
 }
 function allTargets(){
   const candidates=[...document.querySelectorAll('input,textarea')].filter(isProductInput);
   const path=location.pathname.split('/').pop().toLowerCase();
   const priority=path==='erp_facturas.php'?['buscar']:path==='erp_oc_win.php'||path==='erp_ordenes_compra.php'?['q']:path==='erp_kardex_general.php'?['q']:[];
   if(path==='existencias.php')return candidates.sort((a,b)=>Number(isExistenciasSearch(b))-Number(isExistenciasSearch(a)));
   return candidates.sort((a,b)=>{
     const pa=priority.indexOf(a.id),pb=priority.indexOf(b.id);
     if(pa>=0||pb>=0)return (pa<0?99:pa)-(pb<0?99:pb);
     return 0;
   });
 }
 function chooseTarget(){return isProductInput(document.activeElement)?document.activeElement:(allTargets()[0]||null);}
 function labelForTarget(el){
   if(isExistenciasSearch(el))return 'Buscar general · Existencias';
   const l=el?.labels?.[0]?.textContent?.trim()||el?.getAttribute('aria-label')||el?.placeholder||el?.id||'Buscador de productos';
   return String(l).slice(0,90);
 }
 let modal=null,search=null,results=null,caption=null,filter=null,next=null,prev=null,where=null,targetSelect=null,targetHint=null;
 let target=null,availableTargets=[],current=[],selected=0,pagina=1,paginas=1,timer=null,controller=null,seq=0,open=false;
 function el(tag,attrs={},text){const e=document.createElement(tag);for(const [k,v] of Object.entries(attrs)){if(k==='class')e.className=v;else e.setAttribute(k,v);}if(text!==undefined)e.textContent=text;return e;}
 function style(){if(document.getElementById('gyd-catalogo-global-estilos'))return;
 const css=`
 #gyd-catalogo-global{position:fixed;inset:0;z-index:2147483000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(10,25,49,.68);font:14px system-ui,Segoe UI,Arial,sans-serif;color:#183c62}
 #gyd-catalogo-global[data-open="1"]{display:flex}
 #gyd-catalogo-global *{box-sizing:border-box}
 #gyd-catalogo-global .cg-panel{width:min(1050px,100%);max-height:min(92vh,920px);background:#fff;display:flex;flex-direction:column;overflow:hidden;border-radius:18px;box-shadow:0 22px 85px #071d3290}
 #gyd-catalogo-global .cg-top{padding:17px 20px;background:#163f72;color:white;display:flex;align-items:center;justify-content:space-between;gap:12px}
 #gyd-catalogo-global h2{margin:0;color:white;font:700 19px system-ui,Segoe UI,Arial,sans-serif}
 #gyd-catalogo-global .cg-help{margin-top:4px;font-size:12px;color:#d0e3fa}
 #gyd-catalogo-global button{cursor:pointer;border:1px solid #cbdbee;background:#ecf3fb;color:#164a7c;border-radius:9px;padding:9px 13px;font:600 13px system-ui,Segoe UI,Arial,sans-serif;width:auto;min-height:36px;white-space:nowrap}
 #gyd-catalogo-global button:hover:not(:disabled){background:#d9eafd}
 #gyd-catalogo-global button:focus-visible,#gyd-catalogo-global input:focus-visible,#gyd-catalogo-global select:focus-visible{outline:3px solid #65a8ed;outline-offset:1px}
 #gyd-catalogo-global button:disabled{opacity:.4;cursor:not-allowed}
 #gyd-catalogo-global .cg-close{border-color:#98b5d9;color:#fff;background:transparent;font-size:20px}
 #gyd-catalogo-global .cg-controls{padding:15px 18px;display:grid;grid-template-columns:minmax(0,1fr) 160px 92px;align-items:center;gap:9px}
 #gyd-catalogo-global input,#gyd-catalogo-global select{min-width:0;width:100%;border:1px solid #bacce0;padding:11px;border-radius:9px;font:14px system-ui,Segoe UI,Arial,sans-serif;background:#fff;color:#183c62}
 #gyd-catalogo-global .cg-destino{padding:0 18px 12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
 #gyd-catalogo-global .cg-destino label{font-weight:700;font-size:12px}
 #gyd-catalogo-global .cg-destino select{width:min(500px,100%);flex:1}
 #gyd-catalogo-global .cg-hint{font-size:12px;color:#496a84}
 #gyd-catalogo-global .cg-caption{padding:0 18px 12px;font-size:12px;color:#506b83}
 #gyd-catalogo-global .cg-results{overflow:auto;min-height:130px;max-height:55vh;padding:0 17px 5px}
 #gyd-catalogo-global .cg-row{border:1px solid #e1e9f4;border-radius:10px;background:#fff;width:100%;padding:12px 14px;margin:0 0 7px;text-align:left;display:flex;justify-content:space-between;align-items:center;gap:15px;white-space:normal}
 #gyd-catalogo-global .cg-row[data-sel="1"]{border:2px solid #2169b0;background:#eef6ff;padding:11px 13px}
 #gyd-catalogo-global .cg-row:hover{background:#edf5ff}
 #gyd-catalogo-global .cg-primary{font-weight:750;font-size:14px;line-height:1.5;color:#123963;overflow-wrap:anywhere}
 #gyd-catalogo-global .cg-secondary{font-size:12px;color:#51697f;line-height:1.45;overflow-wrap:anywhere}
 #gyd-catalogo-global .cg-pill{border-radius:15px;background:#dcecff;color:#114675;font-size:12px;font-weight:700;padding:5px 9px;flex-shrink:0}
 #gyd-catalogo-global .cg-bottom{padding:11px 18px;background:#f4f8fd;border-top:1px solid #dfebf5;display:flex;gap:9px;align-items:center;justify-content:space-between;flex-wrap:wrap}
 #gyd-catalogo-global .cg-status{font-size:12px;color:#536d84}
 @media(max-width:610px){#gyd-catalogo-global .cg-controls{grid-template-columns:minmax(0,1fr) 100px}#gyd-catalogo-global .cg-controls button{grid-column:span 2}#gyd-catalogo-global .cg-top{padding:13px}#gyd-catalogo-global h2{font-size:16px}#gyd-catalogo-global .cg-results{padding:0 10px}#gyd-catalogo-global .cg-row{padding:9px}#gyd-catalogo-global .cg-row[data-sel="1"]{padding:8px}}
 `;const st=el('style',{id:'gyd-catalogo-global-estilos'});st.textContent=css;document.head.append(st);
 }
 function build(){if(modal)return;style();
 modal=el('div',{id:'gyd-catalogo-global','data-gyd-catalogo-modal':'1','aria-hidden':'true'});
 const panel=el('section',{class:'cg-panel',role:'dialog','aria-modal':'true','aria-labelledby':'cg-title'});
 const top=el('div',{class:'cg-top'}),title=el('div');title.append(el('h2',{id:'cg-title'},'Catálogo general de productos'),el('div',{class:'cg-help'},'Ctrl + B · buscar por código, barras, nombre o laboratorio · ↑ ↓ Enter · Esc'));
 const closeBtn=el('button',{type:'button',class:'cg-close','aria-label':'Cerrar catálogo'},'×');closeBtn.onclick=close;top.append(title,closeBtn);
 const controls=el('div',{class:'cg-controls'});search=el('input',{type:'search',placeholder:'Código, barras, nombre o laboratorio…',autocomplete:'off','aria-label':'Buscar productos del catálogo'});search.addEventListener('input',()=>{pagina=1;clearTimeout(timer);timer=setTimeout(load,160)});
 filter=el('select',{'aria-label':'Estado del catálogo'});[['activos','Activos'],['todos','Todos'],['inactivos','Inactivos']].forEach(([value,label])=>filter.append(el('option',{value},label)));filter.onchange=()=>{pagina=1;load()};
 const go=el('button',{type:'button'},'Buscar');go.onclick=()=>{pagina=1;load()};controls.append(search,filter,go);
 const destino=el('div',{class:'cg-destino'}),targetLabel=el('label',{for:'gyd-catalogo-destino'},'Enviar producto al campo:');
 targetSelect=el('select',{id:'gyd-catalogo-destino'});targetSelect.addEventListener('change',()=>{const i=Number(targetSelect.value);target=availableTargets[i]||null;});
 targetHint=el('span',{class:'cg-hint','aria-live':'polite'});
 destino.append(targetLabel,targetSelect,targetHint);
 caption=el('div',{class:'cg-caption','aria-live':'polite'},'Preparando catálogo…');
 results=el('div',{class:'cg-results',role:'listbox','aria-label':'Productos encontrados'});
 const bottom=el('div',{class:'cg-bottom'}),state=el('span',{class:'cg-status'},'Selecciona un producto para llevarlo a la búsqueda del módulo.'),pager=el('div',{class:'cg-pages'});prev=el('button',{type:'button'},'← Anterior');next=el('button',{type:'button'},'Siguiente →');where=el('span',{class:'cg-status'});prev.onclick=()=>{if(pagina>1){pagina--;load()}};next.onclick=()=>{if(pagina<paginas){pagina++;load()}};pager.append(prev,' ',where,' ',next);bottom.append(state,pager);
 panel.append(top,controls,destino,caption,results,bottom);modal.append(panel);document.body.append(modal);
 modal.addEventListener('mousedown',e=>{if(e.target===modal)close()});
 }
 function row(p,i){const b=el('button',{class:'cg-row',type:'button',role:'option','data-sel':i===selected?'1':'0','aria-selected':i===selected?'true':'false'});
   const a=el('span'),d=el('div',{class:'cg-primary'},p.descripcion||'Sin descripción'),s=el('div',{class:'cg-secondary'},`Código: ${p.codigo||'—'} · Barras: ${p.codigo_barras||'—'}${p.laboratorio?' · '+p.laboratorio:''}${p.unidad?' · '+p.unidad:''}`);a.append(d,s);
   b.append(a,el('span',{class:'cg-pill'},p.activo?'Seleccionar':'Inactivo'));b.onclick=()=>select(p);b.onfocus=()=>{selected=i;updateSelection()};return b;
 }
 function updateSelection(){[...results.querySelectorAll('.cg-row')].forEach((b,i)=>{b.dataset.sel=i===selected?'1':'0';b.setAttribute('aria-selected',i===selected?'true':'false')})}
 async function load(){if(!open)return;const mine=++seq;if(controller)controller.abort();controller=new AbortController();caption.textContent='Buscando en el catálogo…';results.replaceChildren();
   const qs=new URLSearchParams({q:search.value.trim(),pagina:String(pagina),estado:filter.value});
   try{const response=await fetch(endpoint+'?'+qs,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}});
     if(!response.ok)throw new Error(response.status===403?'No tienes permiso para consultar el catálogo.':'No se pudo consultar el servidor ('+response.status+').');
     const data=await response.json();if(mine!==seq||!open)return;if(!data.ok)throw new Error(data.error||'Error del catálogo.');
     if(!data.puede_inactivos){filter.value='activos';filter.querySelectorAll('option').forEach(o=>{if(o.value!=='activos')o.disabled=true})}
     current=data.items||[];pagina=data.pagina||1;paginas=data.paginas||1;selected=0;
     caption.textContent=`${Number(data.total||0).toLocaleString('es-MX')} productos encontrados · ${current.length} en esta página`;
     if(!current.length)results.append(el('p',{},'No hay productos con esos filtros. Prueba menos palabras o parte del código.'));
     else results.append(...current.map(row));where.textContent=`Página ${pagina} de ${paginas}`;prev.disabled=pagina<=1;next.disabled=pagina>=paginas;
   }catch(err){if(err.name==='AbortError'||mine!==seq||!open)return;caption.textContent=err.message||'Error de búsqueda';results.append(el('p',{},'Revisa tu conexión y vuelve a buscar.'));}
 }
 function syncTargets(startTarget){
   availableTargets=allTargets();
   target=isProductInput(startTarget)?startTarget:(target&&isProductInput(target)?target:(chooseTarget()));
   targetSelect.replaceChildren();
   if(!availableTargets.length){target=null;targetSelect.append(el('option',{value:'-1'},'No hay buscador de productos disponible'));targetSelect.disabled=true;
     targetHint.textContent='Abre la sección de búsqueda de productos del módulo. En esta pantalla no se detectó un campo de destino.';return;
   }
   targetSelect.disabled=false;
   availableTargets.forEach((input,i)=>targetSelect.append(el('option',{value:String(i)},labelForTarget(input))));
   const index=Math.max(0,availableTargets.indexOf(target));target=availableTargets[index];targetSelect.value=String(index);
   targetHint.textContent='Al seleccionar se filtrará en el buscador existente, sin añadir ni guardar productos.';
 }
 function openModal(startTarget){if(isPos){document.getElementById('abrir_catalogo')?.click();return;}
   build();syncTargets(startTarget);search.value=target?.value||'';
   modal.dataset.open='1';modal.setAttribute('aria-hidden','false');open=true;document.body.dataset.gydCatalogoAbierto='1';
   pagina=1;load();setTimeout(()=>search.focus(),0);
 }
 function close(){if(!open)return;open=false;seq++;controller?.abort();clearTimeout(timer);modal.dataset.open='0';modal.setAttribute('aria-hidden','true');delete document.body.dataset.gydCatalogoAbierto;target?.focus();}
 function showFeedback(msg,isError=false){
   let note=document.getElementById('gyd-catalogo-feedback');if(!note){note=el('div',{id:'gyd-catalogo-feedback',role:'status','aria-live':'polite'});document.body.append(note);}
   note.style.cssText='position:fixed;right:18px;bottom:18px;z-index:2147483001;max-width:min(460px,calc(100vw - 36px));background:'+(isError?'#fff0f0':'#eaf6ef')+';border:1px solid '+(isError?'#dfb4b4':'#9bc5a7')+';color:#123b56;padding:14px 16px;border-radius:10px;box-shadow:0 8px 32px #102a3f22;font:600 13px system-ui';
   note.textContent=msg;note.onclick=()=>note.remove();
   clearTimeout(note._gydTimeout);note._gydTimeout=setTimeout(()=>note.remove(),7000);
 }
 function triggerOriginalSearch(destino){
   const path=location.pathname.split('/').pop().toLowerCase();
   // Existencias: campo REAL name="buscar", botón Filtrar y formulario GET.
   // Se preservan almacén, rack, estado, categoría, proveedor y orden.
   if(isExistenciasSearch(destino)){
     const form=destino.form;
     if(!form||(form.method||'get').toLowerCase()!=='get')return 'input';
     const btn=form.querySelector('.existencias-actions button[type="submit"]')||form.querySelector('button[type="submit"]');
     if(btn?.disabled)return 'input';
     if(typeof form.requestSubmit==='function')form.requestSubmit(btn||undefined);
     else if(btn)btn.click();
     else form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
     return 'get';
   }
   // Adaptadores específicos comprobados contra los formularios originales. SOLO BUSCAR.
   let btn=null;
   if(path==='erp_facturas.php'&&destino.id==='buscar')btn=document.getElementById('buscarBoton');
   else if((path==='erp_oc_win.php'||path==='erp_ordenes_compra.php')&&destino.id==='q')btn=document.getElementById('buscar');
   else if(destino.id==='buscar')btn=document.getElementById('buscarBoton')||document.getElementById('btnbuscar');
   if(!btn){
     const c=destino.closest('.search-row,.twocol,.find,.search,.field,.searchbar,.busqueda,.search-box,.controls')||destino.form;
     if(c)btn=[...c.querySelectorAll('button,input[type="submit"]')].find(b=>/buscar|consultar|filtrar|ver resultados/i.test(b.textContent||b.value||''))||null;
   }
   if(btn&&isVisible(btn)&&!btn.disabled){btn.click();return 'button';}
   // Una consulta GET puede ejecutarse sin cambiar datos; nunca enviar formularios POST.
   const form=destino.form;
   if(form&&(form.method||'get').toLowerCase()==='get'&&destino.name){
     // El Kardex conserva el id de producto seleccionado como filtro independiente.
     if(path==='erp_kardex_general.php'){
       const old=form.querySelector('input[name="producto"]');if(old)old.value='0';
     }
     if(typeof form.requestSubmit==='function')form.requestSubmit();else form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
     return 'get';
   }
   return 'input';
 }
 function select(p){if(!p.activo){caption.textContent='Este artículo está inactivo. No se envió al formulario.';return;}
   const code=String(p.codigo||p.codigo_barras||p.id);
   // Refresca los campos porque algunas pantallas los renderizan después de abrir el modal.
   if(!target||!isProductInput(target)){syncTargets(null);}
   const destino=target&&isProductInput(target)?target:null;
   if(!destino){
     caption.textContent='No se encontró un campo de búsqueda de productos en esta pantalla. Abre primero ese apartado y vuelve a pulsar Ctrl+B.';
     targetHint.textContent='La selección no se realizó: no hay campo al cual enviar el código.';
     return;
   }
   close();
   destino.value=code;
   destino.dispatchEvent(new Event('input',{bubbles:true}));
   destino.dispatchEvent(new Event('change',{bubbles:true}));
   document.dispatchEvent(new CustomEvent('gyd:catalogo-producto-seleccionado',{detail:{producto:p,campo:destino,codigo:code},bubbles:true}));
   destino.scrollIntoView({behavior:'auto',block:'center'});
   destino.focus();
   const outcome=triggerOriginalSearch(destino);
   if(outcome==='input')showFeedback('Código '+code+' enviado a «'+labelForTarget(destino)+'». Si el módulo no filtra al escribir, pulsa su botón Buscar.');
 }
 document.addEventListener('keydown',e=>{
   if(e.isComposing||e.altKey)return;
   if(open){if(e.key==='Escape'){e.preventDefault();close();return}if(e.key==='ArrowDown'){e.preventDefault();selected=Math.min(current.length-1,selected+1);updateSelection();results.children[selected]?.scrollIntoView({block:'nearest'});return}if(e.key==='ArrowUp'){e.preventDefault();selected=Math.max(0,selected-1);updateSelection();results.children[selected]?.scrollIntoView({block:'nearest'});return}if(e.key==='Enter'&&current[selected]){e.preventDefault();select(current[selected]);return}}
   if((e.ctrlKey||e.metaKey)&&!e.shiftKey&&e.key.toLowerCase()==='b'){
     if(isPos)return;
     if(open){e.preventDefault();search.focus();search.select();return;} // El POS ya implementa Ctrl+B/F3, se respeta su catálogo con stock.
     e.preventDefault();e.stopImmediatePropagation();openModal(document.activeElement);
   }
 },true);
 window.GYD_CatalogoGlobal={abrir:openModal,cerrar:close,version:'global-integracion-v3-existencias'};
})();
