(() => {
  'use strict';
  const source = document.querySelector('#stationData');
  if (!source || !globalThis.GMar) return;
  const data = JSON.parse(source.textContent || '{}');
  const byId = (id) => document.getElementById(id);
  const field = {
    pending: byId('pendingFilling'), plan: byId('planId'), rsa: byId('rsaDisplay'), tub: byId('tubId'),
    actualTank: byId('actualTankId'), sources: byId('sourceTankIds'), species: byId('speciesId'), size: byId('sizeId'),
    sorter: byId('sorterId'), labeler: byId('labelerId'), gross: byId('grossWeight'), weightSource: byId('weightSource'),
    tareSource: byId('tareSource'), declaredTare: byId('declaredTare'), penalty: byId('penaltyPercent'), reason: byId('reasonCode'), observations: byId('observations')
  };
  let fillingId = null;
  let reading = null;

  const selectValue = (element, value) => {
    element.value = value == null ? '' : String(value);
    if (globalThis.jQuery) jQuery(element).trigger('change.select2');
  };

  function selectedPlan() { return data.plans.find((item) => Number(item.id) === Number(field.plan.value)); }
  function selectedTub() { return data.tubs.find((item) => Number(item.id) === Number(field.tub.value)); }

  function populateTanks() {
    const voyageId = Number(selectedPlan()?.voyage_id || 0);
    const rows = data.tanks.filter((tank) => Number(tank.voyage_id) === voyageId);
    const options = ['<option value="">Selecciona…</option>', ...rows.map((tank) => `<option value="${Number(tank.id)}">${String(tank.tank_number).replace(/[&<>"']/g, '')}</option>`)].join('');
    field.actualTank.innerHTML = options;
    field.sources.innerHTML = rows.map((tank) => `<option value="${Number(tank.id)}">${String(tank.tank_number).replace(/[&<>"']/g, '')}</option>`).join('');
    if (globalThis.jQuery) { jQuery(field.actualTank).trigger('change.select2'); jQuery(field.sources).trigger('change.select2'); }
    field.rsa.value = selectedPlan()?.rsa_code || 'Sin RSA asociado';
  }

  function appliedTare() {
    if (field.tareSource.value === 'estimated') return Number(data.estimatedTare || 0);
    if (field.tareSource.value === 'painted') return Number(field.declaredTare.value || 0);
    return Number(selectedTub()?.current_tare_kg || 0);
  }

  function calculate() {
    const gross = Number(field.gross.value || 0);
    const tare = appliedTare();
    const physical = Math.max(0, gross - tare);
    const penalty = physical * Math.max(0, Math.min(100, Number(field.penalty.value || 0))) / 100;
    byId('grossDisplay').textContent = gross.toFixed(3);
    byId('tareResult').textContent = `${tare.toFixed(3)} kg`;
    byId('physicalNetResult').textContent = `${physical.toFixed(3)} kg`;
    byId('penaltyResult').textContent = `${penalty.toFixed(3)} kg`;
    byId('acceptedResult').textContent = `${Math.max(0, physical - penalty).toFixed(3)} kg`;
  }

  async function checkScale() {
    const status = byId('scaleStatus');
    try {
      const result = await GMar.api('/internal/v1/scale/status');
      const connected = Boolean(result.status?.connected);
      status.className = `status-pill ${connected ? 'online' : 'warning'}`;
      status.querySelector('span:last-child').textContent = `Báscula: ${result.status?.status || 'sin estado'}`;
    } catch (error) {
      status.className = 'status-pill offline';
      status.querySelector('span:last-child').textContent = 'Báscula: no disponible';
    }
  }

  field.plan.addEventListener('change', populateTanks);
  field.actualTank.addEventListener('change', () => {
    const id = field.actualTank.value;
    const selected = new Set(Array.from(field.sources.selectedOptions).map((option) => option.value));
    if (id) selected.add(id);
    if (globalThis.jQuery) jQuery(field.sources).val(Array.from(selected)).trigger('change');
  });
  field.pending.addEventListener('change', () => {
    const pending = data.pending.find((item) => Number(item.id) === Number(field.pending.value));
    fillingId = pending ? Number(pending.id) : null;
    if (!pending) return;
    selectValue(field.plan, pending.discharge_plan_id);
    populateTanks();
    selectValue(field.tub, pending.tub_id);
    selectValue(field.actualTank, pending.actual_voyage_tank_id);
    selectValue(field.species, pending.species_id);
    selectValue(field.size, pending.size_grade_id);
    if (globalThis.jQuery) jQuery(field.sources).val((data.sourcesByFilling || {})[pending.id] || [pending.actual_voyage_tank_id]).trigger('change');
    calculate();
    byId('lotStatus').textContent = 'Se resolverá con los componentes del llenado';
  });
  field.tub.addEventListener('change', calculate);
  field.tareSource.addEventListener('change', () => {
    field.declaredTare.disabled = field.tareSource.value !== 'painted';
    if (field.tareSource.value === 'estimated') field.reason.required = true;
    calculate();
  });
  field.declaredTare.addEventListener('input', calculate);
  field.penalty.addEventListener('input', calculate);
  field.gross.addEventListener('input', calculate);
  field.weightSource.addEventListener('change', () => {
    const manual = field.weightSource.value === 'manual';
    field.gross.readOnly = !manual;
    field.reason.required = manual || field.tareSource.value !== 'catalog';
    if (manual) { reading = null; byId('readingStatus').textContent = 'Captura manual pendiente de motivo'; }
  });

  byId('prepareFilling')?.addEventListener('click', async () => {
    const payload = {
      plan_id: Number(field.plan.value), tub_id: Number(field.tub.value), actual_tank_id: Number(field.actualTank.value),
      source_tank_ids: Array.from(field.sources.selectedOptions).map((option) => Number(option.value)),
      species_id: Number(field.species.value), size_grade_id: Number(field.size.value),
      sorter_user_id: field.sorter.value ? Number(field.sorter.value) : null,
      labeler_user_id: field.labeler.value ? Number(field.labeler.value) : null,
      observations: field.observations.value || null
    };
    if (!payload.plan_id || !payload.tub_id || !payload.actual_tank_id || !payload.species_id || !payload.size_grade_id || payload.source_tank_ids.length === 0) {
      return Swal.fire({ icon: 'warning', text: 'Completa plan, tina, tanque real, procedencias, especie y talla.' });
    }
    const button = byId('prepareFilling'); button.disabled = true;
    try {
      const started = await GMar.api('/internal/v1/fillings', { method: 'POST', body: JSON.stringify(payload) });
      const completed = await GMar.api(`/internal/v1/fillings/${started.id}/complete`, { method: 'POST', body: '{}' });
      fillingId = Number(completed.id);
      await Swal.fire({ icon: 'success', text: `Tina preparada. Llenado #${fillingId}`, timer: 1500, showConfirmButton: false });
    } catch (error) {
      await Swal.fire({ icon: 'error', title: error.code || 'No se pudo preparar', text: error.message });
    } finally { button.disabled = !navigator.onLine; }
  });

  byId('readScale').addEventListener('click', async () => {
    const button = byId('readScale'); button.disabled = true;
    try {
      reading = await GMar.api('/internal/v1/scale/read-stable', { method: 'POST', body: JSON.stringify({ scale_id: data.scale?.id ? Number(data.scale.id) : null }) });
      field.gross.value = Number(reading.weight).toFixed(3);
      field.weightSource.value = reading.weight_source || (String(reading.source).startsWith('simulator') ? 'simulator' : 'middleware');
      byId('readingStatus').textContent = `${reading.stable ? 'Estable' : 'Inestable'} · ${reading.scale_identifier} · ${reading.captured_at}`;
      calculate();
    } catch (error) {
      await Swal.fire({ icon: 'error', title: 'Báscula no disponible', text: error.message });
    } finally { button.disabled = !navigator.onLine; }
  });

  byId('confirmWeighing').addEventListener('click', async () => {
    const manual = field.weightSource.value === 'manual';
    if (!fillingId || Number(field.gross.value) <= 0) return Swal.fire({ icon: 'warning', text: 'Prepara o selecciona un llenado y captura el peso.' });
    if (!manual && !reading?.stable) return Swal.fire({ icon: 'warning', text: 'Se requiere una lectura estable de báscula.' });
    if ((manual || field.tareSource.value !== 'catalog' || Number(field.penalty.value) > 0) && !field.reason.value.trim()) return Swal.fire({ icon: 'warning', text: 'Indica el motivo de contingencia o castigo.' });
    const plan = selectedPlan();
    const payload = {
      filling_id: fillingId, rsa_id: plan?.rsa_id ? Number(plan.rsa_id) : null,
      scale_id: data.scale?.id ? Number(data.scale.id) : null,
      scale_reading_id: manual ? null : Number(reading.id), gross_weight_kg: Number(field.gross.value),
      declared_tare_kg: field.tareSource.value === 'painted' ? Number(field.declaredTare.value) : null,
      tare_source: field.tareSource.value, penalty_percent: Number(field.penalty.value || 0), weight_source: field.weightSource.value,
      stable: manual ? false : Boolean(reading?.stable), original_value: reading?.original_value || null,
      device_timestamp: reading?.captured_at || null, reason_code: field.reason.value.trim() || null, observations: field.observations.value.trim() || null
    };
    const button = byId('confirmWeighing'); button.disabled = true;
    try {
      const weighing = await GMar.api('/internal/v1/weighings', { method: 'POST', body: JSON.stringify(payload) });
      let printText = 'Etiqueta no solicitada.';
      if (byId('printAfter').checked && data.template?.id) {
        const printed = await GMar.api('/internal/v1/labels/print', { method: 'POST', body: JSON.stringify({ weighing_id: Number(weighing.id), template_id: Number(data.template.id), printer_id: data.printer?.id ? Number(data.printer.id) : null }) });
        printText = `Etiqueta ${printed.label.code}: ${printed.print.status}`;
        if (printed.print?.document_html) GMar.printHtml(printed.print.document_html, printed.label.code);
        if (printed.print?.download_available) printText += `<br><a class="btn btn-sm btn-outline-primary mt-2" href="/operacion/etiquetas/trabajos/${Number(printed.print_job_id)}/archivo">Descargar PDF</a>`;
      }
      await Swal.fire({ icon: 'success', title: `Pesaje ${weighing.folio}`, html: `<strong>${Number(weighing.accepted_net_kg).toFixed(3)} kg aceptados</strong><br>${printText}`, confirmButtonColor: '#0d5f82' });
      window.location.reload();
    } catch (error) {
      await Swal.fire({ icon: 'error', title: error.code || 'Operación rechazada', text: error.message });
    } finally { button.disabled = !navigator.onLine; }
  });

  calculate();
  checkScale();
})();
