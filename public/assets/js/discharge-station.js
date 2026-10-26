(() => {
  'use strict';

  const source = document.querySelector('#dischargeData');
  const form = document.querySelector('#fillingForm');
  if (!source || !globalThis.GMar) return;

  const data = JSON.parse(source.textContent || '{}');
  const plan = document.querySelector('#dischargePlan');
  const composition = document.querySelector('#planComposition');
  const size = document.querySelector('#fillingSize');
  const tub = document.querySelector('#fillingTub');
  const sources = document.querySelector('#sourceTanks');
  const notes = document.querySelector('#fillingNotes');
  const summary = document.querySelector('#certificationSummary');
  const escape = (value) => String(value ?? '').replace(/[&<>'"]/g, '');
  const selectedPlan = () => data.plans.find((item) => Number(item.id) === Number(plan?.value));
  const selectedLine = () => data.lines.find((item) => Number(item.id) === Number(composition?.value));

  const refreshSelect = (element) => {
    if (globalThis.jQuery) jQuery(element).trigger('change.select2');
  };

  const populatePlan = () => {
    const rows = data.lines.filter((item) => Number(item.discharge_plan_id) === Number(plan.value));
    composition.innerHTML = ['<option value="">Selecciona…</option>', ...rows.map((item) => `<option value="${Number(item.id)}">Tanque ${escape(item.tank_number)} · ${escape(item.species_name)} · ${escape(item.size_name || 'talla por capturar')}</option>`)].join('');
    composition.disabled = rows.length === 0;
    composition.value = '';
    size.innerHTML = '<option value="">Selecciona composición…</option>';
    size.disabled = true;
    const voyageId = Number(selectedPlan()?.voyage_id || 0);
    const tanks = data.tanks.filter((item) => Number(item.voyage_id) === voyageId);
    sources.innerHTML = tanks.map((item) => `<option value="${Number(item.id)}">${escape(item.tank_number)}</option>`).join('');
    sources.disabled = tanks.length === 0;
    refreshSelect(composition); refreshSelect(size); refreshSelect(sources);
  };

  const populateComposition = () => {
    const line = selectedLine();
    if (!line) {
      size.innerHTML = '<option value="">Selecciona composición…</option>';
      size.disabled = true;
      summary.textContent = 'MSC, Dolphin Safe y temperatura se heredan del plan.';
      refreshSelect(size);
      return;
    }
    const sizes = data.sizes.filter((item) => !item.species_id || Number(item.species_id) === Number(line.species_id));
    size.innerHTML = ['<option value="">Selecciona…</option>', ...sizes.map((item) => `<option value="${Number(item.id)}">${escape(item.name)}</option>`)].join('');
    size.disabled = false;
    size.value = line.size_grade_id ? String(line.size_grade_id) : '';
    if (globalThis.jQuery) jQuery(sources).val([String(line.voyage_tank_id)]).trigger('change');
    summary.textContent = `MSC ${line.msc_code} · Dolphin Safe ${line.dolphin_safe_code} · congelación ${Number(line.freezing_temperature_c).toFixed(2)} °C`;
    refreshSelect(size);
  };

  plan?.addEventListener('change', populatePlan);
  composition?.addEventListener('change', populateComposition);
  sources?.addEventListener('change', () => {
    const requiredTank = String(selectedLine()?.voyage_tank_id || '');
    if (!requiredTank) return;
    const selected = new Set(Array.from(sources.selectedOptions).map((option) => option.value));
    if (!selected.has(requiredTank) && globalThis.jQuery) {
      selected.add(requiredTank);
      jQuery(sources).val(Array.from(selected)).trigger('change.select2');
    }
  });

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();
    const line = selectedLine();
    const payload = {
      plan_id: Number(plan.value),
      tub_id: Number(tub.value),
      actual_tank_id: Number(line?.voyage_tank_id || 0),
      source_tank_ids: Array.from(sources.selectedOptions).map((option) => Number(option.value)),
      species_id: Number(line?.species_id || 0),
      size_grade_id: Number(size.value),
      sorter_user_id: Number(data.currentUserId),
      observations: notes.value.trim() || null
    };
    if (!payload.plan_id || !payload.tub_id || !payload.actual_tank_id || !payload.species_id || !payload.size_grade_id || payload.source_tank_ids.length === 0) {
      return Swal.fire({ icon: 'warning', text: 'Completa plan, composición, talla, tina y procedencia.' });
    }
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const result = await GMar.api('/internal/v1/fillings', { method: 'POST', body: JSON.stringify(payload) });
      await Swal.fire({ icon: 'success', title: `Llenado #${result.id} iniciado`, text: 'La tina quedó bloqueada para esta operación.' });
      location.reload();
    } catch (error) {
      await Swal.fire({ icon: 'error', title: error.code || 'Inicio rechazado', text: error.message });
      button.disabled = !navigator.onLine;
    }
  });

  document.querySelectorAll('[data-complete-filling]').forEach((button) => button.addEventListener('click', async () => {
    const confirmation = await Swal.fire({
      icon: 'question', title: `Cerrar llenado de ${button.dataset.tubCode}`,
      text: 'La tina quedará disponible exclusivamente para la estación de pesaje.',
      showCancelButton: true, confirmButtonText: 'Cerrar llenado', cancelButtonText: 'Cancelar', confirmButtonColor: '#198754'
    });
    if (!confirmation.isConfirmed) return;
    button.disabled = true;
    try {
      await GMar.api(`/internal/v1/fillings/${button.dataset.completeFilling}/complete`, { method: 'POST', body: '{}' });
      await Swal.fire({ icon: 'success', title: 'Tina enviada a pesaje', timer: 1600, showConfirmButton: false });
      location.reload();
    } catch (error) {
      await Swal.fire({ icon: 'error', title: error.code || 'Cierre rechazado', text: error.message });
      button.disabled = !navigator.onLine;
    }
  }));
})();
