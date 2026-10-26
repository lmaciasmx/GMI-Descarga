(() => {
  'use strict';

  const contextNode = document.querySelector('#transferContext');
  if (!contextNode) return;
  const context = JSON.parse(contextNode.textContent || '{}');
  const $ = window.jQuery;
  const transferModalEl = document.querySelector('#transferModal');
  const detailModalEl = document.querySelector('#transferDetailModal');
  const receiveModalEl = document.querySelector('#transferReceiveModal');
  const detailModal = detailModalEl ? bootstrap.Modal.getOrCreateInstance(detailModalEl) : null;
  const receiveModal = receiveModalEl ? bootstrap.Modal.getOrCreateInstance(receiveModalEl) : null;
  const form = document.querySelector('[data-transfer-form]');
  const receiveForm = document.querySelector('[data-receive-form]');
  const selectedBody = document.querySelector('[data-transfer-selected-units]');
  const selectedCount = document.querySelector('[data-selected-count]');
  const unitSearch = document.querySelector('[data-transfer-unit-search]');
  const selectedUnits = new Map();

  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const fmtKg = (value) => Number(value || 0).toLocaleString('es-MX', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  const statusBadge = (status, label) => `<span class="badge-state ${esc(status)}">${esc(label || status)}</span>`;

  function currentOrigin() { return Number(form?.elements.origin_warehouse_id?.value || 0); }
  function currentDestination() { return Number(form?.elements.destination_warehouse_id?.value || 0); }

  function renderSelectedUnits() {
    if (!selectedBody) return;
    selectedCount.textContent = String(selectedUnits.size);
    if (!selectedUnits.size) {
      selectedBody.innerHTML = '<tr data-empty-row><td colspan="7" class="text-center text-secondary py-4">Aún no has agregado tinas.</td></tr>';
      return;
    }
    selectedBody.innerHTML = [...selectedUnits.values()].map((u) => `
      <tr>
        <td class="fw-semibold">${esc(u.display_code || u.unit_code)}</td>
        <td>${esc(u.lot_code)}</td>
        <td>${esc(u.species_name)}</td>
        <td>${esc(u.size_name)}</td>
        <td>${esc(u.location_path || '—')}</td>
        <td class="text-end">${fmtKg(u.on_hand_weight_kg)}</td>
        <td class="text-end"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-unit="${Number(u.id)}"><i class="bi bi-x-lg"></i></button></td>
      </tr>`).join('');
  }

  function initUnitAutocomplete() {
    if (!unitSearch || !$ || !$.fn.select2) return;
    const $search = $(unitSearch);
    $search.select2({
      width: '100%', dropdownParent: $(transferModalEl), placeholder: 'Escanea o busca una tina…', minimumInputLength: 2,
      ajax: {
        delay: 250,
        transport: async (params, success, failure) => {
          try {
            const q = params.data?.term || '';
            const origin = currentOrigin();
            if (!origin) return success({ items: [] });
            const result = await GMar.api(`/internal/v1/transfers/units/search?q=${encodeURIComponent(q)}&origin_warehouse_id=${origin}`);
            success(result);
          } catch (e) { failure(e); }
        },
        processResults: (data) => ({ results: (data.items || []).map((item) => ({ id: item.id, text: item.text, raw: item })) }),
      },
      templateResult: (item) => item.loading ? item.text : $('<div>').text(item.text),
      templateSelection: (item) => item.text || '',
    });
    $search.on('select2:select', (event) => {
      const item = event.params.data.raw;
      if (item && !selectedUnits.has(Number(item.id))) selectedUnits.set(Number(item.id), item);
      renderSelectedUnits();
      $search.val(null).trigger('change');
    });
  }

  form?.elements.origin_warehouse_id?.addEventListener('change', () => {
    selectedUnits.clear(); renderSelectedUnits();
    if (unitSearch) unitSearch.disabled = !currentOrigin();
    if ($ && $.fn.select2 && unitSearch) $(unitSearch).val(null).trigger('change');
  });
  form?.elements.destination_warehouse_id?.addEventListener('change', () => {
    if (currentOrigin() && currentOrigin() === currentDestination()) {
      form.elements.destination_warehouse_id.value = '';
      Swal.fire({ icon: 'warning', text: 'El almacén destino debe ser distinto del origen.' });
    }
  });
  selectedBody?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-unit]');
    if (!button) return;
    selectedUnits.delete(Number(button.dataset.removeUnit)); renderSelectedUnits();
  });

  form?.addEventListener('submit', async (event) => {
    event.preventDefault(); event.stopImmediatePropagation();
    if (!currentOrigin() || !currentDestination() || currentOrigin() === currentDestination()) return Swal.fire({ icon: 'warning', text: 'Selecciona almacenes origen y destino distintos.' });
    if (!selectedUnits.size) return Swal.fire({ icon: 'warning', text: 'Agrega al menos una tina al traspaso.' });
    const values = new FormData(form);
    const payload = {
      company_id: Number(context.companyId), origin_warehouse_id: currentOrigin(), destination_warehouse_id: currentDestination(),
      transfer_type: 'administrative', stock_unit_ids: [...selectedUnits.keys()], carrier_reference: values.get('carrier_reference') || null, observations: values.get('observations') || null,
    };
    try {
      const result = await GMar.api('/internal/v1/transfers', { method: 'POST', body: JSON.stringify(payload) });
      await Swal.fire({ icon: 'success', title: 'Traspaso administrativo solicitado', text: result.folio }); location.reload();
    } catch (error) { Swal.fire({ icon: 'error', title: error.code || 'No se creó el traspaso', text: error.message }); }
  });

  async function loadTransfer(id) { return GMar.api(`/internal/v1/transfers/${id}`); }

  async function showDetail(id) {
    detailModal?.show();
    const body = document.querySelector('[data-transfer-detail]');
    body.innerHTML = '<div class="text-center text-secondary py-5"><div class="spinner-border spinner-border-sm me-2"></div>Cargando…</div>';
    try {
      const result = await loadTransfer(id); const t = result.transfer; const lines = result.lines || [];
      document.querySelector('[data-detail-title]').textContent = t.folio;
      document.querySelector('[data-detail-subtitle]').textContent = `${t.origin_name} → ${t.destination_name}`;
      body.innerHTML = `
        <div class="row g-3 mb-4">
          <div class="col-md-3"><div class="text-secondary small">Estado</div><div>${statusBadge(t.status, t.status_label)}</div></div>
          <div class="col-md-3"><div class="text-secondary small">Tinas</div><div class="fw-semibold">${Number(t.unit_count)}</div></div>
          <div class="col-md-3"><div class="text-secondary small">Solicitado</div><div>${esc(t.requested_at)}</div></div>
          <div class="col-md-3"><div class="text-secondary small">Referencia</div><div>${esc(t.carrier_reference || '—')}</div></div>
          <div class="col-md-3"><div class="text-secondary small">Tipo</div><div>${t.transfer_type === 'request' ? '<span class="badge text-bg-primary">Por solicitud</span>' : '<span class="badge text-bg-secondary">Administrativo</span>'}</div></div>
          <div class="col-md-3"><div class="text-secondary small">Solicitud</div><div>${esc(t.request_folio || '—')}</div></div>
          <div class="col-md-3"><div class="text-secondary small">Reserva</div><div>${esc(t.reservation_folio || '—')}</div></div>
          <div class="col-md-6"><div class="text-secondary small">Origen</div><div class="fw-semibold">${esc(t.origin_code)} · ${esc(t.origin_name)}</div></div>
          <div class="col-md-6"><div class="text-secondary small">Destino</div><div class="fw-semibold">${esc(t.destination_code)} · ${esc(t.destination_name)}</div></div>
          <div class="col-md-4"><div class="text-secondary small">Embarcado</div><div>${esc(t.shipped_at || '—')}</div></div>
          <div class="col-md-4"><div class="text-secondary small">Recibido</div><div>${esc(t.received_at || '—')}</div></div>
          <div class="col-md-4"><div class="text-secondary small">No recibidas</div><div class="fw-semibold">${Number(t.missing_count || 0)}</div></div>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>#</th><th>Tina</th><th>Lote</th><th>Especie</th><th>Talla</th><th class="text-end">Peso kg</th><th>Estado</th></tr></thead><tbody>
          ${lines.map((l) => `<tr><td>${Number(l.line_number)}</td><td class="fw-semibold">${esc(l.display_code)}</td><td>${esc(l.lot_code)}</td><td>${esc(l.species_name)}</td><td>${esc(l.size_name)}</td><td class="text-end">${fmtKg(l.shipped_weight_kg)}</td><td>${esc(l.status_label)}</td></tr>`).join('')}
        </tbody></table></div>
        ${t.observations ? `<div class="mt-3"><div class="text-secondary small">Observaciones</div><div>${esc(t.observations)}</div></div>` : ''}`;
    } catch (error) { body.innerHTML = `<div class="alert alert-danger mb-0">${esc(error.message || 'No fue posible consultar el traspaso.')}</div>`; }
  }

  async function fillChambers(warehouseId) {
    const chamber = receiveForm.elements.chamber_id; const row = receiveForm.elements.row_location_id;
    chamber.innerHTML = '<option value="">Cargando…</option>'; row.innerHTML = '<option value="">Seleccione cámara…</option>'; row.disabled = true;
    const data = await GMar.api(`/internal/v1/transfers/warehouses/${warehouseId}/chambers`);
    chamber.innerHTML = '<option value="">Seleccione…</option>' + (data.items || []).map((x) => `<option value="${Number(x.id)}">${esc(x.code ? `${x.code} · ${x.name}` : x.name)}</option>`).join('');
  }

  async function fillRows(chamberId) {
    const row = receiveForm.elements.row_location_id;
    row.innerHTML = '<option value="">Cargando…</option>'; row.disabled = true;
    if (!chamberId) { row.innerHTML = '<option value="">Seleccione cámara…</option>'; return; }
    const data = await GMar.api(`/internal/v1/transfers/chambers/${chamberId}/sectors`);
    const items = (data.items || []).filter((x) => x.available !== false && Number(x.available_positions ?? x.available_count ?? 1) > 0);
    row.innerHTML = '<option value="">Seleccione…</option>' + items.map((x) => `<option value="${Number(x.id)}">${esc(x.display_name || x.name || x.code)}</option>`).join('');
    row.disabled = false;
  }

  receiveForm?.elements.chamber_id?.addEventListener('change', () => fillRows(Number(receiveForm.elements.chamber_id.value || 0)).catch((e) => Swal.fire({ icon: 'error', text: e.message })));
  document.querySelector('[data-receive-all]')?.addEventListener('click', () => document.querySelectorAll('[data-receive-unit]:not(:disabled)').forEach((c) => { c.checked = true; }));

  async function openReceive(id, destinationWarehouseId) {
    try {
      const result = await loadTransfer(id); const t = result.transfer; const lines = result.lines || [];
      receiveForm.reset(); receiveForm.elements.transfer_id.value = id; receiveForm.elements.destination_warehouse_id.value = destinationWarehouseId;
      document.querySelector('[data-receive-folio]').textContent = `${t.folio} · ${t.origin_name} → ${t.destination_name}`;
      const pending = lines.filter((l) => ['shipped', 'missing'].includes(l.status));
      document.querySelector('[data-receive-lines]').innerHTML = pending.map((l) => `
        <tr><td><input class="form-check-input" type="checkbox" data-receive-unit value="${Number(l.stock_unit_id)}" ${l.status === 'shipped' ? 'checked' : ''}></td>
        <td class="fw-semibold">${esc(l.display_code)}</td><td>${esc(l.lot_code)}</td><td>${esc(l.species_name)}</td><td>${esc(l.size_name)}</td><td>${fmtKg(l.shipped_weight_kg)}</td><td>${esc(l.status_label)}</td></tr>`).join('');
      await fillChambers(destinationWarehouseId); receiveModal?.show();
    } catch (error) { Swal.fire({ icon: 'error', title: 'No se pudo abrir la recepción', text: error.message }); }
  }

  receiveForm?.addEventListener('submit', async (event) => {
    event.preventDefault(); event.stopImmediatePropagation();
    const id = Number(receiveForm.elements.transfer_id.value); const rowId = Number(receiveForm.elements.row_location_id.value || 0);
    const received = [...document.querySelectorAll('[data-receive-unit]:checked')].map((x) => Number(x.value));
    if (!rowId) return Swal.fire({ icon: 'warning', text: 'Selecciona Cámara y Fila de recepción.' });
    if (!received.length) return Swal.fire({ icon: 'warning', text: 'Marca al menos una tina como recibida.' });
    const totalPending = document.querySelectorAll('[data-receive-unit]').length;
    const missing = totalPending - received.length;
    const confirm = await Swal.fire({
      icon: missing > 0 ? 'warning' : 'question', title: 'Confirmar recepción',
      html: missing > 0 ? `Se recibirán <strong>${received.length}</strong> tinas y <strong>${missing}</strong> quedarán como no recibidas.` : `Se recibirán <strong>${received.length}</strong> tinas.`,
      showCancelButton: true, confirmButtonText: 'Confirmar recepción', cancelButtonText: 'Cancelar'
    });
    if (!confirm.isConfirmed) return;
    try {
      const result = await GMar.api(`/internal/v1/transfers/${id}/receive`, { method: 'POST', body: JSON.stringify({ row_location_id: rowId, received_stock_unit_ids: received }) });
      await Swal.fire({ icon: result.status === 'received' ? 'success' : 'warning', title: result.status === 'received' ? 'Traspaso recibido' : 'Recepción con diferencias', text: result.missing_count ? `${result.missing_count} tina(s) quedaron pendientes de recibir.` : 'Recepción completada.' });
      location.reload();
    } catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Recepción rechazada', text: error.message }); }
  });

  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-transfer-action]'); if (!button) return;
    const id = Number(button.dataset.id); const action = button.dataset.transferAction;
    if (action === 'view') return showDetail(id);
    if (action === 'receive') return openReceive(id, Number(button.dataset.destination));

    let url = ''; let payload = {};
    if (action === 'authorize') { url = `/internal/v1/transfers/${id}/authorize`; payload = { approved: true }; }
    if (action === 'ship') { url = `/internal/v1/transfers/${id}/ship`; }
    const confirmation = await Swal.fire({ icon: 'question', title: action === 'authorize' ? 'Autorizar traspaso' : 'Confirmar embarque', text: action === 'ship' ? 'Las tinas pasarán a estado En tránsito.' : 'El traspaso quedará disponible para embarque.', showCancelButton: true, confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar' });
    if (!confirmation.isConfirmed) return;
    try { await GMar.api(url, { method: 'POST', body: JSON.stringify(payload) }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Operación rechazada', text: error.message }); }
  });

  transferModalEl?.addEventListener('hidden.bs.modal', () => { form?.reset(); selectedUnits.clear(); renderSelectedUnits(); if (unitSearch) unitSearch.disabled = true; if ($ && $.fn.select2 && unitSearch) $(unitSearch).val(null).trigger('change'); });

  initUnitAutocomplete(); renderSelectedUnits();
})();
