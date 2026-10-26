(() => {
  'use strict';

  const contextNode = document.querySelector('#requestContext');
  if (!contextNode) return;

  const context = JSON.parse(contextNode.textContent || '{}');
  const form = document.querySelector('[data-request-form]');
  const reserveModalElement = document.querySelector('#reserveModal');
  const reserveModal = reserveModalElement ? bootstrap.Modal.getOrCreateInstance(reserveModalElement) : null;
  const reserveHelp = document.querySelector('[data-reservation-help]');
  const reserveTitle = document.querySelector('[data-reservation-title]');
  const reserveSubmit = document.querySelector('[data-reserve-submit]');
  let requestId = null;
  let requestType = 'production';

  const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
  const toSqlDateTime = (value) => `${value.replace('T', ' ')}${value.length === 16 ? ':00' : ''}`;
  const kg = (value) => Number(value || 0).toLocaleString('es-MX', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

  function scopeWarehouseLabel() {
    const warehouse = context.scopeWarehouse || {};
    return [warehouse.site_name, warehouse.code, warehouse.name].filter(Boolean).join(' · ');
  }

  function refreshRequestType() {
    if (!form) return;
    const type = String(form.elements.request_type?.value || 'production');
    const productionContext = form.querySelector('[data-production-context]');
    const productionDestination = form.querySelector('[data-production-destination]');
    const transferContext = form.querySelector('[data-transfer-context]');
    const destination = form.elements.destination;

    const transfer = type === 'warehouse_transfer';
    if (productionContext) productionContext.hidden = transfer;
    if (productionDestination) productionDestination.hidden = transfer;
    if (transferContext) transferContext.hidden = !transfer;
    if (destination) destination.required = !transfer;
  }

  function requestTotals(lines) {
    return (lines || []).reduce((totals, line) => {
      totals.requested += Number(line.requested_weight_kg || 0);
      totals.reserved += Number(line.reserved_weight_kg || 0);
      totals.fulfilled += Number(line.fulfilled_weight_kg || 0);
      return totals;
    }, { requested: 0, reserved: 0, fulfilled: 0 });
  }

  function flattenCandidates(lines) {
    const candidates = [];
    const seen = new Set();
    (lines || []).forEach((line) => {
      (line.candidates || []).forEach((unit) => {
        const key = `${Number(unit.id)}:${Number(line.id)}`;
        if (seen.has(key)) return;
        seen.add(key);
        candidates.push({ ...unit, request_line_id: Number(line.id) });
      });
    });
    return candidates;
  }

  function candidateTable(candidates, includeOrigin = false) {
    if (!candidates.length) {
      return '<div class="alert alert-warning mb-0">No hay unidades disponibles para los criterios solicitados.</div>';
    }

    return `
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th></th><th>Unidad</th><th>Lote</th><th>Propietario</th>${includeOrigin ? '<th>Almacén origen</th>' : ''}<th>Ubicación</th><th>Disponible kg</th><th>Reservar kg</th></tr></thead>
          <tbody>
            ${candidates.map((unit) => {
              const full = requestType === 'warehouse_transfer' || unit.transfer_full_unit === true;
              const available = Number(unit.available_weight_kg || 0);
              const origin = [unit.site_name, unit.warehouse_code, unit.warehouse_name].filter(Boolean).join(' · ');
              return `<tr>
                <td><input class="form-check-input" type="checkbox" data-reserve-check data-warehouse-id="${Number(unit.warehouse_id || 0)}"></td>
                <td class="fw-bold">${escape(unit.unit_code || unit.display_code)}</td>
                <td>${escape(unit.lot_code)}</td>
                <td>${escape(unit.owner_name || '—')}</td>
                ${includeOrigin ? `<td>${escape(origin)}</td>` : ''}
                <td>${escape(unit.location_path)}</td>
                <td>${kg(available)}</td>
                <td><input class="form-control form-control-sm" type="number" data-reserve-weight min="0.001" max="${available}" step="0.001" value="${available.toFixed(3)}" data-stock-id="${Number(unit.id)}" data-line-id="${Number(unit.request_line_id)}" ${full ? 'readonly' : ''}></td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>`;
  }

  function renderProductionAvailability(result, target) {
    const candidates = flattenCandidates(result.lines);
    const request = result.request || {};
    const source = [request.source_site_name, request.source_warehouse_code, request.source_warehouse_name].filter(Boolean).join(' · ');
    const legacy = !Number(request.source_warehouse_id || 0);

    target.innerHTML = `
      <div class="alert alert-info py-2">
        <div class="fw-semibold">Salida a producción</div>
        <div class="small">Almacén surtidor: <strong>${escape(source || (legacy ? 'Solicitud histórica · disponibilidad por instalación' : 'No definido'))}</strong></div>
      </div>
      ${candidateTable(candidates, false)}`;

    if (reserveSubmit) reserveSubmit.disabled = false;
  }

  function renderTransferAvailability(result, target) {
    const request = result.request || {};
    const totals = requestTotals(result.lines);
    const remaining = Math.max(0, totals.requested - totals.reserved - totals.fulfilled);
    const destination = [request.destination_site_name, request.destination_warehouse_code, request.destination_warehouse_name].filter(Boolean).join(' · ');
    const groups = Array.isArray(result.origin_warehouses) ? result.origin_warehouses : [];
    const candidates = flattenCandidates(result.lines);

    if (!groups.length) {
      target.innerHTML = `
        <div class="alert alert-warning mb-0">
          <div class="fw-semibold">No existe un almacén origen con tinas disponibles para esta solicitud.</div>
          <div class="small mt-1">Destino: ${escape(destination || request.destination || '—')} · Pendiente: ${kg(remaining)} kg.</div>
        </div>`;
      if (reserveSubmit) reserveSubmit.disabled = true;
      return;
    }

    target.innerHTML = `
      <div class="row g-3 mb-3">
        <div class="col-lg-7">
          <div class="border rounded-3 p-3 h-100">
            <div class="small text-secondary">Almacén solicitante / destino</div>
            <div class="fw-semibold">${escape(destination || request.destination || '—')}</div>
            <div class="small text-secondary mt-2">Solicitado: ${kg(totals.requested)} kg · Reservado: ${kg(totals.reserved)} kg · Pendiente: <strong>${kg(remaining)} kg</strong></div>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="border border-primary-subtle bg-primary-subtle rounded-3 p-3 h-100">
            <label class="form-label fw-semibold" for="transferOriginWarehouse">1. Selecciona almacén origen</label>
            <select class="form-select" id="transferOriginWarehouse" data-transfer-origin-select>
              <option value="">Seleccione origen…</option>
              ${groups.map((group) => `<option value="${Number(group.warehouse_id)}">${escape([group.site_name, group.warehouse_code, group.warehouse_name].filter(Boolean).join(' · '))} — ${Number(group.unit_count)} tinas / ${kg(group.available_weight_kg)} kg</option>`).join('')}
            </select>
            <div class="form-text">El origen se fija al confirmar la reserva. Todas las tinas deben salir del mismo almacén.</div>
          </div>
        </div>
      </div>
      <div class="mb-3">
        <div class="small text-secondary mb-2">Disponibilidad encontrada por almacén</div>
        <div class="row g-2">
          ${groups.map((group) => `
            <div class="col-md-6 col-xl-4">
              <div class="border rounded-3 p-2 h-100">
                <div class="fw-semibold small">${escape([group.site_name, group.warehouse_code, group.warehouse_name].filter(Boolean).join(' · '))}</div>
                <div class="small text-secondary">${Number(group.unit_count)} tinas · ${kg(group.available_weight_kg)} kg candidatos</div>
              </div>
            </div>`).join('')}
        </div>
      </div>
      <div data-transfer-origin-units>
        <div class="text-center text-secondary py-4 border rounded-3">Selecciona primero el almacén origen para revisar sus tinas.</div>
      </div>`;

    const originSelect = target.querySelector('[data-transfer-origin-select]');
    const unitsTarget = target.querySelector('[data-transfer-origin-units]');

    const renderOrigin = () => {
      const warehouseId = Number(originSelect?.value || 0);
      if (!warehouseId) {
        unitsTarget.innerHTML = '<div class="text-center text-secondary py-4 border rounded-3">Selecciona primero el almacén origen para revisar sus tinas.</div>';
        if (reserveSubmit) reserveSubmit.disabled = true;
        return;
      }

      const originCandidates = candidates.filter((unit) => Number(unit.warehouse_id || 0) === warehouseId);
      unitsTarget.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold">2. Selecciona tinas completas</div>
          <div class="small text-secondary">El peso de cada tina queda bloqueado porque el traspaso mueve la unidad física completa.</div>
        </div>
        ${candidateTable(originCandidates, false)}`;
      if (reserveSubmit) reserveSubmit.disabled = false;
    };

    originSelect?.addEventListener('change', renderOrigin);
    if (groups.length === 1 && originSelect) {
      originSelect.value = String(groups[0].warehouse_id);
      renderOrigin();
    } else if (reserveSubmit) {
      reserveSubmit.disabled = true;
    }
  }

  form?.elements.species_id?.addEventListener('change', () => {
    Array.from(form.elements.size_grade_id.options).forEach((option) => {
      if (option.dataset.species) option.hidden = option.dataset.species !== '0' && option.dataset.species !== form.elements.species_id.value;
    });
    Array.from(form.elements.lot_id?.options || []).forEach((option) => {
      if (option.dataset.species) option.hidden = option.dataset.species !== form.elements.species_id.value;
    });
    form.elements.size_grade_id.value = '';
    form.elements.lot_id.value = '';
  });
  form?.elements.owner_company_id?.addEventListener('change', () => {
    const ownerId = form.elements.owner_company_id.value;
    Array.from(form.elements.lot_id?.options || []).forEach((option) => {
      if (option.value) option.hidden = (option.dataset.species !== form.elements.species_id.value) || (ownerId && option.dataset.owner !== ownerId);
    });
    form.elements.lot_id.value = '';
  });
  form?.elements.request_type?.addEventListener('change', refreshRequestType);
  refreshRequestType();

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    const scopeWarehouseId = Number(context.scopeWarehouse?.id || context.scope?.warehouse_id || 0);
    if (!scopeWarehouseId) {
      return Swal.fire({
        icon: 'warning',
        title: 'Falta almacén operativo',
        text: 'Selecciona empresa, instalación y almacén en Cambiar alcance antes de crear la solicitud.',
      });
    }

    const values = new FormData(form);
    const type = String(values.get('request_type') || 'production');
    const line = {
      species_id: Number(values.get('species_id')),
      size_grade_id: Number(values.get('size_grade_id')),
      requested_weight_kg: Number(values.get('requested_weight_kg')),
    };
    if (values.get('lot_id')) line.lot_id = Number(values.get('lot_id'));
    if (values.get('owner_company_id')) line.owner_company_id = Number(values.get('owner_company_id'));
    if (values.get('quality_grade')) line.quality_grade = String(values.get('quality_grade')).trim();

    const payload = {
      company_id: Number(values.get('company_id')),
      site_id: Number(values.get('site_id')),
      request_type: type,
      destination: type === 'warehouse_transfer' ? 'Destino definido por alcance operativo' : String(values.get('destination') || '').trim(),
      required_at: toSqlDateTime(String(values.get('required_at'))),
      priority: values.get('priority'),
      observations: values.get('observations'),
      lines: [line],
    };

    try {
      const result = await GMar.api('/internal/v1/requests', { method: 'POST', body: JSON.stringify(payload) });
      await Swal.fire({
        icon: 'success',
        title: result.folio,
        text: type === 'warehouse_transfer'
          ? 'Solicitud registrada. El siguiente paso es autorizarla y después elegir el almacén origen según disponibilidad.'
          : `Solicitud de producción registrada. El surtido queda ligado a ${scopeWarehouseLabel() || 'el almacén del alcance actual'}.`,
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
    target.innerHTML = '<div class="text-center py-5"><div class="spinner-border"></div><div class="mt-2 text-secondary">Consultando disponibilidad real…</div></div>';
    if (reserveSubmit) reserveSubmit.disabled = requestType === 'warehouse_transfer';

    if (reserveTitle) {
      reserveTitle.textContent = requestType === 'warehouse_transfer'
        ? 'Seleccionar almacén origen y tinas'
        : 'Seleccionar unidades para producción';
    }
    if (reserveHelp) {
      reserveHelp.textContent = requestType === 'warehouse_transfer'
        ? 'Primero elige un almacén origen; después selecciona tinas completas de ese mismo almacén.'
        : 'Sólo se muestran existencias del almacén surtidor registrado en la solicitud.';
    }

    try {
      const result = await GMar.api(`/internal/v1/requests/${requestId}/availability`);
      if (requestType === 'warehouse_transfer') {
        renderTransferAvailability(result, target);
      } else {
        renderProductionAvailability(result, target);
      }
    } catch (error) {
      if (reserveSubmit) reserveSubmit.disabled = true;
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

    if (requestType === 'warehouse_transfer') {
      const warehouseIds = [...new Set(
        Array.from(document.querySelectorAll('[data-reserve-check]:checked'))
          .map((check) => Number(check.dataset.warehouseId || 0))
          .filter((id) => id > 0)
      )];

      if (warehouseIds.length !== 1) {
        return Swal.fire({
          icon: 'warning',
          title: 'Selecciona un solo almacén origen',
          text: 'Cada reserva de traspaso debe contener tinas de un único almacén origen.',
        });
      }
    }

    try {
      const result = await GMar.api('/internal/v1/reservations', {
        method: 'POST',
        body: JSON.stringify({ request_id: requestId, selections }),
      });
      await Swal.fire({
        icon: 'success',
        title: result.folio || 'Reserva confirmada',
        text: requestType === 'warehouse_transfer'
          ? 'Origen y tinas quedaron reservados. El siguiente paso es generar el traspaso.'
          : 'Reserva confirmada para salida a producción.',
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
      text: 'El traspaso tomará como origen el almacén de las tinas reservadas y como destino el almacén que hizo la solicitud.',
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
