(() => {
  'use strict';
  const contextEl = document.querySelector('#countContext');
  const context = contextEl ? JSON.parse(contextEl.textContent) : null;
  const rows = JSON.parse(document.querySelector('#countRows')?.textContent || '[]');
  const labels = { pending: 'Pendiente', match: 'Correcta', relocated: 'Reubicada', relocation_applied: 'Reubicación aplicada', missing: 'No encontrada', unexpected: 'No esperada', not_recorded: 'Sin registrar' };
  const startForm = document.querySelector('[data-count-start]');
  const warehouse = document.querySelector('#countWarehouse');
  const chamber = document.querySelector('#countChamber');
  const scanner = document.querySelector('#countScanner');
  const card = document.querySelector('#countUnitCard');
  const actualChamber = document.querySelector('#countActualChamber');
  const actualRow = document.querySelector('#countActualRow');
  let currentUnit = null;

  warehouse?.addEventListener('change', () => {
    Array.from(chamber.options).forEach((option) => { option.hidden = option.dataset.warehouse && option.dataset.warehouse !== warehouse.value; });
    if (chamber.selectedOptions[0]?.hidden) chamber.value = '';
  });

  startForm?.addEventListener('submit', async (event) => {
    event.preventDefault(); event.stopImmediatePropagation();
    const values = new FormData(startForm);
    const payload = { warehouse_id: Number(values.get('warehouse_id')), observations: String(values.get('observations') || '').trim() };
    if (values.get('chamber_id')) payload.chamber_id = Number(values.get('chamber_id'));
    try {
      const result = await GMar.api('/internal/v1/inventory/counts', { method: 'POST', body: JSON.stringify(payload) });
      location.href = `/inventario/conteos?count=${result.id}`;
    } catch (error) { Swal.fire({ icon: 'error', title: error.code || 'No se inició el conteo', text: error.message }); }
  });

  const chamberGroups = () => {
    const map = new Map();
    rows.forEach((row) => { if (!map.has(Number(row.chamber_id))) map.set(Number(row.chamber_id), { id: Number(row.chamber_id), name: row.chamber_name || row.chamber_code || `Cámara ${row.chamber_id}` }); });
    return Array.from(map.values());
  };
  const loadChambers = (selected = null) => {
    if (!actualChamber) return;
    actualChamber.innerHTML = '<option value="">Seleccione…</option>' + chamberGroups().map((c) => `<option value="${c.id}">${GMar.escapeHtml ? GMar.escapeHtml(c.name) : c.name}</option>`).join('');
    if (selected) actualChamber.value = String(selected);
    loadRows();
  };
  const loadRows = (selected = null) => {
    if (!actualRow) return;
    const chamberId = Number(actualChamber?.value || 0);
    const filtered = rows.filter((row) => !chamberId || Number(row.chamber_id) === chamberId);
    actualRow.innerHTML = '<option value="">Seleccione…</option>' + filtered.map((row) => `<option value="${row.id}">${String(row.name || row.code || `Fila ${row.id}`)}</option>`).join('');
    if (selected) actualRow.value = String(selected);
  };
  actualChamber?.addEventListener('change', () => loadRows());
  loadChambers();

  const showUnit = (unit) => {
    currentUnit = unit;
    card?.classList.remove('d-none');
    document.querySelector('[data-unit-title]').textContent = unit.tub_code || unit.unit_code;
    document.querySelector('[data-unit-subtitle]').textContent = unit.expected ? 'Tina esperada en la fotografía' : 'Tina no esperada en la fotografía';
    document.querySelector('[data-unit-species]').textContent = unit.species || '—';
    document.querySelector('[data-unit-size]').textContent = unit.size || '—';
    document.querySelector('[data-unit-lot]').textContent = unit.lot_code || '—';
    document.querySelector('[data-unit-expected]').textContent = unit.expected_row ? `${unit.expected_row.chamber} · ${unit.expected_row.name}` : 'No esperada';
    document.querySelector('[data-unit-result]').textContent = labels[unit.result] || unit.result;
    document.querySelector('[data-count-missing]')?.classList.toggle('d-none', !unit.expected);
    const chamberId = unit.counted_row?.chamber_id || unit.expected_row?.chamber_id || null;
    const rowId = unit.counted_row?.id || unit.expected_row?.id || null;
    loadChambers(chamberId); loadRows(rowId);
  };

  const lookup = async () => {
    const code = String(scanner?.value || '').trim();
    if (!code || !context) return;
    try {
      const unit = await GMar.api(`/internal/v1/inventory/counts/${context.id}/lookup`, { method: 'POST', body: JSON.stringify({ code }) });
      showUnit(unit); scanner.value = '';
    } catch (error) { Swal.fire({ icon: 'warning', title: 'Tina no localizada', text: error.message }); }
  };
  document.querySelector('[data-count-lookup]')?.addEventListener('click', lookup);
  scanner?.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); lookup(); } });

  document.querySelector('[data-count-found]')?.addEventListener('click', async () => {
    if (!currentUnit) return;
    const rowId = Number(actualRow?.value || 0);
    if (!rowId) return Swal.fire({ icon: 'warning', text: 'Selecciona Cámara y Fila donde se encontró la tina.' });
    try {
      const result = await GMar.api(`/internal/v1/inventory/counts/${context.id}/lines`, { method: 'POST', body: JSON.stringify({ stock_unit_id: currentUnit.stock_unit_id, row_location_id: rowId }) });
      await Swal.fire({ icon: result.result === 'match' ? 'success' : 'info', title: labels[result.result] || 'Registrada', timer: 900, showConfirmButton: false });
      location.reload();
    } catch (error) { Swal.fire({ icon: 'error', title: 'No se guardó', text: error.message }); }
  });

  document.querySelector('[data-count-missing]')?.addEventListener('click', async () => {
    if (!currentUnit?.expected) return;
    const confirmation = await Swal.fire({ icon: 'warning', title: '¿Marcar como no encontrada?', text: 'No se descontará inventario. Quedará como incidencia para revisión.', showCancelButton: true, confirmButtonText: 'Sí, marcar', cancelButtonText: 'Cancelar' });
    if (!confirmation.isConfirmed) return;
    try {
      await GMar.api(`/internal/v1/inventory/counts/${context.id}/missing`, { method: 'POST', body: JSON.stringify({ stock_unit_id: currentUnit.stock_unit_id }) });
      location.reload();
    } catch (error) { Swal.fire({ icon: 'error', title: 'No se guardó', text: error.message }); }
  });

  document.querySelector('[data-count-finish]')?.addEventListener('click', async () => {
    const confirmation = await Swal.fire({ icon: 'question', title: '¿Finalizar conteo?', text: 'Todas las tinas esperadas deben estar confirmadas o marcadas como no encontradas.', showCancelButton: true, confirmButtonText: 'Finalizar', cancelButtonText: 'Cancelar' });
    if (!confirmation.isConfirmed) return;
    try { await GMar.api(`/internal/v1/inventory/counts/${context.id}/finish`, { method: 'POST', body: '{}' }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'No se finalizó', text: error.message + (error.details?.pending_lines ? ` Pendientes: ${error.details.pending_lines}.` : '') }); }
  });

  document.querySelector('[data-count-reconcile]')?.addEventListener('click', async () => {
    const motive = await Swal.fire({ icon: 'warning', title: 'Conciliar conteo', html: '<div class="text-start">Sólo se aplicarán <strong>reubicaciones confirmadas</strong>.<br>Las tinas no encontradas y no esperadas quedarán pendientes de investigación. No se modificará ningún peso.</div>', input: 'textarea', inputPlaceholder: 'Motivo documentado', showCancelButton: true, confirmButtonText: 'Aplicar reubicaciones', cancelButtonText: 'Cancelar', inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres' });
    if (!motive.isConfirmed) return;
    try {
      const result = await GMar.api(`/internal/v1/inventory/counts/${context.id}/reconcile`, { method: 'POST', body: JSON.stringify({ reason: motive.value.trim() }) });
      await Swal.fire({ icon: 'success', title: 'Conteo conciliado', html: `${result.relocations_applied || 0} reubicación(es) aplicada(s).<br>${result.missing_pending_review || 0} no encontrada(s) pendientes.<br>${result.unexpected_pending_review || 0} no esperada(s) pendientes.` });
      location.reload();
    } catch (error) { Swal.fire({ icon: 'error', title: error.code || 'No se concilió', text: error.message }); }
  });


  // Detalle paginado de KPIs del conteo.
  const detailModalEl = document.querySelector('#countItemsModal');
  const detailModal = detailModalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(detailModalEl) : null;
  const detailBody = document.querySelector('[data-count-items-body]');
  const detailTitle = document.querySelector('[data-count-items-title]');
  const detailTotal = document.querySelector('[data-count-items-total]');
  const detailPage = document.querySelector('[data-count-items-page]');
  const detailSearch = document.querySelector('#countItemsSearch');
  const detailPrev = document.querySelector('[data-count-items-prev]');
  const detailNext = document.querySelector('[data-count-items-next]');
  let detailState = { result: 'pending', label: 'Pendientes', page: 1, pages: 1, q: '' };

  const esc = (value) => GMar.escapeHtml ? GMar.escapeHtml(String(value ?? '')) : String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[c]));
  const locationText = (value) => String(value || '').trim() || '—';

  const renderDetailRows = (items) => {
    if (!detailBody) return;
    if (!items.length) {
      detailBody.innerHTML = '<tr><td colspan="7" class="text-center text-secondary py-4">No hay tinas para este filtro.</td></tr>';
      return;
    }
    detailBody.innerHTML = items.map((item) => {
      const code = item.tub_code || item.unit_code || '—';
      const product = [item.species, item.size].filter(Boolean).join(' · ') || '—';
      const action = context?.status === 'counting' && item.lookup_code
        ? `<button class="btn btn-sm btn-outline-primary" type="button" data-count-open-unit="${esc(item.lookup_code)}">${item.result === 'pending' ? 'Contar' : 'Revisar'}</button>`
        : '';
      return `<tr>
        <td class="fw-semibold">${esc(code)}</td>
        <td>${esc(product)}</td>
        <td>${esc(item.lot_code || '—')}</td>
        <td>${esc(locationText(item.expected))}</td>
        <td>${esc(locationText(item.counted))}</td>
        <td><span class="badge text-bg-light">${esc(labels[item.result] || item.result)}</span></td>
        <td class="text-end">${action}</td>
      </tr>`;
    }).join('');
  };

  const loadDetail = async () => {
    if (!context || !detailBody) return;
    detailBody.innerHTML = '<tr><td colspan="7" class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</td></tr>';
    try {
      const params = new URLSearchParams({ result: detailState.result, page: String(detailState.page), per_page: '25' });
      if (detailState.q) params.set('q', detailState.q);
      const response = await GMar.api(`/internal/v1/inventory/counts/${context.id}/items?${params.toString()}`, { method: 'GET' });
      detailState.page = Number(response.page || 1);
      detailState.pages = Number(response.pages || 1);
      renderDetailRows(response.items || []);
      if (detailTotal) detailTotal.textContent = `${Number(response.total || 0)} registro(s)`;
      if (detailPage) detailPage.textContent = `Página ${detailState.page} de ${detailState.pages}`;
      if (detailPrev) detailPrev.disabled = detailState.page <= 1;
      if (detailNext) detailNext.disabled = detailState.page >= detailState.pages;
    } catch (error) {
      detailBody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">${esc(error.message || 'No se pudo consultar el detalle.')}</td></tr>`;
    }
  };

  document.querySelectorAll('[data-count-kpi]').forEach((button) => {
    button.addEventListener('click', () => {
      detailState = { result: button.dataset.countKpi || 'pending', label: button.dataset.countKpiLabel || 'Detalle', page: 1, pages: 1, q: '' };
      if (detailTitle) detailTitle.textContent = `Tinas ${detailState.label.toLowerCase()}`;
      if (detailSearch) detailSearch.value = '';
      detailModal?.show();
      loadDetail();
    });
  });

  document.querySelector('[data-count-items-search]')?.addEventListener('click', () => { detailState.q = String(detailSearch?.value || '').trim(); detailState.page = 1; loadDetail(); });
  detailSearch?.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); detailState.q = String(detailSearch.value || '').trim(); detailState.page = 1; loadDetail(); } });
  detailPrev?.addEventListener('click', () => { if (detailState.page > 1) { detailState.page -= 1; loadDetail(); } });
  detailNext?.addEventListener('click', () => { if (detailState.page < detailState.pages) { detailState.page += 1; loadDetail(); } });

  detailBody?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-count-open-unit]');
    if (!button) return;
    const code = String(button.dataset.countOpenUnit || '').trim();
    if (!code || !scanner) return;
    detailModal?.hide();
    scanner.value = code;
    scanner.focus();
    await lookup();
    card?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

})();
