(() => {
  'use strict';
  const context = JSON.parse(document.querySelector('#requestContext').textContent);
  const toSqlDateTime = (value) => `${value.replace('T', ' ')}${value.length === 16 ? ':00' : ''}`;
  const form = document.querySelector('[data-request-form]');
  form?.elements.species_id.addEventListener('change', () => Array.from(form.elements.size_grade_id.options).forEach((option) => { if (option.dataset.species) option.hidden = option.dataset.species !== '0' && option.dataset.species !== form.elements.species_id.value; }));
  form?.addEventListener('submit', async (event) => {
    event.preventDefault(); event.stopImmediatePropagation(); const values = new FormData(form);
    const line = { species_id: Number(values.get('species_id')), requested_weight_kg: Number(values.get('requested_weight_kg')) };
    if (values.get('size_grade_id')) line.size_grade_id = Number(values.get('size_grade_id')); if (values.get('quality_grade')) line.quality_grade = String(values.get('quality_grade')).trim();
    const payload = { company_id: Number(values.get('company_id')), site_id: Number(values.get('site_id')), destination: values.get('destination'), required_at: toSqlDateTime(values.get('required_at')), priority: values.get('priority'), observations: values.get('observations'), lines: [line] };
    try { const result = await GMar.api('/internal/v1/requests', { method: 'POST', body: JSON.stringify(payload) }); await Swal.fire({ icon: 'success', title: result.folio }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'No se creó', text: error.message }); }
  });
  document.querySelectorAll('[data-request-authorize]').forEach((button) => button.addEventListener('click', async () => {
    const approved = button.dataset.approved === '1'; let reason = null;
    if (!approved) { const prompt = await Swal.fire({ title: 'Motivo de rechazo', input: 'textarea', showCancelButton: true, inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres' }); if (!prompt.isConfirmed) return; reason = prompt.value; }
    try { await GMar.api(`/internal/v1/requests/${button.dataset.requestAuthorize}/authorize`, { method: 'POST', body: JSON.stringify({ approved, reason }) }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Cambio rechazado', text: error.message }); }
  }));
  const reserveModalElement = document.querySelector('#reserveModal'); const reserveModal = reserveModalElement ? bootstrap.Modal.getOrCreateInstance(reserveModalElement) : null; let requestId = null;
  const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
  document.querySelectorAll('[data-request-reserve]').forEach((button) => button.addEventListener('click', async () => {
    requestId = Number(button.dataset.requestReserve); reserveModal.show(); const target = document.querySelector('[data-reservation-candidates]');
    try { const result = await GMar.api(`/internal/v1/requests/${requestId}/availability`); const candidates = result.lines.flatMap((line) => line.candidates.map((unit) => ({ ...unit, request_line_id: line.id }))); target.innerHTML = candidates.length ? `<div class="table-responsive"><table class="table"><thead><tr><th></th><th>Unidad</th><th>Lote</th><th>Ubicación</th><th>Disponible kg</th><th>Reservar kg</th></tr></thead><tbody>${candidates.map((unit) => `<tr><td><input class="form-check-input" type="checkbox" data-reserve-check></td><td class="fw-bold">${escape(unit.unit_code)}</td><td>${escape(unit.lot_code)}</td><td>${escape(unit.location_path)}</td><td>${Number(unit.available_weight_kg).toFixed(3)}</td><td><input class="form-control form-control-sm" type="number" data-reserve-weight min="0.001" max="${Number(unit.available_weight_kg)}" step="0.001" value="${Number(unit.available_weight_kg).toFixed(3)}" data-stock-id="${Number(unit.id)}" data-line-id="${Number(unit.request_line_id)}"></td></tr>`).join('')}</tbody></table></div>` : '<div class="alert alert-warning">No hay unidades disponibles para los criterios solicitados.</div>'; }
    catch (error) { target.innerHTML = `<div class="alert alert-danger">${escape(error.message)}</div>`; }
  }));
  document.querySelector('[data-reserve-form]')?.addEventListener('submit', async (event) => {
    event.preventDefault(); event.stopImmediatePropagation(); const selections = Array.from(document.querySelectorAll('[data-reserve-check]:checked')).map((check) => { const input = check.closest('tr').querySelector('[data-reserve-weight]'); return { stock_unit_id: Number(input.dataset.stockId), request_line_id: Number(input.dataset.lineId), quantity_kg: Number(input.value) }; });
    if (!selections.length) return Swal.fire({ icon: 'warning', text: 'Selecciona al menos una unidad.' });
    try { const result = await GMar.api('/internal/v1/reservations', { method: 'POST', body: JSON.stringify({ request_id: requestId, selections }) }); await Swal.fire({ icon: 'success', title: result.folio || 'Reserva confirmada' }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Reserva rechazada', text: error.message }); }
  });
  document.querySelectorAll('[data-reservation-fulfill]').forEach((button) => button.addEventListener('click', async () => {
    const confirmation = await Swal.fire({ icon: 'warning', title: `Surtir ${button.dataset.reservationFolio}`, text: 'Se descontarán físicamente las unidades seleccionadas y se generará un documento confirmado de salida a producción.', showCancelButton: true, confirmButtonText: 'Sí, surtir', cancelButtonText: 'Cancelar', confirmButtonColor: '#198754' });
    if (!confirmation.isConfirmed) return;
    try { const result = await GMar.api(`/internal/v1/reservations/${button.dataset.reservationFulfill}/fulfill`, { method: 'POST', body: '{}' }); await Swal.fire({ icon: 'success', title: 'Surtido confirmado', text: `Documento de inventario ${result.inventory_document_id}` }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Surtido rechazado', text: error.message }); }
  }));
  document.querySelectorAll('[data-reservation-release]').forEach((button) => button.addEventListener('click', async () => {
    const prompt = await Swal.fire({ title: `Liberar ${button.dataset.reservationFolio}`, input: 'textarea', inputLabel: 'Motivo operativo', inputPlaceholder: 'Describe por qué se libera la reserva', showCancelButton: true, confirmButtonText: 'Liberar', inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres' });
    if (!prompt.isConfirmed) return;
    try { await GMar.api(`/internal/v1/reservations/${button.dataset.reservationRelease}/release`, { method: 'POST', body: JSON.stringify({ reason: prompt.value.trim() }) }); await Swal.fire({ icon: 'success', title: 'Reserva liberada' }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Liberación rechazada', text: error.message }); }
  }));
})();
