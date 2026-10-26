(() => {
  'use strict';

  const tableBody = document.querySelector('#rh-current-body');
  if (!tableBody) return;

  const search = document.querySelector('#rh-search');
  const type = document.querySelector('#rh-type');
  const status = document.querySelector('#rh-status');
  const relation = document.querySelector('#rh-relation');
  const clear = document.querySelector('#rh-clear-filters');
  const count = document.querySelector('#rh-visible-count');
  const empty = document.querySelector('#rh-empty-row');
  const modalElement = document.querySelector('#historyRequestModal');
  const detailContent = document.querySelector('#rh-detail-content');
  const modalTitle = document.querySelector('#historyRequestModalLabel');

  const rows = Array.from(tableBody.querySelectorAll('[data-rh-row]'));

  const normalize = (value) => String(value ?? '').trim().toLocaleLowerCase('es-MX');

  function applyFilters() {
    const q = normalize(search?.value);
    const wantedType = String(type?.value ?? '');
    const wantedStatus = String(status?.value ?? '');
    const wantedRelation = String(relation?.value ?? '');
    let visible = 0;

    rows.forEach((row) => {
      const matchesSearch = q === '' || normalize(row.dataset.search).includes(q);
      const matchesType = wantedType === '' || row.dataset.type === wantedType;
      const matchesStatus = wantedStatus === '' || row.dataset.status === wantedStatus;
      const matchesRelation = wantedRelation === '' || row.dataset.relation === wantedRelation;
      const show = matchesSearch && matchesType && matchesStatus && matchesRelation;

      row.classList.toggle('d-none', !show);
      if (show) visible += 1;
    });

    if (count) count.textContent = String(visible);
    if (empty) empty.classList.toggle('d-none', visible !== 0);
  }

  [search, type, status, relation].forEach((element) => {
    if (!element) return;
    element.addEventListener(element.tagName === 'INPUT' ? 'input' : 'change', applyFilters);
  });

  clear?.addEventListener('click', () => {
    if (search) search.value = '';
    if (type) type.value = '';
    if (status) status.value = '';
    if (relation) relation.value = '';
    applyFilters();
    search?.focus();
  });

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const number = (value) => {
    const n = Number(value ?? 0);
    return Number.isFinite(n)
      ? n.toLocaleString('es-MX', { minimumFractionDigits: 3, maximumFractionDigits: 3 })
      : '0.000';
  };

  const statusLabels = {
    open: 'Pendiente',
    accepted: 'Aceptada',
    preparing: 'Preparando',
    dispatching: 'En salida',
    dispatched: 'En recepción',
    closed: 'Cerrada',
    rejected: 'Rechazada',
  };

  const statusClass = (status) => ({
    open: 'is-pending',
    accepted: 'is-accepted',
    preparing: 'is-preparing',
    dispatching: 'is-dispatching',
    dispatched: 'is-dispatched',
    closed: 'is-closed',
    rejected: 'is-rejected',
  }[status] || 'is-unknown');

  const eventLabels = {
    created: 'Solicitud creada',
    preselection: 'Preselección actualizada',
    accepted: 'Solicitud aceptada',
    unit_shipped: 'Tina registrada en salida',
    supply_finalized: 'Surtido finalizado',
    receipt_registered: 'Recepción registrada',
    return_reentered: 'Devolución reingresada',
    closed: 'Solicitud cerrada',
    rejected: 'Solicitud rechazada',
  };

  function lineRows(lines, units, summaryRows) {
    if (!Array.isArray(lines) || lines.length === 0) {
      return '<tr><td colspan="5" class="text-center text-secondary py-4">Sin líneas registradas.</td></tr>';
    }

    const operational = new Set(['shipped', 'accepted', 'returning', 'returned']);

    return lines.map((line) => {
      const lineId = Number(line.id || 0);
      const linkedUnits = Array.isArray(units)
        ? units.filter((unit) => Number(unit.request_line_id || 0) === lineId)
        : [];

      const lineSummary = Array.isArray(summaryRows)
        ? summaryRows.filter((row) => Number(row.request_line_id || 0) === lineId)
        : [];

      const suppliedRows = lineSummary.filter((row) => operational.has(String(row.status || '')));
      const suppliedUnits = suppliedRows.reduce((sum, row) => sum + Number(row.units || 0), 0);
      const suppliedKg = suppliedRows.reduce((sum, row) => sum + Number(row.kg || 0), 0);
      const requestedKg = Number(line.requested_weight_kg || 0);
      const difference = suppliedKg - requestedKg;
      const requestedUnits = Number(line.requested_unit_count || 0);

      return `
        <tr>
          <td class="rh-line-product">
            <strong>${escapeHtml(line.line_number)}. ${escapeHtml(line.species_name)} · ${escapeHtml(line.size_name)}</strong>
            <span>Lote: ${escapeHtml(line.lot_code || 'Cualquier lote')} · Propietario: ${escapeHtml(line.owner_name || 'Cualquier propietario')}</span>
          </td>
          <td>
            <strong>${number(requestedKg)} kg</strong>
            <span>${requestedUnits > 0 ? `${escapeHtml(requestedUnits)} tina(s)` : 'Sin cantidad de tinas fija'}</span>
          </td>
          <td>
            <strong>${number(suppliedKg)} kg</strong>
            <span>${escapeHtml(suppliedUnits)} tina(s) operadas</span>
          </td>
          <td class="${difference < -0.0005 ? 'text-danger' : (difference > 0.0005 ? 'text-warning' : 'text-success')}">
            <strong>${difference > 0 ? '+' : ''}${number(difference)} kg</strong>
          </td>
          <td class="text-center">
            <strong>${escapeHtml(linkedUnits.length)}</strong>
            <span>vinculadas</span>
          </td>
        </tr>
      `;
    }).join('');
  }

  function unitRows(units) {
    if (!Array.isArray(units) || units.length === 0) {
      return '<tr><td colspan="6" class="text-center text-secondary py-4">No hay tinas vinculadas a esta solicitud.</td></tr>';
    }

    const labels = {
      selected: 'Propuesta',
      shipped: 'Despachada',
      accepted: 'Recibida',
      returning: 'Devolución',
      returned: 'Reingresada',
      released: 'Liberada',
    };

    return units.map((unit) => `
      <tr>
        <td><strong>${escapeHtml(unit.tub_code || unit.unit_code || '—')}</strong><span>${escapeHtml(unit.unit_code || '')}</span></td>
        <td>${escapeHtml(labels[unit.status] || unit.status || '—')}</td>
        <td>${escapeHtml(unit.lot_code || '—')}</td>
        <td>${escapeHtml(unit.owner_name || '—')}</td>
        <td class="text-end">${unit.outbound_weight_kg == null ? '—' : `${number(unit.outbound_weight_kg)} kg`}</td>
        <td class="text-end">${unit.inbound_weight_kg == null ? '—' : `${number(unit.inbound_weight_kg)} kg`}</td>
      </tr>
    `).join('');
  }

  function eventRows(events) {
    if (!Array.isArray(events) || events.length === 0) {
      return '<div class="p-3 text-secondary small">Sin eventos registrados.</div>';
    }

    return events.map((event) => `
      <div class="rh-event">
        <span class="rh-event-dot"></span>
        <div>
          <strong>${escapeHtml(eventLabels[event.action] || String(event.action || '').replaceAll('_', ' '))}</strong>
          <span>Usuario ID ${escapeHtml(event.actor_user_id || '—')}</span>
        </div>
        <span>${escapeHtml(event.created_at || '')}</span>
      </div>
    `).join('');
  }

  function renderDetail(detail) {
    const request = detail?.request || {};
    const source = detail?.source || {};
    const destination = detail?.destination || {};
    const lines = detail?.lines || [];
    const units = detail?.units || [];
    const events = detail?.events || [];
    const summaryRows = detail?.summary || [];

    const sourceLabel = [source.company_name, source.site_name, source.code, source.name].filter(Boolean).join(' · ');
    const destinationLabel = [destination.company_name, destination.site_name, destination.code, destination.name].filter(Boolean).join(' · ');
    const requestType = request.request_type === 'production' ? 'Materia prima' : 'Traspaso interno';
    const statusText = statusLabels[request.status] || String(request.status || '').replaceAll('_', ' ');

    const operational = new Set(['shipped', 'accepted', 'returning', 'returned']);
    const requestedKg = lines.reduce((sum, line) => sum + Number(line.requested_weight_kg || 0), 0);
    const suppliedKg = summaryRows
      .filter((row) => operational.has(String(row.status || '')))
      .reduce((sum, row) => sum + Number(row.kg || 0), 0);
    const requestedUnits = lines.reduce((sum, line) => sum + Number(line.requested_unit_count || 0), 0);
    const suppliedUnits = summaryRows
      .filter((row) => operational.has(String(row.status || '')))
      .reduce((sum, row) => sum + Number(row.units || 0), 0);

    return `
      <section class="rh-origin-detail">
        <div class="rh-origin-detail-head">
          <div>
            <div class="rh-origin-detail-title">
              <h3>${escapeHtml(request.folio || 'Solicitud')}</h3>
              <span class="rh-status ${statusClass(request.status)}">${escapeHtml(statusText)}</span>
            </div>
            <div class="rh-origin-detail-sub">
              ${escapeHtml(sourceLabel || '—')}
              <i class="bi bi-arrow-right"></i>
              ${escapeHtml(destinationLabel || '—')}
              <span>·</span>
              ${escapeHtml(requestType)}
            </div>
          </div>
          <div class="rh-origin-detail-mini">
            <div><span>Requerida</span><strong>${escapeHtml(request.required_at || '—')}</strong></div>
            <div><span>Creada</span><strong>${escapeHtml(request.created_at || '—')}</strong></div>
          </div>
        </div>

        <div class="rh-origin-tabs" role="tablist" aria-label="Detalle de solicitud">
          <button type="button" class="rh-origin-tab active" data-rh-detail-tab="lines">
            <i class="bi bi-list-ul"></i> Líneas de solicitud
            <span>${escapeHtml(lines.length)}</span>
          </button>
          <button type="button" class="rh-origin-tab" data-rh-detail-tab="summary">
            <i class="bi bi-grid-1x2"></i> Resumen
          </button>
          <button type="button" class="rh-origin-tab" data-rh-detail-tab="units">
            <i class="bi bi-box-seam"></i> Tinas
            <span>${escapeHtml(units.length)}</span>
          </button>
          <button type="button" class="rh-origin-tab" data-rh-detail-tab="activity">
            <i class="bi bi-clock-history"></i> Seguimiento
            <span>${escapeHtml(events.length)}</span>
          </button>
        </div>

        <div class="rh-origin-panel" data-rh-detail-panel="lines">
          <div class="rh-origin-panel-head">
            <div>
              <strong>Productos solicitados</strong>
              <span>Especie, talla y criterios específicos de cada línea.</span>
            </div>
            <div class="rh-origin-line-totals">
              <span><strong>${number(requestedKg)}</strong> kg solicitados</span>
              <span><strong>${number(suppliedKg)}</strong> kg operados</span>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle rh-origin-lines-table">
              <thead>
                <tr>
                  <th>Producto / filtros</th>
                  <th>Solicitado</th>
                  <th>Operado</th>
                  <th>Diferencia kg</th>
                  <th class="text-center">Tinas</th>
                </tr>
              </thead>
              <tbody>${lineRows(lines, units, summaryRows)}</tbody>
            </table>
          </div>
        </div>

        <div class="rh-origin-panel d-none" data-rh-detail-panel="summary">
          <div class="rh-origin-metrics">
            <div><span>Kg solicitados</span><strong>${number(requestedKg)}</strong></div>
            <div><span>Kg operados</span><strong>${number(suppliedKg)}</strong></div>
            <div><span>Diferencia</span><strong>${suppliedKg - requestedKg > 0 ? '+' : ''}${number(suppliedKg - requestedKg)}</strong></div>
            <div><span>Tinas requeridas</span><strong>${requestedUnits > 0 ? escapeHtml(requestedUnits) : '—'}</strong></div>
            <div><span>Tinas operadas</span><strong>${escapeHtml(suppliedUnits)}</strong></div>
          </div>

          <div class="rh-origin-summary-grid">
            <div><span>Proveedor</span><strong>${escapeHtml(sourceLabel || '—')}</strong></div>
            <div><span>Destino</span><strong>${escapeHtml(destinationLabel || '—')}</strong></div>
            <div><span>Tipo de solicitud</span><strong>${escapeHtml(requestType)}</strong></div>
            <div><span>Estado</span><strong>${escapeHtml(statusText)}</strong></div>
          </div>

          <div class="rh-origin-observations">
            <strong>Observaciones</strong>
            <p>${escapeHtml(request.observations || 'Sin observaciones')}</p>
          </div>
        </div>

        <div class="rh-origin-panel d-none" data-rh-detail-panel="units">
          <div class="rh-origin-panel-head">
            <div>
              <strong>Tinas de la solicitud</strong>
              <span>Unidades vinculadas durante el surtido y recepción.</span>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle rh-origin-units-table">
              <thead>
                <tr>
                  <th>Tina / etiqueta</th>
                  <th>Estado</th>
                  <th>Lote</th>
                  <th>Propietario</th>
                  <th class="text-end">Salida</th>
                  <th class="text-end">Entrada</th>
                </tr>
              </thead>
              <tbody>${unitRows(units)}</tbody>
            </table>
          </div>
        </div>

        <div class="rh-origin-panel d-none" data-rh-detail-panel="activity">
          <div class="rh-origin-panel-head">
            <div>
              <strong>Seguimiento</strong>
              <span>Bitácora cronológica de eventos de la solicitud.</span>
            </div>
          </div>
          <div class="rh-event-list">${eventRows(events)}</div>
        </div>
      </section>
    `;
  }

  detailContent?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-rh-detail-tab]');
    if (!button) return;

    const tab = button.dataset.rhDetailTab;
    if (!tab) return;

    detailContent.querySelectorAll('[data-rh-detail-tab]').forEach((item) => {
      item.classList.toggle('active', item === button);
    });

    detailContent.querySelectorAll('[data-rh-detail-panel]').forEach((panel) => {
      panel.classList.toggle('d-none', panel.dataset.rhDetailPanel !== tab);
    });
  });

  async function showDetail(button) {
    if (!modalElement || !detailContent) return;

    const id = Number(button.dataset.requestId || 0);
    if (!id) return;

    if (modalTitle) modalTitle.textContent = button.dataset.requestFolio || 'Solicitud';

    detailContent.innerHTML = `
      <div class="rh-detail-loading">
        <div class="spinner-border" role="status"></div>
        <span>Cargando detalle de la solicitud...</span>
      </div>
    `;

    const modal = globalThis.bootstrap?.Modal.getOrCreateInstance(modalElement);
    modal?.show();

    try {
      const response = await fetch(`/internal/v1/warehouse-requests/${id}`, {
        method: 'GET',
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
      });

      const payload = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(payload?.message || payload?.error?.message || 'No fue posible consultar la solicitud.');
      }

      const detail = payload?.data ?? payload;
      detailContent.innerHTML = renderDetail(detail);
    } catch (error) {
      detailContent.innerHTML = `
        <div class="rh-detail-error">
          <i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>
          ${escapeHtml(error?.message || 'No fue posible consultar la solicitud.')}
        </div>
      `;
    }
  }

  rows.forEach((row) => {
    row.querySelector('.rh-detail-button')?.addEventListener('click', (event) => {
      showDetail(event.currentTarget);
    });
  });

  applyFilters();
})();
