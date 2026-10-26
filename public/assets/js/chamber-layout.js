(() => {
  'use strict';

  const board = document.querySelector('#layoutBoard');
  const selector = document.querySelector('#chamberSelector');
  const contextElement = document.querySelector('#chamberLayoutContext');

  if (!board || !selector || !contextElement) return;

  const context = JSON.parse(contextElement.textContent);
  const modalElement = document.querySelector('#stackDetailModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  const detailContent = document.querySelector('#stackDetailContent');
  const modalTitle = document.querySelector('#stackModalTitle');
  const modalPath = document.querySelector('#stackModalPath');
  const restrictionWrap = document.querySelector('#restrictionFormWrap');
  const restrictionForm = document.querySelector('#restrictionForm');
  const releaseButton = document.querySelector('#releaseRestrictionButton');

  let currentLayout = null;
  let selectedStack = null;

  const stateLabels = {
    free: 'LIBRE',
    occupied: 'OCUPADA',
    blocked: 'INHABILITADA',
  };

  const restrictionLabels = {
    humidifier: 'Humidificador',
    maintenance: 'Mantenimiento',
    obstacle: 'Obstáculo físico',
    reserved: 'Reserva operativa',
    other: 'Otro',
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  async function api(url, options = {}) {
    if (globalThis.GMar?.api) {
      return GMar.api(url, options);
    }

    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
      },
      ...options,
    });

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
      throw new Error(body.message || 'La operación no pudo completarse.');
    }

    return body;
  }

  function responseData(response) {
    return response?.data ?? response;
  }

  function updateSummary(summary) {
    ['total', 'free', 'occupied', 'blocked'].forEach((key) => {
      const element = document.querySelector(`[data-summary="${key}"]`);
      if (element) element.textContent = Number(summary?.[key] || 0).toLocaleString('es-MX');
    });
  }

  function stackButton(stack) {
    return `
      <button
        type="button"
        class="stack-cell stack-${escapeHtml(stack.state)}"
        data-stack-id="${Number(stack.id)}"
        title="${escapeHtml(stack.code)} · ${stateLabels[stack.state] || stack.state}"
      >
        ${String(Number(stack.number || 0)).padStart(2, '0')}
      </button>
    `;
  }

  function renderSector(sector) {
    const positions = [...sector.positions].sort((a, b) => Number(b.number) - Number(a.number));

    const rows = positions.map((position) => {
      const stacks = [...position.stacks].sort((a, b) => Number(a.number) - Number(b.number));

      return `
        <div class="position-row">
          <div class="position-number" title="${escapeHtml(position.code)}">
            ${String(Number(position.number || 0)).padStart(2, '0')}
          </div>
          ${stacks.map(stackButton).join('')}
        </div>
      `;
    }).join('');

    const typeLabel = sector.type === 'row' ? 'Fila' : 'Pasillo';

    return `
      <section class="sector-block">
        <div class="sector-title">
          ${escapeHtml(typeLabel)} ${String(Number(sector.number || 0)).padStart(2, '0')}
          <div class="small text-secondary fw-normal">${escapeHtml(sector.code)}</div>
        </div>
        ${rows}
      </section>
    `;
  }

  function renderLayout(layout) {
    currentLayout = layout;

    document.querySelector('#layoutTitle').textContent =
      `${layout.chamber.code} · ${layout.chamber.name}`;

    document.querySelector('#layoutSubtitle').textContent =
      `${layout.chamber.warehouse_code} · ${layout.chamber.warehouse_name}`;

    updateSummary(layout.summary);

    if (!Array.isArray(layout.sectors) || layout.sectors.length === 0) {
      board.innerHTML = `
        <div class="empty-layout text-secondary">
          <div>
            <i class="bi bi-grid-3x3-gap fs-2"></i>
            <div class="mt-2">La cámara no tiene estibas configuradas.</div>
          </div>
        </div>
      `;
      return;
    }

    const rows = layout.sectors.filter((sector) => sector.type === 'row');
    const aisles = layout.sectors.filter((sector) => sector.type === 'aisle');

    board.innerHTML = `
      <div class="mb-4">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h2 class="h6 mb-0">Filas</h2>
          <small class="text-secondary">Posición 14 arriba → posición 01 abajo</small>
        </div>
        <div class="sector-grid">
          ${rows.map(renderSector).join('')}
        </div>
      </div>

      <div>
        <h2 class="h6 mb-2">Pasillos</h2>
        <div class="sector-grid">
          ${aisles.map(renderSector).join('')}
        </div>
      </div>
    `;

    board.querySelectorAll('[data-stack-id]').forEach((button) => {
      button.addEventListener('click', () => {
        const stack = findStack(Number(button.dataset.stackId));
        if (stack) openStack(stack);
      });
    });
  }

  function findStack(stackId) {
    if (!currentLayout) return null;

    for (const sector of currentLayout.sectors) {
      for (const position of sector.positions) {
        const found = position.stacks.find((stack) => Number(stack.id) === Number(stackId));
        if (found) {
          return {
            ...found,
            sector,
            position,
          };
        }
      }
    }

    return null;
  }

  function renderStock(units) {
    if (!Array.isArray(units) || units.length === 0) return '';

    return `
      <div class="mt-3">
        <h3 class="h6">Inventario</h3>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Unidad</th>
                <th>Tina</th>
                <th>Especie</th>
                <th>Talla</th>
                <th class="text-end">Peso kg</th>
              </tr>
            </thead>
            <tbody>
              ${units.map((unit) => `
                <tr>
                  <td>${escapeHtml(unit.unit_code)}</td>
                  <td>${escapeHtml(unit.tub_code)}</td>
                  <td>${escapeHtml(unit.species_code)} · ${escapeHtml(unit.species_name)}</td>
                  <td>${escapeHtml(unit.size_code)} · ${escapeHtml(unit.size_name)}</td>
                  <td class="text-end">${Number(unit.on_hand_weight_kg || 0).toLocaleString('es-MX', {
                    minimumFractionDigits: 3,
                    maximumFractionDigits: 3,
                  })}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      </div>
    `;
  }

  function renderRestriction(restriction) {
    if (!restriction) return '';

    return `
      <div class="alert alert-danger mt-3 mb-0">
        <div class="fw-bold">
          ${escapeHtml(restrictionLabels[restriction.restriction_type] || restriction.restriction_type)}
        </div>
        <div>${escapeHtml(restriction.reason)}</div>
        ${restriction.notes ? `<div class="small mt-1">${escapeHtml(restriction.notes)}</div>` : ''}
        <div class="small text-secondary mt-2">
          Desde ${escapeHtml(restriction.created_at)}
        </div>
      </div>
    `;
  }

  function openStack(stack) {
    selectedStack = stack;

    modalTitle.textContent = stack.code;
    modalPath.textContent = stack.path || '';

    const badgeClass = {
      free: 'text-bg-success',
      occupied: 'text-bg-primary',
      blocked: 'text-bg-danger',
    }[stack.state] || 'text-bg-secondary';

    detailContent.innerHTML = `
      <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <div>
          <div class="small text-secondary">
            ${escapeHtml(stack.sector.name)} · ${escapeHtml(stack.position.name)}
          </div>
          <div class="fw-semibold">${escapeHtml(stack.name)}</div>
        </div>
        <span class="badge ${badgeClass}">
          ${escapeHtml(stateLabels[stack.state] || stack.state)}
        </span>
      </div>

      <dl class="row detail-list mb-0">
        <dt class="col-sm-3">Código</dt>
        <dd class="col-sm-9">${escapeHtml(stack.code)}</dd>

        <dt class="col-sm-3">Ruta</dt>
        <dd class="col-sm-9"><code>${escapeHtml(stack.path)}</code></dd>

        <dt class="col-sm-3">Posición</dt>
        <dd class="col-sm-9">${Number(stack.position.number || 0)}</dd>

        <dt class="col-sm-3">Estiba</dt>
        <dd class="col-sm-9">${Number(stack.number || 0)}</dd>
      </dl>

      ${renderRestriction(stack.restriction)}
      ${renderStock(stack.stock)}
    `;

    restrictionWrap.classList.toggle('d-none', stack.state !== 'free');
    releaseButton.classList.toggle('d-none', stack.state !== 'blocked');

    restrictionForm.reset();
    modal.show();
  }

  async function loadLayout(chamberId) {
    if (!chamberId) return;

    board.innerHTML = `
      <div class="empty-layout text-secondary">
        <div>
          <div class="spinner-border spinner-border-sm mb-2" role="status"></div>
          <div>Cargando distribución...</div>
        </div>
      </div>
    `;

    try {
      const response = await api(`/internal/v1/inventory/chambers/${chamberId}/layout`);
      renderLayout(responseData(response));

      const url = new URL(window.location.href);
      url.searchParams.set('chamber_id', chamberId);
      window.history.replaceState({}, '', url);
    } catch (error) {
      board.innerHTML = `
        <div class="empty-layout text-danger">
          <div>
            <i class="bi bi-exclamation-triangle fs-2"></i>
            <div class="mt-2">${escapeHtml(error.message)}</div>
          </div>
        </div>
      `;
    }
  }

  restrictionForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (!selectedStack) return;

    const payload = {
      restriction_type: document.querySelector('#restrictionType').value,
      reason: document.querySelector('#restrictionReason').value.trim(),
      notes: document.querySelector('#restrictionNotes').value.trim(),
    };

    if (!payload.restriction_type || !payload.reason) {
      Swal.fire({
        icon: 'warning',
        title: 'Datos incompletos',
        text: 'Selecciona el tipo e indica el motivo.',
      });
      return;
    }

    const confirmation = await Swal.fire({
      icon: 'warning',
      title: '¿Inhabilitar esta estiba?',
      text: selectedStack.code,
      showCancelButton: true,
      confirmButtonText: 'Inhabilitar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#b42318',
    });

    if (!confirmation.isConfirmed) return;

    try {
      await api(`/internal/v1/inventory/locations/${selectedStack.id}/restriction`, {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      modal.hide();

      await Swal.fire({
        icon: 'success',
        title: 'Ubicación inhabilitada',
        timer: 1200,
        showConfirmButton: false,
      });

      await loadLayout(selector.value);
    } catch (error) {
      Swal.fire({
        icon: 'error',
        title: 'No se pudo inhabilitar',
        text: error.message,
      });
    }
  });

  releaseButton.addEventListener('click', async () => {
    if (!selectedStack) return;

    const confirmation = await Swal.fire({
      icon: 'question',
      title: '¿Liberar esta ubicación?',
      text: selectedStack.code,
      showCancelButton: true,
      confirmButtonText: 'Liberar',
      cancelButtonText: 'Cancelar',
    });

    if (!confirmation.isConfirmed) return;

    try {
      await api(`/internal/v1/inventory/locations/${selectedStack.id}/restriction/release`, {
        method: 'POST',
        body: '{}',
      });

      modal.hide();

      await Swal.fire({
        icon: 'success',
        title: 'Ubicación liberada',
        timer: 1200,
        showConfirmButton: false,
      });

      await loadLayout(selector.value);
    } catch (error) {
      Swal.fire({
        icon: 'error',
        title: 'No se pudo liberar',
        text: error.message,
      });
    }
  });

  selector.addEventListener('change', () => loadLayout(selector.value));

  loadLayout(selector.value || context.selected_chamber_id);
})();
