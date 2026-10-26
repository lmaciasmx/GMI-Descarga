(() => {
  'use strict';

  const contextNode = document.querySelector('#requestContext');
  if (!contextNode) return;

  const context = JSON.parse(contextNode.textContent || '{}');
  const form = document.querySelector('[data-request-form]');
  const reserveModalElement = document.querySelector('#reserveModal');
  const reserveModal = reserveModalElement ? bootstrap.Modal.getOrCreateInstance(reserveModalElement) : null;
  const reserveHelp = document.querySelector('[data-reservation-help]');
  let requestId = null;
  let requestType = 'production';

  const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
  const toSqlDateTime = (value) => `${value.replace('T', ' ')}${value.length === 16 ? ':00' : ''}`;

  function refreshRequestType() {
    if (!form) return;
    const type = String(form.elements.request_type?.value || 'production');
    const production = form.querySelector('[data-production-destination]');
    const transfer = form.querySelector('[data-transfer-destination]');
    const destination = form.elements.destination;
    const destinationWarehouse = form.elements.destination_warehouse_id;

    if (type === 'warehouse_transfer') {
      if (production) production.hidden = true;
      if (transfer) transfer.hidden = false;
      if (destination) destination.required = false;
      if (destinationWarehouse) destinationWarehouse.required = true;
    } else {
      if (production) production.hidden = false;
      if (transfer) transfer.hidden = true;
      if (destination) destination.required = true;
      if (destinationWarehouse) {
        destinationWarehouse.required = false;
        destinationWarehouse.value = '';
      }
    }
  }

  form?.elements.species_id?.addEventListener('change', () => {
    Array.from(form.elements.size_grade_id.options).forEach((option) => {
      if (option.dataset.species) option.hidden = option.dataset.species !== '0' && option.dataset.species !== form.elements.species_id.value;
    });
  });
  form?.elements.request_type?.addEventListener('change', refreshRequestType);
  refreshRequestType();

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    const values = new FormData(form);
    const type = String(values.get('request_type') || 'production');
    const line = {
      species_id: Number(values.get('species_id')),
      requested_weight_kg: Number(values.get('requested_weight_kg')),
    };
    if (values.get('size_grade_id')) line.size_grade_id = Number(values.get('size_grade_id'));
    if (values.get('quality_grade')) line.quality_grade = String(values.get('quality_grade')).trim();

    const payload = {
      company_id: Number(values.get('company_id')),
      site_id: Number(values.get('site_id')),
      request_type: type,
      destination: type === 'warehouse_transfer' ? 'Almacén destino' : String(values.get('destination') || '').trim(),
      required_at: toSqlDateTime(String(values.get('required_at'))),
      priority: values.get('priority'),
      observations: values.get('observations'),
      lines: [line],
    };

    if (type === 'warehouse_transfer') {
      const destinationWarehouseId = Number(values.get('destination_warehouse_id') || 0);
      if (!destinationWarehouseId) {
        return Swal.fire({ icon: 'warning', title: 'Destino requerido', text: 'Selecciona el almacén destino del traspaso.' });
      }
      payload.destination_warehouse_id = destinationWarehouseId;
    }

    try {
      const result = await GMar.api('/internal/v1/requests', { method: 'POST', body: JSON.stringify(payload) });
      await Swal.fire({
        icon: 'success',
        title: result.folio,
        text: type === 'warehouse_transfer'
          ? 'Solicitud de traspaso creada. Debe autorizarse y reservar tinas completas antes de generar el traspaso.'
          : 'Solicitud de producción creada.',
      });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'No se creó', text: error.message });
    }
  });

  document.querySelectorAll('[data-request-authorize]').forEach((button) => button.addEventListener('click', async () => {
    const approved = button.dataset.approved === '1';
    let reason = null;
    if (!approved) {
      const prompt = await Swal.fire({
        title: 'Motivo de rechazo',
        input: 'textarea',
        showCancelButton: true,
        inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres',
      });
      if (!prompt.isConfirmed) return;
      reason = prompt.value;
    }
    try {
      await GMar.api(`/internal/v1/requests/${button.dataset.requestAuthorize}/authorize`, {
        method: 'POST',
        body: JSON.stringify({ approved, reason }),
      });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'Cambio rechazado', text: error.message });
    }
  }));

  document.querySelectorAll('[data-request-reserve]').forEach((button) => button.addEventListener('click', async () => {
    requestId = Number(button.dataset.requestReserve);
    requestType = String(button.dataset.requestType || 'production');
    reserveModal?.show();

    const target = document.querySelector('[data-reservation-candidates]');
    if (!target) return;
    target.innerHTML = '<div class="text-center py-5"><div class="spinner-border"></div><div class="mt-2 text-secondary">Consultando disponibilidad…</div></div>';

    if (reserveHelp) {
      reserveHelp.textContent = requestType === 'warehouse_transfer'
        ? 'Para traspaso se reservan tinas completas; no se permite reserva parcial de una tina.'
        : 'La disponibilidad se recalcula en el servidor al confirmar.';
    }

    try {
      const result = await GMar.api(`/internal/v1/requests/${requestId}/availability`);
      const candidates = result.lines.flatMap((line) => line.candidates.map((unit) => ({ ...unit, request_line_id: line.id })));

      target.innerHTML = candidates.length ? `
        <div class="${requestType === 'warehouse_transfer' ? 'alert alert-primary' : 'alert alert-info'} py-2">
          ${requestType === 'warehouse_transfer'
            ? '<strong>Traspaso:</strong> selecciona tinas completas. Al confirmar la reserva, después podrás generar el traspaso ligado.'
            : 'Selecciona las unidades y el peso a reservar.'}
        </div>
        <div class="table-responsive"><table class="table"><thead><tr><th></th><th>Unidad</th><th>Lote</th><th>Ubicación</th><th>Disponible kg</th><th>Reservar kg</th></tr></thead><tbody>
        ${candidates.map((unit) => {
          const full = requestType === 'warehouse_transfer' || unit.transfer_full_unit === true;
          const available = Number(unit.available_weight_kg || 0);
          return `<tr>
            <td><input class="form-check-input" type="checkbox" data-reserve-check></td>
            <td class="fw-bold">${escape(unit.unit_code || unit.display_code)}</td>
            <td>${escape(unit.lot_code)}</td>
            <td>${escape(unit.location_path)}</td>
            <td>${available.toFixed(3)}</td>
            <td><input class="form-control form-control-sm" type="number" data-reserve-weight min="0.001" max="${available}" step="0.001" value="${available.toFixed(3)}" data-stock-id="${Number(unit.id)}" data-line-id="${Number(unit.request_line_id)}" ${full ? 'readonly' : ''}></td>
          </tr>`;
        }).join('')}
        </tbody></table></div>` : '<div class="alert alert-warning">No hay unidades disponibles para los criterios solicitados.</div>';
    } catch (error) {
      target.innerHTML = `<div class="alert alert-danger">${escape(error.message)}</div>`;
    }
  }));

  document.querySelector('[data-reserve-form]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    const selections = Array.from(document.querySelectorAll('[data-reserve-check]:checked')).map((check) => {
      const input = check.closest('tr').querySelector('[data-reserve-weight]');
      return {
        stock_unit_id: Number(input.dataset.stockId),
        request_line_id: Number(input.dataset.lineId),
        quantity_kg: Number(input.value),
      };
    });

    if (!selections.length) return Swal.fire({ icon: 'warning', text: 'Selecciona al menos una unidad.' });

    try {
      const result = await GMar.api('/internal/v1/reservations', {
        method: 'POST',
        body: JSON.stringify({ request_id: requestId, selections }),
      });
      await Swal.fire({
        icon: 'success',
        title: result.folio || 'Reserva confirmada',
        text: requestType === 'warehouse_transfer'
          ? 'Las tinas quedaron reservadas. El siguiente paso es Generar traspaso.'
          : 'Reserva confirmada.',
      });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'Reserva rechazada', text: error.message });
    }
  });

  document.querySelectorAll('[data-reservation-transfer]').forEach((button) => button.addEventListener('click', async () => {
    const confirmation = await Swal.fire({
      icon: 'question',
      title: `Generar traspaso desde ${button.dataset.reservationFolio}`,
      text: 'Las tinas reservadas quedarán ligadas al traspaso. La solicitud ya autorizada sustituye la autorización manual del traspaso.',
      showCancelButton: true,
      confirmButtonText: 'Generar traspaso',
      cancelButtonText: 'Cancelar',
    });
    if (!confirmation.isConfirmed) return;

    try {
      const result = await GMar.api('/internal/v1/transfers', {
        method: 'POST',
        body: JSON.stringify({ reservation_id: Number(button.dataset.reservationTransfer) }),
      });
      await Swal.fire({
        icon: 'success',
        title: result.transfer_count > 1 ? `${result.transfer_count} traspasos generados` : 'Traspaso generado',
        text: (result.folios || []).join(' · '),
      });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'No se generó el traspaso', text: error.message });
    }
  }));

  document.querySelectorAll('[data-reservation-fulfill]').forEach((button) => button.addEventListener('click', async () => {
    const confirmation = await Swal.fire({
      icon: 'warning',
      title: `Surtir ${button.dataset.reservationFolio}`,
      text: 'Se descontarán físicamente las unidades seleccionadas y se generará un documento confirmado de salida a producción.',
      showCancelButton: true,
      confirmButtonText: 'Sí, surtir',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#198754',
    });
    if (!confirmation.isConfirmed) return;
    try {
      const result = await GMar.api(`/internal/v1/reservations/${button.dataset.reservationFulfill}/fulfill`, { method: 'POST', body: '{}' });
      await Swal.fire({ icon: 'success', title: 'Surtido confirmado', text: `Documento de inventario ${result.inventory_document_id}` });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'Surtido rechazado', text: error.message });
    }
  }));

  document.querySelectorAll('[data-reservation-release]').forEach((button) => button.addEventListener('click', async () => {
    const prompt = await Swal.fire({
      title: `Liberar ${button.dataset.reservationFolio}`,
      input: 'textarea',
      inputLabel: 'Motivo operativo',
      inputPlaceholder: 'Describe por qué se libera la reserva',
      showCancelButton: true,
      confirmButtonText: 'Liberar',
      inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres',
    });
    if (!prompt.isConfirmed) return;
    try {
      await GMar.api(`/internal/v1/reservations/${button.dataset.reservationRelease}/release`, {
        method: 'POST',
        body: JSON.stringify({ reason: prompt.value.trim() }),
      });
      await Swal.fire({ icon: 'success', title: 'Reserva liberada' });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'Liberación rechazada', text: error.message });
    }
  }));
})();
