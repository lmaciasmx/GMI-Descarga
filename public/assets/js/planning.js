(() => {
  'use strict';

  const contextElement = document.querySelector('#planningContext');
  if (!contextElement) return;

  const context = JSON.parse(contextElement.textContent);

  const toSqlDateTime = (value) => {
    if (!value) return null;
    return `${value.replace('T', ' ')}${value.length === 16 ? ':00' : ''}`;
  };

  const toLocalDateTime = (value) => {
    if (!value) return '';
    return String(value).replace(' ', 'T').slice(0, 16);
  };

  const today = () => {
    const date = new Date();
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  };

  const referenceDate = (plannedStart) => {
    const current = today();
    const planned = plannedStart ? String(plannedStart).slice(0, 10) : current;
    return planned < current ? current : planned;
  };

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const formatKg = (value) => new Intl.NumberFormat('es-MX', {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  }).format(Number(value || 0));

  const statusLabel = (status) => ({
    planned: 'Planeada',
    unloading: 'Descargando',
    closed: 'Cerrada',
    canceled: 'Cancelada',
    draft: 'Borrador',
    approved: 'Aprobado',
    in_progress: 'En operación',
    paused: 'Pausado',
    finished: 'Finalizado',
  }[status] || status);

  async function submitForm(form, url, method, payload, successTitle) {
    const button = form.querySelector('[type="submit"]');
    if (button) button.disabled = true;

    try {
      const result = await GMar.api(url, {
        method,
        body: JSON.stringify(payload),
      });

      await Swal.fire({
        icon: 'success',
        title: result.folio || result.code || successTitle || 'Registro guardado',
      });

      location.reload();
      return result;
    } catch (error) {
      await Swal.fire({
        icon: 'error',
        title: error.code || 'Operación rechazada',
        text: error.message || 'No fue posible completar la acción.',
      });
      return null;
    } finally {
      if (button) button.disabled = !navigator.onLine;
    }
  }

  // ---------------------------------------------------------------------------
  // MAREAS: número sugerido, duplicados y alta
  // ---------------------------------------------------------------------------
  const voyageForm = document.querySelector('[data-voyage-form]');
  const voyageVessel = document.querySelector('#voyageVessel');
  const voyageYear = document.querySelector('#voyageYear');
  const voyageNumber = document.querySelector('#voyageAnnualNumber');
  const voyageNumberHelp = document.querySelector('#voyageNumberHelp');
  const voyageDuplicateWarning = document.querySelector('#voyageDuplicateWarning');

  function voyageNumberRows() {
    const vesselId = voyageVessel?.value || '';
    const year = voyageYear?.value || '';
    return context.voyageNumbers?.[vesselId]?.[year] || [];
  }

  function updateVoyageNumberInfo({ suggest = false } = {}) {
    if (!voyageVessel || !voyageYear || !voyageNumber) return;

    const rows = voyageNumberRows();
    const numbers = rows.map((row) => Number(row.number));
    const next = numbers.length ? Math.max(...numbers) + 1 : 1;

    if (voyageNumberHelp) {
      voyageNumberHelp.textContent = `Siguiente número sugerido: ${next}`;
    }

    if (suggest && !voyageNumber.value) {
      voyageNumber.value = String(next);
    }

    const selected = rows.find(
      (row) => Number(row.number) === Number(voyageNumber.value)
    );

    if (!voyageDuplicateWarning) return;

    if (!selected || !voyageNumber.value) {
      voyageDuplicateWarning.classList.add('d-none');
      voyageDuplicateWarning.textContent = '';
      return;
    }

    const extra = selected.status === 'canceled'
      ? ' Está cancelada; debes restaurarla desde Historial.'
      : ` Estado actual: ${statusLabel(selected.status)}.`;

    voyageDuplicateWarning.textContent =
      `La marea ${voyageNumber.value}/${voyageYear.value} ya existe para esta embarcación.${extra}`;
    voyageDuplicateWarning.classList.remove('d-none');
  }

  voyageVessel?.addEventListener('change', () => {
    voyageNumber.value = '';
    updateVoyageNumberInfo({ suggest: true });
  });

  voyageYear?.addEventListener('change', () => {
    voyageNumber.value = '';
    updateVoyageNumberInfo({ suggest: true });
  });

  voyageNumber?.addEventListener('input', () => updateVoyageNumberInfo());

  voyageForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    const values = new FormData(voyageForm);
    const existing = voyageNumberRows().find(
      (row) => Number(row.number) === Number(values.get('annual_number'))
    );

    if (existing) {
      await Swal.fire({
        icon: 'warning',
        title: 'Marea ya registrada',
        text: existing.status === 'canceled'
          ? 'La marea ya existe y está cancelada. Restáurala desde Historial.'
          : `La marea ya existe con estado ${statusLabel(existing.status)}.`,
      });
      return;
    }

    await submitForm(
      voyageForm,
      '/internal/v1/voyages',
      'POST',
      {
        // Empresa e instalación se resuelven en el servidor desde el alcance
        // operativo autenticado; no se envían como datos confiables del cliente.
        vessel_id: Number(values.get('vessel_id')),
        receiving_warehouse_id: values.get('receiving_warehouse_id')
          ? Number(values.get('receiving_warehouse_id'))
          : null,
        annual_number: Number(values.get('annual_number')),
        year: Number(values.get('year')),
        port_name: String(values.get('port_name') || '').trim(),
        arrival_at: toSqlDateTime(values.get('arrival_at')),
        notes: String(values.get('notes') || '').trim(),
      },
      'Marea creada'
    );
  });

  // ---------------------------------------------------------------------------
  // RSA
  // ---------------------------------------------------------------------------
  document.querySelector('[data-rsa-form]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    const form = event.currentTarget;
    const values = new FormData(form);
    const issued = String(values.get('issued_on') || '');
    const expires = String(values.get('expires_on') || '');

    if (issued && expires && expires < issued) {
      await Swal.fire({
        icon: 'warning',
        title: 'Fechas RSA inválidas',
        text: 'El vencimiento no puede ser anterior a la fecha de emisión.',
      });
      return;
    }

    await submitForm(
      form,
      '/internal/v1/rsas',
      'POST',
      {
        voyage_id: Number(values.get('voyage_id')),
        code: String(values.get('code') || '').trim(),
        issued_on: issued || null,
        expires_on: expires || null,
      },
      'RSA registrado'
    );
  });

  // ---------------------------------------------------------------------------
  // PLAN: crear / editar
  // ---------------------------------------------------------------------------
  const planModalElement = document.querySelector('#planModal');
  const planForm = document.querySelector('[data-plan-form]');
  const planVoyage = document.querySelector('#planVoyage');
  const planRsa = document.querySelector('#planRsa');
  const planStart = document.querySelector('#planStart');
  const planEnd = document.querySelector('#planEnd');
  const planTitle = document.querySelector('#planModalTitle');
  const planSubmitButton = document.querySelector('#planSubmitButton');
  const rsaPlanHint = document.querySelector('#rsaPlanHint');
  const linesBody = document.querySelector('#planLines tbody');

  function filterOptionSet(select, dataKey, selectedValue) {
    if (!select) return;

    Array.from(select.options).forEach((option) => {
      if (!option.dataset[dataKey]) return;
      const visible = option.dataset[dataKey] === selectedValue;
      option.hidden = !visible;
      option.disabled = !visible;
    });

    if (select.selectedOptions[0]?.disabled) {
      select.value = '';
    }
  }

  function planReferenceDate() {
    return referenceDate(planStart?.value || null);
  }

  function refreshRsaOptions({ preserveInvalid = false } = {}) {
    if (!planRsa) return;

    const voyageId = planVoyage?.value || '';
    const ref = planReferenceDate();
    const currentValue = planRsa.value;
    let currentInvalidMessage = '';

    Array.from(planRsa.options).forEach((option) => {
      if (!option.dataset.voyage) {
        option.hidden = false;
        option.disabled = false;
        return;
      }

      const belongs = option.dataset.voyage === voyageId;
      const issued = option.dataset.issued || '';
      const expires = option.dataset.expires || '';
      const notEffective = Boolean(issued && issued > ref);
      const expired = Boolean(expires && expires < ref);
      const invalid = notEffective || expired;

      if (!option.dataset.baseLabel) {
        option.dataset.baseLabel = option.textContent.trim();
      }
      option.textContent = invalid
        ? `${option.dataset.baseLabel} · ${expired ? 'VENCIDO' : 'NO VIGENTE'}`
        : option.dataset.baseLabel;

      option.hidden = !belongs;
      option.disabled = !belongs || invalid;

      if (option.value === currentValue && belongs && invalid) {
        currentInvalidMessage = notEffective
          ? `El RSA seleccionado inicia vigencia el ${issued}.`
          : `El RSA seleccionado venció el ${expires}.`;

        if (preserveInvalid) option.disabled = false;
      }
    });

    if (planRsa.value && planRsa.selectedOptions[0]?.disabled && !preserveInvalid) {
      planRsa.value = '';
    }

    if (rsaPlanHint) {
      rsaPlanHint.textContent = currentInvalidMessage || `Vigencia comprobada contra ${ref}.`;
      rsaPlanHint.classList.toggle('text-danger', Boolean(currentInvalidMessage));
    }
  }

  function filterSizes(row) {
    const species = row.querySelector('[data-line="species_id"]');
    const size = row.querySelector('[data-line="size_grade_id"]');
    if (!species || !size) return;

    Array.from(size.options).forEach((option) => {
      if (!option.dataset.species) return;
      const visible = option.dataset.species === '0'
        || option.dataset.species === species.value;
      option.hidden = !visible;
      option.disabled = !visible;
    });

    if (size.selectedOptions[0]?.disabled) size.value = '';
  }

  function prepareLine(row) {
    const tank = row.querySelector('[data-line="voyage_tank_id"]');
    const species = row.querySelector('[data-line="species_id"]');

    filterOptionSet(tank, 'voyage', planVoyage?.value || '');
    species?.addEventListener('change', () => filterSizes(row));
    filterSizes(row);

    row.querySelector('[data-remove-plan-line]')?.addEventListener('click', () => row.remove());
  }

  function addLine(data = null) {
    const template = document.querySelector('#planLineTemplate');
    if (!template || !linesBody) return;

    const row = template.content.firstElementChild.cloneNode(true);
    linesBody.append(row);
    prepareLine(row);

    if (!data) return;

    row.querySelector('[data-line="voyage_tank_id"]').value = String(data.voyage_tank_id || '');
    row.querySelector('[data-line="species_id"]').value = String(data.species_id || '');
    filterSizes(row);
    row.querySelector('[data-line="size_grade_id"]').value = data.size_grade_id ? String(data.size_grade_id) : '';
    row.querySelector('[data-line="planned_weight_kg"]').value = data.planned_weight_kg ?? '';
    row.querySelector('[data-line="msc_code"]').value = data.msc_code ?? '';
    row.querySelector('[data-line="dolphin_safe_code"]').value = data.dolphin_safe_code ?? '';
    row.querySelector('[data-line="freezing_temperature_c"]').value = data.freezing_temperature_c ?? '';
  }

  function resetPlanForm() {
    if (!planForm) return;

    planForm.reset();
    planForm.dataset.planId = '';
    planVoyage.disabled = false;
    linesBody.innerHTML = '';
    if (planTitle) planTitle.textContent = 'Nuevo plan de descarga';
    if (planSubmitButton) planSubmitButton.textContent = 'Guardar plan en borrador';
    addLine();
    refreshRsaOptions();
  }

  document.querySelector('[data-plan-new]')?.addEventListener('click', resetPlanForm);
  document.querySelector('[data-add-plan-line]')?.addEventListener('click', () => addLine());

  planVoyage?.addEventListener('change', () => {
    refreshRsaOptions();
    linesBody?.querySelectorAll('tr').forEach((row) => {
      filterOptionSet(
        row.querySelector('[data-line="voyage_tank_id"]'),
        'voyage',
        planVoyage.value
      );
    });
  });

  planStart?.addEventListener('change', () => refreshRsaOptions());

  function buildPlanPayload() {
    const tankLines = Array.from(linesBody?.querySelectorAll('tr') || []).map((row, index) => ({
      voyage_tank_id: Number(row.querySelector('[data-line="voyage_tank_id"]').value),
      species_id: Number(row.querySelector('[data-line="species_id"]').value),
      size_grade_id: row.querySelector('[data-line="size_grade_id"]').value
        ? Number(row.querySelector('[data-line="size_grade_id"]').value)
        : null,
      planned_weight_kg: Number(row.querySelector('[data-line="planned_weight_kg"]').value),
      msc_code: row.querySelector('[data-line="msc_code"]').value,
      dolphin_safe_code: row.querySelector('[data-line="dolphin_safe_code"]').value,
      freezing_temperature_c: Number(row.querySelector('[data-line="freezing_temperature_c"]').value),
      sequence_number: index + 1,
    }));

    return {
      voyage_id: Number(planVoyage.value),
      rsa_id: planRsa.value ? Number(planRsa.value) : null,
      planned_start_at: toSqlDateTime(planStart.value),
      planned_end_at: toSqlDateTime(planEnd.value),
      notes: String(planForm.elements.notes.value || '').trim(),
      tank_lines: tankLines,
    };
  }

  function validatePlanPayloadClient(payload) {
    if (!payload.voyage_id) return 'Selecciona una marea.';
    if (!payload.tank_lines.length) return 'Agrega al menos una línea de composición.';

    if (
      payload.planned_start_at
      && payload.planned_end_at
      && payload.planned_end_at <= payload.planned_start_at
    ) {
      return 'La fecha de fin debe ser posterior al inicio.';
    }

    const invalidLine = payload.tank_lines.some((line) => (
      !line.voyage_tank_id
      || !line.species_id
      || !Number.isFinite(line.planned_weight_kg)
      || line.planned_weight_kg <= 0
      || !line.msc_code
      || !line.dolphin_safe_code
      || !Number.isFinite(line.freezing_temperature_c)
    ));

    if (invalidLine) return 'Completa todos los datos de composición.';

    if (payload.rsa_id) {
      const option = planRsa.querySelector(`option[value="${payload.rsa_id}"]`);
      const ref = planReferenceDate();
      const issued = option?.dataset.issued || '';
      const expires = option?.dataset.expires || '';

      if (issued && issued > ref) return `El RSA todavía no es vigente el ${ref}.`;
      if (expires && expires < ref) return `El RSA está vencido para el ${ref}.`;
    }

    return null;
  }

  planForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    const payload = buildPlanPayload();
    const validationError = validatePlanPayloadClient(payload);

    if (validationError) {
      await Swal.fire({ icon: 'warning', title: 'Revisa el plan', text: validationError });
      return;
    }

    const planId = Number(planForm.dataset.planId || 0);

    await submitForm(
      planForm,
      planId ? `/internal/v1/plans/${planId}` : '/internal/v1/plans',
      planId ? 'PUT' : 'POST',
      payload,
      planId ? 'Plan actualizado' : 'Plan creado'
    );
  });

  async function fetchPlan(planId) {
    return GMar.api(`/internal/v1/plans/${planId}`, { method: 'GET' });
  }

  async function openEditPlan(planId) {
    try {
      const plan = await fetchPlan(planId);

      if (!plan.can_edit) {
        await Swal.fire({
          icon: 'warning',
          title: 'Plan no editable',
          text: 'Sólo los planes en borrador y sin operación pueden modificarse.',
        });
        return;
      }

      resetPlanForm();
      planForm.dataset.planId = String(plan.id);
      planVoyage.value = String(plan.voyage_id);
      planVoyage.disabled = true;
      planStart.value = toLocalDateTime(plan.planned_start_at);
      planEnd.value = toLocalDateTime(plan.planned_end_at);
      planForm.elements.notes.value = plan.notes || '';

      linesBody.innerHTML = '';
      plan.tank_lines.forEach((line) => addLine(line));

      planRsa.value = plan.rsa_id ? String(plan.rsa_id) : '';
      refreshRsaOptions({ preserveInvalid: true });

      if (planTitle) planTitle.textContent = `Editar plan ${plan.folio}`;
      if (planSubmitButton) planSubmitButton.textContent = 'Guardar cambios';

      bootstrap.Modal.getOrCreateInstance(planModalElement).show();
    } catch (error) {
      await Swal.fire({
        icon: 'error',
        title: error.code || 'No se pudo abrir el plan',
        text: error.message,
      });
    }
  }

  document.querySelectorAll('[data-plan-edit]').forEach((button) => {
    button.addEventListener('click', () => openEditPlan(Number(button.dataset.planEdit)));
  });

  // ---------------------------------------------------------------------------
  // DETALLE / REVISIÓN / APROBACIÓN
  // ---------------------------------------------------------------------------
  const detailModalElement = document.querySelector('#planDetailModal');
  const detailTitle = document.querySelector('#planDetailTitle');
  const detailSubtitle = document.querySelector('#planDetailSubtitle');
  const detailSummary = document.querySelector('#planDetailSummary');
  const detailLines = document.querySelector('#planDetailLines');
  const detailTotal = document.querySelector('#planDetailTotal');
  const detailValidations = document.querySelector('#planDetailValidations');
  const detailActions = document.querySelector('#planDetailActions');

  function renderPlanDetail(plan) {
    detailTitle.textContent = `Plan ${plan.folio}`;
    detailSubtitle.textContent = `${plan.vessel_code} · ${plan.vessel_name} · Marea ${plan.annual_number}/${plan.year}`;

    const rsaText = plan.rsa_code
      ? `${escapeHtml(plan.rsa_code)}${plan.rsa_expires_on ? ` · vence ${escapeHtml(plan.rsa_expires_on)}` : ''}`
      : 'Sin RSA';

    detailSummary.innerHTML = `
      <div class="row g-3">
        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="text-secondary small">Estado</div><strong>${escapeHtml(statusLabel(plan.status))}</strong></div></div>
        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="text-secondary small">RSA</div><strong>${rsaText}</strong></div></div>
        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="text-secondary small">Inicio</div><strong>${escapeHtml(plan.planned_start_at || 'Sin definir')}</strong></div></div>
        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="text-secondary small">Fin</div><strong>${escapeHtml(plan.planned_end_at || 'Sin definir')}</strong></div></div>
        ${plan.notes ? `<div class="col-12"><div class="alert alert-light border mb-0"><strong>Notas:</strong> ${escapeHtml(plan.notes)}</div></div>` : ''}
      </div>`;

    let total = 0;
    detailLines.innerHTML = plan.tank_lines.map((line, index) => {
      total += Number(line.planned_weight_kg || 0);
      return `
        <tr>
          <td>${index + 1}</td>
          <td><strong>${escapeHtml(line.tank_number)}</strong></td>
          <td>${escapeHtml(`${line.species_code} · ${line.species_name}`)}</td>
          <td>${escapeHtml(line.size_name || 'Sin talla')}</td>
          <td class="text-end">${formatKg(line.planned_weight_kg)}</td>
          <td>${escapeHtml(line.msc_code)}</td>
          <td>${escapeHtml(line.dolphin_safe_code)}</td>
          <td>${escapeHtml(line.freezing_temperature_c)}</td>
        </tr>`;
    }).join('');

    detailTotal.textContent = `${formatKg(total)} kg`;

    detailValidations.innerHTML = (plan.review?.validations || []).map((validation) => `
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="d-flex gap-2 align-items-start">
            <span class="fs-5 ${validation.ok ? 'text-success' : 'text-danger'}">${validation.ok ? '✓' : '✕'}</span>
            <div>
              <div class="fw-semibold ${validation.ok ? 'text-success' : 'text-danger'}">${escapeHtml(validation.label)}</div>
              <div class="small text-secondary">${escapeHtml(validation.message)}</div>
            </div>
          </div>
        </div>
      </div>`).join('');

    detailActions.innerHTML = '<button class="btn btn-light" type="button" data-bs-dismiss="modal">Cerrar</button>';

    if (plan.can_edit) {
      const editButton = document.createElement('button');
      editButton.className = 'btn btn-outline-primary';
      editButton.type = 'button';
      editButton.textContent = 'Editar';
      editButton.addEventListener('click', () => {
        bootstrap.Modal.getInstance(detailModalElement)?.hide();
        window.setTimeout(() => openEditPlan(Number(plan.id)), 200);
      });
      detailActions.append(editButton);
    }

    if (plan.status === 'draft') {
      const approveButton = document.createElement('button');
      approveButton.className = 'btn btn-success';
      approveButton.type = 'button';
      approveButton.textContent = plan.review?.can_approve ? 'Aprobar plan' : 'No puede aprobarse';
      approveButton.disabled = !plan.review?.can_approve;

      approveButton.addEventListener('click', async () => {
        approveButton.disabled = true;
        try {
          await GMar.api(`/internal/v1/plans/${plan.id}/transition`, {
            method: 'POST',
            body: JSON.stringify({ target: 'approved', reason: null }),
          });
          await Swal.fire({ icon: 'success', title: `Plan ${plan.folio} aprobado` });
          location.reload();
        } catch (error) {
          approveButton.disabled = false;
          await Swal.fire({
            icon: 'error',
            title: error.code || 'No se aprobó el plan',
            text: error.message,
          });
        }
      });

      detailActions.append(approveButton);
    }
  }

  async function openPlanDetail(planId) {
    try {
      const plan = await fetchPlan(planId);
      renderPlanDetail(plan);
      bootstrap.Modal.getOrCreateInstance(detailModalElement).show();
    } catch (error) {
      await Swal.fire({
        icon: 'error',
        title: error.code || 'No se pudo consultar el plan',
        text: error.message,
      });
    }
  }

  document.querySelectorAll('[data-plan-view]').forEach((button) => {
    button.addEventListener('click', () => openPlanDetail(Number(button.dataset.planView)));
  });

  document.querySelectorAll('[data-plan-review]').forEach((button) => {
    button.addEventListener('click', () => openPlanDetail(Number(button.dataset.planReview)));
  });

  // ---------------------------------------------------------------------------
  // TRANSICIONES
  // ---------------------------------------------------------------------------
  async function requestReason(title, label) {
    const result = await Swal.fire({
      icon: 'warning',
      title,
      input: 'textarea',
      inputLabel: label,
      inputPlaceholder: 'Describe el motivo…',
      inputAttributes: { maxlength: '500' },
      showCancelButton: true,
      confirmButtonText: 'Confirmar',
      cancelButtonText: 'Volver',
      inputValidator: (value) => String(value || '').trim().length < 5
        ? 'Captura al menos 5 caracteres.'
        : undefined,
    });

    return {
      confirmed: result.isConfirmed,
      reason: String(result.value || '').trim(),
    };
  }

  async function transitionPlan(planId, target) {
    let confirmation;

    if (target === 'canceled') {
      confirmation = await requestReason('Cancelar plan', 'Motivo obligatorio');
    } else if (target === 'draft') {
      confirmation = await requestReason(
        'Regresar a borrador',
        'Indica por qué necesitas corregir el plan'
      );
    } else {
      const labels = {
        in_progress: 'iniciar el plan',
        paused: 'pausar el plan',
        finished: 'finalizar el plan',
      };
      const result = await Swal.fire({
        icon: 'question',
        title: `¿${labels[target] || 'cambiar el plan'}?`,
        showCancelButton: true,
        confirmButtonText: 'Confirmar',
        cancelButtonText: 'Cancelar',
      });
      confirmation = { confirmed: result.isConfirmed, reason: null };
    }

    if (!confirmation.confirmed) return;

    try {
      await GMar.api(`/internal/v1/plans/${planId}/transition`, {
        method: 'POST',
        body: JSON.stringify({ target, reason: confirmation.reason }),
      });
      location.reload();
    } catch (error) {
      await Swal.fire({
        icon: 'error',
        title: error.code || 'Transición rechazada',
        text: error.message,
      });
    }
  }

  document.querySelectorAll('[data-plan-transition]').forEach((button) => {
    button.addEventListener('click', () => {
      transitionPlan(Number(button.dataset.planTransition), button.dataset.target);
    });
  });

  async function transitionVoyage(button) {
    const voyageId = Number(button.dataset.voyageTransition);
    const target = button.dataset.target;
    const label = button.dataset.label || 'la marea';
    let confirmation;

    if (target === 'canceled') {
      const openPlans = Number(button.dataset.openPlans || 0);
      confirmation = await requestReason(
        `Cancelar ${label}`,
        openPlans > 0
          ? `Se cancelarán también ${openPlans} plan(es) abierto(s). Motivo obligatorio`
          : 'Motivo obligatorio'
      );
    } else if (target === 'planned') {
      confirmation = await requestReason(
        `Restaurar ${label}`,
        'Motivo obligatorio. Los planes cancelados no se restauran automáticamente.'
      );
    } else {
      const result = await Swal.fire({
        icon: 'question',
        title: `¿Cerrar ${label}?`,
        text: 'La marea debe tener sus planes finalizados y no conservar operación pendiente.',
        showCancelButton: true,
        confirmButtonText: 'Cerrar marea',
        cancelButtonText: 'Volver',
      });
      confirmation = { confirmed: result.isConfirmed, reason: null };
    }

    if (!confirmation.confirmed) return;
    button.disabled = true;

    try {
      await GMar.api(`/internal/v1/voyages/${voyageId}/transition`, {
        method: 'POST',
        body: JSON.stringify({ target, reason: confirmation.reason }),
      });
      location.reload();
    } catch (error) {
      button.disabled = false;
      await Swal.fire({
        icon: 'error',
        title: error.code || 'No se pudo cambiar la marea',
        text: error.message,
      });
    }
  }

  document.querySelectorAll('[data-voyage-transition]').forEach((button) => {
    button.addEventListener('click', () => transitionVoyage(button));
  });

  // ---------------------------------------------------------------------------
  // DUPLICAR PLAN CANCELADO
  // ---------------------------------------------------------------------------
  document.querySelectorAll('[data-plan-duplicate]').forEach((button) => {
    button.addEventListener('click', async () => {
      const planId = Number(button.dataset.planDuplicate);
      const confirmation = await Swal.fire({
        icon: 'question',
        title: 'Duplicar plan cancelado',
        text: 'Se creará un nuevo borrador con la misma composición. Si el RSA ya no es válido, el nuevo plan quedará sin RSA.',
        showCancelButton: true,
        confirmButtonText: 'Duplicar',
        cancelButtonText: 'Volver',
      });

      if (!confirmation.isConfirmed) return;
      button.disabled = true;

      try {
        const result = await GMar.api(`/internal/v1/plans/${planId}/duplicate`, {
          method: 'POST',
          body: JSON.stringify({}),
        });

        await Swal.fire({
          icon: 'success',
          title: `Nuevo plan ${result.folio}`,
          text: result.rsa_copied
            ? 'Se copió la composición y el RSA vigente.'
            : 'Se copió la composición. Selecciona un RSA vigente antes de aprobar.',
        });
        location.reload();
      } catch (error) {
        button.disabled = false;
        await Swal.fire({
          icon: 'error',
          title: error.code || 'No se pudo duplicar el plan',
          text: error.message,
        });
      }
    });
  });

  updateVoyageNumberInfo();
})();
