(() => {
  'use strict';

  const source = document.querySelector('#movementContext');
  let context = { scope: {} };
  try { context = source ? JSON.parse(source.textContent || '{}') : context; context.scope = context.scope || {}; }
  catch (error) { console.error('No fue posible cargar el contexto de movimientos.', error); }

  const form = document.querySelector('[data-movement-form]');
  const typeInput = form?.querySelector('[name="movement_type"]');
  const unitInput = form?.querySelector('[name="stock_unit_id"]');
  const chamberInput = form?.querySelector('[name="chamber_id"]');
  const locationInput = form?.querySelector('[name="to_location_id"]');
  const quantityField = form?.querySelector('[data-quantity-field]');
  const relocationField = form?.querySelector('[data-relocation-field]');
  const summary = form?.querySelector('[data-unit-summary]');
  const movementModal = document.querySelector('#movementModal');
  let selectedUnitData = null;

  const fmt = (value) => Number(value || 0).toLocaleString('es-MX', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const labelType = (type) => ({receipt:'Recepción',relocation:'Reubicación',return:'Devolución',loss:'Merma',adjustment_increase:'Ajuste positivo',adjustment_decrease:'Ajuste negativo',reservation:'Reserva',reservation_release:'Liberación reserva',production_issue:'Surtido',sale_dispatch:'Salida venta',transfer_out:'Traspaso salida',transfer_in:'Traspaso entrada',reversal:'Reverso'}[type] || type);
  const statusLabel = (status) => ({available:'Disponible',reserved:'Reservado',held:'Retenido',rejected:'Rechazado',in_transit:'En tránsito',received:'Recibido',dispatched:'Despachado',exhausted:'Agotado'}[status] || status || '—');
  const reasonLabel = (reason) => ({
    quality_inspection_annulment: 'Anulación de inspección de calidad',
    quality_disposition: 'Dictamen de calidad',
    relocation: 'Reubicación operativa',
  }[String(reason || '')] || String(reason || '—').replaceAll('_', ' '));
  const sourceLabel = (source) => ({
    quality_inspection_annulment: 'Inspección de calidad anulada',
    quality_inspection: 'Inspección de calidad',
    reception: 'Recepción',
    transfer: 'Traspaso',
  }[String(source || '')] || String(source || '—').replaceAll('_', ' '));

  const resetDestination = () => {
    if (chamberInput) chamberInput.innerHTML = '<option value="">Selecciona cámara...</option>';
    if (locationInput) { locationInput.innerHTML = '<option value="">Selecciona cámara...</option>'; locationInput.disabled = true; }
  };

  const paintUnit = () => {
    const unit = selectedUnitData;
    if (!summary) return;
    if (!unit) { summary.classList.add('d-none'); resetDestination(); return; }
    summary.classList.remove('d-none');
    summary.querySelector('[data-unit-product]').textContent = `${unit.species_name || '—'} · ${unit.size_name || '—'}`;
    summary.querySelector('[data-unit-lot]').textContent = unit.lot_code || '—';
    summary.querySelector('[data-unit-status]').textContent = unit.status_label || statusLabel(unit.status);
    summary.querySelector('[data-unit-weight]').textContent = `${fmt(unit.on_hand_weight_kg)} kg`;
    summary.querySelector('[data-unit-available]').textContent = `${fmt(unit.reserved_weight_kg)} / ${fmt(Number(unit.on_hand_weight_kg) - Number(unit.reserved_weight_kg))} kg`;
    summary.querySelector('[data-unit-location]').textContent = unit.location_display || unit.sector_name || '—';
  };

  const loadChambers = async () => {
    resetDestination();
    if (!selectedUnitData || !chamberInput) return;
    chamberInput.disabled = true;
    chamberInput.innerHTML = '<option value="">Consultando cámaras...</option>';
    try {
      const response = await GMar.api(`/internal/v1/inventory/movements/warehouses/${Number(selectedUnitData.warehouse_id)}/chambers`);
      const rows = Array.isArray(response.items) ? response.items : [];
      chamberInput.innerHTML = '<option value="">Selecciona cámara...</option>' + rows.map((row) => `<option value="${Number(row.id)}">${esc(row.name || row.code)}</option>`).join('');
      chamberInput.disabled = rows.length === 0;
    } catch (error) {
      chamberInput.innerHTML = '<option value="">No fue posible consultar cámaras</option>';
      chamberInput.disabled = true;
      await Swal.fire({ icon: 'error', title: 'Cámaras no disponibles', text: error.message });
    }
  };

  const loadSectors = async () => {
    if (!locationInput) return;
    const chamberId = Number(chamberInput?.value || 0);
    if (!chamberId) { locationInput.innerHTML = '<option value="">Selecciona cámara...</option>'; locationInput.disabled = true; return; }
    locationInput.disabled = true;
    locationInput.innerHTML = '<option value="">Consultando filas...</option>';
    try {
      const response = await GMar.api(`/internal/v1/inventory/movements/chambers/${chamberId}/sectors`);
      const rows = Array.isArray(response.items) ? response.items : [];
      locationInput.innerHTML = '<option value="">Selecciona fila...</option>' + rows.map((row) => {
        const disabled = Number(row.available || 0) <= 0 ? ' disabled' : '';
        return `<option value="${Number(row.id)}"${disabled}>${esc(row.display_name || row.name || row.code)}</option>`;
      }).join('');
      locationInput.disabled = rows.length === 0;
    } catch (error) {
      locationInput.innerHTML = '<option value="">No fue posible consultar filas</option>';
      locationInput.disabled = true;
      await Swal.fire({ icon: 'error', title: 'Filas no disponibles', text: error.message });
    }
  };

  const paintType = () => {
    if (!form || !typeInput) return;
    const isRelocation = typeInput.value === 'relocation';
    relocationField?.classList.toggle('d-none', !isRelocation);
    quantityField?.classList.toggle('d-none', isRelocation);
    const qty = form.querySelector('[name="quantity_kg"]');
    if (qty) { qty.required = !isRelocation; if (isRelocation) qty.value = ''; }
    if (chamberInput) chamberInput.required = isRelocation;
    if (locationInput) locationInput.required = isRelocation;
  };

  const initUnitSelector = () => {
    if (!unitInput || typeof window.jQuery === 'undefined' || !window.jQuery.fn?.select2) return;
    const $unit = window.jQuery(unitInput);
    if ($unit.hasClass('select2-hidden-accessible')) $unit.select2('destroy');
    $unit.select2({
      width: '100%',
      placeholder: 'Buscar tina, marbete o lote...',
      allowClear: true,
      minimumInputLength: 2,
      dropdownParent: movementModal ? window.jQuery(movementModal) : window.jQuery(document.body),
      ajax: {
        delay: 250,
        transport: (params, success, failure) => {
          const term = String(params.data?.term || '').trim();
          let aborted = false;
          GMar.api(`/internal/v1/inventory/movements/units/search?q=${encodeURIComponent(term)}`)
            .then((data) => { if (!aborted) success(data); })
            .catch((error) => { if (!aborted) failure(error); });
          return { abort: () => { aborted = true; } };
        },
        processResults: (data) => ({ results: (data.items || []).map((item) => ({ id: item.id, text: item.text, unit: item })) }),
      },
      language: {
        inputTooShort: () => 'Escribe al menos 2 caracteres',
        noResults: () => 'No se encontraron tinas',
        searching: () => 'Buscando...',
      },
    });
    $unit.off('.gmarMovement');
    $unit.on('select2:select.gmarMovement', async (event) => {
      selectedUnitData = event.params?.data?.unit || null;
      paintUnit();
      await loadChambers();
    });
    $unit.on('select2:clear.gmarMovement', () => { selectedUnitData = null; paintUnit(); });
  };

  typeInput?.addEventListener('change', paintType);
  chamberInput?.addEventListener('change', loadSectors);
  movementModal?.addEventListener('shown.bs.modal', () => { initUnitSelector(); paintType(); paintUnit(); });
  movementModal?.addEventListener('hidden.bs.modal', () => {
    selectedUnitData = null;
    if (unitInput && window.jQuery?.fn?.select2) window.jQuery(unitInput).val(null).trigger('change');
    paintUnit();
  });
  initUnitSelector();
  paintType();

  form?.addEventListener('submit', async (event) => {
    event.preventDefault(); event.stopImmediatePropagation();
    const values = new FormData(form); const unit = selectedUnitData;
    if (!unit) return Swal.fire({ icon: 'warning', text: 'Selecciona una tina.' });
    const type = String(values.get('movement_type'));
    const quantity = type === 'relocation' ? Number(unit.on_hand_weight_kg) : Number(values.get('quantity_kg'));
    if (!Number.isFinite(quantity) || quantity <= 0) return Swal.fire({ icon: 'warning', text: 'Captura una cantidad válida.' });
    const line = { stock_unit_id: Number(unit.id), quantity_kg: quantity, from_location_id: Number(unit.location_id), from_warehouse_id: Number(unit.warehouse_id) };
    if (type === 'relocation') {
      if (!values.get('chamber_id') || !values.get('to_location_id')) return Swal.fire({ icon: 'warning', text: 'Selecciona Cámara y Fila para la nueva ubicación.' });
      line.to_location_id = Number(values.get('to_location_id'));
      line.to_warehouse_id = Number(unit.warehouse_id);
    }
    const reason = String(values.get('reason_code') || '').trim();
    if (['loss', 'adjustment_increase', 'adjustment_decrease'].includes(type) && !reason) return Swal.fire({ icon: 'warning', text: 'Captura el motivo del ajuste.' });
    const payload = { movement_type: type, company_id: Number(context.scope.company_id), site_id: Number(context.scope.site_id), origin_warehouse_id: Number(unit.warehouse_id), destination_warehouse_id: type === 'relocation' ? Number(unit.warehouse_id) : null, reason_code: reason || null, observations: String(values.get('observations') || '').trim() || null, lines: [line] };
    const chamberText = chamberInput?.selectedOptions?.[0]?.textContent || '';
    const sectorText = locationInput?.selectedOptions?.[0]?.textContent || '';
    const destination = type === 'relocation' ? `${chamberText} · ${sectorText}` : '';
    const confirmation = await Swal.fire({ icon: type === 'relocation' ? 'question' : 'warning', title: `Confirmar ${labelType(type)}`, html: `<div class="text-start"><strong>${esc(unit.tub_code || unit.unit_code)}</strong><br>${fmt(quantity)} kg${destination ? `<br><span class="text-secondary">Destino: ${esc(destination)}</span>` : ''}</div>`, showCancelButton: true, confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar' });
    if (!confirmation.isConfirmed) return;
    try {
      const result = await GMar.api('/internal/v1/inventory/movements', { method: 'POST', body: JSON.stringify(payload) });
      await Swal.fire({ icon: 'success', title: 'Movimiento confirmado', text: `Documento #${result.document_id}` });
      location.reload();
    } catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Movimiento rechazado', text: error.message }); }
  });

  document.querySelectorAll('[data-movement-view]').forEach((button) => button.addEventListener('click', async () => {
    const id = button.dataset.movementView; const modalElement = document.querySelector('#movementDetailModal'); const body = modalElement.querySelector('[data-movement-detail]'); const subtitle = modalElement.querySelector('[data-detail-subtitle]');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement); body.innerHTML = '<div class="text-center py-5"><div class="spinner-border" role="status"></div></div>'; subtitle.textContent = ''; modal.show();
    try {
      const result = await GMar.api(`/internal/v1/inventory/movements/${id}`); const d = result.document; subtitle.textContent = `${d.folio} · ${labelType(d.movement_type)}`;
      const rows = result.lines.map((l) => `<tr><td><strong>${esc(l.unit_code)}</strong><small>${esc(l.lot_code)}</small></td><td><strong>${esc(l.species_name)}</strong><small>${esc(l.size_name)}</small></td><td class="movement-detail-number">${fmt(l.quantity_kg)} kg</td><td>${esc(l.from_location_path || '—')}</td><td>${esc(l.to_location_path || '—')}</td><td><span class="movement-status-change"><span>${esc(l.stock_status_before_label || statusLabel(l.stock_status_before))}</span><i class="bi bi-arrow-right"></i><span>${esc(l.stock_status_after_label || statusLabel(l.stock_status_after))}</span></span></td><td class="movement-detail-number">${fmt(l.resulting_on_hand_kg)} kg</td></tr>`).join('');
      const sourceDocument = `${sourceLabel(d.source_document_type)}${d.source_document_id ? ` · #${esc(d.source_document_id)}` : ''}`;
      body.innerHTML = `<div class="movement-detail-summary">
        <div class="movement-detail-stat"><span><i class="bi bi-calendar3"></i> Fecha</span><strong>${esc(d.confirmed_at || '—')}</strong></div>
        <div class="movement-detail-stat"><span><i class="bi bi-tag"></i> Motivo</span><strong>${esc(reasonLabel(d.reason_code))}</strong></div>
        <div class="movement-detail-stat"><span><i class="bi bi-person"></i> Usuario</span><strong>#${esc(d.confirmed_by || '—')}</strong></div>
        <div class="movement-detail-stat"><span><i class="bi bi-file-earmark-text"></i> Documento origen</span><strong>${sourceDocument}</strong></div>
      </div>
      <div class="movement-detail-observations"><span>Observaciones</span><strong>${esc(d.observations || 'Sin observaciones')}</strong></div>
      <div class="movement-detail-table-title"><div><strong>Detalle de unidades</strong><small>${result.lines.length} línea(s) registradas</small></div></div>
      <div class="table-responsive movement-detail-scroll"><table class="table align-middle movement-detail-table"><thead><tr><th>Tina / lote</th><th>Producto</th><th>Cantidad</th><th>Origen</th><th>Destino</th><th>Estado</th><th>Saldo resultante</th></tr></thead><tbody>${rows || '<tr><td colspan="7" class="text-center text-secondary py-4">Sin detalles.</td></tr>'}</tbody></table></div>`;
    } catch (error) { body.innerHTML = `<div class="alert alert-danger">${esc(error.message || 'No fue posible cargar el movimiento.')}</div>`; }
  }));

  document.querySelectorAll('[data-movement-reverse]').forEach((button) => button.addEventListener('click', async () => {
    const prompt = await Swal.fire({ icon: 'warning', title: 'Reversar documento', text: 'Se generará un documento compensatorio. El original no se modifica.', input: 'textarea', inputPlaceholder: 'Motivo documentado', showCancelButton: true, confirmButtonText: 'Reversar', cancelButtonText: 'Cancelar', inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres' });
    if (!prompt.isConfirmed) return;
    try { const result = await GMar.api(`/internal/v1/inventory/movements/${button.dataset.movementReverse}/reverse`, { method: 'POST', body: JSON.stringify({ reason: prompt.value.trim() }) }); await Swal.fire({ icon: 'success', title: `Reverso #${result.document_id}` }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Reverso rechazado', text: error.message }); }
  }));
})();
