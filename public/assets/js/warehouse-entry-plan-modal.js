(() => {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const planSelect = byId('entryPlanId');
  const detailButton = byId('entryPlanDetail');
  const modalElement = byId('entryPlanDetailModal');

  if (!planSelect || !detailButton || !modalElement || !window.bootstrap || !window.GMar) return;

  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  const loading = byId('entryPlanDetailLoading');
  const content = byId('entryPlanDetailContent');
  const productBody = byId('entryPlanModalProductBody');
  const tubsBody = byId('entryPlanModalTubsBody');
  const filterButton = byId('entryPlanModalCancelableOnly');

  let currentPlanId = 0;
  let currentDetail = null;
  let cancelableOnly = false;
  let activeTab = 'summary';

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const kg = (value) => `${Number(value || 0).toLocaleString('es-MX', {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  })} kg`;

  const kgCell = (value) => Number(value || 0).toLocaleString('es-MX', {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });

  const pct = (value) => `${Number(value || 0).toFixed(1)}%`;

  const statusText = (status) => ({
    in_progress: 'En proceso',
    approved: 'Aprobado',
    paused: 'Pausado',
    finished: 'Finalizado',
    canceled: 'Cancelado',
    draft: 'Borrador',
  }[String(status || '')] || String(status || '—').replaceAll('_', ' '));

  const dateTime = (value) => {
    if (!value) return '—';
    const raw = String(value).replace(' ', 'T');
    const date = new Date(raw);
    if (Number.isNaN(date.getTime())) return String(value);

    return new Intl.DateTimeFormat('es-MX', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(date);
  };

  const timeOnly = (value) => {
    if (!value) return '';
    const raw = String(value).replace(' ', 'T');
    const date = new Date(raw);
    if (Number.isNaN(date.getTime())) return '';

    return new Intl.DateTimeFormat('es-MX', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(date);
  };

  const syncButton = () => {
    const enabled = Number(planSelect.value || 0) > 0;
    detailButton.disabled = !enabled;
    detailButton.setAttribute('aria-disabled', enabled ? 'false' : 'true');
    detailButton.title = enabled ? 'Ver detalle del plan' : 'Selecciona un plan para consultar su detalle';
  };

  const setLoading = (isLoading) => {
    loading.classList.toggle('d-none', !isLoading);
    content.classList.toggle('d-none', isLoading);
  };

  const switchTab = (name) => {
    activeTab = name;

    document.querySelectorAll('[data-plan-tab]').forEach((button) => {
      const selected = button.dataset.planTab === name;
      button.classList.toggle('active', selected);
      button.setAttribute('aria-selected', selected ? 'true' : 'false');
    });

    document.querySelectorAll('[data-plan-panel]').forEach((panel) => {
      panel.classList.toggle('d-none', panel.dataset.planPanel !== name);
    });

    filterButton.classList.toggle('d-none', name !== 'tubs');
  };

  const renderProducts = (items) => {
    if (!Array.isArray(items) || items.length === 0) {
      productBody.innerHTML = '<tr><td colspan="7" class="text-center text-secondary py-4">El plan no tiene composición disponible.</td></tr>';
      return;
    }

    productBody.innerHTML = items.map((item) => `
      <tr>
        <td><span class="ws-plan-product-name"><i class="bi bi-star-fill"></i>${escapeHtml(item.species_name || item.species_code || '—')}</span></td>
        <td>${escapeHtml(item.size_name || item.size_code || 'Todas')}</td>
        <td>${escapeHtml(item.plan_tanks || '—')}</td>
        <td>${escapeHtml(item.product_tanks || item.plan_tanks || '—')}</td>
        <td class="text-end">${kgCell(item.planned_kg)}</td>
        <td class="text-end">${kgCell(item.processed_kg)}</td>
        <td class="text-end ws-plan-pending-value">${kgCell(item.pending_kg)}</td>
      </tr>
    `).join('');
  };

  const destinationBadge = (entry) => {
    const status = String(entry.destination_status || '');
    if (status === 'placed') {
      return '<span class="ws-destination-badge placed"><i class="bi bi-check-circle-fill"></i> Ingresada en destino</span>';
    }
    if (status === 'proposed') {
      return '<span class="ws-destination-badge pending"><i class="bi bi-clock-fill"></i> Pendiente de ingreso</span>';
    }
    return `<span class="ws-destination-badge blocked"><i class="bi bi-slash-circle"></i> ${escapeHtml(entry.destination_label || 'Sin estado')}</span>`;
  };

  const renderTubs = () => {
    const entries = Array.isArray(currentDetail?.entries) ? currentDetail.entries : [];
    const visible = cancelableOnly ? entries.filter((entry) => Boolean(entry.cancelable)) : entries;

    if (visible.length === 0) {
      tubsBody.innerHTML = `<tr><td colspan="8" class="text-center text-secondary py-4">${
        cancelableOnly ? 'No hay tinas cancelables en este momento.' : 'No hay tinas registradas para este plan.'
      }</td></tr>`;
      return;
    }

    tubsBody.innerHTML = visible.map((entry) => {
      const action = entry.cancelable
        ? `<button type="button" class="btn btn-outline-danger ws-plan-cancel-btn" data-cancel-entry="${Number(entry.weighing_id)}" data-tub-code="${escapeHtml(entry.tub_code)}"><i class="bi bi-trash me-1"></i> Cancelar registro</button>`
        : '<span class="ws-plan-not-cancelable"><i class="bi bi-slash-circle"></i> No cancelable</span>';

      return `
        <tr data-entry-cancelable="${entry.cancelable ? '1' : '0'}">
          <td class="fw-bold text-primary">${escapeHtml(entry.tub_code || '—')}</td>
          <td>${escapeHtml(entry.species_name || '—')}</td>
          <td>${escapeHtml(entry.size_name || '—')}</td>
          <td class="text-end">${kgCell(entry.accepted_net_kg)}</td>
          <td>${escapeHtml(dateTime(entry.confirmed_at))}</td>
          <td>${escapeHtml(entry.registered_by || '—')}</td>
          <td>${destinationBadge(entry)}</td>
          <td>${action}</td>
        </tr>
      `;
    }).join('');
  };

  const renderDetail = (detail) => {
    currentDetail = detail;
    const plan = detail.plan || {};

    byId('entryPlanDetailSubtitle').textContent =
      `${plan.folio || '—'} · ${plan.vessel_code || '—'} · Marea ${plan.annual_number || '—'}/${plan.year || '—'}`;

    byId('entryPlanModalVessel').textContent =
      [plan.vessel_code, plan.vessel_name].filter(Boolean).join(' · ') || '—';

    byId('entryPlanModalVoyage').textContent = `${plan.annual_number || '—'}/${plan.year || '—'}`;
    byId('entryPlanModalRsa').textContent = plan.rsa_code || 'Sin RSA';
    byId('entryPlanModalWarehouse').textContent =
      [plan.warehouse_code, plan.warehouse_name].filter(Boolean).join(' · ') || '—';
    byId('entryPlanModalStatus').textContent = statusText(plan.status);

    const progress = Math.max(0, Math.min(100, Number(plan.progress_percent || 0)));
    byId('entryPlanModalProgressBar').style.width = `${progress}%`;
    byId('entryPlanModalProgressBar').parentElement.setAttribute('aria-valuenow', String(progress));
    byId('entryPlanModalProgressText').textContent = pct(progress);
    byId('entryPlanModalProgressMetric').textContent = pct(progress);

    byId('entryPlanModalPlanned').textContent = kg(plan.planned_kg);
    byId('entryPlanModalProcessed').textContent = kg(plan.processed_kg);
    byId('entryPlanModalPending').textContent = kg(plan.pending_kg);

    // Mantener sincronizados también los indicadores funcionales ocultos de Pantalla 1.
    if (byId('planPlanned')) byId('planPlanned').textContent = kg(plan.planned_kg);
    if (byId('planProcessed')) byId('planProcessed').textContent = kg(plan.processed_kg);
    if (byId('planPending')) byId('planPending').textContent = kg(plan.pending_kg);

    renderProducts(detail.products || []);

    const count = Number(detail.processed_tub_count || 0);
    byId('entryPlanModalTubCount').textContent = String(count);
    byId('entryPlanModalTubCountSummary').textContent = String(count);

    if (detail.last_record) {
      const last = detail.last_record;
      const time = timeOnly(last.confirmed_at);
      byId('entryPlanModalLastRecord').textContent =
        `Último registro: ${last.tub_code || '—'} · ${kg(last.accepted_net_kg)}${time ? ` · ${time}` : ''}`;
    } else {
      byId('entryPlanModalLastRecord').textContent = 'Sin registros procesados.';
    }

    cancelableOnly = false;
    filterButton.classList.remove('active');
    filterButton.setAttribute('aria-pressed', 'false');
    filterButton.innerHTML = '<i class="bi bi-funnel me-1"></i> Ver solo cancelables';

    renderTubs();
    switchTab(activeTab);
  };

  const loadDetail = async (planId) => {
    currentPlanId = Number(planId || 0);
    if (!currentPlanId) return;

    setLoading(true);

    try {
      const response = await GMar.api(`/internal/v1/warehouse-entry/plans/${currentPlanId}/detail`);
      renderDetail(response);
      setLoading(false);
    } catch (error) {
      setLoading(false);
      content.classList.remove('d-none');
      content.innerHTML = `
        <div class="alert alert-danger mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i>
          ${escapeHtml(error?.message || 'No fue posible consultar el detalle del plan.')}
        </div>
      `;
    }
  };

  const cancelEntry = async (weighingId, tubCode) => {
    if (!window.Swal) return;

    const result = await Swal.fire({
      // SweetAlert se monta DENTRO de la modal Bootstrap.
      // Si se monta en <body>, el focus trap de Bootstrap impide escribir
      // en el textarea aunque visualmente el diálogo aparezca correctamente.
      target: modalElement,
      icon: 'warning',
      title: `Cancelar ${tubCode || 'registro'}`,
      html: '<div class="text-start small">Se reversará la recepción/inventario y el pesaje. Esta acción sólo procede si la tina aún no fue ingresada en el destino y no tiene movimientos posteriores.</div>',
      input: 'textarea',
      inputLabel: 'Motivo de cancelación',
      inputPlaceholder: 'Ej. Se capturó una tina equivocada.',
      inputAttributes: {
        maxlength: '500',
        rows: '4',
        autocomplete: 'off',
      },
      showCancelButton: true,
      confirmButtonText: 'Sí, cancelar registro',
      cancelButtonText: 'No cancelar',
      confirmButtonColor: '#dc3545',
      focusCancel: false,
      returnFocus: false,
      didOpen: () => {
        // Forzar foco al motivo una vez que Bootstrap reconoce el control
        // como parte de la propia modal.
        const input = Swal.getInput();
        if (input) {
          window.setTimeout(() => input.focus(), 0);
        }
      },
      preConfirm: (value) => {
        const reason = String(value || '').trim();
        if (reason.length < 5) {
          Swal.showValidationMessage('Indica un motivo de al menos 5 caracteres.');
          return false;
        }
        return reason;
      },
    });

    if (!result.isConfirmed) return;

    try {
      await GMar.api(`/internal/v1/warehouse-entry/weighings/${Number(weighingId)}/cancel`, {
        method: 'POST',
        body: JSON.stringify({ reason: result.value }),
      });

      await Swal.fire({
        target: modalElement,
        icon: 'success',
        title: 'Registro cancelado',
        text: 'La tina quedó liberada y el reverso conserva la trazabilidad.',
        confirmButtonText: 'Aceptar',
      });

      await loadDetail(currentPlanId);
    } catch (error) {
      await Swal.fire({
        target: modalElement,
        icon: 'error',
        title: 'No se pudo cancelar',
        text: error?.message || 'La tina ya no cumple las condiciones para cancelarse.',
        confirmButtonText: 'Cerrar',
      });

      await loadDetail(currentPlanId);
    }
  };

  detailButton.addEventListener('click', async () => {
    const planId = Number(planSelect.value || 0);
    if (!planId) return;

    activeTab = 'summary';
    switchTab('summary');
    modal.show();
    await loadDetail(planId);
  });

  planSelect.addEventListener('change', syncButton);

  document.querySelectorAll('[data-plan-tab]').forEach((button) => {
    button.addEventListener('click', () => switchTab(button.dataset.planTab || 'summary'));
  });

  filterButton.addEventListener('click', () => {
    cancelableOnly = !cancelableOnly;
    filterButton.classList.toggle('active', cancelableOnly);
    filterButton.setAttribute('aria-pressed', cancelableOnly ? 'true' : 'false');
    filterButton.innerHTML = cancelableOnly
      ? '<i class="bi bi-list-ul me-1"></i> Ver todas'
      : '<i class="bi bi-funnel me-1"></i> Ver solo cancelables';
    renderTubs();
  });

  tubsBody.addEventListener('click', (event) => {
    const button = event.target.closest('[data-cancel-entry]');
    if (!button) return;

    cancelEntry(
      Number(button.dataset.cancelEntry || 0),
      button.dataset.tubCode || 'registro'
    );
  });

  modalElement.addEventListener('hidden.bs.modal', () => {
    currentDetail = null;
    cancelableOnly = false;
    activeTab = 'summary';
  });

  syncButton();
})();
