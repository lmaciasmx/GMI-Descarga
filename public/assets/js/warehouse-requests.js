(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  if (!$('wr-context')) return;
  const cfg = JSON.parse($('wr-context').textContent);
  const cat = cfg.catalogs, mode = cfg.mode, root = '/internal/v1/warehouse-requests', saleRoot = '/internal/v1/sales/requests', historyRoot = '/internal/v1/sales/requests/history';
  const customers = Array.isArray(cat.customers) ? [...cat.customers] : [];
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const num = (v) => Number(v || 0), kg = (v) => num(v).toLocaleString('es-MX', {minimumFractionDigits:3,maximumFractionDigits:3});
  const round3 = (v) => Math.round((Number(v) + Number.EPSILON) * 1000) / 1000;
  const states = {open:'Pendiente de aceptación',accepted:'Aceptada',preparing:'En preparación',dispatching:'Despacho abierto',dispatched:'Despacho finalizado',closed:'Cerrada',rejected:'Rechazada'};
  const unitStates = {selected:'Propuesta',shipped:'En tránsito',accepted:'Recibida',returning:'Devolución pendiente',returned:'Reingresada al origen',released:'Liberada'};
  const events = {created:'Solicitud enviada',accepted:'Aceptada por proveedor',preselection:'Propuesta actualizada',unit_shipped:'Salida de tina',supply_finalized:'Despacho finalizado',dispatch_reopened:'Despacho reabierto',receipt_registered:'Recepción o devolución registrada',receipt_canceled:'Recepción anulada',return_reentered:'Devolución reingresada',closed:'Cierre validado',rejected:'Solicitud rechazada'};
  const concept = (r) => r.request_type === 'production' ? 'Materia prima para producción' : 'Traspaso entre almacenes';
  const button = (action, label, id='', style='outline-primary') => '<button type="button" class="btn btn-sm btn-'+style+'" data-action="'+action+'" data-id="'+esc(id)+'">'+label+'</button>';
  const disabledButton = (label, style='outline-secondary') => '<button type="button" class="btn btn-sm btn-'+style+'" disabled>'+esc(label)+'</button>';
  const opts = (rows,label,empty='Selecciona') => '<option value="">'+esc(empty)+'</option>'+rows.map((r) => '<option value="'+num(r.id)+'">'+esc(typeof label==='function'?label(r):r[label])+'</option>').join('');
  let detail = null, saleDetail = null, lookup = null, reading = null, busy = false, before = null, unitAfter = null;
  let recoveredOutgoing = null;
  let messageTimer = null;
  let historyPage = 1;
  let stockTarget = null, stockRows = [], stockTable = null, dialogAction = null;
  const draftSelections = new Map();
  let workView = mode==='requests' ? (cfg.canCapture?'capture':'list') : null;
  let detailTab = 'summary';
  const api = (url, data) => GMar.api(root+url, data === undefined ? {} : {method:'POST',body:JSON.stringify(data)});
  const saleApi = (url, data) => GMar.api(saleRoot+url, {method:'POST',body:JSON.stringify(data)});
  const saleGet = (url) => GMar.api(saleRoot+url);
  const historyApi = (params={}) => GMar.api(historyRoot+query(params));
  const query = (data) => '?'+new URLSearchParams(Object.entries(data).filter(([,v])=>v!==null && v!==undefined)).toString();
  const show = (id, yes=true) => $(id)?.classList.toggle('d-none',!yes);
  const message = (text, type='success') => {
    const box=$('wr-message');
    if(!box) return;
    if(messageTimer){clearTimeout(messageTimer);messageTimer=null;}
    box.className='alert alert-'+type;
    box.textContent=text;
    // Los avisos operativos correctos no deben quedarse ocupando la estación.
    // Los errores/advertencias permanecen visibles para que el operador pueda revisarlos.
    const delay=type==='success'?4200:(type==='info'?5200:0);
    if(delay>0){
      messageTimer=setTimeout(()=>{
        box.classList.add('d-none');
        messageTimer=null;
      },delay);
    }
  };
  function stationState(text,tone='') {
    const pill=$('wr-station-ready');
    if(!pill) return;
    pill.className='ws-status-pill'+(tone?' '+tone:'');
    const label=pill.querySelector('span:last-child');
    if(label) label.textContent=text;
  }
  function stationContext(text) {
    if($('wr-station-context-title')) $('wr-station-context-title').textContent=text;
  }
  function selectedScale() {
    if(!$('wr-scale')) return null;
    return (cat.scales||[]).find((row)=>num(row.id)===num($('wr-scale').value))||null;
  }
  function scaleAgentUrl(row) {
    return String(row?.agent_url||'http://127.0.0.1:18182').replace(/\/$/,'');
  }
  function scaleConfiguration(row) {
    return {
      scale_identifier:row.station_code||row.code||'BASCULA',station_code:row.station_code||'',model:row.model||'',
      port_name:String(row.port_name||'').toUpperCase(),baud_rate:num(row.baud_rate)||9600,data_bits:num(row.data_bits)||8,
      parity:row.parity||'none',stop_bits:String(row.stop_bits||'1'),read_command:row.read_command??'P',
      command_terminator:row.command_terminator||'none',response_timeout_ms:num(row.response_timeout_ms)||2000,
      settle_delay_ms:num(row.settle_delay_ms)||250,stable_reads:num(row.stable_reads)||2,
      stable_tolerance_kg:num(row.stable_tolerance_kg)||0.050,weight_regex:row.weight_regex||'[-+]?[0-9]+(?:[.,][0-9]+)?',unit:'kg'
    };
  }
  async function scaleAgentFetch(row,path,options={}) {
    const controller=new AbortController(), timer=setTimeout(()=>controller.abort(),10000);
    try {
      const response=await fetch(scaleAgentUrl(row)+path,{...options,signal:controller.signal,mode:'cors',cache:'no-store',headers:{'Content-Type':'application/json',...(options.headers||{})}});
      const payload=await response.json().catch(()=>({}));
      if(!response.ok||payload.success===false) throw new Error(payload?.error?.message||payload?.message||('El agente respondió HTTP '+response.status+'.'));
      return payload.data||payload;
    } catch(error) {
      if(error.name==='AbortError') throw new Error('El agente de báscula no respondió a tiempo.');
      if(error instanceof TypeError) throw new Error('No fue posible conectar con el agente de báscula. Verifica que esté iniciado en 127.0.0.1:18182.');
      throw error;
    } finally { clearTimeout(timer); }
  }
  function scaleStatus(text,ok=false) {
    const box=$('wr-scale-top-status'); if(!box) return;
    box.classList.toggle('online',ok); box.classList.toggle('offline',!ok);
    const small=box.querySelector('small'); if(small) small.textContent=text;
  }
  function testWeightMode() {
    return ['outgoing','incoming'].includes(mode) && !!$('wr-test-mode')?.checked;
  }
  function syncTestWeightMode(resetReading=true) {
    if(!['outgoing','incoming'].includes(mode) || !$('wr-test-mode')) return;
    const active=testWeightMode();
    const card=$('wr-unit-form')?.closest('.wr-outgoing-register-card');
    card?.classList.toggle('is-test-mode',active);
    show('wr-weight-display',!active);
    show('wr-test-weight-panel',active);
    const label=$('wr-read-label'); if(label) label.textContent=active?'Usar peso de prueba':'Leer báscula';
    const icon=$('wr-read')?.querySelector('i'); if(icon) icon.className=active?'bi bi-beaker me-1':'bi bi-speedometer2 me-1';
    if(active && $('wr-test-weight') && lookup?.stock && !$('wr-test-weight').value) {
      const tare=num(lookup.stock.current_tare_kg);
      const net=mode==='incoming'?num(lookup.unit?.shipped_weight_kg||lookup.stock.on_hand_weight_kg):num(lookup.stock.on_hand_weight_kg);
      const baseline=round3(net+tare);
      if(baseline>0) $('wr-test-weight').value=baseline.toFixed(3);
    }
    if(resetReading) {
      reading=null;
      if($('wr-weight')) $('wr-weight').textContent='—';
      if($('wr-weight-raw')) $('wr-weight-raw').textContent=active?'PRUEBA · captura un peso ficticio antes de registrar.':'Sin lectura';
    }
  }
  function chamberLabel(row) {
    const name=String(row?.name||'').trim(), code=String(row?.code||'').trim();
    const nameMatch=name.match(/c[aá]mara\s*0*(\d+)/i);
    if(nameMatch) return 'Cámara '+nameMatch[1].padStart(2,'0');
    const codeMatch=code.match(/(?:CAM(?:ARA)?[-\s]*)0*(\d+)/i);
    if(codeMatch) return 'Cámara '+codeMatch[1].padStart(2,'0');
    return name||code||'Cámara';
  }
  function rowLabel(row) {
    const values=[row?.code,row?.name,row?.path].map((v)=>String(v||'').trim()).filter(Boolean);
    for(const value of values) {
      const match=value.match(/(?:^|\/|\b)F(?:ILA)?[-\s]*0*(\d+)(?:\/|\b|$)/i);
      if(match) return 'F'+match[1].padStart(2,'0');
    }
    return values[0]||'Fila';
  }
  function currentLocationLabel(stock) {
    const chamberId=num(stock?.current_chamber_id||stock?.location_chamber_id);
    const rowId=num(stock?.current_row_id);
    const chamber=(cat.chambers||[]).find((item)=>num(item.id)===chamberId);
    const row=(cat.rows||[]).find((item)=>num(item.id)===rowId);
    let chamberText=chamber?chamberLabel(chamber):'';
    let rowText=row?rowLabel(row):'';
    const path=String(stock?.location_path||'');
    if(!chamberText) {
      const match=path.match(/CAM(?:ARA)?[-\s]*0*(\d+)/i);
      if(match) chamberText='Cámara '+match[1].padStart(2,'0');
    }
    if(!rowText) {
      const match=path.match(/(?:^|\/)F(?:ILA)?[-\s]*0*(\d+)(?:\/|$)/i);
      if(match) rowText='F'+match[1].padStart(2,'0');
    }
    if(chamberText&&rowText) return chamberText.toUpperCase()+' / FILA '+rowText.replace(/^F/i,'');
    return (chamberText||rowText||'Sin ubicación').toUpperCase();
  }
  function outgoingEmptyRequestHtml() {
    return '<div><i class="bi bi-check-circle"></i><strong>Solicitud detectada</strong><span>Se mostrará al leer la tina.</span></div>';
  }
  function outgoingEmptyManifestHtml() {
    return '<div class="wr-manifest-table-head" aria-hidden="true"><span>#</span><span>Código de tina</span><span>Peso (kg)</span><span>Estatus</span></div><div class="wr-manifest-empty"><i class="bi bi-clipboard"></i><span>Escanea una tina para cargar el folio y su avance.</span></div>';
  }
  function clearOutgoingContext() {
    if(mode!=='outgoing') return;
    detail=null; saleDetail=null; unitAfter=null;
    if($('wr-outgoing-request')) {
      $('wr-outgoing-request').className='wr-outgoing-request-empty';
      $('wr-outgoing-request').innerHTML=outgoingEmptyRequestHtml();
    }
    if($('wr-manifest-count')) $('wr-manifest-count').textContent='0 tinas';
    if($('wr-outgoing-manifest')) $('wr-outgoing-manifest').innerHTML=outgoingEmptyManifestHtml();
    stationContext('Lee una tina para identificar la solicitud');
  }
  function incomingEmptyRequestHtml() {
    return '<div><i class="bi bi-check-circle"></i><strong>Solicitud detectada</strong><span>Se mostrará al leer la tina.</span></div>';
  }
  function incomingEmptyManifestHtml() {
    return '<div class="wr-manifest-table-head" aria-hidden="true"><span>#</span><span>Código de tina</span><span>Peso (kg)</span><span>Estatus</span></div><div class="wr-manifest-empty"><i class="bi bi-clipboard"></i><span>Escanea una tina para cargar el folio y su avance de recepción.</span></div>';
  }
  function clearIncomingContext() {
    if(mode!=='incoming') return;
    detail=null; unitAfter=null;
    if($('wr-incoming-request')) {
      $('wr-incoming-request').className='wr-outgoing-request-empty';
      $('wr-incoming-request').innerHTML=incomingEmptyRequestHtml();
    }
    if($('wr-manifest-count')) $('wr-manifest-count').textContent='0 tinas';
    if($('wr-incoming-manifest')) $('wr-incoming-manifest').innerHTML=incomingEmptyManifestHtml();
    stationContext('Lee una tina para identificar la solicitud');
  }
  async function probeScale() {
    if(!['outgoing','incoming'].includes(mode)) return;
    const row=selectedScale();
    if(!row) { scaleStatus('Sin báscula configurada',false); return; }
    if(!row.port_name) { scaleStatus('Falta puerto COM',false); return; }
    try { const status=await scaleAgentFetch(row,'/api/v1/status',{method:'GET',headers:{}}); scaleStatus((row.name||'Báscula')+' · '+(row.port_name||'')+(status.status==='ready'?' lista':''),true); }
    catch(e) { scaleStatus('Agente desconectado',false); }
  }
  function fillRows(chamberId,selected=null) {
    if(!$('wr-row')) return;
    const rows=(cat.rows||[]).filter((row)=>num(row.chamber_id)===num(chamberId));
    $('wr-row').innerHTML=opts(rows,rowLabel,'Selecciona fila');
    if(selected&&rows.some((row)=>num(row.id)===num(selected))) $('wr-row').value=String(selected);
  }
  async function run(fn) {
    if (busy) return false;
    busy=true; $('wr-app').setAttribute('aria-busy','true');
    try { await fn(); return true; }
    catch (e) {
      message(e.message || 'No se pudo completar. Actualiza el folio y revisa el resultado antes de repetir.', 'danger');
      const target=$('wr-stock-dialog').open?'wr-stock-message':($('wr-dialog').open?'wr-dialog-message':null);
      if(target) { $(target).textContent=e.message; show(target); }
      return false;
    } finally { busy=false; $('wr-app').setAttribute('aria-busy','false'); }
  }
  const provider = () => detail && num(detail.request.source_warehouse_id)===num(cat.scope.id);
  const openSupply = () => detail && !detail.request.supply_finalized_at && ['accepted','preparing','dispatching'].includes(detail.request.status);
  const selections = () => detail.selected_units.map((u)=>({stock_unit_id:num(u.stock_unit_id),request_line_id:num(u.request_line_id)}));
  async function mutate(action, data={}) {
    detail=await api('/'+detail.request.id+'/'+action,{lock_version:num(detail.request.lock_version),...data});
    unitAfter=detail.next_unit_after;
    if(mode==='outgoing') renderOutgoingRequest(); else if(mode==='incoming') renderIncomingRequest(); else renderDetail();
    return detail;
  }
  function sizes(species) { return cat.sizes.filter((s)=>!num(s.species_id)||num(s.species_id)===num(species)); }
  function addLine() {
    const tr=document.createElement('tr');
    tr.innerHTML='<td class="wr-line-number" data-line-number></td>'+
      '<td><select class="form-select form-select-sm" data-field="species_id" aria-label="Especie" required>'+opts(cat.species,'name')+'</select></td>'+
      '<td><select class="form-select form-select-sm" data-field="size_grade_id" aria-label="Talla" required>'+opts([],'name')+'</select></td>'+
      '<td><select class="form-select form-select-sm wr-lot" data-field="lot_id" aria-label="Lote"><option value="">Cualquier lote</option></select></td>'+
      '<td><select class="form-select form-select-sm" data-field="owner_company_id" aria-label="Propietario">'+opts(cat.owners,'trade_name','Cualquier propietario')+'</select></td>'+
      '<td><input type="number" class="form-control form-control-sm text-end" data-field="requested_weight_kg" aria-label="Kilos solicitados" step=".001" min=".001" max="99999999999.999" required></td>'+
      '<td class="wr-coverage" data-coverage><div class="wr-coverage-main">0.000 kg</div><div class="wr-coverage-sub">Sin selección</div></td>'+
      '<td class="text-center fw-semibold" data-units>0</td>'+
      '<td data-line-state><span class="wr-state-badge wr-state-empty">Sin cobertura</span></td>'+
      '<td><div class="wr-line-actions">'+button('capture-stock','<i class="bi bi-search"></i>','','outline-primary').replace('class="btn btn-sm','aria-label="Consultar existencias" title="Consultar / seleccionar" class="btn btn-sm wr-icon-btn')+button('remove-line','<i class="bi bi-trash"></i>','','outline-danger').replace('class="btn btn-sm','aria-label="Eliminar línea" title="Eliminar línea" class="btn btn-sm wr-icon-btn')+'</div></td>';
    $('wr-lines').append(tr);
    draftSelections.set(tr,new Map());
    updateLineNumbers();
    updateLineCoverage(tr);
    updateCaptureDashboard();
  }
  function lineData(tr) {
    return Object.fromEntries([...tr.querySelectorAll('[data-field]')].map((el)=>[el.dataset.field,el.value===''?null:el.value]));
  }
  const lineRows = () => [...$('wr-lines').children];
  const lineNumber = (tr) => lineRows().indexOf(tr)+1;
  const specificity = (line) => (num(line.lot_id)>0?1:0)+(num(line.owner_company_id)>0?1:0);
  function scopeMatches(stock,line) {
    return num(stock.species_id)===num(line.species_id) && num(stock.size_grade_id)===num(line.size_grade_id)
      && (!num(line.lot_id)||num(stock.lot_id)===num(line.lot_id))
      && (!num(line.owner_company_id)||num(stock.owner_company_id)===num(line.owner_company_id));
  }
  function preferredDraftLines(stock) {
    const matches=lineRows().map((tr)=>({tr,line:lineData(tr)})).filter((x)=>scopeMatches(stock,x.line));
    if(!matches.length) return [];
    const max=Math.max(...matches.map((x)=>specificity(x.line)));
    return matches.filter((x)=>specificity(x.line)===max);
  }
  function draftAllocation(stock,tr) {
    const preferred=preferredDraftLines(stock);
    return {allowed:preferred.length===1&&preferred[0].tr===tr,preferred};
  }
  function draftMap(tr) {
    if(!draftSelections.has(tr)) draftSelections.set(tr,new Map());
    return draftSelections.get(tr);
  }
  function draftSelectedOwner(stockId) {
    for(const tr of lineRows()) if(draftMap(tr).has(num(stockId))) return tr;
    return null;
  }
  function draftSummary(tr) {
    const rows=[...draftMap(tr).values()];
    return {units:rows.length,kg:rows.reduce((sum,row)=>sum+num(row.on_hand_weight_kg),0)};
  }
  function clearDraft(tr) {
    draftSelections.set(tr,new Map());
    updateLineCoverage(tr);
  }
  function scopeKey(line) {
    return [num(line.species_id),num(line.size_grade_id),num(line.lot_id),num(line.owner_company_id)].join(':');
  }
  function validateDraftStructure() {
    const seen=new Map();
    for(const tr of lineRows()) {
      const line=lineData(tr);
      if(!num(line.species_id)||!num(line.size_grade_id)) continue;
      const key=scopeKey(line);
      if(seen.has(key)) throw new Error('Las líneas '+lineNumber(seen.get(key))+' y '+lineNumber(tr)+' tienen la misma Especie, Talla, Lote y Propietario. Modifica la línea existente en lugar de duplicarla.');
      seen.set(key,tr);
    }
  }
  function validateDraftAllocations() {
    for(const tr of lineRows()) {
      for(const stock of draftMap(tr).values()) {
        const allocation=draftAllocation(stock,tr);
        if(!allocation.allowed) {
          const lines=allocation.preferred.map((x)=>lineNumber(x.tr)).filter(Boolean).join(', ');
          throw new Error('La tina '+(stock.tub_code||stock.unit_code)+' seleccionada en la línea '+lineNumber(tr)+' ahora corresponde a '+(lines?'la línea '+lines:'otro criterio')+'. Quita esa tina o ajusta los criterios antes de enviar.');
        }
      }
    }
  }
  function competingScopes(tr) {
    const current=lineData(tr);
    return lineRows().filter((row)=>row!==tr).map((row)=>lineData(row)).filter((line)=>num(line.species_id)===num(current.species_id)&&num(line.size_grade_id)===num(current.size_grade_id))
      .map((line)=>num(line.lot_id)+':'+num(line.owner_company_id)).join(';');
  }
  function updateLineCoverage(tr) {
    const box=tr.querySelector('[data-coverage]'), unitsBox=tr.querySelector('[data-units]'), stateBox=tr.querySelector('[data-line-state]');
    if(!box) return;
    const sale=$('wr-type')?.value==='sale';
    const selected=draftSummary(tr), requested=num(tr.querySelector('[data-field="requested_weight_kg"]')?.value), diff=selected.kg-requested;
    box.innerHTML='<div class="wr-coverage-main">'+kg(selected.kg)+' kg</div><div class="wr-coverage-sub">'+(selected.units?(requested>0?(Math.abs(diff)<.0005?(sale?'Propuesta exacta':'Cobertura exacta'):(diff>0?(sale?'Propuesta excede ':'Excede ')+kg(diff)+' kg':(sale?'Propuesta queda corta ':'Faltan ')+kg(Math.abs(diff))+' kg')):'Captura los kg requeridos'):(sale?'Sin propuesta':'Sin selección'))+'</div>';
    if(unitsBox) unitsBox.textContent=String(selected.units);
    if(stateBox) {
      let cls='wr-state-empty', label=sale?'Sin propuesta':'Sin cobertura';
      if(selected.units && requested>0 && Math.abs(diff)<.0005){cls='wr-state-complete';label=sale?'Propuesta exacta':'Completa';}
      else if(selected.units && requested>0 && diff>0){cls='wr-state-over';label=sale?'Propuesta excede':'Con excedente';}
      else if(selected.units){cls='wr-state-partial';label=sale?'Propuesta parcial':'Cobertura parcial';}
      stateBox.innerHTML='<span class="wr-state-badge '+cls+'">'+label+'</span>';
    }
    updateCaptureDashboard();
  }
  function currentLine(tr) {
    const data=lineData(tr);
    data.lot_code=tr.querySelector('[data-field="lot_id"] option:checked')?.textContent||'';
    return data;
  }
  const lotOptionLabel=(row)=>row.code+' · '+num(row.units)+' tinas · '+kg(row.kg)+' kg';
  async function loadLineLots(tr) {
    const warehouse=captureWarehouseId(), line=lineData(tr), lot=tr.querySelector('[data-field="lot_id"]');
    const selected=num(lot.value);
    if(!warehouse||!num(line.species_id)||!num(line.size_grade_id)) { lot.innerHTML='<option value="">Cualquier lote</option>';return; }
    const result=await api('/availability-facets'+query({warehouse_id:warehouse,species_id:line.species_id,size_grade_id:line.size_grade_id,filter_owner_company_id:line.owner_company_id||''}));
    lot.innerHTML=opts(result.lots||[],lotOptionLabel,'Cualquier lote');
    if(selected && (result.lots||[]).some((r)=>num(r.id)===selected)) lot.value=String(selected);
  }
  function updateLineNumbers() {
    lineRows().forEach((tr,i)=>{const cell=tr.querySelector('[data-line-number]');if(cell)cell.textContent=String(i+1);});
  }
  function scopeWarehouseLabel() {
    return [cat.scope?.company_name,cat.scope?.site_name,cat.scope?.name].filter(Boolean).join(' · ')||'Almacén actual';
  }
  function captureWarehouseId() {
    return $('wr-type')?.value==='sale' ? num(cat.scope?.id) : num($('wr-source')?.value);
  }
  function sourceLabel() {
    if($('wr-type')?.value==='sale') return scopeWarehouseLabel();
    const option=$('wr-source')?.selectedOptions?.[0];
    return $('wr-source')?.value ? (option?.textContent||'Seleccionado') : 'Sin seleccionar';
  }
  function customerText(row) {
    if(!row) return '';
    return (row.code ? row.code+' · ' : '') + (row.trade_name || row.legal_name || 'Cliente');
  }
  function selectedCustomer() {
    const id=num($('wr-customer')?.value);
    return id ? (customers.find((row)=>num(row.id)===id)||null) : null;
  }
  function customerLabel() {
    const row=selectedCustomer();
    return row ? customerText(row) : 'Sin seleccionar';
  }
  function hideCustomerResults() {
    const box=$('wr-customer-results');
    if(box) box.classList.add('d-none');
  }
  function selectCustomer(row) {
    if(!row) return;
    if($('wr-customer')) $('wr-customer').value=String(row.id);
    if($('wr-customer-search')) $('wr-customer-search').value=customerText(row);
    hideCustomerResults();
    updateCaptureDashboard();
  }
  function renderCustomerResults() {
    const box=$('wr-customer-results'), input=$('wr-customer-search');
    if(!box||!input) return;
    const term=input.value.trim().toLocaleLowerCase('es-MX');
    const rows=customers.filter((row)=>{
      if(!term) return true;
      return [row.code,row.trade_name,row.legal_name,row.tax_id]
        .filter(Boolean)
        .some((value)=>String(value).toLocaleLowerCase('es-MX').includes(term));
    }).slice(0,12);
    box.innerHTML=rows.length
      ? rows.map((row)=>'<button type="button" class="wr-customer-result" role="option" data-customer-id="'+num(row.id)+'"><strong>'+esc(customerText(row))+'</strong>'+(row.legal_name&&row.legal_name!==row.trade_name?'<span>'+esc(row.legal_name)+'</span>':'')+'</button>').join('')
      : '<div class="wr-customer-empty"><i class="bi bi-search"></i><span>No se encontraron clientes. Puedes registrar uno con <strong>Nuevo cliente</strong>.</span></div>';
    box.classList.remove('d-none');
  }
  function openCustomerDialog() {
    const dialog=$('wr-customer-dialog');
    if(!dialog) return;
    $('wr-customer-form')?.reset();
    const seed=$('wr-customer-search')?.value.trim()||'';
    if(seed && !$('wr-customer')?.value) {
      if($('wr-customer-trade-name')) $('wr-customer-trade-name').value=seed;
      if($('wr-customer-legal-name')) $('wr-customer-legal-name').value=seed;
    }
    hideCustomerResults();
    dialog.showModal();
    setTimeout(()=>$('wr-customer-code')?.focus(),0);
  }
  function requestTypeLabel(type=$('wr-type')?.value) {
    if(type==='production') return 'Materia prima';
    if(type==='sale') return 'Venta a cliente';
    return 'Traspaso interno';
  }
  function productionDestinationFromDetail(d=detail) {
    if(!d || d.request?.request_type!=='production') return '';
    const created=(d.events||[]).slice().reverse().find((e)=>e.action==='created');
    if(!created) return '';
    try {
      const data=typeof created.details==='string' ? JSON.parse(created.details) : (created.details||{});
      return String(data.production_destination||'').trim();
    } catch (_) {
      return '';
    }
  }
  function productionIssueState(d=detail) {
    if(!d || d.request?.request_type!=='production') return {pending:[],completed:[],reversed:[]};
    if(d.production?.units) {
      return {
        pending:d.production.units.filter(u=>u.production_state==='pending'),
        completed:d.production.units.filter(u=>u.production_state==='completed'),
        reversed:d.production.units.filter(u=>u.production_state==='reversed')
      };
    }
    const pending=new Map(), completed=new Set();
    for(const e of (d.events||[])) {
      if(!['production_issue_pending','production_issue_completed'].includes(e.action)) continue;
      try {
        const data=typeof e.details==='string' ? JSON.parse(e.details) : (e.details||{});
        const unitId=num(data.unit_id);
        if(!unitId) continue;
        if(e.action==='production_issue_completed') {
          completed.add(unitId);
          pending.delete(unitId);
        } else if(!completed.has(unitId)) {
          pending.set(unitId,data);
        }
      } catch (_) {}
    }
    return {pending:[...pending.values()],completed:[...completed],reversed:[]};
  }
  function hasDuplicateLines() {
    const seen=new Set();
    for(const tr of lineRows()){
      const line=lineData(tr); if(!num(line.species_id)||!num(line.size_grade_id)) continue;
      const key=scopeKey(line); if(seen.has(key)) return true; seen.add(key);
    }
    return false;
  }
  function repeatedDraftUnits() {
    const seen=new Set();
    for(const tr of lineRows()) for(const id of draftMap(tr).keys()){if(seen.has(num(id))) return true;seen.add(num(id));}
    return false;
  }
  function captureMetrics() {
    const lines=lineRows(); let requested=0,covered=0,units=0,complete=0,partial=0;
    lines.forEach((tr)=>{const req=num(tr.querySelector('[data-field="requested_weight_kg"]')?.value),sum=draftSummary(tr);requested+=req;covered+=sum.kg;units+=sum.units;if(sum.units&&req>0){if(sum.kg+.0005>=req)complete++;else partial++;}});
    return {lines:lines.length,requested,covered,units,pending:Math.max(0,requested-covered),difference:covered-requested,complete,partial};
  }
  function validationRow(name,ok) {
    const el=document.querySelector('#wr-validation-list [data-validation="'+name+'"]'); if(!el) return;
    el.classList.toggle('is-ok',ok);el.classList.toggle('is-error',!ok);
    const icon=el.querySelector('i');if(icon)icon.className='bi '+(ok?'bi-check-circle-fill':'bi-exclamation-circle-fill');
  }
  function liveMessage(type,text,icon='bi-info-circle-fill') { return '<div class="wr-live-message '+type+'"><i class="bi '+icon+'"></i><span>'+esc(text)+'</span></div>'; }
  function updateCaptureDashboard() {
    if(mode!=='requests'||!$('wr-summary-lines')) return;
    const m=captureMetrics(), type=$('wr-type')?.value, sale=type==='sale', production=type==='production',
      productionDestination=String($('wr-production-destination')?.value||'').trim(),
      general=Boolean((sale?num($('wr-customer')?.value):num($('wr-source')?.value))&&$('wr-required')?.value&&type&&(!production||productionDestination));
    const linesValid=lineRows().length>0&&lineRows().every((tr)=>{const l=lineData(tr);return num(l.species_id)>0&&num(l.size_grade_id)>0&&num(l.requested_weight_kg)>0;});
    const duplicate=hasDuplicateLines(), repeated=repeatedDraftUnits();

    $('wr-summary-type').textContent=requestTypeLabel();$('wr-summary-source').textContent=sourceLabel();
    if($('wr-summary-source-label')) $('wr-summary-source-label').textContent=sale?'Almacén que surte':'Almacén proveedor';
    if($('wr-summary-destination-label')) $('wr-summary-destination-label').textContent=sale?'Cliente':(production?'Área de producción':'Almacén destino');
    if($('wr-summary-destination')) $('wr-summary-destination').textContent=sale?customerLabel():(production?(productionDestination||'Sin definir'):(cat.scope?.name||'Almacén actual'));
    $('wr-summary-required').textContent=$('wr-required')?.value ? new Date($('wr-required').value).toLocaleString('es-MX',{dateStyle:'short',timeStyle:'short'}) : 'Sin definir';
    $('wr-summary-lines').textContent=String(m.lines);
    $('wr-summary-requested').textContent=kg(m.requested);
    $('wr-summary-covered').textContent=kg(m.covered);
    $('wr-summary-pending').textContent=sale?((m.difference>0?'+':'')+kg(m.difference)):kg(m.pending);
    $('wr-summary-units').textContent=String(m.units);
    $('wr-summary-pending').classList.toggle('wr-negative',sale?Math.abs(m.difference)>.0005:m.pending>0);

    if($('wr-heading-coverage')) $('wr-heading-coverage').textContent=sale?'Kg propuestos':'Cobertura';
    if($('wr-heading-units')) $('wr-heading-units').textContent=sale?'Tinas propuestas':'Tinas';
    if($('wr-heading-state')) $('wr-heading-state').textContent=sale?'Propuesta':'Estado';
    if($('wr-summary-covered-label')) $('wr-summary-covered-label').textContent=sale?'Kg propuestos':'Kg cubiertos';
    if($('wr-summary-pending-label')) $('wr-summary-pending-label').textContent=sale?'Diferencia propuesta':'Kg pendientes';
    if($('wr-summary-units-label')) $('wr-summary-units-label').textContent=sale?'Tinas propuestas':'Tinas seleccionadas';
    if($('wr-validation-units-label')) $('wr-validation-units-label').textContent=sale?'Propuesta opcional sin duplicados':'Sin tinas repetidas';

    validationRow('general',general);
    validationRow('lines',linesValid);
    validationRow('duplicates',!duplicate);
    validationRow('units',!repeated);

    const messages=[];
    if(duplicate) messages.push(liveMessage('danger','Hay líneas con la misma Especie, Talla, Lote y Propietario.','bi-exclamation-triangle-fill'));
    if(repeated) messages.push(liveMessage('danger',sale?'Una tina no puede proponerse en más de una línea.':'Hay una tina seleccionada en más de una línea.','bi-exclamation-triangle-fill'));
    if(!general) messages.push(liveMessage('warning',sale
      ?'Completa cliente y fecha requerida.'
      :(production
        ?'Completa almacén proveedor, área destino de producción y fecha requerida.'
        :'Completa almacén proveedor y fecha requerida.'),
      'bi-exclamation-circle-fill'));
    if(!linesValid) messages.push(liveMessage('warning','Completa Especie, Talla y Kg requeridos en todas las líneas.','bi-exclamation-circle-fill'));

    if(sale) {
      if(m.units) {
        messages.push(liveMessage('info',m.units+' tina'+(m.units===1?'':'s')+' en la propuesta. No se reservarán; almacén decidirá cuáles surtir.','bi-lightbulb-fill'));
      } else if(m.lines&&linesValid) {
        messages.push(liveMessage('info','Las tinas son opcionales. Puedes crear la solicitud sólo con la materia prima requerida.','bi-info-circle-fill'));
      }
      if(general&&linesValid&&!duplicate&&!repeated) {
        messages.push(liveMessage('success','La solicitud de venta está lista. La selección de tinas, si existe, se guardará únicamente como propuesta.','bi-check-circle-fill'));
      }
    } else {
      if(m.partial) messages.push(liveMessage('warning',m.partial+' línea'+(m.partial===1?' tiene':'s tienen')+' cobertura parcial.','bi-info-circle-fill'));
      if(m.lines&&linesValid&&!m.units) messages.push(liveMessage('info','Puedes consultar existencias por línea. La selección es una estimación y no reserva inventario.'));
      if(general&&linesValid&&!duplicate&&!repeated) messages.push(liveMessage('success','La estructura de la solicitud es válida y puede enviarse.','bi-check-circle-fill'));
    }

    $('wr-live-messages').innerHTML=messages.join('');

    if($('wr-capture-footer-text')){
      if(duplicate||repeated) {
        $('wr-capture-footer-text').textContent='Corrige los conflictos antes de enviar la solicitud.';
      } else if(!general||!linesValid) {
        $('wr-capture-footer-text').textContent='Completa los datos obligatorios; el botón de envío permanece disponible al final de la captura.';
      } else if(sale) {
        $('wr-capture-footer-text').textContent=m.units
          ? 'Solicitud lista. Las tinas seleccionadas son sólo una propuesta y no reservan inventario.'
          : 'Solicitud lista. No es necesario proponer tinas para enviarla.';
      } else {
        $('wr-capture-footer-text').textContent=m.pending>0
          ? 'Solicitud válida. Quedan '+kg(m.pending)+' kg por cubrir en la estimación.'
          : 'Solicitud lista para enviar al proveedor.';
      }
    }
  }
  function allowedRequestTypes() {
    const configured=Array.isArray(cfg.allowedRequestTypes)?cfg.allowedRequestTypes:[];
    return configured.length?configured:['transfer'];
  }
  function setRequestType(type) {
    if(!$('wr-type')) return;
    if(!allowedRequestTypes().includes(type)) return;
    if(type==='sale'&&!cfg.canSale) return;
    if(type!=='sale'&&!cfg.canCreate) return;
    const previous=$('wr-type').value;
    $('wr-type').value=type;
    const sale=type==='sale';
    document.querySelectorAll('[data-request-type]').forEach((b)=>b.classList.toggle('active',b.dataset.requestType===type));
    const production=type==='production';
    show('wr-provider-group',!sale);
    show('wr-sale-origin-group',sale);
    show('wr-customer-group',sale);
    show('wr-production-destination-group',production);
    $('wr-create')?.classList.toggle('wr-sale-mode',sale);
    if(!sale) hideCustomerResults();
    if($('wr-source')) $('wr-source').required=!sale;
    if($('wr-production-destination')) $('wr-production-destination').required=production;
    if($('wr-submit-main')) $('wr-submit-main').innerHTML=sale?'<i class="bi bi-send-check me-1"></i>Crear solicitud':'<i class="bi bi-send me-1"></i>Enviar solicitud';
    if(previous!==type) {
      for(const tr of lineRows()) {
        clearDraft(tr);
        const lot=tr.querySelector('[data-field="lot_id"]');
        if(lot) lot.innerHTML='<option value="">Cualquier lote</option>';
      }
    }
    for(const tr of lineRows()) updateLineCoverage(tr);
    updateCaptureDashboard();
  }
  function setWorkView(view) {
    if(mode!=='requests') return; workView=view;
    show('wr-capture-view',view==='capture');show('wr-list-view',view==='list');show('wr-detail-view',view==='detail');
    show('wr-new',view!=='capture');
    document.querySelectorAll('[data-work-view]').forEach((b)=>b.classList.toggle('active',b.dataset.workView===view||(view==='detail'&&b.dataset.workView==='list')));
    if(view==='capture') {
      saleDetail=null;
      const currentType=$('wr-type')?.value||'';
      document.querySelectorAll('[data-request-type]').forEach((b)=>b.classList.toggle('active',b.dataset.requestType===currentType));
      updateCaptureDashboard();
    } else {
      document.querySelectorAll('[data-request-type]').forEach((b)=>b.classList.remove('active'));
    }
  }
  function resetDraft() {
    $('wr-form')?.reset();
    if($('wr-customer')) $('wr-customer').value='';
    if($('wr-customer-search')) $('wr-customer-search').value='';
    if($('wr-production-destination')) $('wr-production-destination').value='';
    hideCustomerResults();
    const allowed=allowedRequestTypes();
    const defaultType=allowed.includes('transfer')?'transfer':(allowed[0]||'transfer');
    if($('wr-type')) $('wr-type').value=defaultType;
    $('wr-lines').innerHTML='';draftSelections.clear();addLine();setRequestType(defaultType);
    const d=new Date(Date.now()+3600000);d.setMinutes(d.getMinutes()-d.getTimezoneOffset());if($('wr-required'))$('wr-required').value=d.toISOString().slice(0,16);
    updateCaptureDashboard();
  }
  function detailStatusClass(status){return ['closed','accepted'].includes(status)?'text-bg-success':(status==='rejected'?'text-bg-danger':(['dispatched','dispatching','preparing'].includes(status)?'text-bg-primary':'text-bg-light'));}
  function switchDetailTab(tab){detailTab=tab;document.querySelectorAll('[data-detail-tab]').forEach((b)=>b.classList.toggle('active',b.dataset.detailTab===tab));document.querySelectorAll('[data-detail-panel]').forEach((p)=>p.classList.toggle('d-none',p.dataset.detailPanel!==tab));}

  if($('wr-source')) $('wr-source').innerHTML=opts(cat.warehouses,(w)=>w.company_name+' · '+w.site_name+' · '+w.name,'Selecciona quién te surtirá');

  if($('wr-scale')) {
    const scales=cat.scales||[];
    $('wr-scale').innerHTML=opts(scales,(row)=>(row.name||row.code)+(row.port_name?' · '+row.port_name:''),scales.length?'Selecciona báscula':'Sin báscula configurada en esta instalación');
    const preferred=scales.find((row)=>num(row.is_default)===1)||(scales.length===1?scales[0]:null);
    if(preferred) $('wr-scale').value=preferred.id;
  }
  if($('wr-chamber')) $('wr-chamber').innerHTML=opts(cat.chambers||[],chamberLabel,'Selecciona cámara');
  if($('wr-row')) { if(['outgoing','incoming'].includes(mode)) fillRows(0); else $('wr-row').innerHTML=opts(cat.rows||[],'path','Selecciona una fila'); }
  $('wr-stock-species').innerHTML=opts(cat.species,'name','Todas');
  $('wr-stock-size').innerHTML=opts(cat.sizes,'name','Todas');
  $('wr-stock-owner').innerHTML=opts(cat.owners,'trade_name','Todos');
  if($('wr-status')) $('wr-status').innerHTML=[
    '<option value="">Todos los estados</option>',
    '<option value="pending">Pendientes</option>',
    '<option value="accepted">Aceptadas</option>',
    '<option value="in_supply">En surtido</option>',
    '<option value="closed">Cerradas / despachadas</option>',
    '<option value="rejected">Rechazadas / canceladas</option>'
  ].join('');
  if(mode==='outgoing'&&$('wr-role')) { $('wr-role').value='provider'; $('wr-role').disabled=true; }
  addLine();
  function historyTypeClass(type) {
    return {transfer:'transfer',production:'production',sale:'sale'}[type]||'other';
  }
  function historyStatusClass(group) {
    return {pending:'pending',accepted:'accepted',in_supply:'supply',closed:'closed',rejected:'rejected'}[group]||'pending';
  }
  function historyDate(value) {
    const raw=String(value||'');
    if(!raw) return {date:'—',time:''};
    const parts=raw.replace('T',' ').split(' ');
    const date=parts[0]||'', time=(parts[1]||'').slice(0,5);
    const d=date.split('-');
    return {date:d.length===3?(d[2]+'/'+d[1]+'/'+d[0]):date,time};
  }
  function historyPct(value) {
    const n=Math.max(0,num(value));
    return (Math.round(n*10)/10).toLocaleString('es-MX',{maximumFractionDigits:1})+'%';
  }
  function renderHistorySummary(summary) {
    const total=num(summary?.total);
    const set=(id,value)=>{if($(id))$(id).textContent=String(num(value));};
    const pct=(id,value)=>{if($(id))$(id).textContent=(total?Math.round((num(value)/total)*100):0)+'% del total';};
    set('wr-history-total',total);
    set('wr-history-pending',summary?.pending); pct('wr-history-pending-pct',summary?.pending);
    set('wr-history-accepted',summary?.accepted); pct('wr-history-accepted-pct',summary?.accepted);
    set('wr-history-supply',summary?.in_supply); pct('wr-history-supply-pct',summary?.in_supply);
    set('wr-history-closed',summary?.closed); pct('wr-history-closed-pct',summary?.closed);
  }
  function renderHistoryPagination(pagination) {
    const host=$('wr-history-pagination'); if(!host)return;
    const page=num(pagination?.page)||1, pages=Math.max(1,num(pagination?.pages)||1);
    const items=[];
    const push=(label,target,disabled=false,active=false)=>items.push(
      '<button type="button" class="wr-history-page-btn'+(active?' active':'')+'" data-history-page="'+target+'" '+(disabled?'disabled':'')+'>'+label+'</button>'
    );
    push('&laquo;',1,page<=1);
    push('Anterior',Math.max(1,page-1),page<=1);
    const start=Math.max(1,page-1), end=Math.min(pages,page+1);
    for(let p=start;p<=end;p++) push(String(p),p,false,p===page);
    push('Siguiente',Math.min(pages,page+1),page>=pages);
    push('&raquo;',pages,page>=pages);
    host.innerHTML=items.join('');
  }
  async function listing(page=historyPage) {
    if(!$('wr-list')||!$('wr-status')||!$('wr-role')||!$('wr-search')) return;
    historyPage=Math.max(1,num(page)||1);
    const result=await historyApi({
      page:historyPage,
      per_page:$('wr-history-per-page')?.value||10,
      status:$('wr-status').value,
      role:$('wr-role').value,
      type:$('wr-history-type')?.value||'',
      date_from:$('wr-history-from')?.value||'',
      date_to:$('wr-history-to')?.value||'',
      search:$('wr-search').value.trim()
    });
    historyPage=num(result.pagination?.page)||1;
    renderHistorySummary(result.summary||{});
    renderHistoryPagination(result.pagination||{});
    if($('wr-history-count')) $('wr-history-count').textContent='Mostrando '+num(result.pagination?.total)+' solicitud'+(num(result.pagination?.total)===1?'':'es');

    $('wr-list').innerHTML=result.rows.length?result.rows.map((r)=>{
      const dt=historyDate(r.required_at);
      const typeCls=historyTypeClass(r.request_type);
      const statusCls=historyStatusClass(r.status_group);
      const coverage=Math.max(0,num(r.coverage_pct));
      const bar=Math.min(100,coverage);
      const unitText=num(r.unit_count)>0
        ? num(r.unit_count)+''
        : (num(r.proposed_units)>0 ? num(r.proposed_units)+' prop.' : '0');
      const openAction=r.source==='sale'?'open-sale':'open';
      return '<tr>'+
        '<td><strong class="wr-history-folio">'+esc(r.folio)+'</strong><div class="wr-history-type-line"><span class="wr-history-type '+typeCls+'">'+esc(r.type_label)+'</span><span>'+esc(r.concept)+'</span></div></td>'+
        '<td><div class="wr-history-route"><strong>'+esc(r.source_name)+'</strong><span><i class="bi bi-arrow-right"></i> '+esc(r.destination_name)+'</span></div></td>'+
        '<td><div class="wr-history-date"><strong>'+esc(dt.date)+'</strong><span>'+esc(dt.time)+'</span></div></td>'+
        '<td class="text-end"><strong class="wr-history-weight">'+kg(r.requested_kg)+' kg</strong><small>'+num(r.line_count)+' línea'+(num(r.line_count)===1?'':'s')+'</small></td>'+
        '<td><div class="wr-history-coverage"><div><span style="width:'+bar+'%"></span></div><strong>'+historyPct(coverage)+'</strong><small>'+kg(r.covered_kg)+' / '+kg(r.requested_kg)+' kg</small></div></td>'+
        '<td class="text-center"><span class="wr-history-units">'+esc(unitText)+'</span></td>'+
        '<td><span class="wr-history-status '+statusCls+'"><i class="bi bi-circle-fill"></i>'+esc(r.status_label)+'</span></td>'+
        '<td class="text-end">'+button(openAction,'Abrir',r.id,'outline-primary')+'</td>'+
      '</tr>';
    }).join(''):'<tr><td colspan="8" class="text-secondary py-5 text-center"><i class="bi bi-inbox fs-4 d-block mb-2"></i>No hay solicitudes con estos filtros.</td></tr>';
  }
  function summary(lineId=null) {
    const rows=detail.summary.filter((r)=>lineId===null||num(r.request_line_id)===num(lineId));
    const n=(state)=>rows.filter((r)=>r.status===state).reduce((sum,r)=>sum+num(r.units),0);
    const sent=rows.filter((r)=>['shipped','accepted','returning','returned'].includes(r.status));
    return {sent:sent.reduce((a,r)=>a+num(r.units),0),kg:sent.reduce((a,r)=>a+num(r.kg),0),accepted:n('accepted'),returned:n('returned'),returning:n('returning'),pending:n('shipped'),selected:n('selected')};
  }
  function hasPendingRequested() {
    if(!detail) return false;
    return detail.lines.some((l)=>{
      const s=summary(l.id), requestedUnits=l.requested_unit_count===null?null:num(l.requested_unit_count);
      return requestedUnits!==null ? s.sent<requestedUnits : s.kg+0.0005<num(l.requested_weight_kg);
    });
  }
  function receptionState() {
    if(!detail) return {label:'Sin datos',done:false};
    const r=detail.request,s=summary();
    if(s.sent===0) return {label:'Sin envíos',done:false};
    if(s.pending>0) return {label:'Recepción en proceso · '+s.pending+' pendiente'+(s.pending===1?'':'s'),done:false};
    if(s.returning>0) return {label:'Recepción finalizada · '+s.returning+' devolución'+(s.returning===1?'':'es')+' pendiente'+(s.returning===1?'':'s'),done:false};
    if(r.status==='closed') return {label:'Recepción finalizada',done:true};
    if(!r.supply_finalized_at) return {label:'Recepción al día · despacho abierto',done:false};
    return {label:'Recepción resuelta · cerrando',done:false};
  }
  function requesterProposals(lineId=null) {
    const rows=Array.isArray(detail?.requester_proposals)?detail.requester_proposals:[];
    return lineId===null?rows:rows.filter((row)=>num(row.request_line_id)===num(lineId));
  }
  function requesterProposal(stockId,lineId=null) {
    return requesterProposals(lineId).find((row)=>num(row.stock_unit_id)===num(stockId))||null;
  }
  function outgoingStatus(status) {
    return {selected:'Pendiente',shipped:'Registrada',accepted:'Recibida',returning:'Devolución',returned:'Reingresada',released:'Liberada'}[status]||status;
  }
  function renderOutgoingRequest() {
    if(mode!=='outgoing'||!detail) return;
    const r=detail.request,total=summary(),line=lookup?.matching_lines?.[0]||detail.lines.find((item)=>num(item.id)===num(lookup?.unit?.request_line_id))||null;
    const requested=detail.lines.reduce((sum,item)=>sum+num(item.requested_weight_kg),0);
    const pct=requested>0?Math.min(100,Math.round((total.kg/requested)*100)):0;
    const tracked=total.sent+total.selected;
    const progressLabel=tracked>0?(total.sent+' de '+tracked+' tinas registradas'):(total.sent+' tinas registradas');
    if($('wr-manifest-count')) $('wr-manifest-count').textContent=tracked>0?(total.sent+' de '+tracked):(total.sent+' tinas');
    if($('wr-outgoing-request')) {
      $('wr-outgoing-request').className='wr-outgoing-request-content';
      $('wr-outgoing-request').innerHTML=
        '<div class="wr-request-titlebar"><span class="wr-request-ok"><i class="bi bi-check-circle-fill"></i> Solicitud detectada</span><strong>'+esc(progressLabel)+'</strong></div>'+
        '<div class="wr-request-detected-head"><div class="wr-request-folio-line"><strong>'+esc(r.folio)+'</strong><span class="badge '+detailStatusClass(r.status)+'">'+esc(states[r.status]||r.status)+'</span></div><div class="wr-request-progress"><div><span style="width:'+pct+'%"></span></div><small>'+pct+'%</small></div></div>'+
        '<div class="wr-request-detected-grid"><div><span>Tipo</span><strong>'+esc(concept(r))+'</strong><span>Origen</span><strong>'+esc(detail.source.name)+'</strong><span>'+(r.request_type==='production'?'Área producción':'Destino')+'</span><strong>'+esc(r.request_type==='production'?(productionDestinationFromDetail()||'—'):detail.destination.name)+'</strong></div><div><span>Producto actual</span><strong>'+esc(line?(line.species_name+' · '+line.size_name):'—')+'</strong><span>Lote</span><strong>'+esc(line?.lot_code||'Cualquier lote')+'</strong><span>Propietario</span><strong>'+esc(line?.owner_name||'Cualquier propietario')+'</strong></div></div>';
    }
    stationContext(r.folio+' · '+concept(r)+(r.request_type==='production'&&productionDestinationFromDetail()?' · Área: '+productionDestinationFromDetail():'')+' · '+detail.source.name+' → '+detail.destination.name);

    const rows=[...(detail.units||[])];
    const currentStock=num(lookup?.stock?.id);
    const known=rows.some((u)=>num(u.stock_unit_id)===currentStock);
    if(lookup&&currentStock&&!known) rows.unshift({stock_unit_id:currentStock,tub_code:lookup.stock.tub_code,unit_code:lookup.stock.unit_code,status:'current',outbound_weight_kg:null});
    const manifestHead='<div class="wr-manifest-table-head" aria-hidden="true"><span>#</span><span>Código de tina</span><span>Peso (kg)</span><span>Estatus</span></div>';
    let body=manifestHead+(rows.length?rows.slice(0,24).map((u,index)=>{
      const current=num(u.stock_unit_id)===currentStock;
      const state=u.status==='current'?'Actual':outgoingStatus(u.status);
      const cls=u.status==='shipped'||u.status==='accepted'||u.status==='returned'?'done':(current?'current':'pending');
      const weight=u.outbound_weight_kg??u.shipped_weight_kg;
      const cancelAction=u.status==='shipped'
        ?'<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" data-action="cancel-shipment" data-id="'+num(u.id)+'" data-code="'+esc(u.tub_code||u.unit_code)+'" aria-label="Cancelar salida" title="Cancelar salida"><i class="bi bi-three-dots-vertical"></i></button>'
        :'';
      return '<div class="wr-manifest-row '+(current?'is-current':'')+'"><span class="wr-manifest-num">'+(index+1)+'</span><div><strong>'+esc(u.tub_code||u.unit_code)+'</strong><small>'+esc(u.unit_code||'')+'</small></div><span class="wr-manifest-weight">'+(weight==null?'—':kg(weight))+'</span><span class="d-flex align-items-center justify-content-end gap-1"><span class="wr-mini-state '+cls+'">'+esc(state)+'</span>'+cancelAction+'</span></div>';
    }).join(''):'<div class="wr-manifest-empty"><i class="bi bi-clipboard"></i><span>Aún no hay tinas registradas en este folio.</span></div>');
    const canFinalize=provider()&&openSupply()&&cfg.canOperate&&total.sent>0;
    const manifest=$('wr-outgoing-manifest');
    if(manifest) {
      const manifestCard=manifest.closest('.wr-outgoing-manifest-card');
      manifest.innerHTML=body;

      let footer=document.getElementById('wr-transfer-finalize-footer');
      if(canFinalize&&manifestCard) {
        if(!footer) {
          footer=document.createElement('div');
          footer.id='wr-transfer-finalize-footer';
          footer.setAttribute('aria-label','Finalizar despacho');
        }
        if(footer.parentElement!==manifestCard) manifestCard.appendChild(footer);
        footer.innerHTML='<button type="button" class="btn btn-primary wr-finalize-button" data-action="finalize"><i class="bi bi-check2-circle me-1"></i>Finalizar despacho</button>';
      } else if(footer) {
        footer.remove();
      }

      if(!document.getElementById('wr-transfer-finalize-footer-style')) {
        const style=document.createElement('style');
        style.id='wr-transfer-finalize-footer-style';
        style.textContent=`
          #wr-transfer-finalize-footer{
            flex:0 0 auto;
            padding:12px 14px;
            border-top:1px solid #e0e8f0;
            background:#fff;
          }
          #wr-transfer-finalize-footer .wr-finalize-button{
            width:100%;
            min-height:48px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:9px;
            font-weight:850;
            white-space:nowrap;
          }
        `;
        document.head.appendChild(style);
      }
    }
  }
  function incomingStatus(status) {
    return {selected:'Propuesta',shipped:'Pendiente',accepted:'Recibida',returning:'Devolución',returned:'Reingresada',released:'Liberada'}[status]||status;
  }
  function renderIncomingRequest() {
    if(mode!=='incoming'||!detail) return;
    const r=detail.request,total=summary(),line=detail.lines.find((item)=>num(item.id)===num(lookup?.unit?.request_line_id))||null;
    const tracked=total.sent;
    const processed=total.accepted+total.returning+total.returned;
    const pct=tracked>0?Math.min(100,Math.round((processed/tracked)*100)):0;
    const progressLabel=tracked>0?(processed+' de '+tracked+' tinas procesadas'):(processed+' tinas procesadas');
    if($('wr-manifest-count')) $('wr-manifest-count').textContent=tracked>0?(processed+' de '+tracked):(processed+' tinas');
    if($('wr-incoming-request')) {
      $('wr-incoming-request').className='wr-outgoing-request-content';
      $('wr-incoming-request').innerHTML=
        '<div class="wr-request-titlebar"><span class="wr-request-ok"><i class="bi bi-check-circle-fill"></i> Solicitud detectada</span><strong>'+esc(progressLabel)+'</strong></div>'+
        '<div class="wr-request-detected-head"><div class="wr-request-folio-line"><strong>'+esc(r.folio)+'</strong><span class="badge '+detailStatusClass(r.status)+'">'+esc(states[r.status]||r.status)+'</span></div><div class="wr-request-progress"><div><span style="width:'+pct+'%"></span></div><small>'+pct+'% de tinas procesadas</small></div></div>'+
        '<div class="wr-request-detected-grid"><div><span>Tipo</span><strong>'+esc(concept(r))+'</strong><span>Origen</span><strong>'+esc(detail.source.name)+'</strong><span>'+(r.request_type==='production'?'Área producción':'Destino')+'</span><strong>'+esc(r.request_type==='production'?(productionDestinationFromDetail()||'—'):detail.destination.name)+'</strong></div><div><span>Producto actual</span><strong>'+esc(line?(line.species_name+' · '+line.size_name):'—')+'</strong><span>Lote</span><strong>'+esc(line?.lot_code||'Cualquier lote')+'</strong><span>Propietario</span><strong>'+esc(line?.owner_name||'Cualquier propietario')+'</strong></div></div>';
    }
    stationContext(r.folio+' · '+concept(r)+(r.request_type==='production'&&productionDestinationFromDetail()?' · Área: '+productionDestinationFromDetail():'')+' · '+detail.source.name+' → '+detail.destination.name);

    const rows=[...(detail.units||[])];
    const currentStock=num(lookup?.stock?.id);
    const manifestHead='<div class="wr-manifest-table-head" aria-hidden="true"><span>#</span><span>Código de tina</span><span>Peso (kg)</span><span>Estatus</span></div>';
    let body=manifestHead+(rows.length?rows.slice(0,24).map((u,index)=>{
      const current=num(u.stock_unit_id)===currentStock;
      const state=incomingStatus(u.status);
      const cls=['accepted','returned'].includes(u.status)?'done':(current?'current':'pending');
      const weight=u.inbound_weight_kg??u.shipped_weight_kg??u.outbound_weight_kg;
      const cancelReceipt=u.status==='accepted'&&!provider()&&cfg.canOperate
        ?'<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" data-action="cancel-receipt" data-id="'+num(u.id)+'" data-code="'+esc(u.tub_code||u.unit_code)+'" aria-label="Anular recepción" title="Anular recepción"><i class="bi bi-three-dots-vertical"></i></button>'
        :'';
      return '<div class="wr-manifest-row '+(current?'is-current':'')+'"><span class="wr-manifest-num">'+(index+1)+'</span><div><strong>'+esc(u.tub_code||u.unit_code)+'</strong><small>'+esc(u.unit_code||'')+'</small></div><span class="wr-manifest-weight">'+(weight==null?'—':kg(weight))+'</span><span class="d-flex align-items-center justify-content-end gap-1"><span class="wr-mini-state '+cls+'">'+esc(state)+'</span>'+cancelReceipt+'</span></div>';
    }).join(''):'<div class="wr-manifest-empty"><i class="bi bi-clipboard"></i><span>Aún no hay tinas despachadas en este folio.</span></div>');
    const reception=receptionState();
    if(tracked>0) body+='<div class="wr-manifest-footer"><div class="small '+(reception.done?'text-success':'text-secondary')+'"><i class="bi '+(reception.done?'bi-check-circle-fill':'bi-arrow-repeat')+' me-1"></i>'+esc(reception.label)+'</div></div>';
    if($('wr-incoming-manifest')) $('wr-incoming-manifest').innerHTML=body;
  }
  function renderDetail() {
    if(!detail) return;
    if(mode==='outgoing') { renderOutgoingRequest(); return; }
    const r=detail.request, p=provider(), open=openSupply(), total=summary();
    let actions=button('reload','<i class="bi bi-arrow-clockwise me-1"></i>Actualizar',r.id,'outline-secondary');
    actions+=' <a class="btn btn-sm btn-outline-danger" href="/solicitudes/almacenes/'+num(r.id)+'/reporte.pdf"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>';
    actions+=' <a class="btn btn-sm btn-outline-success" href="/solicitudes/almacenes/'+num(r.id)+'/reporte.xlsx"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>';
    if(p && cfg.canAccept && r.status==='open') actions+=' '+button('accept','Aceptar solicitud','','success');
    if(p && cfg.canAccept && ['open','accepted','preparing'].includes(r.status)) actions+=' '+button('reject','Rechazar','','outline-danger');
    if(p && cfg.canOperate && open) actions+=' <a class="btn btn-sm btn-primary" href="/solicitudes/almacenes/salidas?request='+num(r.id)+'"><i class="bi bi-box-arrow-right me-1"></i>Ir a Salidas</a>';
    if(p && cfg.canOperate && open && total.sent>0) actions+=' '+button('finalize','Finalizar despacho','','primary');
    if(p && cfg.canOperate && mode==='requests' && r.supply_finalized_at && ['dispatched','closed'].includes(r.status) && hasPendingRequested()) actions+=' '+button('reopen-dispatch','Reabrir despacho','','warning');
    const productionState=productionIssueState();
    if(!p && cfg.canOperate && mode==='requests' && r.request_type==='production' && productionState.pending.length)
      actions+=' '+button('retry-production','Reintentar salida a Producción','','warning');
    const stats=[['Despachadas',total.sent],['Recibidas',total.accepted],['Por recibir',total.pending],['Devoluciones pendientes',total.returning],['Reingresadas',total.returned]];

    if(mode!=='requests'){
      let stationActions=button('reload','<i class="bi bi-arrow-clockwise me-1"></i>Actualizar',r.id,'outline-secondary');
      if(mode==='outgoing'&&p&&cfg.canOperate&&open&&total.sent>0) stationActions+=' '+button('finalize','Finalizar despacho','','primary');
      const stationStats=mode==='outgoing'
        ? [['Despachadas',total.sent],['Propuestas',total.selected],['En tránsito',total.pending]]
        : [['En tránsito',total.pending],['Recibidas',total.accepted],['Devoluciones',total.returning],['Reingresadas',total.returned]];
      $('wr-detail').innerHTML='<section class="wr-station-request-card"><div class="wr-station-request-main"><div><span class="wr-station-request-label">Solicitud activa</span><div class="d-flex align-items-center gap-2 flex-wrap"><strong class="wr-station-request-folio">'+esc(r.folio)+'</strong><span class="badge '+detailStatusClass(r.status)+'">'+esc(states[r.status]||r.status)+'</span></div><div class="wr-station-request-route">'+esc(detail.source.name)+' <i class="bi bi-arrow-right"></i> '+esc(detail.destination.name)+' · '+esc(concept(r))+'</div></div><div class="wr-detail-actions">'+stationActions+'</div></div><div class="wr-station-request-metrics">'+stationStats.map(([l,n])=>'<div><span>'+esc(l)+'</span><strong>'+num(n)+'</strong></div>').join('')+'</div></section>';
      show('wr-detail');
      if(mode==='outgoing'){
        show('wr-station',p&&open&&cfg.canOperate);
        $('wr-station-title').textContent='Salida · '+r.folio+' → '+detail.destination.name;
        stationContext(r.folio+' · '+concept(r)+(r.request_type==='production'&&productionDestinationFromDetail()?' · Área: '+productionDestinationFromDetail():'')+' · '+detail.source.name+' → '+detail.destination.name);
        stationState(p&&open&&cfg.canOperate?'Listo para escanear':'Solicitud no disponible',p&&open&&cfg.canOperate?'online':'warning');
      }
      return;
    }

    let html='<section class="wr-card"><div class="wr-detail-hero"><div><button type="button" class="btn btn-sm btn-link ps-0 text-decoration-none" data-action="back-list"><i class="bi bi-arrow-left me-1"></i>Solicitudes registradas</button><div class="d-flex gap-2 align-items-center flex-wrap"><h2>'+esc(r.folio)+'</h2><span class="badge '+detailStatusClass(r.status)+'">'+esc(states[r.status]||r.status)+'</span></div><div class="wr-detail-sub">'+esc(detail.source.name)+' <i class="bi bi-arrow-right"></i> '+esc(detail.destination.name)+' · '+esc(concept(r))+(r.request_type==='production'&&productionDestinationFromDetail()?' · Área: '+esc(productionDestinationFromDetail()):'')+'</div></div><div class="wr-detail-actions">'+actions+'</div></div>';
    html+='<div class="wr-detail-tabs"><button type="button" class="wr-detail-tab active" data-detail-tab="summary">Resumen</button><button type="button" class="wr-detail-tab" data-detail-tab="lines">Líneas</button><button type="button" class="wr-detail-tab" data-detail-tab="units">Tinas</button>'+(r.request_type==='production'?'<button type="button" class="wr-detail-tab" data-detail-tab="production">Salida a Producción</button>':'')+'<button type="button" class="wr-detail-tab" data-detail-tab="activity">Actividad</button></div>';
    const reception=receptionState();
    html+='<div class="wr-detail-panel" data-detail-panel="summary"><div class="wr-detail-metrics">'+stats.map(([l,n])=>'<div class="wr-detail-metric"><span>'+esc(l)+'</span><strong>'+num(n)+'</strong></div>').join('')+'</div><div class="wr-steps"><span class="done">Solicitud</span><span class="'+(r.accepted_at?'done':'')+'">Aceptación</span><span class="'+(total.sent?'done':'')+'">Salidas y entradas</span><span class="'+(r.supply_finalized_at?'done':'')+'">Despacho finalizado</span><span class="'+(reception.done?'done':'')+'">'+esc(reception.label)+'</span></div>'+(r.request_type==='production'&&productionState.pending.length?'<div class="alert alert-warning mt-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i><strong>Salida a Producción pendiente.</strong><div class="small mt-1">'+productionState.pending.length+' tina'+(productionState.pending.length===1?'':'s')+' fue recibida correctamente, pero falta completar su salida terminal a '+esc(productionDestinationFromDetail()||'Producción')+'. La recepción no se revirtió.</div></div>':'')+'<div class="mt-3 p-3 bg-light rounded"><strong>Observaciones</strong><div class="text-secondary mt-1">'+esc(r.observations||'Sin observaciones')+'</div></div>'+(r.rejection_reason?'<div class="alert alert-warning mt-3">'+esc(r.rejection_reason)+'</div>':'')+'</div>';
    html+='<div class="wr-detail-panel d-none" data-detail-panel="lines"><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Producto / filtros</th><th>Solicitado</th><th>Despachado</th><th>Diferencia kg</th><th>Observación / selección</th></tr></thead><tbody>';
    html+=detail.lines.map((l)=>{const s=summary(l.id),suggested=requesterProposals(l.id);return '<tr><td><strong>'+num(l.line_number)+'. '+esc(l.species_name)+' · '+esc(l.size_name)+'</strong><div class="small text-secondary">Lote: '+esc(l.lot_code||'cualquiera')+' · Propietario: '+esc(l.owner_name||'cualquiera')+'</div></td><td>'+kg(l.requested_weight_kg)+' kg'+(l.requested_unit_count?' · '+num(l.requested_unit_count)+' tinas':'')+'</td><td>'+kg(s.kg)+' kg · '+s.sent+' tinas</td><td>'+kg(s.kg-num(l.requested_weight_kg))+'</td><td>'+esc(l.supply_observation||'')+(suggested.length?'<div class="wr-requester-line-count"><i class="bi bi-person-check"></i> '+suggested.length+' tina'+(suggested.length===1?' sugerida':'s sugeridas')+' por solicitante</div>':'')+(p&&open&&cfg.canSelect&&mode==='requests'?'<div class="d-flex gap-1 mt-1">'+button('fifo','Sugerir FIFO',l.id)+button('prepare-stock','Elegir tinas',l.id)+'</div>':'')+'</td></tr>';}).join('')+'</tbody></table></div>';

    const requesterRows=requesterProposals();
    html+='<div class="wr-requester-proposal-box mt-3"><div class="wr-requester-proposal-head"><div><h3 class="h6 mb-1">Tinas sugeridas por el solicitante</h3><p class="small text-secondary mb-0">Son una referencia. No reservan inventario y el almacén puede conservarlas, sustituirlas o elegir otras.</p></div><span class="wr-requester-proposal-count">'+requesterRows.length+' tina'+(requesterRows.length===1?'':'s')+'</span></div>';
    if(requesterRows.length){
      const selectedIds=new Set(detail.selected_units.map((u)=>num(u.stock_unit_id)));
      html+='<div class="table-responsive mt-2"><table class="table table-sm align-middle mb-0"><thead><tr><th>Tina</th><th>Línea</th><th>Lote</th><th>Propietario</th><th>Ubicación</th><th class="text-end">Kg sugeridos</th><th>Situación</th></tr></thead><tbody>'+requesterRows.map((u)=>{const chosen=selectedIds.has(num(u.stock_unit_id));const state=chosen?'<span class="wr-requester-state chosen">Elegida para surtido</span>':(u.available_now?'<span class="wr-requester-state available">Disponible para elegir</span>':'<span class="wr-requester-state unavailable">Ya no disponible</span>');return '<tr><td><strong>'+esc(u.tub_code||u.unit_code||('#'+u.stock_unit_id))+'</strong><div class="small text-secondary">'+esc(u.unit_code||'')+'</div></td><td>'+num(u.line_number||detail.lines.find((l)=>num(l.id)===num(u.request_line_id))?.line_number)+'</td><td>'+esc(u.lot_code||'—')+'</td><td>'+esc(u.owner_name||'—')+'</td><td>'+esc(u.location_path||'—')+'</td><td class="text-end">'+kg(u.proposed_weight_kg)+'</td><td>'+state+'</td></tr>';}).join('')+'</tbody></table></div>';
    } else {
      html+='<div class="wr-requester-proposal-empty"><i class="bi bi-info-circle"></i><span>El solicitante no indicó tinas específicas. El almacén define libremente el surtido.</span></div>';
    }
    html+='</div>';

    if(p && cfg.canSelect && open && mode==='requests') html+='<div class="wr-supply-selection-box mt-3"><h3 class="h6 mb-1">Selección para surtido</h3><p class="small text-secondary">'+detail.selected_units.length+' tinas comprometidas temporalmente por el almacén; la salida física se registra después por QR.</p><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Tina</th><th>Línea</th><th>Ubicación</th><th>Kg netos</th><th></th></tr></thead><tbody>'+detail.selected_units.map((u)=>'<tr><td>'+esc(u.tub_code)+' · '+esc(u.unit_code)+(requesterProposal(u.stock_unit_id,u.request_line_id)?'<div><span class="wr-requester-suggested-badge"><i class="bi bi-person-check"></i> Coincide con sugerencia</span></div>':'')+'</td><td>'+num(detail.lines.find((l)=>num(l.id)===num(u.request_line_id))?.line_number)+'</td><td>'+esc(u.location_path)+'</td><td>'+kg(u.preselected_weight_kg)+'</td><td>'+button('release','Retirar',u.stock_unit_id,'outline-danger')+'</td></tr>').join('')+'</tbody></table></div></div>';
    html+='</div>';
    html+='<div class="wr-detail-panel d-none" data-detail-panel="units"><div class="d-flex justify-content-between align-items-center gap-2 mb-3"><div><strong>Tinas de la solicitud</strong><div class="small text-secondary">Seguimiento por estado operativo.</div></div><select id="wr-unit-status" class="form-select form-select-sm w-auto" aria-label="Estado de tinas"><option value="">Todos los estados</option>'+Object.entries(unitStates).map(([v,l])=>'<option value="'+v+'">'+l+'</option>').join('')+'</select></div><div class="table-responsive"><table class="table table-sm wr-manifest"><thead><tr><th>Tina / etiqueta</th><th>Estado</th><th>Salida kg netos</th><th>Entrada kg netos</th><th>Retorno kg netos</th></tr></thead><tbody id="wr-manifest"></tbody></table></div>'+button('more-units','Siguiente página','','outline-secondary')+'</div>';
    if(r.request_type==='production'){
      const prod=detail.production||{area:productionDestinationFromDetail(),state:'waiting',total_units:0,completed_units:0,pending_units:0,reversed_units:0,completed_weight_kg:0,units:[]};
      const prodLabel={waiting:'Pendiente de recepción',pending:'Salida pendiente',completed:'Completada',reversed:'Revertida'}[prod.state]||prod.state;
      const prodClass=prod.state==='completed'?'text-success':(prod.state==='pending'?'text-warning':'text-secondary');
      html+='<div class="wr-detail-panel d-none" data-detail-panel="production"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3"><div><strong>Salida a Producción</strong><div class="small text-secondary">Subdetalle de esta misma solicitud de Materia Prima.</div></div><span class="'+prodClass+'"><i class="bi bi-gear-wide-connected me-1"></i>'+esc(prodLabel)+'</span></div><div class="wr-detail-metrics"><div class="wr-detail-metric"><span>Área</span><strong>'+esc(prod.area||'—')+'</strong></div><div class="wr-detail-metric"><span>Tinas completadas</span><strong>'+num(prod.completed_units)+' / '+num(prod.total_units)+'</strong></div><div class="wr-detail-metric"><span>Peso enviado</span><strong>'+kg(prod.completed_weight_kg)+'</strong></div><div class="wr-detail-metric"><span>Pendientes</span><strong>'+num(prod.pending_units)+'</strong></div></div>'+(prod.last_completed_at?'<div class="small text-secondary mt-2">Último movimiento: '+esc(prod.last_completed_at)+' · Usuario '+num(prod.last_completed_by)+'</div>':'')+'<div class="table-responsive mt-3"><table class="table table-sm align-middle"><thead><tr><th>Tina</th><th>Recepción</th><th>Producción</th><th class="text-end">Kg</th><th>Fecha</th><th></th></tr></thead><tbody>'+(prod.units?.length?prod.units.map(u=>{const state={not_received:'Pendiente de recepción',pending:'Pendiente',completed:'Completada',reversed:'Revertida'}[u.production_state]||u.production_state;const canCancel=!provider()&&cfg.canOperate&&u.receipt_status==='accepted';return '<tr><td><strong>'+esc(u.tub_code||u.unit_code)+'</strong><div class="small text-secondary">'+esc(u.unit_code||'')+'</div></td><td>'+esc(unitStates[u.receipt_status]||u.receipt_status)+'</td><td>'+esc(state)+(u.production_folio?'<div class="small text-secondary">'+esc(u.production_folio)+'</div>':'')+'</td><td class="text-end">'+(u.production_weight_kg==null?'—':kg(u.production_weight_kg))+'</td><td>'+esc(u.production_at||u.production_reversed_at||u.received_at||'—')+'</td><td class="text-end">'+(canCancel?'<button type="button" class="btn btn-sm btn-outline-danger" data-action="cancel-receipt" data-id="'+num(u.unit_id)+'" data-code="'+esc(u.tub_code||u.unit_code)+'"><i class="bi bi-arrow-counterclockwise me-1"></i>Anular recepción</button>':'')+'</td></tr>';}).join(''):'<tr><td colspan="6" class="text-secondary text-center py-4">Todavía no hay tinas asociadas.</td></tr>')+'</tbody></table></div>'+(num(prod.pending_units)>0&&!provider()&&cfg.canOperate?'<div class="mt-3"><button type="button" class="btn btn-sm btn-warning" data-action="retry-production">Reintentar pendientes</button></div>':'')+'</div>';
    }
    html+='<div class="wr-detail-panel d-none" data-detail-panel="activity"><div class="wr-activity-list">'+detail.events.map((e)=>'<div class="wr-activity-item"><time>'+esc(e.created_at)+'</time><strong>'+esc(events[e.action]||e.action)+'</strong><span>Usuario '+num(e.actor_user_id)+'</span></div>').join('')+'</div></div></section>';
    $('wr-detail').innerHTML=html;show('wr-detail');if(mode==='requests')setWorkView('detail');switchDetailTab(detailTab='summary');renderManifest();
  }
  function renderManifest() {
    if(!$('wr-manifest')||!detail) return;
    $('wr-manifest').innerHTML=detail.units.length?detail.units.map((u)=>{const cancelReceipt=!provider()&&cfg.canOperate&&u.status==='accepted'?'<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2" data-action="cancel-receipt" data-id="'+num(u.id)+'" data-code="'+esc(u.tub_code||u.unit_code)+'" title="Anular recepción"><i class="bi bi-arrow-counterclockwise"></i></button>':'';return '<tr><td>'+esc(u.tub_code)+'<div class="small text-secondary">'+esc(u.unit_code)+'</div></td><td>'+esc(unitStates[u.status]||u.status)+cancelReceipt+'</td><td>'+(u.outbound_weight_kg===null?'—':kg(u.outbound_weight_kg))+'</td><td>'+(u.inbound_weight_kg===null?(num(u.return_without_weight)?'Sin pesaje':'—'):kg(u.inbound_weight_kg))+'</td><td>'+(u.returned_weight_kg===null?'—':kg(u.returned_weight_kg))+'</td></tr>';}).join(''):'<tr><td colspan="5" class="text-secondary text-center py-4">Sin tinas con este estado.</td></tr>';
    const more=$('wr-detail')?.querySelector('[data-action="more-units"]');if(more)more.classList.toggle('d-none',!unitAfter);
  }
  async function loadDetail(id) {
    detail=await api('/'+num(id)); unitAfter=detail.next_unit_after; resetUnit();
    if(mode==='outgoing') renderOutgoingRequest(); else if(mode==='incoming') renderIncomingRequest(); else renderDetail();
    if(mode==='outgoing') $('wr-qr')?.focus();
  }
  function resetDialogSubmit(label=null) {
    const submit=$('wr-dialog-submit');
    if(!submit) return;
    submit.disabled=false;
    submit.removeAttribute('disabled');
    submit.removeAttribute('aria-disabled');
    submit.classList.remove('disabled');
    if(label!==null) submit.textContent=label;
  }
  function dialog(title,body,onSubmit,label='Confirmar') {
    $('wr-dialog-title').textContent=title;
    $('wr-dialog-body').innerHTML=body;
    resetDialogSubmit(label);
    show('wr-dialog-message',false);
    dialogAction=onSubmit;
    $('wr-dialog').showModal();
    // Reafirma el estado después de abrir el <dialog>, por si otro manejador
    // global del formulario dejó el botón deshabilitado en una operación previa.
    requestAnimationFrame(()=>resetDialogSubmit(label));
  }
  function closeDialog() {
    if($('wr-dialog')?.open) $('wr-dialog').close();
    resetDialogSubmit();
    dialogAction=null;
  }
  function selectedInDraft(stockId) {
    return Boolean(stockTarget?.capture && stockTarget.tr && draftMap(stockTarget.tr).has(num(stockId)));
  }
  function updateStockSelectedSummary() {
    if(!stockTarget) return;
    const line=stockTarget.capture?currentLine(stockTarget.tr):detail.lines.find((l)=>num(l.id)===num(stockTarget.id));
    const selected=stockTarget.capture?draftSummary(stockTarget.tr):{units:detail.selected_units.filter((u)=>num(u.request_line_id)===num(stockTarget.id)).length,kg:detail.selected_units.filter((u)=>num(u.request_line_id)===num(stockTarget.id)).reduce((a,u)=>a+num(u.preselected_weight_kg),0)};
    const requested=num(line?.requested_weight_kg),diff=selected.kg-requested;
    if($('wr-stock-product'))$('wr-stock-product').textContent=(line?.species_name||$('wr-stock-species').selectedOptions[0]?.textContent||'')+' · '+(line?.size_name||$('wr-stock-size').selectedOptions[0]?.textContent||'');
    if($('wr-stock-required'))$('wr-stock-required').textContent=kg(requested)+' kg';if($('wr-stock-covered'))$('wr-stock-covered').textContent=kg(selected.kg)+' kg';if($('wr-stock-selected-count'))$('wr-stock-selected-count').textContent=String(selected.units);
    let coverage=requested>0?(Math.abs(diff)<.0005?'Cobertura exacta.':(diff>0?'Excede '+kg(diff)+' kg.':'Faltan '+kg(Math.abs(diff))+' kg.')):'Captura los kg requeridos para comparar cobertura.';
    const excluded=stockTarget.capture&&competingScopes(stockTarget.tr)?' Se excluyen tinas asignables a otra línea más específica.':'';
    $('wr-stock-selected').textContent='Seleccionadas: '+selected.units+' tinas · '+kg(selected.kg)+' kg. '+coverage+excluded;
    if($('wr-stock-footer-summary'))$('wr-stock-footer-summary').textContent=selected.units+' tinas seleccionadas · '+kg(selected.kg)+' kg';
    if(stockTarget.capture) updateCaptureDashboard();
  }
  function stockContextParams() {
    if(!stockTarget) return {};
    if(!stockTarget.capture) return {request_id:detail.request.id,line_id:stockTarget.id};
    return {scope_lot_id:stockTarget.fixed_lot_id||'',scope_owner_company_id:stockTarget.fixed_owner_id||'',competing_scopes:competingScopes(stockTarget.tr)};
  }
  function stockFilters(dt={start:0,length:25}) {
    return {
      warehouse_id:stockTarget.warehouse,
      species_id:stockTarget.species_id,
      size_grade_id:stockTarget.size_grade_id,
      ...stockContextParams(),
      filter_owner_company_id:$('wr-stock-owner').value,
      filter_lot_id:$('wr-stock-lot').value,
      search:$('wr-stock-search').value.trim(),
      start:dt.start||0,
      length:dt.length||25
    };
  }
  function setFacetOptions(el,rows,label,empty,selected,fallback='') {
    el.innerHTML=opts(rows,label,empty);
    if(selected && rows.some((r)=>num(r.id)===num(selected))) el.value=String(selected);
    else if(selected && fallback) { el.insertAdjacentHTML('beforeend','<option value="'+num(selected)+'">'+esc(fallback)+'</option>');el.value=String(selected); }
  }
  async function loadStockFacets(desiredLot=null,desiredOwner=null) {
    const lotId=desiredLot===null?num($('wr-stock-lot').value):num(desiredLot);
    const ownerId=desiredOwner===null?num($('wr-stock-owner').value):num(desiredOwner);
    const result=await api('/availability-facets'+query({warehouse_id:stockTarget.warehouse,species_id:stockTarget.species_id,size_grade_id:stockTarget.size_grade_id,...stockContextParams(),filter_lot_id:lotId||'',filter_owner_company_id:ownerId||''}));
    const line=stockTarget.capture?currentLine(stockTarget.tr):detail.lines.find((l)=>num(l.id)===num(stockTarget.id));
    const fixedLot=stockTarget.fixed_lot_id||null, fixedOwner=stockTarget.fixed_owner_id||null;
    setFacetOptions($('wr-stock-lot'),result.lots||[],lotOptionLabel,'Todos los lotes',fixedLot||lotId,fixedLot?(line.lot_code||'Lote seleccionado'):'');
    setFacetOptions($('wr-stock-owner'),result.owners||[],(r)=>r.trade_name+' · '+num(r.units)+' tinas · '+kg(r.kg)+' kg','Todos los propietarios',fixedOwner||ownerId,fixedOwner?(line.owner_name||'Propietario seleccionado'):'');
    $('wr-stock-lot').disabled=Boolean(fixedLot);
    $('wr-stock-owner').disabled=Boolean(fixedOwner);
  }
  function ensureStockTable() {
    if(stockTable) return stockTable;
    if(!globalThis.jQuery || !jQuery.fn.DataTable) throw new Error('DataTables no está disponible. Revisa los recursos del layout antes de consultar existencias.');
    stockTable=jQuery('#wr-stock-table').DataTable({
      processing:true,serverSide:true,searching:false,ordering:false,pageLength:25,lengthMenu:[[25,50,100],[25,50,100]],autoWidth:false,
      ajax:async (dt,callback)=>{try{const result=await api('/availability'+query(stockFilters(dt)));stockRows=result.rows||[];$('wr-stock-total').textContent=num(result.totals?.units)+' tinas disponibles · '+kg(result.totals?.kg)+' kg netos según filtros · FIFO/PEPS por antigüedad';updateStockSelectedSummary();show('wr-stock-message',false);callback({draw:dt.draw,recordsTotal:num(result.records_total),recordsFiltered:num(result.records_filtered),data:stockRows});}catch(e){stockRows=[];$('wr-stock-message').textContent=e.message||'No se pudieron consultar existencias.';show('wr-stock-message');callback({draw:dt.draw,recordsTotal:0,recordsFiltered:0,data:[]});}},
      columns:[
        {data:null,className:'text-center',orderable:false,searchable:false,render:(_,type,u)=>{if(type!=='display')return '';if(stockTarget?.capture){if(selectedInDraft(u.id))return '<button type="button" class="wr-stock-select is-selected" data-action="stock-pick" data-id="'+num(u.id)+'" aria-label="Quitar tina"><i class="bi bi-check-lg"></i></button>';const owner=draftSelectedOwner(u.id),allocation=draftAllocation(u,stockTarget.tr),blocked=(owner&&owner!==stockTarget.tr)||!allocation.allowed;return '<button type="button" class="wr-stock-select" data-action="stock-pick" data-id="'+num(u.id)+'" aria-label="Seleccionar tina" '+(blocked?'disabled':'')+'><i class="bi bi-check-lg"></i></button>';}return '<button type="button" class="wr-stock-select" data-action="stock-pick" data-id="'+num(u.id)+'" aria-label="Agregar propuesta"><i class="bi bi-plus-lg"></i></button>'; }},
        {data:null,render:(_,type,u)=>{if(type!=='display')return u.tub_code;const suggested=!stockTarget?.capture?requesterProposal(u.id,stockTarget?.id):null;return '<strong>'+esc(u.tub_code)+'</strong><div class="small text-secondary">'+esc(u.unit_code)+'</div>'+(suggested?'<span class="wr-requester-suggested-badge"><i class="bi bi-person-check"></i> Sugerida por solicitante</span>':'');}},
        {data:'lot_code',render:(v,type)=>type==='display'?esc(v||'—'):v},
        {data:'owner_name',render:(v,type)=>type==='display'?esc(v||'Sin propietario'):v},
        {data:'on_hand_weight_kg',className:'text-end fw-semibold',render:(v,type)=>type==='display'?kg(v):num(v)},
        {data:'received_at',render:(v,type)=>type==='display'?esc(v):v},
        {data:'location_path',render:(v,type)=>type==='display'?esc(v||'—'):v},
        {data:null,render:(_,type,u)=>{if(type!=='display')return '';if(stockTarget?.capture){if(selectedInDraft(u.id))return '<span class="wr-stock-status selected">Seleccionada</span>';const owner=draftSelectedOwner(u.id);if(owner&&owner!==stockTarget.tr)return '<span class="wr-stock-status blocked">Línea '+lineNumber(owner)+'</span>';const allocation=draftAllocation(u,stockTarget.tr);if(!allocation.allowed)return '<span class="wr-stock-status blocked">Otra línea</span>';}return '<span class="wr-stock-status available">Disponible</span>';}}
      ],
      language:{processing:'Consultando existencias…',lengthMenu:'Mostrar _MENU_ tinas',info:'Mostrando _START_ a _END_ de _TOTAL_ tinas',infoEmpty:'Sin tinas disponibles',zeroRecords:'No hay tinas disponibles con estos filtros.',paginate:{previous:'Anterior',next:'Siguiente'}}
    });
    return stockTable;
  }
  function reloadStock(reset=true) {
    ensureStockTable().ajax.reload(null,reset);
  }
  async function openStock(target) {
    const warehouse=target.capture?captureWarehouseId():num(detail.request.source_warehouse_id);
    if(!warehouse) throw new Error($('wr-type')?.value==='sale'?'No hay almacén operativo seleccionado.':'Selecciona primero el almacén proveedor.');
    const line=target.capture?currentLine(target.tr):detail.lines.find((l)=>num(l.id)===num(target.id));
    const speciesId=num(line.species_id), sizeId=num(line.size_grade_id);
    if(!speciesId || !sizeId) throw new Error('Selecciona primero Especie y Talla. Esos datos quedan fijos al consultar existencias.');
    if(target.capture) validateDraftStructure();

    stockTarget={...target,warehouse,species_id:speciesId,size_grade_id:sizeId,fixed_lot_id:num(line.lot_id)||null,fixed_owner_id:num(line.owner_company_id)||null};
    $('wr-stock-species').innerHTML=opts(cat.species,'name');$('wr-stock-species').value=speciesId;
    $('wr-stock-size').innerHTML=opts(sizes(speciesId),'name');$('wr-stock-size').value=sizeId;
    $('wr-stock-lot').innerHTML='<option value="">Todos los lotes</option>';
    $('wr-stock-owner').innerHTML='<option value="">Todos los propietarios</option>';
    $('wr-stock-search').value='';$('wr-fix-lot').checked=false;$('wr-fix-owner').checked=false;
    show('wr-stock-capture-options',false);show('wr-stock-message',false);
    $('wr-stock-title').textContent='Seleccionar tinas · Línea '+(target.capture?lineNumber(target.tr):num(line.line_number||0));
    if($('wr-stock-scope-note')) {
      const suggested=target.capture?[]:requesterProposals(line.id);
      $('wr-stock-scope-note').querySelector('span').textContent=suggested.length
        ? suggested.length+' tina'+(suggested.length===1?' fue sugerida':'s fueron sugeridas')+' por el solicitante. Son referencia únicamente; tú decides cuáles comprometer para surtido.'
        : 'Especie y talla permanecen fijas. Lote y propietario son sólo filtros de búsqueda; no modifican la línea.';
    }
    await loadStockFacets(stockTarget.fixed_lot_id,stockTarget.fixed_owner_id);
    $('wr-stock-dialog').showModal();updateStockSelectedSummary();reloadStock(true);
  }
  async function pickStock(id) {
    const u=stockRows.find((r)=>num(r.id)===num(id));
    if(!u) return;
    if(num(u.species_id)!==num(stockTarget.species_id) || num(u.size_grade_id)!==num(stockTarget.size_grade_id)) {
      throw new Error('La tina no coincide con la Especie/Talla de la línea. Actualiza la consulta.');
    }

    if(stockTarget.capture) {
      const tr=stockTarget.tr, selected=draftMap(tr), field=(n)=>tr.querySelector('[data-field="'+n+'"]'), stockId=num(u.id);
      if(selected.has(stockId)) {
        selected.delete(stockId);
      } else {
        const elsewhere=draftSelectedOwner(stockId);
        if(elsewhere&&elsewhere!==tr) throw new Error('Esta tina ya está seleccionada en la línea '+lineNumber(elsewhere)+'. Quítala de esa línea antes de reutilizarla.');
        const oldLot=field('lot_id').value, oldOwner=field('owner_company_id').value;
        const wantLot=$('wr-fix-lot').checked?num(u.lot_id):num(oldLot);
        const wantOwner=$('wr-fix-owner').checked?num(u.owner_company_id):num(oldOwner);
        for(const row of selected.values()) {
          if((wantLot&&num(row.lot_id)!==wantLot)||(wantOwner&&num(row.owner_company_id)!==wantOwner)) {
            throw new Error('Para fijar lote o propietario, primero quita de esta línea las tinas seleccionadas que no coinciden con el nuevo criterio.');
          }
        }
        if($('wr-fix-lot').checked) {
          if(![...field('lot_id').options].some((o)=>num(o.value)===num(u.lot_id))) field('lot_id').insertAdjacentHTML('beforeend','<option value="'+num(u.lot_id)+'">'+esc(u.lot_code)+'</option>');
          field('lot_id').value=String(u.lot_id);
        }
        if($('wr-fix-owner').checked) field('owner_company_id').value=String(u.owner_company_id||'');
        try { validateDraftStructure();validateDraftAllocations(); }
        catch(e) { field('lot_id').value=oldLot;field('owner_company_id').value=oldOwner;throw e; }
        stockTarget.fixed_lot_id=num(field('lot_id').value)||null;stockTarget.fixed_owner_id=num(field('owner_company_id').value)||null;
        const allocation=draftAllocation(u,tr);
        if(!allocation.allowed) {
          field('lot_id').value=oldLot;field('owner_company_id').value=oldOwner;
          stockTarget.fixed_lot_id=num(oldLot)||null;stockTarget.fixed_owner_id=num(oldOwner)||null;
          const lines=allocation.preferred.map((x)=>lineNumber(x.tr)).join('/');
          throw new Error('Esta tina corresponde a '+(lines?'la línea '+lines:'otro criterio')+'. Ajusta Lote/Propietario o usa otra tina.');
        }
        selected.set(stockId,u);
        await loadStockFacets(stockTarget.fixed_lot_id,stockTarget.fixed_owner_id);
      }
      updateLineCoverage(tr);updateStockSelectedSummary();reloadStock(false);return;
    }

    const selected=selections();
    selected.push({stock_unit_id:num(u.id),request_line_id:num(stockTarget.id)});
    await mutate('select',{selections:selected});
    reloadStock(false);
    message('Tina agregada a la selección para surtido. Quedó comprometida temporalmente; aún no se ha despachado.');
  }
  function resetUnit(clearRequest=false) {
    lookup=null; reading=null;
    if($('wr-qr')) $('wr-qr').value='';
    if($('wr-weight')) $('wr-weight').textContent=['outgoing','incoming'].includes(mode)?'—':'Sin lectura';
    if($('wr-weight-raw')) $('wr-weight-raw').textContent='Sin lectura';
    if($('wr-chamber')) $('wr-chamber').value='';
    if($('wr-row')) fillRows(0);
    show('wr-unit-form',false);
    show('wr-outgoing-register-empty',mode==='outgoing');
    show('wr-incoming-register-empty',mode==='incoming');
    if(mode==='outgoing') {
      if($('wr-unit-info')) $('wr-unit-info').innerHTML='<div class="wr-empty-unit"><i class="bi bi-qr-code"></i><strong>Escanea una tina</strong><span>Aquí aparecerán especie, talla, lote, propietario y ubicación registrada.</span></div>';
      if($('wr-unit-state')) { $('wr-unit-state').className='wr-mini-state neutral'; $('wr-unit-state').textContent='Sin lectura'; }
      stationState('Esperando tina','warning');
      if($('wr-operation-hint')) $('wr-operation-hint').textContent='Lee la tina para habilitar la operación.';
      if(clearRequest) clearOutgoingContext();
    } else if(mode==='incoming') {
      if($('wr-unit-info')) $('wr-unit-info').innerHTML='<div class="wr-empty-unit"><i class="bi bi-qr-code"></i><strong>Escanea una tina</strong><span>Aquí aparecerán producto, tara y pesos de referencia.</span></div>';
      if($('wr-unit-state')) { $('wr-unit-state').className='wr-mini-state neutral'; $('wr-unit-state').textContent='Sin lectura'; }
      if($('wr-test-mode')) { $('wr-test-mode').checked=false; syncTestWeightMode(false); }
      if($('wr-test-weight')) $('wr-test-weight').value='';
      stationState('Esperando tina','warning');
      if($('wr-operation-title')) $('wr-operation-title').innerHTML='<i class="bi bi-box-arrow-in-down me-1"></i> Registrar recepción';
      if($('wr-operation-hint')) $('wr-operation-hint').textContent='Lee la tina para habilitar la recepción.';
      if(clearRequest) clearIncomingContext();
    } else stationState('Esperando QR','warning');
  }
  function unitMode() {
    if(mode==='outgoing') return 'ship';
    if(lookup?.operation==='return_entry') return 'return_entry';
    return $('wr-decision')?.value||'accept';
  }
  function isProductionIncoming() {
    return mode==='incoming'
      && detail?.request?.request_type==='production'
      && lookup?.operation!=='return_entry';
  }
  function unitFields() {
    const op=unitMode();
    if(mode==='outgoing') {
      show('wr-weighing-section',true);
      show('wr-weight-box',true);
      if($('wr-unit-submit')) $('wr-unit-submit').innerHTML='<i class="bi bi-send-fill me-1"></i>Registrar salida';
      if($('wr-operation-hint')) $('wr-operation-hint').textContent='Selecciona la báscula y obtén una lectura estable antes de registrar.';
      return;
    }
    const returning=op==='return', withWeight=!returning||$('wr-weigh-return')?.checked;
    const needsPlacement=['accept','return_entry'].includes(op);
    const productionIncoming=isProductionIncoming();
    show('wr-return-options',returning);
    show('wr-checklist',op==='accept'&&!productionIncoming);
    show('wr-weighing-section',withWeight);
    show('wr-weight-box',withWeight);
    show('wr-placement-box',needsPlacement);
    if($('wr-chamber')) $('wr-chamber').required=needsPlacement;
    if($('wr-row')) $('wr-row').required=needsPlacement;
    $('wr-identity')?.toggleAttribute('required',op==='accept'&&!productionIncoming);
    $('wr-condition')?.toggleAttribute('required',op==='accept'&&!productionIncoming);
    $('wr-return-reason')?.toggleAttribute('required',returning);
    document.querySelectorAll('[data-receive-decision]').forEach((button)=>{
      if(productionIncoming&&button.dataset.productionToggle==='1') {
        const backToReceive=returning;
        button.dataset.receiveDecision=backToReceive?'accept':'return';
        button.classList.toggle('btn-outline-secondary',backToReceive);
        button.classList.toggle('btn-outline-warning',!backToReceive);
        button.innerHTML=backToReceive
          ?'<i class="bi bi-arrow-left me-1"></i>Volver a recepción'
          :'<i class="bi bi-arrow-return-left me-1"></i>Registrar devolución';
        button.setAttribute('aria-pressed',returning?'true':'false');
        return;
      }
      const active=button.dataset.receiveDecision===op;
      button.classList.toggle('active',active);
      button.setAttribute('aria-pressed',active?'true':'false');
    });
    if(returning&&!withWeight) {
      reading=null;
      if($('wr-weight')) $('wr-weight').textContent='Sin pesaje';
      if($('wr-weight-raw')) $('wr-weight-raw').textContent='Devolución sin pesaje.';
    }
    if($('wr-operation-title')) $('wr-operation-title').innerHTML={
      accept:'<i class="bi bi-box-arrow-in-down me-1"></i> Registrar recepción',
      return:'<i class="bi bi-arrow-return-left me-1"></i> Registrar devolución',
      return_entry:'<i class="bi bi-arrow-return-left me-1"></i> Confirmar reingreso'
    }[op];
    if($('wr-unit-submit')) $('wr-unit-submit').innerHTML={
      accept:'<i class="bi bi-check2-circle me-1"></i>Registrar recepción',
      return:'<i class="bi bi-arrow-return-left me-1"></i>Registrar devolución',
      return_entry:'<i class="bi bi-box-arrow-in-down me-1"></i>Confirmar reingreso'
    }[op];
    if($('wr-operation-hint')) $('wr-operation-hint').textContent=(
      productionIncoming&&op==='accept'
        ?'Asigna Cámara/Fila y confirma el peso. Registrar recepción confirma el ingreso.'
        :{
          accept:'Verifica la tina, asigna Cámara/Fila y confirma el peso.',
          return:'Indica el motivo de devolución. El pesaje es opcional.',
          return_entry:'La tina regresa al origen: asigna Cámara/Fila y confirma el peso.'
        }[op]
    );
  }
  function renderSaleOutgoingRequest() {
    if(mode!=='outgoing'||!saleDetail) return;

    const o=saleDetail.order||{}, lines=saleDetail.lines||[], allocations=saleDetail.allocations||[];
    const line=lookup?.matching_lines?.[0]||null;
    const closure=saleDetail.closure||{};
    const requested=num(closure.requested_kg)||lines.reduce((sum,item)=>sum+num(item.requested_weight_kg),0);
    const dispatchedRows=allocations.filter((item)=>String(item.status)==='dispatched');
    const activeRows=allocations.filter((item)=>String(item.status)!=='released');
    const dispatchedKg=Number.isFinite(Number(closure.dispatched_kg))
      ?num(closure.dispatched_kg)
      :dispatchedRows.reduce((sum,item)=>sum+num(item.outbound_weight_kg??item.quantity_kg),0);
    const pct=requested>0?Math.min(100,Math.round((dispatchedKg/requested)*100)):0;
    const tracked=activeRows.length;
    const progressLabel=dispatchedRows.length+' de '+tracked+' tinas despachadas';

    if($('wr-manifest-count')) $('wr-manifest-count').textContent=tracked>0?(dispatchedRows.length+' de '+tracked):(dispatchedRows.length+' tinas');

    if($('wr-outgoing-request')) {
      const [statusLabel,statusTone]=saleStatusMeta(String(o.status||'draft'));
      $('wr-outgoing-request').className='wr-outgoing-request-content';
      $('wr-outgoing-request').innerHTML=
        '<div class="wr-request-titlebar"><span class="wr-request-ok"><i class="bi bi-check-circle-fill"></i> Venta detectada</span><strong>'+esc(progressLabel)+'</strong></div>'+
        '<div class="wr-request-detected-head"><div class="wr-request-folio-line"><strong>'+esc(o.folio||'Venta')+'</strong><span class="wr-sale-inline-badges"><span class="wr-history-type sale">Venta a cliente</span><span class="wr-history-status '+esc(statusTone)+'"><i class="bi bi-circle-fill"></i>'+esc(statusLabel)+'</span></span></div><div class="wr-request-progress"><div><span style="width:'+pct+'%"></span></div><small>'+pct+'%</small></div></div>'+
        '<div class="wr-request-detected-grid"><div><span>Tipo</span><strong>Venta a cliente</strong><span>Origen</span><strong>'+esc(o.source_warehouse_name||'Almacén')+'</strong><span>Destino</span><strong>Cliente: '+esc(o.customer_name||'—')+'</strong></div><div><span>Producto actual</span><strong>'+esc(line?(line.species_name+' · '+line.size_name):'—')+'</strong><span>Lote</span><strong>'+esc(line?.lot_code||'Cualquier lote')+'</strong><span>Propietario</span><strong>'+esc(line?.owner_name||'Cualquier propietario')+'</strong></div></div>'+
        (String(o.status||'')!=='dispatched'&&dispatchedRows.length
          ?(
            closure.can_finalize
              ?'<div class="alert alert-info d-none align-items-center justify-content-between gap-3 mt-3 mb-0 py-2 px-3" aria-hidden="true"><div><strong>Salida lista para finalizar.</strong><div class="small">'+esc(closure.message||'Puedes concluir la venta con el peso real despachado.')+' Solicitado '+kg(requested)+' kg · despachado '+kg(dispatchedKg)+' kg · diferencia '+kg(num(closure.difference_kg))+' kg.</div></div><button type="button" class="btn btn-sm btn-primary flex-shrink-0" data-action="finalize-sale"><i class="bi bi-check2-circle me-1"></i>Finalizar salida</button></div>'
              :'<div class="alert alert-light border d-flex align-items-center justify-content-between gap-3 mt-3 mb-0 py-2 px-3"><div><strong>Finalizar salida</strong><div class="small text-secondary">'+esc(closure.message||'Continúa registrando las tinas de la venta.')+'</div></div><button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" disabled><i class="bi bi-hourglass-split me-1"></i>Pendiente</button></div>'
          )
          :'');
    }

    stationContext((o.folio||'Venta')+' · Venta a cliente · '+(o.source_warehouse_name||'Almacén')+' → Cliente: '+(o.customer_name||'—'));

    const rows=[...allocations];
    const currentStock=num(lookup?.stock?.id);
    const known=rows.some((item)=>num(item.stock_unit_id)===currentStock);
    if(lookup&&currentStock&&!known) {
      rows.unshift({
        stock_unit_id:currentStock,
        tub_code:lookup.stock.tub_code,
        unit_code:lookup.stock.unit_code,
        status:'current',
        outbound_weight_kg:null,
        quantity_kg:null
      });
    }

    const manifestHead='<div class="wr-manifest-table-head" aria-hidden="true"><span>#</span><span>Código de tina</span><span>Peso (kg)</span><span>Estatus</span></div>';
    const stateLabel=(status)=>({
      selected:'Seleccionada',
      reserved:'Preparada',
      dispatched:'Despachada',
      current:'Actual',
      released:'Salida cancelada'
    }[status]||status);

    const body=manifestHead+(rows.length?rows.slice(0,24).map((item,index)=>{
      const current=num(item.stock_unit_id)===currentStock;
      const status=String(item.status||'');
      const done=status==='dispatched';
      const cls=done?'done':(current?'current':'pending');
      const weight=item.outbound_weight_kg??item.quantity_kg;
      const cancelAction=status==='dispatched'
        ?'<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" data-action="cancel-sale-shipment" data-id="'+num(item.id)+'" data-code="'+esc(item.tub_code||item.unit_code||('#'+item.stock_unit_id))+'" aria-label="Cancelar despacho" title="Cancelar despacho"><i class="bi bi-three-dots-vertical"></i></button>'
        :'';
      return '<div class="wr-manifest-row '+(current?'is-current':'')+'"><span class="wr-manifest-num">'+(index+1)+'</span><div><strong>'+esc(item.tub_code||item.unit_code||('#'+item.stock_unit_id))+'</strong><small>'+esc(item.unit_code||'')+'</small></div><span class="wr-manifest-weight">'+(weight==null?'—':kg(weight))+'</span><span class="d-flex align-items-center justify-content-end gap-1"><span class="wr-mini-state '+cls+'">'+esc(stateLabel(status))+'</span>'+cancelAction+'</span></div>';
    }).join(''):'<div class="wr-manifest-empty"><i class="bi bi-clipboard"></i><span>Aún no hay tinas registradas en esta venta.</span></div>');

    if($('wr-outgoing-manifest')) $('wr-outgoing-manifest').innerHTML=body;
  }

  async function prepareSaleOutgoingLookup() {
    recoveredOutgoing=null;
    setOutgoingRecoveryMode(false);
    const saleId=num(
      lookup?.sale_order_id
      || lookup?.sale_order_line?.sales_order_id
      || lookup?.candidate?.request?.id
      || lookup?.selected_request_id
    );
    if(!saleId) throw new Error('No fue posible identificar la solicitud de venta.');

    saleDetail=lookup.sale_detail||await saleGet('/'+saleId);
    lookup.sale_order_id=saleId;
    lookup.sale_detail=saleDetail;
    detail=null;
    unitAfter=null;

    renderSaleOutgoingRequest();
    stationState('Tina identificada','online');

    const s=lookup.stock, line=lookup.matching_lines?.[0];
    const inventoryNet=num(s.on_hand_weight_kg), inventoryTare=num(s.current_tare_kg), inventoryGross=round3(inventoryNet+inventoryTare);

    $('wr-unit-info').innerHTML='<div class="wr-current-unit-layout"><div class="wr-current-unit-visual"><i class="bi bi-box-seam"></i><span>Tina</span></div><div class="wr-current-unit-data"><div class="wr-current-unit-code"><span>Código de tina</span><strong>'+esc(s.tub_code||s.unit_code)+'</strong></div><div class="wr-current-unit-meta"><div><span>Especie / Talla</span><strong>'+esc(s.species_name)+' · '+esc(s.size_name)+'</strong></div><div><span>Lote</span><strong>'+esc(s.lot_code||'—')+'</strong></div><div><span>Propietario</span><strong>'+esc(s.owner_name||'—')+'</strong></div><div><span>Peso neto</span><strong>'+kg(inventoryNet)+' kg</strong></div><div><span>Tara</span><strong>'+kg(inventoryTare)+' kg</strong></div><div><span>Peso bruto</span><strong>'+kg(inventoryGross)+' kg</strong></div><div class="full"><span>Ubicación registrada</span><strong>'+esc(currentLocationLabel(s))+'</strong></div></div></div></div>';

    if($('wr-test-weight')) $('wr-test-weight').value=inventoryGross>0?inventoryGross.toFixed(3):'';
    syncTestWeightMode(false);

    if($('wr-unit-state')) {
      $('wr-unit-state').className='wr-mini-state done';
      $('wr-unit-state').textContent='Tina reconocida';
    }

    $('wr-match-line').value=String(line?.id||'');
    show('wr-outgoing-register-empty',false);
    show('wr-unit-form',true);
    unitFields();
    await probeScale();
  }

  function setOutgoingRecoveryMode(active) {
    if(mode!=='outgoing') return;
    ['wr-scale','wr-read','wr-unit-submit','wr-test-mode','wr-test-weight'].forEach((id)=>{
      const el=$(id);
      if(el) el.disabled=Boolean(active);
    });
    const form=$('wr-unit-form');
    form?.classList.toggle('wr-history-recovered',Boolean(active));
  }

  function recoveredStatusLabel(row) {
    const status=String(row?.status||'');
    if(row?.source==='sale') {
      if(row?.cancelable) return 'Despachada · cancelable';
      if(row?.already_reversed || status==='released') return 'Despacho cancelado';
      return status==='dispatched'?'Despachada':'Histórica';
    }
    return {
      shipped:row?.cancelable?'En tránsito · cancelable':'En tránsito',
      accepted:'Recibida en destino',
      returning:'Devolución en tránsito',
      returned:'Reingresada al origen',
      released:'Salida cancelada'
    }[status]||'Salida histórica';
  }

  function renderRecoveredUnit(row) {
    const s=row.stock||{};
    const outbound=num(row.weight_kg);
    const tare=num(s.current_tare_kg);
    const gross=round3(outbound+tare);

    $('wr-unit-info').innerHTML=
      '<div class="wr-current-unit-layout">'+
        '<div class="wr-current-unit-visual"><i class="bi bi-clock-history"></i><span>Salida</span></div>'+
        '<div class="wr-current-unit-data">'+
          '<div class="wr-current-unit-code"><span>Código de tina</span><strong>'+esc(s.tub_code||s.unit_code||'—')+'</strong></div>'+
          '<div class="wr-current-unit-meta">'+
            '<div><span>Especie / Talla</span><strong>'+esc((s.species_name||'—')+' · '+(s.size_name||'—'))+'</strong></div>'+
            '<div><span>Lote</span><strong>'+esc(s.lot_code||'—')+'</strong></div>'+
            '<div><span>Propietario</span><strong>'+esc(s.owner_name||'—')+'</strong></div>'+
            '<div><span>Peso neto de salida</span><strong>'+kg(outbound)+' kg</strong></div>'+
            '<div><span>Tara registrada</span><strong>'+kg(tare)+' kg</strong></div>'+
            '<div><span>Bruto de referencia</span><strong>'+kg(gross)+' kg</strong></div>'+
            '<div class="full"><span>Ubicación actualmente registrada</span><strong>'+esc(currentLocationLabel(s))+'</strong></div>'+
          '</div>'+
        '</div>'+
      '</div>';

    if($('wr-unit-state')) {
      $('wr-unit-state').className='wr-mini-state '+(row.cancelable?'current':'done');
      $('wr-unit-state').textContent=recoveredStatusLabel(row);
    }

    show('wr-outgoing-register-empty',false);
    show('wr-unit-form',true);
    setOutgoingRecoveryMode(true);
    reading=null;

    if($('wr-weight')) $('wr-weight').textContent='—';
    if($('wr-weight-raw')) $('wr-weight-raw').textContent='Consulta histórica · no se registrará una nueva salida.';
    if($('wr-operation-hint')) {
      $('wr-operation-hint').textContent=row.cancelable
        ? 'Salida recuperada. Usa ⋮ en "Tinas de la solicitud" para cancelar esta tina.'
        : 'Salida recuperada para consulta. Esta tina ya no admite cancelación desde Salidas.';
    }
    if($('wr-unit-submit')) {
      $('wr-unit-submit').innerHTML='<i class="bi bi-clock-history me-1"></i>Salida ya registrada';
    }

    stationState(row.cancelable?'Salida recuperada · cancelable':'Salida recuperada','warning');
  }

  async function prepareRecoveredOutgoing(row,code='') {
    recoveredOutgoing=row;
    const stock=row.stock||{};

    if(row.source==='sale') {
      saleDetail=await saleGet('/'+num(row.sale_order_id));
      detail=null;
      const line=(saleDetail.lines||[]).find((item)=>num(item.id)===num(row.request_line_id))||null;
      const allocation=(saleDetail.allocations||[]).find((item)=>num(item.id)===num(row.sales_order_line_id))||null;
      lookup={
        source:'sale',
        stock,
        sale_order_id:num(row.sale_order_id),
        sale_order_line:allocation,
        matching_lines:line?[line]:[],
        operation:'history',
        resolution:'history_recovered',
        code:code||stock.tub_code||stock.unit_code||''
      };
      renderSaleOutgoingRequest();
    } else {
      detail=await api('/'+num(row.request_id));
      saleDetail=null;
      const line=(detail.lines||[]).find((item)=>num(item.id)===num(row.request_line_id))||null;
      const unit=(detail.units||[]).find((item)=>num(item.id)===num(row.unit_id))||{
        id:num(row.unit_id),
        request_line_id:num(row.request_line_id),
        stock_unit_id:num(stock.id),
        status:String(row.status||'')
      };
      lookup={
        source:'warehouse',
        stock,
        unit,
        detail,
        matching_lines:line?[line]:[],
        operation:'history',
        resolution:'history_recovered',
        code:code||stock.tub_code||stock.unit_code||''
      };
      unitAfter=detail.next_unit_after;
      renderOutgoingRequest();
    }

    renderRecoveredUnit(row);
    message(
      row.cancelable
        ? 'Esta tina ya tiene una salida registrada. Se recuperó la operación para que puedas revisarla o cancelarla.'
        : 'Esta tina ya tiene una salida registrada. Se recuperó únicamente para consulta.',
      'info'
    );
  }

  async function recoverOutgoingByCode(code) {
    const clean=String(code||'').trim();
    if(!clean) throw new Error('Indica el código de la tina.');
    resetUnit(true);
    if($('wr-qr')) $('wr-qr').value=clean;
    stationState('Buscando salida anterior…','warning');
    const row=await api('/outgoing-recover'+query({code:clean}));
    await prepareRecoveredOutgoing(row,clean);
  }

  function recentOutgoingStatus(row) {
    const status=String(row?.item_status||'');
    if(row?.source==='sale') return status==='dispatched'?'Despachada':'Despacho cancelado';
    return {
      shipped:'En tránsito',
      accepted:'Recibida',
      returning:'Devolución',
      returned:'Reingresada',
      released:'Salida cancelada'
    }[status]||status;
  }

  async function loadRecentOutgoingResults(search='') {
    const result=await api('/outgoing-recent'+query({search:String(search||'').trim(),limit:80}));
    const host=$('wr-recent-outgoing-results');
    if(!host) return;

    const rows=Array.isArray(result.rows)?result.rows:[];
    host.innerHTML=rows.length
      ? '<div class="table-responsive"><table class="table table-sm align-middle mb-0">'+
          '<thead><tr><th>Fecha</th><th>Tina</th><th>Folio / destino</th><th class="text-end">Kg</th><th>Estado</th><th></th></tr></thead>'+
          '<tbody>'+rows.map((row)=>{
            const code=row.tub_code||row.unit_code||'';
            const tone=row.cancelable?'text-primary':'text-secondary';
            return '<tr>'+
              '<td class="small text-nowrap">'+esc(row.occurred_at||'—')+'</td>'+
              '<td><strong>'+esc(code||'—')+'</strong><small class="d-block text-muted">'+esc(row.type_label||'')+'</small></td>'+
              '<td><strong>'+esc(row.folio||'—')+'</strong><small class="d-block text-muted">'+esc(row.destination_name||'—')+'</small></td>'+
              '<td class="text-end">'+kg(row.weight_kg)+'</td>'+
              '<td><span class="'+tone+' small fw-semibold">'+esc(recentOutgoingStatus(row))+'</span></td>'+
              '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" data-recover-outgoing-code="'+esc(code)+'"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir</button></td>'+
            '</tr>';
          }).join('')+'</tbody></table></div>'
      : '<div class="text-center text-muted py-4"><i class="bi bi-clock-history fs-3 d-block mb-2"></i>No se encontraron salidas con ese criterio.</div>';
  }

  async function openRecentOutgoing() {
    const initial=await api('/outgoing-recent'+query({limit:80}));
    const rows=Array.isArray(initial.rows)?initial.rows:[];

    dialog(
      'Salidas recientes',
      '<div class="mb-3">'+
        '<label for="wr-recent-outgoing-search" class="form-label">Buscar por tina, folio, cliente o destino</label>'+
        '<div class="input-group">'+
          '<input id="wr-recent-outgoing-search" class="form-control" maxlength="100" placeholder="Ej. T-2600, VTA-2026..., Marindustrias">'+
          '<button type="button" class="btn btn-outline-primary" id="wr-recent-outgoing-search-btn"><i class="bi bi-search me-1"></i>Buscar</button>'+
        '</div>'+
      '</div>'+
      '<div id="wr-recent-outgoing-results"></div>',
      async()=>closeDialog(),
      'Cerrar'
    );

    const host=$('wr-recent-outgoing-results');
    const renderInitial=()=>{
      if(!host) return;
      host.innerHTML=rows.length
        ? '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Fecha</th><th>Tina</th><th>Folio / destino</th><th class="text-end">Kg</th><th>Estado</th><th></th></tr></thead><tbody>'+
          rows.map((row)=>{
            const code=row.tub_code||row.unit_code||'';
            const tone=row.cancelable?'text-primary':'text-secondary';
            return '<tr><td class="small text-nowrap">'+esc(row.occurred_at||'—')+'</td><td><strong>'+esc(code||'—')+'</strong><small class="d-block text-muted">'+esc(row.type_label||'')+'</small></td><td><strong>'+esc(row.folio||'—')+'</strong><small class="d-block text-muted">'+esc(row.destination_name||'—')+'</small></td><td class="text-end">'+kg(row.weight_kg)+'</td><td><span class="'+tone+' small fw-semibold">'+esc(recentOutgoingStatus(row))+'</span></td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" data-recover-outgoing-code="'+esc(code)+'"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir</button></td></tr>';
          }).join('')+
          '</tbody></table></div>'
        : '<div class="text-center text-muted py-4"><i class="bi bi-clock-history fs-3 d-block mb-2"></i>No hay salidas recientes.</div>';
    };
    renderInitial();

    const search=()=>run(()=>loadRecentOutgoingResults($('wr-recent-outgoing-search')?.value||''));
    $('wr-recent-outgoing-search-btn')?.addEventListener('click',search);
    $('wr-recent-outgoing-search')?.addEventListener('keydown',(e)=>{
      if(e.key==='Enter'){e.preventDefault();search();}
    });

    host?.addEventListener('click',(e)=>{
      const button=e.target.closest('[data-recover-outgoing-code]');
      if(!button) return;
      const code=String(button.dataset.recoverOutgoingCode||'');
      closeDialog();
      run(()=>recoverOutgoingByCode(code));
    });
  }

  function installRecentOutgoingButton() {
    if(mode!=='outgoing' || document.querySelector('[data-action="outgoing-recent"]')) return;
    const header=document.querySelector('.wr-outgoing-scan-card .ws-card-header');
    if(!header) return;
    const button=document.createElement('button');
    button.type='button';
    button.className='btn btn-sm btn-outline-secondary ms-auto';
    button.dataset.action='outgoing-recent';
    button.innerHTML='<i class="bi bi-clock-history me-1"></i>Salidas recientes';
    button.title='Buscar una salida anterior por tina, folio, cliente o destino';
    header.appendChild(button);
  }

  async function prepareOutgoingLookup() {
    recoveredOutgoing=null;
    setOutgoingRecoveryMode(false);
    if(lookup?.source==='sale') {
      await prepareSaleOutgoingLookup();
      return;
    }
    saleDetail=null;
    detail=lookup.detail;
    unitAfter=detail.next_unit_after;
    renderOutgoingRequest();
    stationState('Tina identificada','online');

    const s=lookup.stock, line=lookup.matching_lines?.[0];
    const inventoryNet=num(s.on_hand_weight_kg), inventoryTare=num(s.current_tare_kg), inventoryGross=round3(inventoryNet+inventoryTare);

    $('wr-unit-info').innerHTML='<div class="wr-current-unit-layout"><div class="wr-current-unit-visual"><i class="bi bi-box-seam"></i><span>Tina</span></div><div class="wr-current-unit-data"><div class="wr-current-unit-code"><span>Código de tina</span><strong>'+esc(s.tub_code||s.unit_code)+'</strong></div><div class="wr-current-unit-meta"><div><span>Especie / Talla</span><strong>'+esc(s.species_name)+' · '+esc(s.size_name)+'</strong></div><div><span>Lote</span><strong>'+esc(s.lot_code||'—')+'</strong></div><div><span>Propietario</span><strong>'+esc(s.owner_name||'—')+'</strong></div><div><span>Peso neto</span><strong>'+kg(inventoryNet)+' kg</strong></div><div><span>Tara</span><strong>'+kg(inventoryTare)+' kg</strong></div><div><span>Peso bruto</span><strong>'+kg(inventoryGross)+' kg</strong></div><div class="full"><span>Ubicación registrada</span><strong>'+esc(currentLocationLabel(s))+'</strong></div></div></div></div>';

    if($('wr-test-weight')) $('wr-test-weight').value=inventoryGross>0?inventoryGross.toFixed(3):'';
    syncTestWeightMode(false);
    if($('wr-unit-state')) {
      $('wr-unit-state').className='wr-mini-state done';
      $('wr-unit-state').textContent='Tina reconocida';
    }
    $('wr-match-line').value=String(line?.id||lookup.unit?.request_line_id||'');
    show('wr-outgoing-register-empty',false);
    show('wr-unit-form',true);
    unitFields();
    await probeScale();
  }

  function requestTypeLabel(type) {
    if(type==='sale') return 'Venta a cliente';
    return type==='production'?'Materia prima':'Traspaso interno';
  }

  function chooseOutgoingRequest() {
    const candidates=Array.isArray(lookup?.candidates)?lookup.candidates:[];
    if(!candidates.length) throw new Error('No se encontraron solicitudes compatibles.');

    const s=lookup.stock;
    const body=
      '<div class="wr-outgoing-choice-intro">'+
        '<div class="wr-outgoing-choice-tub"><i class="bi bi-box-seam"></i><div><span>Tina</span><strong>'+esc(s.tub_code||s.unit_code)+'</strong><small>'+esc(s.species_name)+' · '+esc(s.size_name)+' · '+kg(s.on_hand_weight_kg)+' kg</small></div></div>'+
        '<p>La tina no está seleccionada para surtido en ningún folio y cumple con más de una solicitud activa. Selecciona a cuál solicitud se aplicará esta salida.</p>'+
        '<div class="wr-outgoing-choice-warning"><i class="bi bi-info-circle"></i><span>Las sugerencias hechas por el solicitante no intervienen en esta decisión. La selección del almacén es la que manda.</span></div>'+
      '</div>'+
      '<div class="wr-outgoing-choice-list">'+candidates.map((candidate,index)=>{
        const r=candidate.request,line=candidate.line,source=candidate.source||'warehouse';
        const key=source+':'+num(r.id);
        return '<label class="wr-outgoing-choice-card">'+
          '<input type="radio" name="wr-outgoing-request-choice" value="'+esc(key)+'" '+(index===0?'checked':'')+'>'+
          '<span class="wr-outgoing-choice-radio"></span>'+
          '<span class="wr-outgoing-choice-main">'+
            '<span class="wr-outgoing-choice-head"><strong>'+esc(r.folio)+'</strong><em>'+esc(requestTypeLabel(r.request_type))+'</em></span>'+
            '<span class="wr-outgoing-choice-route"><i class="bi bi-arrow-right"></i> '+esc(r.destination_name||'Destino')+'</span>'+
            '<span class="wr-outgoing-choice-product">'+esc(line.species_name)+' · '+esc(line.size_name)+(line.lot_code?' · Lote '+esc(line.lot_code):'')+(line.owner_name?' · '+esc(line.owner_name):'')+'</span>'+
            '<span class="wr-outgoing-choice-metrics"><b>Pendiente '+kg(r.pending_weight_kg)+' kg</b><small>Solicitado '+kg(r.requested_weight_kg)+' kg · Despachado '+kg(r.shipped_weight_kg)+' kg</small></span>'+
          '</span>'+
        '</label>';
      }).join('')+'</div>';

    dialog(
      'Seleccionar solicitud para esta tina',
      body,
      async()=>{
        const selected=document.querySelector('input[name="wr-outgoing-request-choice"]:checked');
        if(!selected) throw new Error('Selecciona la solicitud a la que se aplicará esta tina.');

        const [selectedSource,rawId]=String(selected.value).split(':');
        const selectedId=num(rawId);
        const candidate=candidates.find((item)=>(item.source||'warehouse')===selectedSource&&num(item.request.id)===selectedId);
        if(!candidate) throw new Error('La solicitud seleccionada ya no está disponible.');

        if(selectedSource==='sale') {
          const selectedDetail=await saleGet('/'+selectedId);
          lookup={
            ...lookup,
            source:'sale',
            sale_order_id:selectedId,
            sale_detail:selectedDetail,
            sale_order_line:null,
            unit:null,
            matching_lines:[candidate.line],
            resolution:'operator_selected_sale',
            selected_request_id:selectedId,
            candidates:[]
          };
        } else {
          const selectedDetail=await api('/'+selectedId);
          lookup={
            ...lookup,
            source:'warehouse',
            detail:selectedDetail,
            unit:null,
            matching_lines:[candidate.line],
            resolution:'operator_selected',
            selected_request_id:selectedId,
            candidates:[]
          };
        }

        closeDialog();
        await prepareOutgoingLookup();
        message((selectedSource==='sale'?'Venta ':'Solicitud ')+candidate.request.folio+' elegida para esta salida. La asignación se confirmará al registrar la salida.','info');
      },
      'Continuar con solicitud'
    );

    const dialogBody=$('wr-dialog-body');
    dialogBody?.querySelectorAll('input[name="wr-outgoing-request-choice"]').forEach((radio)=>{
      radio.addEventListener('change',()=>{
        if(radio.checked) resetDialogSubmit('Continuar con solicitud');
      });
    });
    resetDialogSubmit('Continuar con solicitud');
    stationState('Selecciona la solicitud','warning');
  }

  async function scan() {
    const code=$('wr-qr').value.trim();
    if(!code) throw new Error('Lee el código de la tina.');
    resetUnit(mode==='outgoing'); $('wr-qr').value=code;
    if(['outgoing','incoming'].includes(mode)) stationState('Identificando tina…','warning');
    if(mode==='outgoing') {
      try {
        lookup=await api('/lookup-outgoing'+query({code}));
      } catch(activeError) {
        try {
          const recovered=await api('/outgoing-recover'+query({code}));
          await prepareRecoveredOutgoing(recovered,code);
          return;
        } catch(historyError) {
          throw activeError;
        }
      }
    } else {
      lookup=await api('/lookup'+query({code}));
    }
    lookup.code=code;

    if(mode==='outgoing') {
      if(lookup.resolution==='choose_request') {
        detail=null;
        unitAfter=null;
        chooseOutgoingRequest();
        return;
      }
      await prepareOutgoingLookup();
      return;
    }

    detail=lookup.detail;
    unitAfter=detail.next_unit_after;
    renderIncomingRequest(); stationState('Tina identificada','online');
    reading=null; if($('wr-weight')) $('wr-weight').textContent='—'; if($('wr-weight-raw')) $('wr-weight-raw').textContent='Sin lectura';
    const s=lookup.stock;
    const netReference=num(lookup.unit?.shipped_weight_kg||s.on_hand_weight_kg), tare=num(s.current_tare_kg), grossReference=round3(netReference+tare);
    $('wr-unit-info').innerHTML='<div class="wr-current-unit-layout"><div class="wr-current-unit-visual"><i class="bi bi-box-seam"></i><span>Tina</span></div><div class="wr-current-unit-data"><div class="wr-current-unit-code"><span>Código de tina</span><strong>'+esc(s.tub_code||s.unit_code)+'</strong></div><div class="wr-current-unit-meta"><div><span>Especie / Talla</span><strong>'+esc(s.species_name)+' · '+esc(s.size_name)+'</strong></div><div><span>Lote</span><strong>'+esc(s.lot_code||'—')+'</strong></div><div><span>Propietario</span><strong>'+esc(s.owner_name||'—')+'</strong></div><div><span>Peso neto</span><strong>'+kg(netReference)+' kg</strong></div><div><span>Tara</span><strong>'+kg(tare)+' kg</strong></div><div><span>Peso bruto</span><strong>'+kg(grossReference)+' kg</strong></div></div></div></div>';
    if($('wr-test-weight')) $('wr-test-weight').value=grossReference>0?grossReference.toFixed(3):'';
    syncTestWeightMode(false);
    if($('wr-unit-state')) { $('wr-unit-state').className='wr-mini-state done'; $('wr-unit-state').textContent='Tina reconocida'; }
    if(lookup.operation==='return_entry') {
      $('wr-unit-fields').innerHTML='<div class="wr-return-entry-note"><div class="wr-return-entry-icon"><i class="bi bi-arrow-return-left"></i></div><div><strong>Reingreso por devolución</strong><small>Motivo registrado: '+esc(lookup.unit.return_reason||'Sin detalle')+'</small></div></div>';
    } else if(detail?.request?.request_type==='production') {
      $('wr-unit-fields').innerHTML='<input type="hidden" id="wr-decision" value="accept"><div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-2"><div class="small text-secondary"><i class="bi bi-info-circle me-1"></i>Registrar recepción confirma el ingreso de la tina a Suarcream.</div><button type="button" class="btn btn-sm btn-outline-warning" data-production-toggle="1" data-receive-decision="return" aria-pressed="false"><i class="bi bi-arrow-return-left me-1"></i>Registrar devolución</button></div><div id="wr-return-options" class="wr-return-panel d-none"><div class="wr-return-panel-title"><i class="bi bi-arrow-return-left"></i><div><strong>Datos de devolución</strong><small>Describe por qué la tina no será recibida en destino.</small></div></div><label for="wr-return-reason" class="form-label">Motivo de devolución</label><textarea id="wr-return-reason" class="form-control" rows="2" minlength="5" maxlength="1000" placeholder="Describe por qué la tina no será recibida"></textarea><div class="form-check form-switch wr-return-weigh"><input class="form-check-input" type="checkbox" role="switch" id="wr-weigh-return"><label class="form-check-label" for="wr-weigh-return">Pesar antes de devolver</label></div></div>';
    } else {
      $('wr-unit-fields').innerHTML='<div class="wr-receive-operation"><div class="wr-receive-operation-head"><span class="form-label mb-0">Operación</span><small>Define si la tina ingresa al destino o se devuelve al origen.</small></div><div class="wr-receive-choice-group" role="group" aria-label="Operación de recepción"><button type="button" class="wr-receive-choice active" data-receive-decision="accept" aria-pressed="true"><span class="wr-receive-choice-icon"><i class="bi bi-box-arrow-in-down"></i></span><span><strong>Aceptar entrada</strong><small>Ingresa al almacén destino</small></span></button><button type="button" class="wr-receive-choice" data-receive-decision="return" aria-pressed="false"><span class="wr-receive-choice-icon"><i class="bi bi-arrow-return-left"></i></span><span><strong>Devolución</strong><small>No ingresa al almacén destino</small></span></button></div><input type="hidden" id="wr-decision" value="accept"></div><div id="wr-checklist" class="wr-receive-checklist"><label class="wr-receive-check"><input class="form-check-input" type="checkbox" id="wr-identity" required><span><i class="bi bi-upc-scan"></i><strong>Identidad verificada</strong><small>La tina corresponde al folio</small></span></label><label class="wr-receive-check"><input class="form-check-input" type="checkbox" id="wr-condition" required><span><i class="bi bi-shield-check"></i><strong>Condición verificada</strong><small>La tina se recibe físicamente</small></span></label></div><div id="wr-return-options" class="wr-return-panel d-none"><div class="wr-return-panel-title"><i class="bi bi-arrow-return-left"></i><div><strong>Datos de devolución</strong><small>Describe por qué la tina no será recibida en destino.</small></div></div><label for="wr-return-reason" class="form-label">Motivo de devolución</label><textarea id="wr-return-reason" class="form-control" rows="2" minlength="5" maxlength="1000" placeholder="Describe por qué la tina no será recibida"></textarea><div class="form-check form-switch wr-return-weigh"><input class="form-check-input" type="checkbox" role="switch" id="wr-weigh-return"><label class="form-check-label" for="wr-weigh-return">Pesar antes de devolver</label></div></div>';
    }
    if($('wr-chamber')) $('wr-chamber').value='';
    fillRows(0);
    show('wr-incoming-register-empty',false); show('wr-unit-form',true); unitFields();
    await probeScale();
  }
  async function readWeight() {
    if(!lookup) throw new Error('Primero identifica la tina.');
    const scale=num($('wr-scale').value);
    if(!scale) throw new Error('Selecciona una báscula activa de esta instalación.');
    reading=null; if($('wr-weight')) $('wr-weight').textContent='…'; if($('wr-weight-raw')) $('wr-weight-raw').textContent='Solicitando lectura estable…';
    if(['outgoing','incoming'].includes(mode)) {
      const row=selectedScale();
      if(testWeightMode()) {
        const fakeWeight=num($('wr-test-weight')?.value);
        if(!Number.isFinite(fakeWeight) || fakeWeight<=0 || fakeWeight>9999.999) {
          $('wr-weight').textContent='—';
          if($('wr-weight-raw')) $('wr-weight-raw').textContent='PRUEBA · captura un peso entre 0.001 y 9999.999 kg.';
          throw new Error('Captura un peso ficticio válido entre 0.001 y 9999.999 kg.');
        }
        try {
          reading=await api('/scale-agent/capture',{scale_id:scale,test_mode:true,weight:fakeWeight,unit:'kg',stable:true});
          const tare=num(lookup.stock.current_tare_kg), net=round3(num(reading.weight)-tare);
          $('wr-weight').textContent=kg(reading.weight);
          if($('wr-weight-raw')) $('wr-weight-raw').textContent='PRUEBA · Bruto '+kg(reading.weight)+' kg · Tara '+kg(tare)+' kg · Neto calculado '+kg(net)+' kg.';
        } catch(e) {
          $('wr-weight').textContent='—';
          if($('wr-weight-raw')) $('wr-weight-raw').textContent='PRUEBA · no se pudo generar la lectura simulada.';
          throw e;
        }
        return;
      }
      if(!row?.port_name) { $('wr-weight').textContent='—'; throw new Error('La báscula seleccionada no tiene puerto COM configurado.'); }
      try {
        const agent=await scaleAgentFetch(row,'/api/v1/read',{method:'POST',body:JSON.stringify({transport:row.connection_type,configuration:scaleConfiguration(row)})});
        if(!agent.stable) throw new Error('La báscula todavía no reporta un peso estable.');
        reading=await api('/scale-agent/capture',{scale_id:scale,weight:agent.weight,unit:agent.unit||'kg',stable:agent.stable,captured_at:agent.captured_at,scale_identifier:agent.scale_identifier,original_value:agent.original_value,connection_status:agent.connection_status||'connected',port_name:agent.port_name||row.port_name});
        const tare=num(lookup.stock.current_tare_kg), net=round3(num(reading.weight)-tare);
        $('wr-weight').textContent=kg(reading.weight);
        if($('wr-weight-raw')) $('wr-weight-raw').textContent='Bruto '+kg(reading.weight)+' kg · Tara '+kg(tare)+' kg · Neto '+kg(net)+' kg · RAW: '+String(reading.original_value||'').slice(0,80);
        scaleStatus((row.name||'Báscula')+' · '+row.port_name+' lista',true);
      } catch(e) {
        $('wr-weight').textContent='—';
        if($('wr-weight-raw')) $('wr-weight-raw').textContent='Sin lectura';
        scaleStatus('Error de lectura',false);
        throw e;
      }
      return;
    }
    const direction=lookup.operation==='return_entry'?'return':'inbound';
    try { reading=await api('/'+detail.request.id+'/scale',{direction,scale_id:scale}); }
    catch(e) { $('wr-weight').textContent='Sin lectura'; throw e; }
    $('wr-weight').textContent=kg(reading.weight)+' − '+kg(lookup.stock.current_tare_kg)+' = '+kg(num(reading.weight)-num(lookup.stock.current_tare_kg))+' kg';
  }

  async function saveUnit() {
    if(!lookup) throw new Error('Lee el QR de la tina.');
    const op=unitMode(), withWeight=op!=='return'||$('wr-weigh-return')?.checked;
    if(withWeight&&!reading?.id) throw new Error('Obtén una lectura estable antes de confirmar.');
    const returnReason=$('wr-return-reason')?.value.trim()||'';
    const varianceReason=op==='return'?returnReason:(op==='return_entry'?String(lookup.unit?.return_reason||'').trim():'');
    const data={code:lookup.code,unit_id:num(lookup.unit?.id),stock_unit_id:num(lookup.stock.id),scale_reading_id:withWeight?num(reading.id):null,variance_reason:varianceReason};
    if(op==='ship') {
      data.request_line_id=num($('wr-match-line').value);
      data.chamber_id=num(lookup.stock.current_chamber_id||lookup.stock.location_chamber_id);
      data.row_location_id=num(lookup.stock.current_row_id);
      if(!data.chamber_id||!data.row_location_id) throw new Error('La tina no tiene Cámara/Fila registradas en inventario. Corrige primero su ubicación física antes de darle salida.');
    } else if(['accept','return_entry'].includes(op)) {
      data.chamber_id=num($('wr-chamber')?.value);
      data.row_location_id=num($('wr-row')?.value);
      if(!data.chamber_id||!data.row_location_id) throw new Error('Selecciona Cámara y Fila antes de registrar la recepción.');
    }
    if(['accept','return'].includes(op)) {
      const implicitProductionAccept=op==='accept'&&isProductionIncoming();
      Object.assign(data,{
        decision:op,
        weigh_return:op==='return'&&withWeight,
        identity_checked:implicitProductionAccept?true:$('wr-identity')?.checked===true,
        condition_checked:implicitProductionAccept?true:$('wr-condition')?.checked===true,
        return_reason:returnReason
      });
    }
    const code=lookup.stock.tub_code;
    try {
      if(op==='ship' && lookup.source==='sale') {
        const saleId=num(lookup.sale_order_id||lookup.sale_order_line?.sales_order_id||lookup.selected_request_id);
        if(!saleId) throw new Error('No fue posible identificar la venta seleccionada.');
        const result=await saleApi('/'+saleId+'/ship-unit',data);
        saleDetail=result.detail||await saleGet('/'+saleId);
        lookup.sale_order_id=saleId;
        lookup.sale_detail=saleDetail;
        renderSaleOutgoingRequest();
      } else {
        const result=await mutate(op==='ship'?'ship':(op==='return_entry'?'return-entry':'receive'),data);
        if(op==='accept' && detail?.request?.request_type==='production') {
          const issue=result?._production_issue||detail?._production_issue;
          if(issue?.pending) {
            message(code+': entrada registrada. La salida a Producción quedó pendiente y puede reintentarse desde el detalle.','warning');
          } else if(issue?.status==='completed') {
            message(code+': entrada registrada y salida a Producción completada. Tina liberada.');
          }
        }
      }
    } catch(e) {
      const msg=String(e?.message||'');
      if(op==='accept' && /diferencia.*tolerancia|motivo.*diferencia|fuera de tolerancia/i.test(msg)) {
        throw new Error('El peso recibido está fuera de tolerancia. Revisa la lectura o selecciona Devolución; la recepción no fue registrada.');
      }
      throw e;
    }
    if(!(op==='accept' && detail?.request?.request_type==='production'))
      message(code+': '+({ship:'salida registrada',accept:'entrada registrada',return:'devolución registrada; pendiente de reingreso al origen',return_entry:'reingreso registrado'})[op]+'.');
    resetUnit(false);
    if(mode==='outgoing') {
      document.querySelectorAll('.wr-manifest-row.is-current').forEach((row)=>row.classList.remove('is-current'));
      if($('wr-test-mode')) { $('wr-test-mode').checked=false; syncTestWeightMode(false); }
      if($('wr-test-weight')) $('wr-test-weight').value='';
    }
    if(mode==='incoming') renderIncomingRequest();
    $('wr-qr')?.focus();
  }
  function saleStatusMeta(status) {
    return {
      draft:['Pendiente de surtido','pending'],
      allocated:['En surtido','supply'],
      dispatched:['Despachada','closed'],
      canceled:['Cancelada','rejected']
    }[status]||[status,'pending'];
  }
  async function loadSaleDetail(id) {
    saleDetail=await saleGet('/'+num(id));
    detail=null;
    const o=saleDetail.order||{}, lines=saleDetail.lines||[], proposals=saleDetail.proposals||[], allocations=saleDetail.allocations||[];
    const closure=saleDetail.closure||{};
    const [statusLabel,statusClass]=saleStatusMeta(String(o.status||''));
    const requested=lines.reduce((s,l)=>s+num(l.requested_weight_kg),0);
    const allocated=allocations.reduce((s,l)=>s+num(l.quantity_kg),0);
    const proposed=proposals.reduce((s,l)=>s+num(l.proposed_weight_kg),0);
    const route=esc(o.source_warehouse_name||'Almacén')+' <i class="bi bi-arrow-right"></i> Cliente: '+esc(o.customer_name||'—');
    const canAdminFinalize=!!cfg.canSale
      && String(o.status||'')==='allocated'
      && num(closure.dispatched_units)>0
      && num(closure.dispatched_kg)>0.0001;
    const adminFinalizeButton=canAdminFinalize
      ?'<button type="button" class="btn btn-sm btn-primary" data-action="admin-finalize-sale"><i class="bi bi-check2-circle me-1"></i>Finalizar solicitud</button>'
      :'';

    let html='<section class="wr-card"><div class="wr-detail-hero"><div><button type="button" class="btn btn-sm btn-link ps-0 text-decoration-none" data-action="back-list"><i class="bi bi-arrow-left me-1"></i>Solicitudes registradas</button><div class="d-flex gap-2 align-items-center flex-wrap"><h2>'+esc(o.folio||'Venta')+'</h2><span class="wr-history-type sale">Venta a cliente</span><span class="wr-history-status '+statusClass+'"><i class="bi bi-circle-fill"></i>'+esc(statusLabel)+'</span></div><div class="wr-detail-sub">'+route+'</div></div><div class="wr-detail-actions">'+adminFinalizeButton+'</div></div>';
    html+='<div class="wr-detail-tabs"><button type="button" class="wr-detail-tab active" data-detail-tab="summary">Resumen</button><button type="button" class="wr-detail-tab" data-detail-tab="lines">Líneas</button><button type="button" class="wr-detail-tab" data-detail-tab="proposals">Propuesta</button><button type="button" class="wr-detail-tab" data-detail-tab="allocations">Surtido</button></div>';
    html+='<div class="wr-detail-panel" data-detail-panel="summary"><div class="wr-detail-metrics"><div class="wr-detail-metric"><span>Kg solicitados</span><strong>'+kg(requested)+'</strong></div><div class="wr-detail-metric"><span>Kg surtidos</span><strong>'+kg(allocated)+'</strong></div><div class="wr-detail-metric"><span>Tinas propuestas</span><strong>'+proposals.length+'</strong></div><div class="wr-detail-metric"><span>Tinas definitivas</span><strong>'+allocations.length+'</strong></div></div><div class="mt-3 p-3 bg-light rounded"><strong>Fecha requerida</strong><div class="text-secondary mt-1">'+esc(o.required_at||'—')+'</div><strong class="d-block mt-3">Observaciones</strong><div class="text-secondary mt-1">'+esc(o.observations||'Sin observaciones')+'</div></div></div>';
    html+='<div class="wr-detail-panel d-none" data-detail-panel="lines"><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>#</th><th>Especie</th><th>Talla</th><th>Lote</th><th>Propietario</th><th class="text-end">Kg solicitados</th></tr></thead><tbody>'+lines.map(l=>'<tr><td>'+num(l.line_number)+'</td><td>'+esc(l.species_name)+'</td><td>'+esc(l.size_name)+'</td><td>'+esc(l.lot_code||'Cualquier lote')+'</td><td>'+esc(l.owner_name||'Cualquier propietario')+'</td><td class="text-end">'+kg(l.requested_weight_kg)+'</td></tr>').join('')+'</tbody></table></div></div>';
    html+='<div class="wr-detail-panel d-none" data-detail-panel="proposals">'+(proposals.length?'<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Tina</th><th>Lote</th><th>Propietario</th><th>Ubicación</th><th class="text-end">Kg propuestos</th></tr></thead><tbody>'+proposals.map(p=>'<tr><td><strong>'+esc(p.tub_code||p.unit_code)+'</strong><div class="small text-secondary">'+esc(p.unit_code||'')+'</div></td><td>'+esc(p.lot_code||'—')+'</td><td>'+esc(p.owner_name||'—')+'</td><td>'+esc(p.location_path||'—')+'</td><td class="text-end">'+kg(p.proposed_weight_kg)+'</td></tr>').join('')+'</tbody></table></div>':'<div class="text-secondary py-4 text-center">Sin tinas propuestas. El almacén definirá las tinas al preparar el surtido.</div>')+'</div>';
    html+='<div class="wr-detail-panel d-none" data-detail-panel="allocations">'+(allocations.length?'<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Tina</th><th>Lote</th><th>Propietario</th><th>Estado</th><th class="text-end">Kg</th></tr></thead><tbody>'+allocations.map(a=>'<tr><td><strong>'+esc(a.tub_code||a.unit_code)+'</strong><div class="small text-secondary">'+esc(a.unit_code||'')+'</div></td><td>'+esc(a.lot_code||'—')+'</td><td>'+esc(a.owner_name||'—')+'</td><td>'+esc(a.status||'—')+'</td><td class="text-end">'+kg(a.quantity_kg)+'</td></tr>').join('')+'</tbody></table></div>':'<div class="text-secondary py-4 text-center">Pendiente de surtido definitivo.</div>')+'</div></section>';
    $('wr-detail').innerHTML=html;
    show('wr-detail');
    setWorkView('detail');
    switchDetailTab(detailTab='summary');
  }

  async function action(name,id,el) {
    if(name==='outgoing-recent') { await openRecentOutgoing(); return; }
    if(name==='open'||name==='reload') return loadDetail(id);
    if(name==='open-sale') return loadSaleDetail(id);
    if(name==='back-list'){saleDetail=null;setWorkView('list');return;}
    if(name==='remove-line') { if($('wr-lines').children.length>1) { const tr=el.closest('tr');draftSelections.delete(tr);tr.remove();updateLineNumbers();updateCaptureDashboard(); } return; }
    if(name==='capture-stock') return openStock({capture:true,tr:el.closest('tr')});
    if(name==='prepare-stock') return openStock({capture:false,id:num(id)});
    if(name==='stock-pick') return pickStock(id);
    if(name==='accept') { dialog('Aceptar solicitud','<p>Confirmarás que tu almacén atenderá '+esc(detail.request.folio)+'. El surtido se registrará por tina.</p>',async()=>{await mutate('accept'); closeDialog(); message('Solicitud aceptada. Ya puedes preparar el surtido.');}); return; }
    if(name==='reject') { dialog('Rechazar solicitud','<label for="wr-reason" class="form-label">Motivo</label><textarea id="wr-reason" class="form-control" minlength="5" maxlength="1000" required></textarea>',async()=>{await mutate('reject',{reason:$('wr-reason').value.trim()});closeDialog();message('Solicitud rechazada con motivo.');}); return; }
    if(name==='release') { await mutate('select',{selections:selections().filter((u)=>u.stock_unit_id!==num(id))});message('Tina retirada; compromiso liberado.');return; }
    if(name==='fifo') {
      const suggestion=await api('/'+detail.request.id+'/suggest'+query({line_id:id}));
      if(!suggestion.rows.length) { message(suggestion.target_met?'La propuesta y lo despachado ya cubren esta línea.':'No hay tinas compatibles disponibles o se alcanzó el límite de propuestas.','info');return; }
      dialog('Sugerencia FIFO · revisar antes de usar','<p>'+suggestion.rows.length+' tinas adicionales. Proyección de la línea: '+kg(suggestion.projected_kg)+' kg, '+suggestion.projected_units+' tinas.</p>'+(!suggestion.target_met?'<p class="text-warning">La sugerencia no cubre todo lo solicitado. Podrás continuar preparando conforme despaches.</p>':'')+'<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Etiqueta</th><th>Antigüedad</th><th>Kg netos</th></tr></thead><tbody>'+suggestion.rows.map((u)=>'<tr><td>'+esc(u.unit_code)+'</td><td>'+esc(u.received_at)+'</td><td>'+kg(u.weight_kg)+'</td></tr>').join('')+'</tbody></table></div><p class="small">Usar esta sugerencia compromete temporalmente las tinas seleccionadas por el almacén; la salida se registra después por QR.</p>',async()=>{await mutate('select',{selections:[...selections(),...suggestion.rows.map((u)=>({stock_unit_id:u.stock_unit_id,request_line_id:u.request_line_id}))]});closeDialog();message('Selección FIFO guardada. Puedes ajustar tinas o continuar en Salidas.');},'Usar selección FIFO');
      return;
    }
    if(name==='cancel-shipment') {
      const code=String(el?.dataset?.code||'tina');
      dialog(
        'Cancelar salida '+esc(code),
        '<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>La tina volverá al inventario del almacén origen. Esta acción sólo procede si el almacén destino todavía no la ha recibido.</div>'+
        '<label for="wr-cancel-shipment-reason" class="form-label">Motivo de cancelación</label>'+
        '<textarea id="wr-cancel-shipment-reason" class="form-control" minlength="5" maxlength="1000" rows="4" required placeholder="Ej. Se registró una tina incorrecta."></textarea>',
        async()=>{
          const reason=$('wr-cancel-shipment-reason').value.trim();
          if(reason.length<5) throw new Error('Indica un motivo de al menos cinco caracteres.');
          await mutate('cancel-shipment',{unit_id:num(id),reason});
          resetUnit(false);
          closeDialog();
          message('Salida de '+code+' cancelada. La tina volvió a estar disponible en el almacén origen.');
          $('wr-qr')?.focus();
        },
        'Sí, cancelar salida'
      );
      return;
    }
    if(name==='admin-finalize-sale') {
      const saleId=num(saleDetail?.order?.id);
      if(!saleId) throw new Error('No fue posible identificar la venta actual.');

      const order=saleDetail?.order||{};
      const closure=saleDetail?.closure||{};
      const requested=num(closure.requested_kg);
      const dispatched=num(closure.dispatched_kg);
      const difference=num(closure.difference_kg);
      const dispatchedUnits=num(closure.dispatched_units);

      if(String(order.status||'')!=='allocated') {
        throw new Error('La venta ya no está en surtido.');
      }
      if(dispatchedUnits<=0||dispatched<=0.0001) {
        throw new Error('Debe existir al menos una tina con peso de salida registrado.');
      }

      dialog(
        'Finalizar solicitud',
        '<div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>Este cierre se realizará desde el detalle administrativo. Las tinas ya despachadas se conservarán.</div>'+
        '<div class="row g-2 mb-3">'+
          '<div class="col-4"><div class="border rounded p-2"><span class="small text-secondary d-block">Solicitado</span><strong>'+kg(requested)+' kg</strong></div></div>'+
          '<div class="col-4"><div class="border rounded p-2"><span class="small text-secondary d-block">Despachado</span><strong>'+kg(dispatched)+' kg</strong></div></div>'+
          '<div class="col-4"><div class="border rounded p-2"><span class="small text-secondary d-block">Diferencia</span><strong>'+kg(difference)+' kg</strong></div></div>'+
          '<div class="col-12"><div class="border rounded p-2"><span class="small text-secondary">Tinas despachadas</span><strong class="ms-2">'+dispatchedUnits+'</strong></div></div>'+
        '</div>'+
        '<label for="wr-admin-finalize-sale-reason" class="form-label">Motivo de finalización</label>'+
        '<textarea id="wr-admin-finalize-sale-reason" class="form-control" minlength="5" maxlength="1000" rows="3" required placeholder="Ej. El surtido terminó y ya no existen más tinas por despachar."></textarea>',
        async()=>{
          const reason=$('wr-admin-finalize-sale-reason').value.trim();
          if(reason.length<5) throw new Error('Indica un motivo de al menos cinco caracteres.');

          const result=await saleApi('/'+saleId+'/finalize',{
            reason,
            administrative:true
          });

          saleDetail=result.detail||await saleGet('/'+saleId);
          closeDialog();
          await loadSaleDetail(saleId);
          message('Solicitud de venta finalizada administrativamente.');
        },
        'Finalizar solicitud'
      );
      return;
    }
    if(name==='finalize-sale') {
      const saleId=num(saleDetail?.order?.id);
      if(!saleId) throw new Error('No fue posible identificar la venta actual.');

      const closure=saleDetail?.closure||{};
      if(!closure.can_finalize) throw new Error(closure.message||'La venta todavía tiene surtido pendiente.');

      const requested=num(closure.requested_kg);
      const dispatched=num(closure.dispatched_kg);
      const difference=num(closure.difference_kg);
      const requestedUnits=(closure.lines||[]).reduce((sum,row)=>sum+num(row.requested_units),0);
      const dispatchedUnits=num(closure.dispatched_units);
      const requiresReason=closure.requires_reason===true||num(closure.requires_reason)===1;

      dialog(
        'Finalizar salida de venta',
        '<div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>La venta se cerrará con las tinas completas ya despachadas. No se fraccionará otra tina sólo para completar una diferencia de kilos.</div>'+
        '<div class="row g-2 mb-3">'+
          '<div class="col-4"><div class="border rounded p-2"><span class="small text-secondary d-block">Solicitado</span><strong>'+kg(requested)+' kg</strong></div></div>'+
          '<div class="col-4"><div class="border rounded p-2"><span class="small text-secondary d-block">Despachado</span><strong>'+kg(dispatched)+' kg</strong></div></div>'+
          '<div class="col-4"><div class="border rounded p-2"><span class="small text-secondary d-block">Diferencia</span><strong>'+kg(difference)+' kg</strong></div></div>'+
          (requestedUnits>0?'<div class="col-12"><div class="border rounded p-2"><span class="small text-secondary">Tinas solicitadas / despachadas</span><strong class="ms-2">'+requestedUnits+' / '+dispatchedUnits+'</strong></div></div>':'')+
        '</div>'+
        (requiresReason
          ?'<label for="wr-finalize-sale-reason" class="form-label">Motivo de la diferencia</label><textarea id="wr-finalize-sale-reason" class="form-control" minlength="5" maxlength="1000" rows="3" required placeholder="Ej. Diferencia por peso real de las tinas completas despachadas."></textarea>'
          :'<div class="small text-secondary">El peso solicitado ya fue cubierto. Puedes finalizar la salida.</div>'),
        async()=>{
          const reason=requiresReason?$('wr-finalize-sale-reason').value.trim():'';
          if(requiresReason&&reason.length<5) throw new Error('Indica un motivo de al menos cinco caracteres para cerrar con diferencia.');

          const result=await saleApi('/'+saleId+'/finalize',{reason});
          saleDetail=result.detail||await saleGet('/'+saleId);
          if(lookup){
            lookup.sale_order_id=saleId;
            lookup.sale_detail=saleDetail;
          }
          resetUnit(false);
          renderSaleOutgoingRequest();
          closeDialog();
          message('Salida de venta finalizada con '+kg(num(saleDetail?.closure?.dispatched_kg??dispatched))+' kg despachados.');
          $('wr-qr')?.focus();
        },
        'Finalizar salida'
      );
      return;
    }
    if(name==='cancel-sale-shipment') {
      const saleId=num(saleDetail?.order?.id);
      if(!saleId) throw new Error('No fue posible identificar la venta actual.');
      const code=String(el?.dataset?.code||'tina');
      dialog(
        'Cancelar despacho '+esc(code),
        '<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Se reversará únicamente el despacho de esta tina. La venta continuará activa si queda producto pendiente.</div>'+
        '<label for="wr-cancel-sale-shipment-reason" class="form-label">Motivo de cancelación</label>'+
        '<textarea id="wr-cancel-sale-shipment-reason" class="form-control" minlength="5" maxlength="1000" rows="4" required placeholder="Ej. Se despachó una tina incorrecta."></textarea>',
        async()=>{
          const reason=$('wr-cancel-sale-shipment-reason').value.trim();
          if(reason.length<5) throw new Error('Indica un motivo de al menos cinco caracteres.');
          const result=await saleApi('/'+saleId+'/cancel-shipment',{
            sales_order_line_id:num(id),
            reason
          });
          saleDetail=result.detail||await saleGet('/'+saleId);
          resetUnit(false);
          renderSaleOutgoingRequest();
          closeDialog();
          message('Despacho de '+code+' cancelado. La tina volvió al inventario disponible.');
          $('wr-qr')?.focus();
        },
        'Sí, cancelar despacho'
      );
      return;
    }
    if(name==='finalize') {
      dialog('Finalizar despacho','<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Al finalizar ya no se podrán sugerir FIFO, elegir ni despachar nuevas tinas. El destino puede seguir recibiendo las tinas ya despachadas.</div>'+detail.lines.map((l)=>{const s=summary(l.id);return '<label class="form-label mt-2" for="wr-note-'+l.id+'">'+esc(l.species_name)+' · '+esc(l.size_name)+' | Solicitados '+kg(l.requested_weight_kg)+' kg'+(l.requested_unit_count!==null?' / '+num(l.requested_unit_count)+' tinas':'')+'; enviados '+kg(s.kg)+' kg, '+s.sent+' tinas</label><textarea id="wr-note-'+l.id+'" data-line-note="'+l.id+'" class="form-control" maxlength="1000" rows="2" '+(Math.abs(s.kg-num(l.requested_weight_kg))>.0005||(l.requested_unit_count!==null&&s.sent!==num(l.requested_unit_count))?'required minlength="5"':'')+'>'+esc(l.supply_observation)+'</textarea>';}).join(''),async()=>{const notes=Object.fromEntries([...$('wr-dialog-body').querySelectorAll('[data-line-note]')].map((e)=>[e.dataset.lineNote,e.value.trim()]));await mutate('dispatch',{line_observations:notes});resetUnit();closeDialog();message(detail.request.status==='closed'?'Despacho y recepción finalizados automáticamente.':'Despacho finalizado. Las recepciones pendientes pueden continuar en destino.');},'Finalizar despacho');return;
    }
    if(name==='reopen-dispatch') {
      dialog('Reabrir despacho','<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Se habilitará nuevamente el surtido para completar cantidades pendientes. Las tinas ya despachadas, recibidas o devueltas NO se modificarán.</div><label for="wr-reopen-dispatch-reason" class="form-label">Motivo</label><textarea id="wr-reopen-dispatch-reason" class="form-control" minlength="5" maxlength="1000" rows="3" required placeholder="Ej. El operador finalizó el despacho antes de completar las tinas solicitadas."></textarea>',async()=>{const reason=$('wr-reopen-dispatch-reason').value.trim();if(reason.length<5)throw new Error('Indica un motivo de al menos cinco caracteres.');await mutate('dispatch',{operation:'reopen',reason});closeDialog();message('Despacho reabierto. Puedes continuar con FIFO, selección y nuevas salidas.');},'Reabrir despacho');return;
    }
    if(name==='retry-production') {
      if(detail?.request?.request_type!=='production') throw new Error('La solicitud no es de Materia prima.');
      const state=productionIssueState();
      if(!state.pending.length) throw new Error('No hay salidas a Producción pendientes.');
      dialog('Reintentar salida a Producción','<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>La recepción ya está confirmada. Este proceso sólo reintentará el movimiento terminal a Producción y la liberación de la tina.</div><p class="mb-0">Área destino: <strong>'+esc(productionDestinationFromDetail()||'Sin definir')+'</strong></p>',async()=>{
        await mutate('production-retry',{});
        closeDialog();
        message('Reintento de salida a Producción ejecutado. Revisa el estado de la solicitud.');
      },'Reintentar');
      return;
    }
    if(name==='cancel-receipt') {
      const code=String(el?.dataset?.code||'tina');
      const production=detail?.request?.request_type==='production';
      const warning=production
        ?'Si la tina ya salió a Producción, primero se revertirá esa salida y después la recepción. La operación se bloqueará si la tina fue reutilizada o tuvo otro movimiento posterior.'
        :'La tina volverá a estado En tránsito y deberá pesarse/recibirse nuevamente. Si existe cualquier movimiento posterior, el sistema bloqueará la anulación.';
      dialog('Anular recepción '+esc(code),'<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>'+esc(warning)+'</div><label for="wr-cancel-receipt-reason" class="form-label">Motivo</label><textarea id="wr-cancel-receipt-reason" class="form-control" minlength="5" maxlength="1000" rows="3" required placeholder="Ej. Se registró la recepción con una pesada incorrecta."></textarea>',async()=>{const reason=$('wr-cancel-receipt-reason').value.trim();if(reason.length<5)throw new Error('Indica un motivo de al menos cinco caracteres.');await mutate('receive',{operation:'cancel_receipt',unit_id:num(id),reason});resetUnit(false);closeDialog();message(production?'Recepción y salida a Producción revertidas. La tina volvió a En tránsito y puede recibirse nuevamente o devolverse.':'Recepción anulada. La tina volvió a En tránsito y requiere una nueva recepción.');},'Sí, anular recepción');return;
    }
    if(name==='close') {
      const s=summary();
      if(s.pending||s.returning||s.sent===0) throw new Error('Faltan tinas por recibir o devoluciones por reingresar.');
      dialog('Validar recepción completa','<p>'+s.sent+' despachadas = '+s.accepted+' recibidas + '+s.returned+' reingresadas al origen.</p><label><input type="checkbox" id="wr-confirm-close" required> Confirmo que registré todo lo recibido y revisé las devoluciones.</label>',async()=>{await mutate('close',{receipt_validated:$('wr-confirm-close').checked});closeDialog();message('Solicitud cerrada con el 100 % de las tinas resueltas.');},'Validar y cerrar');return;
    }
    if(name==='more-units') {
      const result=await api('/'+detail.request.id+query({after:unitAfter,status:$('wr-unit-status').value}));
      detail.units=result.units; unitAfter=result.next_unit_after; renderManifest();
    }
  }
  $('wr-app').addEventListener('click',(e)=>{
    const tab=e.target.closest('[data-detail-tab]');if(tab){switchDetailTab(tab.dataset.detailTab);return;}
    const type=e.target.closest('[data-request-type]');if(type){setRequestType(type.dataset.requestType);if(mode==='requests')setWorkView('capture');return;}
    const view=e.target.closest('[data-work-view]');if(view){setWorkView(view.dataset.workView);if(view.dataset.workView==='list')run(()=>listing(historyPage));return;}
    const b=e.target.closest('[data-action]');if(b)run(()=>action(b.dataset.action,b.dataset.id,b));
  });
  $('wr-lines').addEventListener('change',(e)=>{
    const tr=e.target.closest('tr'), field=e.target.dataset.field;if(!tr||!field)return;
    if(field==='species_id'){tr.querySelector('[data-field="size_grade_id"]').innerHTML=opts(sizes(e.target.value),'name');tr.querySelector('[data-field="lot_id"]').innerHTML='<option value="">Cualquier lote</option>';}
    if(field==='size_grade_id')tr.querySelector('[data-field="lot_id"]').innerHTML='<option value="">Cualquier lote</option>';
    if(['species_id','size_grade_id','lot_id','owner_company_id'].includes(field))clearDraft(tr);
    if(field==='requested_weight_kg')updateLineCoverage(tr);
    try{validateDraftStructure();validateDraftAllocations();}catch(err){message(err.message,'warning');}
    if(['size_grade_id','owner_company_id'].includes(field)||(field==='species_id'&&num(tr.querySelector('[data-field="size_grade_id"]').value)))run(()=>loadLineLots(tr));
    updateCaptureDashboard();
  });
  $('wr-lines').addEventListener('input',(e)=>{const tr=e.target.closest('tr');if(tr&&e.target.dataset.field==='requested_weight_kg')updateLineCoverage(tr);updateCaptureDashboard();});
  $('wr-source')?.addEventListener('change',()=>run(async()=>{for(const tr of lineRows()){tr.querySelector('[data-field="lot_id"]').innerHTML='<option value="">Cualquier lote</option>';clearDraft(tr);await loadLineLots(tr);}updateCaptureDashboard();}));
  $('wr-customer-search')?.addEventListener('focus',renderCustomerResults);
  $('wr-customer-search')?.addEventListener('input',()=>{
    const selected=selectedCustomer();
    if(!selected || $('wr-customer-search').value.trim()!==customerText(selected)) {
      if($('wr-customer')) $('wr-customer').value='';
    }
    renderCustomerResults();
    updateCaptureDashboard();
  });
  $('wr-customer-search')?.addEventListener('keydown',(e)=>{
    if(e.key==='Escape') hideCustomerResults();
  });
  $('wr-customer-results')?.addEventListener('click',(e)=>{
    const option=e.target.closest('[data-customer-id]');
    if(!option) return;
    const row=customers.find((item)=>num(item.id)===num(option.dataset.customerId));
    if(row) selectCustomer(row);
  });
  $('wr-customer-new')?.addEventListener('click',openCustomerDialog);
  $('wr-customer-dialog-close')?.addEventListener('click',()=>$('wr-customer-dialog')?.close());
  $('wr-customer-dialog-cancel')?.addEventListener('click',()=>$('wr-customer-dialog')?.close());
  $('wr-customer-form')?.addEventListener('submit',(e)=>{
    e.preventDefault();
    run(async()=>{
      const result=await saleApi('/customers',{
        code:$('wr-customer-code').value.trim(),
        trade_name:$('wr-customer-trade-name').value.trim(),
        legal_name:$('wr-customer-legal-name').value.trim(),
        tax_id:$('wr-customer-tax-id').value.trim()
      });
      const row=result.customer||result;
      const currentIndex=customers.findIndex((item)=>num(item.id)===num(row.id));
      if(currentIndex>=0) customers[currentIndex]=row; else customers.push(row);
      customers.sort((a,b)=>String(a.trade_name||'').localeCompare(String(b.trade_name||''),'es-MX'));
      selectCustomer(row);
      $('wr-customer-dialog')?.close();
      message('Cliente '+customerText(row)+' registrado y seleccionado.','success');
    });
  });
  document.addEventListener('click',(e)=>{
    if(!e.target.closest('#wr-customer-group')) hideCustomerResults();
  });
  $('wr-required')?.addEventListener('change',updateCaptureDashboard);
  $('wr-production-destination')?.addEventListener('input',updateCaptureDashboard);
  $('wr-observations')?.addEventListener('input',updateCaptureDashboard);
  $('wr-add-line')?.addEventListener('click',()=>{if($('wr-lines').children.length<200)addLine();});
  $('wr-new')?.addEventListener('click',()=>{setWorkView('capture');($('wr-type')?.value==='sale'?$('wr-customer-search'):$('wr-source'))?.focus();});
  $('wr-cancel-create')?.addEventListener('click',()=>setWorkView('list'));
  $('wr-reset-draft')?.addEventListener('click',()=>{if(confirm('¿Limpiar la captura actual? No se ha reservado inventario.'))resetDraft();});
  $('wr-form')?.addEventListener('submit',(e)=>{e.preventDefault();run(async()=>{
    validateDraftStructure();validateDraftAllocations();
    const type=$('wr-type').value;
    const lines=lineRows().map(lineData);
    const commonValid=$('wr-required').value&&lines.length&&!lines.some((l)=>!num(l.species_id)||!num(l.size_grade_id)||!num(l.requested_weight_kg));
    if(type==='sale'){
      if(!cfg.canSale) throw new Error('No tienes permiso para registrar ventas.');
      if(!num($('wr-customer')?.value)||!commonValid) throw new Error('Completa cliente, fecha requerida y todas las líneas antes de crear la venta.');
      const saleLines=lineRows().map((tr)=>({...lineData(tr),stock_unit_ids:[...draftMap(tr).keys()]}));
      const result=await saleApi('',{
        customer_company_id:num($('wr-customer').value),
        required_at:$('wr-required').value,
        observations:$('wr-observations').value.trim(),
        lines:saleLines
      });
      const proposals=num(result.proposed_units);
      resetDraft();
      message('Venta '+result.folio+' creada pendiente de surtido.'+(proposals?' '+proposals+' tina'+(proposals===1?'':'s')+' quedaron registradas sólo como propuesta; almacén podrá cambiarlas.':' No se reservaron tinas; almacén las definirá al surtir.') );
      return;
    }
    const productionDestination=type==='production' ? String($('wr-production-destination')?.value||'').trim() : '';
    if(!num($('wr-source').value)||!commonValid||(type==='production'&&!productionDestination))
      throw new Error(type==='production'
        ?'Completa almacén proveedor, área destino de producción, fecha requerida y todas las líneas antes de enviar.'
        :'Completa los datos generales y todas las líneas antes de enviar.');
    const requestLines=lineRows().map((tr)=>({...lineData(tr),proposal_stock_unit_ids:[...draftMap(tr).keys()]}));
    const proposalCount=requestLines.reduce((sum,line)=>sum+(Array.isArray(line.proposal_stock_unit_ids)?line.proposal_stock_unit_ids.length:0),0);
    detail=await api('',{
      source_warehouse_id:num($('wr-source').value),
      request_type:type,
      production_destination:type==='production'?productionDestination:undefined,
      required_at:$('wr-required').value,
      observations:$('wr-observations').value.trim(),
      lines:requestLines
    });
    unitAfter=detail.next_unit_after;resetDraft();renderDetail();await listing();message('Solicitud enviada. El proveedor debe aceptarla.'+(proposalCount?' '+proposalCount+' tina'+(proposalCount===1?' quedó':'s quedaron')+' registrada'+(proposalCount===1?'':'s')+' sólo como sugerencia; no se reservó inventario.':''));
  });});
  $('wr-list-filter')?.addEventListener('submit',async(e)=>{
    e.preventDefault();
    e.stopImmediatePropagation();
    historyPage=1;
    const button=$('wr-history-filter-submit');
    const originalHtml=button?.innerHTML||'<i class="bi bi-funnel me-1"></i>Filtrar';
    if(button){button.disabled=true;button.innerHTML='<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Procesando';}
    try {
      await run(()=>listing(1));
    } finally {
      if(button){button.disabled=false;button.innerHTML=originalHtml;}
    }
  });
  $('wr-history-per-page')?.addEventListener('change',()=>{historyPage=1;run(()=>listing(1));});
  $('wr-history-pagination')?.addEventListener('click',(e)=>{
    const b=e.target.closest('[data-history-page]'); if(!b||b.disabled)return;
    run(()=>listing(num(b.dataset.historyPage)));
  });
  $('wr-refresh')?.addEventListener('click',()=>run(async()=>{
    if(['outgoing','incoming'].includes(mode)){
      if(mode==='outgoing'&&saleDetail?.order?.id){
        saleDetail=await saleGet('/'+num(saleDetail.order.id));
        renderSaleOutgoingRequest();
        resetUnit(false);
      } else if(detail){
        await loadDetail(detail.request.id);
      } else {
        resetUnit();
      }
      await probeScale();
    }else{
      await listing(historyPage);
      if(detail)await loadDetail(detail.request.id);
      else if(saleDetail?.order?.id)await loadSaleDetail(saleDetail.order.id);
    }
    message('Información actualizada.','info');
  }));
  $('wr-detail')?.addEventListener('change',(e)=>{if(e.target.id==='wr-unit-status')run(async()=>{const r=await api('/'+detail.request.id+query({status:e.target.value}));detail.units=r.units;unitAfter=r.next_unit_after;renderManifest();});});
  $('wr-stock-apply').addEventListener('click',()=>run(async()=>{await loadStockFacets();reloadStock(true);}));
  $('wr-stock-search').addEventListener('keydown',(e)=>{if(e.key==='Enter'){e.preventDefault();reloadStock(true);}});
  $('wr-stock-lot').addEventListener('change',()=>run(async()=>{await loadStockFacets(num($('wr-stock-lot').value),num($('wr-stock-owner').value));reloadStock(true);}));
  $('wr-stock-owner').addEventListener('change',()=>run(async()=>{await loadStockFacets(num($('wr-stock-lot').value),num($('wr-stock-owner').value));reloadStock(true);}));
  $('wr-stock-close').addEventListener('click',()=>{$('wr-stock-dialog').close();});
  $('wr-stock-done')?.addEventListener('click',()=>{$('wr-stock-dialog').close();updateCaptureDashboard();});
  $('wr-dialog-close').addEventListener('click',closeDialog);$('wr-dialog-cancel').addEventListener('click',closeDialog);
  $('wr-dialog-form').addEventListener('submit',async(e)=>{
    e.preventDefault();
    e.stopPropagation();
    const submit=$('wr-dialog-submit');
    const original=submit?.textContent||'Confirmar';
    if(submit){
      submit.disabled=true;
      submit.setAttribute('aria-disabled','true');
      submit.innerHTML='<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Procesando…';
    }
    try{
      await run(async()=>{if(dialogAction)await dialogAction();});
    } finally {
      if(submit){
        submit.innerHTML=esc(original);
        resetDialogSubmit();
      }
    }
  });
  $('wr-scan')?.addEventListener('submit',(e)=>{
    e.preventDefault(); e.stopPropagation();
    const button=e.currentTarget.querySelector('[type="submit"]'), original=button?.innerHTML;
    run(async()=>{
      if(button){button.disabled=true;button.innerHTML='<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span class="visually-hidden">Identificando tina…</span>';}
      try{await scan();}finally{if(button){button.disabled=false;button.innerHTML=original;}}
    });
  });
  $('wr-read')?.addEventListener('click',()=>run(readWeight));
  $('wr-test-mode')?.addEventListener('change',()=>{
    syncTestWeightMode(true);
    if(testWeightMode()) $('wr-test-weight')?.focus();
    else run(probeScale);
  });
  $('wr-test-weight')?.addEventListener('input',()=>{
    if(!testWeightMode()) return;
    reading=null;
    if($('wr-weight')) $('wr-weight').textContent='—';
    if($('wr-weight-raw')) $('wr-weight-raw').textContent='PRUEBA · peso modificado; pulsa Usar peso de prueba.';
  });
  $('wr-scale')?.addEventListener('change',()=>{reading=null;if($('wr-weight'))$('wr-weight').textContent=['outgoing','incoming'].includes(mode)?'—':'Sin lectura';if($('wr-weight-raw'))$('wr-weight-raw').textContent=testWeightMode()?'PRUEBA · pulsa Usar peso de prueba.':'Sin lectura';if(!testWeightMode())run(probeScale);});
  $('wr-chamber')?.addEventListener('change',()=>{reading=null;fillRows($('wr-chamber').value);});
  $('wr-unit-fields')?.addEventListener('click',(e)=>{
    const choice=e.target.closest('[data-receive-decision]');
    if(!choice||!$('wr-decision')) return;
    $('wr-decision').value=choice.dataset.receiveDecision;
    unitFields();
  });
  $('wr-unit-fields')?.addEventListener('change',(e)=>{if(e.target.id==='wr-weigh-return')unitFields();});
  $('wr-unit-form')?.addEventListener('submit',(e)=>{
    e.preventDefault(); e.stopPropagation();
    const button=$('wr-unit-submit'), original=button?.innerHTML;
    run(async()=>{
      if(button){button.disabled=true;button.innerHTML='<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Registrando…';}
      try{await saveUnit();}finally{if(button){button.disabled=false;button.innerHTML=original;}}
    });
  });
  if(mode==='requests'&&cfg.canCapture){const d=new Date(Date.now()+3600000);d.setMinutes(d.getMinutes()-d.getTimezoneOffset());if(!$('wr-required').value)$('wr-required').value=d.toISOString().slice(0,16);const allowed=allowedRequestTypes();setRequestType(allowed.includes('transfer')?'transfer':(allowed[0]||'transfer'));setWorkView('capture');updateCaptureDashboard();}
  run(async()=>{
    if(['outgoing','incoming'].includes(mode)){resetUnit(true);await probeScale();$('wr-qr')?.focus();return;}
    await listing();const id=new URLSearchParams(location.search).get('request');if(id)await loadDetail(id);
  });
})();
