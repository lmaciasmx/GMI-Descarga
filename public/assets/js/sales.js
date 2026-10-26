(() => {
  'use strict';

  const context = JSON.parse(document.querySelector('#salesContext').textContent);
  const form = document.querySelector('[data-sale-form]');
  const toSqlDateTime = (value) => value ? `${value.replace('T', ' ')}${value.length === 16 ? ':00' : ''}` : null;
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const kg = (value) => Number(value || 0).toLocaleString('es-MX', {minimumFractionDigits:3, maximumFractionDigits:3});
  const signedKg = (value) => `${Number(value || 0) > 0 ? '+' : ''}${kg(value)}`;

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    const values = new FormData(form);
    const lines = Array.from(form.querySelectorAll('[data-sale-check]:checked')).map((check) => {
      const row = check.closest('tr');
      const line = {
        stock_unit_id: Number(row.querySelector('[data-sale-quantity]').dataset.stockId),
        quantity_kg: Number(row.querySelector('[data-sale-quantity]').value),
        currency: row.querySelector('[data-sale-currency]').value
      };
      const price = row.querySelector('[data-sale-price]').value;
      if (price !== '') line.unit_price = Number(price);
      return line;
    });

    if (! lines.length) {
      return Swal.fire({ icon: 'warning', text: 'Selecciona al menos una unidad.' });
    }

    const payload = {
      company_id: context.companyId,
      site_id: context.siteId,
      customer_company_id: Number(values.get('customer_company_id')),
      required_at: toSqlDateTime(values.get('required_at')),
      observations: values.get('observations'),
      lines
    };

    try {
      const result = await GMar.api('/internal/v1/sales', { method: 'POST', body: JSON.stringify(payload) });
      await Swal.fire({ icon: 'success', title: result.folio, text: 'Orden creada; inventario aún no afectado.' });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'No se creó la venta', text: error.message });
    }
  });

  const prepareModalElement = document.querySelector('#salePrepareModal');
  const prepareModal = prepareModalElement ? bootstrap.Modal.getOrCreateInstance(prepareModalElement) : null;
  const prepareState = {
    orderId: 0,
    detail: null,
    currentLineId: 0,
    availability: [],
    selections: new Map(),
  };

  const selectedFor = (lineId) => {
    const id = Number(lineId);
    if (! prepareState.selections.has(id)) prepareState.selections.set(id, new Map());
    return prepareState.selections.get(id);
  };

  const currentLine = () => (prepareState.detail?.lines || []).find((line) => Number(line.id) === Number(prepareState.currentLineId)) || null;

  const proposalFor = (lineId) => (prepareState.detail?.proposals || []).filter((proposal) => Number(proposal.request_line_id) === Number(lineId));

  const renderPrepareTabs = () => {
    const box = document.querySelector('#salePrepareTabs');
    if (! box || ! prepareState.detail) return;

    box.innerHTML = prepareState.detail.lines.map((line) => {
      const selected = selectedFor(line.id);
      return `
        <button type="button" class="sale-prepare-tab ${Number(line.id) === Number(prepareState.currentLineId) ? 'active' : ''}" data-prepare-line="${Number(line.id)}">
          <span>Línea ${Number(line.line_number)}</span>
          <strong>${esc(line.species_name)} · ${esc(line.size_name)}</strong>
          <small>${selected.size} tina(s) seleccionada(s)</small>
        </button>
      `;
    }).join('');
  };

  const renderPrepareLine = () => {
    const line = currentLine();
    if (! line) return;

    const proposals = proposalFor(line.id);
    const selected = selectedFor(line.id);
    const selectedKg = [...selected.values()].reduce((sum, row) => sum + Number(row.on_hand_weight_kg || 0), 0);
    const difference = selectedKg - Number(line.requested_weight_kg || 0);

    document.querySelector('#salePrepareLineTitle').innerHTML = `
      <div>
        <strong>${esc(line.species_name)} · ${esc(line.size_name)}</strong>
        <span>Lote: ${esc(line.lot_code || 'Cualquier lote')} · Propietario: ${esc(line.owner_name || 'Cualquier propietario')}</span>
      </div>
    `;

    document.querySelector('#salePrepareLineTotals').innerHTML = `
      <div><span>Solicitado</span><strong>${kg(line.requested_weight_kg)} kg</strong></div>
      <div><span>Seleccionado</span><strong>${kg(selectedKg)} kg</strong></div>
      <div><span>Diferencia</span><strong class="${Math.abs(difference) < .0005 ? 'text-success' : 'text-warning'}">${signedKg(difference)} kg</strong></div>
    `;

    const proposalBox = document.querySelector('#salePrepareProposal');
    if (proposalBox) {
      proposalBox.innerHTML = proposals.length
        ? `<div class="sale-proposal-head"><i class="bi bi-lightbulb"></i><div><strong>Propuesta del solicitante</strong><span>Es referencia. Almacén puede usar estas tinas, sustituirlas o ignorarlas.</span></div></div>
           <div class="sale-proposal-chips">${proposals.map((proposal) => `
             <span class="sale-proposal-chip ${proposal.available_now ? '' : 'unavailable'}">
               ${esc(proposal.tub_code || proposal.unit_code)}
               <small>${kg(proposal.proposed_weight_kg)} kg · ${proposal.available_now ? 'Disponible' : 'Ya no disponible'}</small>
             </span>`).join('')}</div>`
        : `<div class="sale-proposal-empty"><i class="bi bi-info-circle"></i><span>El solicitante no propuso tinas para esta línea. Selecciona el surtido con la existencia actual.</span></div>`;
    }

    renderPrepareTabs();
  };

  const renderAvailability = () => {
    const tbody = document.querySelector('#salePrepareRows');
    if (! tbody) return;

    const line = currentLine();
    const selected = selectedFor(line?.id || 0);

    tbody.innerHTML = prepareState.availability.length
      ? prepareState.availability.map((row) => `
          <tr class="${row.is_proposed ? 'is-proposed' : ''}">
            <td><input class="form-check-input" type="checkbox" data-prepare-stock="${Number(row.id)}" ${selected.has(Number(row.id)) ? 'checked' : ''}></td>
            <td><strong>${esc(row.tub_code || row.unit_code)}</strong><small class="d-block text-secondary">${esc(row.unit_code || '')}</small></td>
            <td>${esc(row.lot_code || '—')}</td>
            <td>${esc(row.owner_name || '—')}</td>
            <td>${esc(row.location_path || '—')}</td>
            <td class="text-end fw-semibold">${kg(row.on_hand_weight_kg)}</td>
            <td>${row.is_proposed ? '<span class="badge text-bg-info">Propuesta</span>' : '<span class="text-secondary small">Disponible</span>'}</td>
          </tr>
        `).join('')
      : '<tr><td colspan="7" class="text-center text-secondary py-4">No hay tinas disponibles con estos criterios.</td></tr>';

    renderPrepareLine();
  };

  const loadAvailability = async () => {
    const line = currentLine();
    if (! line) return;

    const search = document.querySelector('#salePrepareSearch')?.value.trim() || '';
    const query = new URLSearchParams({ line_id: String(line.id) });
    if (search) query.set('search', search);

    const result = await GMar.api(`/internal/v1/sales/requests/${prepareState.orderId}/availability?${query.toString()}`);
    prepareState.availability = result.rows || [];

    const total = document.querySelector('#salePrepareAvailabilityTotal');
    if (total) total.textContent = `${Number(result.totals?.units || 0)} tinas disponibles · ${kg(result.totals?.kg)} kg`;

    renderAvailability();
  };

  const openPrepare = async (orderId) => {
    prepareState.orderId = Number(orderId);
    prepareState.detail = await GMar.api(`/internal/v1/sales/requests/${prepareState.orderId}`);
    prepareState.currentLineId = Number(prepareState.detail.lines?.[0]?.id || 0);
    prepareState.availability = [];
    prepareState.selections = new Map();

    for (const line of prepareState.detail.lines || []) {
      prepareState.selections.set(Number(line.id), new Map());
    }

    document.querySelector('#salePrepareTitle').textContent = `Preparar ${prepareState.detail.order.folio}`;
    document.querySelector('#salePrepareSubtitle').textContent =
      `${prepareState.detail.order.customer_name} · ${prepareState.detail.order.source_warehouse_name || 'Almacén de surtido'}`;

    const requestedKg = (prepareState.detail.lines || []).reduce((sum, line) => sum + Number(line.requested_weight_kg || 0), 0);
    document.querySelector('#salePrepareSummary').innerHTML = `
      <div><span>Cliente</span><strong>${esc(prepareState.detail.order.customer_name)}</strong></div>
      <div><span>Fecha requerida</span><strong>${esc(prepareState.detail.order.required_at || '—')}</strong></div>
      <div><span>Líneas</span><strong>${prepareState.detail.lines.length}</strong></div>
      <div><span>Kg solicitados</span><strong>${kg(requestedKg)}</strong></div>
      <div><span>Tinas propuestas</span><strong>${prepareState.detail.proposals.length}</strong></div>
    `;

    document.querySelector('#salePrepareSearch').value = '';
    renderPrepareTabs();
    renderPrepareLine();
    prepareModal?.show();
    await loadAvailability();
  };

  document.querySelectorAll('[data-sale-prepare]').forEach((button) => button.addEventListener('click', async () => {
    try {
      await openPrepare(button.dataset.salePrepare);
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'No se pudo abrir el surtido', text: error.message });
    }
  }));

  document.querySelector('#salePrepareTabs')?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-prepare-line]');
    if (! button) return;

    prepareState.currentLineId = Number(button.dataset.prepareLine);
    prepareState.availability = [];
    document.querySelector('#salePrepareSearch').value = '';
    renderPrepareTabs();
    renderPrepareLine();

    try {
      await loadAvailability();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'No se consultó existencia', text: error.message });
    }
  });

  document.querySelector('#salePrepareRows')?.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-prepare-stock]');
    if (! checkbox) return;

    const stockId = Number(checkbox.dataset.prepareStock);
    const row = prepareState.availability.find((item) => Number(item.id) === stockId);
    if (! row) return;

    const selected = selectedFor(prepareState.currentLineId);
    if (checkbox.checked) selected.set(stockId, row);
    else selected.delete(stockId);

    renderPrepareLine();
  });

  const searchAvailability = async () => {
    try {
      await loadAvailability();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'No se consultó existencia', text: error.message });
    }
  };

  document.querySelector('#salePrepareSearchBtn')?.addEventListener('click', searchAvailability);
  document.querySelector('#salePrepareSearch')?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      searchAvailability();
    }
  });

  document.querySelector('#salePrepareConfirm')?.addEventListener('click', async () => {
    const lines = (prepareState.detail?.lines || []).map((line) => ({
      request_line_id: Number(line.id),
      stock_unit_ids: [...selectedFor(line.id).keys()],
    }));

    const missing = lines.filter((line) => ! line.stock_unit_ids.length);
    if (missing.length) {
      return Swal.fire({
        icon: 'warning',
        title: 'Falta definir el surtido',
        text: `Selecciona al menos una tina definitiva en ${missing.length} línea(s).`
      });
    }

    const confirmation = await Swal.fire({
      icon: 'question',
      title: 'Confirmar surtido',
      text: 'Estas tinas serán la selección definitiva del almacén y quedarán reservadas. La propuesta original sólo se conserva como referencia.',
      showCancelButton: true,
      confirmButtonText: 'Confirmar y reservar',
      cancelButtonText: 'Seguir revisando'
    });
    if (! confirmation.isConfirmed) return;

    try {
      const result = await GMar.api(`/internal/v1/sales/requests/${prepareState.orderId}/prepare`, {
        method: 'POST',
        body: JSON.stringify({ lines })
      });

      prepareModal?.hide();
      await Swal.fire({
        icon: 'success',
        title: 'Surtido preparado',
        text: `${result.reserved_units} tina(s) · ${kg(result.reserved_kg)} kg reservados · diferencia ${signedKg(result.difference_kg)} kg.`
      });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'No se confirmó el surtido', text: error.message });
    }
  });

  document.querySelectorAll('[data-sale-dispatch]').forEach((button) => button.addEventListener('click', async () => {
    const confirmation = await Swal.fire({
      icon: 'warning',
      title: 'Confirmar despacho físico',
      text: 'La salida se registrará en el libro mayor de inventario.',
      showCancelButton: true,
      confirmButtonText: 'Despachar',
      cancelButtonText: 'Cancelar'
    });
    if (! confirmation.isConfirmed) return;

    try {
      const result = await GMar.api(`/internal/v1/sales/${button.dataset.saleDispatch}/dispatch`, { method: 'POST', body: '{}' });
      await Swal.fire({ icon: 'success', title: 'Despacho confirmado', text: `Documento de inventario #${result.inventory_document_id}` });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'Despacho rechazado', text: error.message });
    }
  }));

  document.querySelectorAll('[data-sale-release]').forEach((button) => button.addEventListener('click', async () => {
    const reason = await Swal.fire({
      icon: 'warning',
      title: 'Liberar tinas reservadas',
      input: 'textarea',
      inputLabel: 'Motivo',
      inputPlaceholder: 'Indica por qué se cancela la solicitud de venta',
      showCancelButton: true,
      confirmButtonText: 'Liberar reserva',
      cancelButtonText: 'Cancelar',
      inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres'
    });
    if (! reason.isConfirmed) return;

    try {
      await GMar.api(`/internal/v1/sales/requests/${button.dataset.saleRelease}/release`, {
        method: 'POST',
        body: JSON.stringify({ reason: reason.value.trim() })
      });
      await Swal.fire({ icon: 'success', title: 'Reserva liberada', text: 'Las tinas volvieron a estar disponibles.' });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'No se liberó', text: error.message });
    }
  }));

  document.querySelectorAll('[data-sale-billing]').forEach((button) => button.addEventListener('click', async () => {
    const external = await Swal.fire({
      title: 'Referencia INVoiice',
      input: 'text',
      inputLabel: 'ID externo del documento',
      inputPlaceholder: 'INV-000123',
      showCancelButton: true,
      inputValidator: (value) => value.trim() ? undefined : 'Captura la referencia'
    });
    if (! external.isConfirmed) return;

    const status = await Swal.fire({
      title: 'Estado fiscal',
      input: 'select',
      inputOptions: { issued: 'Emitido', canceled: 'Cancelado', pending: 'Pendiente' },
      showCancelButton: true
    });
    if (! status.isConfirmed) return;

    const fiscal = await Swal.fire({
      title: 'UUID fiscal opcional',
      input: 'text',
      showCancelButton: true,
      inputValidator: (value) => value && !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)
        ? 'UUID no válido'
        : undefined
    });
    if (! fiscal.isConfirmed) return;

    const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const payload = {
      company_id: context.companyId,
      site_id: context.siteId,
      sales_order_id: Number(button.dataset.saleBilling),
      external_system: 'INVoiice',
      external_document_id: external.value.trim(),
      document_type: 'CFDI',
      fiscal_status: status.value,
      fiscal_uuid: fiscal.value.trim() || null
    };
    if (status.value === 'issued') payload.issued_at = now;
    if (status.value === 'canceled') payload.canceled_at = now;

    try {
      const result = await GMar.api('/internal/v1/billing/references', { method: 'POST', body: JSON.stringify(payload) });
      await Swal.fire({
        icon: 'success',
        title: 'Referencia fiscal registrada',
        text: result.inventory_changed ? 'Inventario afectado' : 'Inventario físico sin cambios'
      });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: error.code || 'No se registró', text: error.message });
    }
  }));
})();
