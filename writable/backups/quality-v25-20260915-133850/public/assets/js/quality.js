(() => {
  'use strict';
  const form = document.querySelector('[data-quality-inspection]');
  form?.addEventListener('submit', async (event) => {
    event.preventDefault(); event.stopImmediatePropagation();
    const values = new FormData(form);
    const payload = {
      weighing_id: Number(values.get('weighing_id')),
      quality_grade: String(values.get('quality_grade') || '').trim(),
      defect_percent: values.get('defect_percent') === '' ? null : Number(values.get('defect_percent')),
      temperature_c: values.get('temperature_c') === '' ? null : Number(values.get('temperature_c')),
      disposition: values.get('disposition'),
      observations: String(values.get('observations') || '').trim(),
      auto_grade: false,
      defects: values.getAll('defects').map((id) => ({ defect_id: Number(id) }))
    };
    const button = form.querySelector('[type="submit"]'); button.disabled = true;
    try {
      await GMar.api('/internal/v1/quality/inspections', { method: 'POST', body: JSON.stringify(payload) });
      await Swal.fire({ icon: 'success', title: 'Inspección registrada' }); location.reload();
    } catch (error) { Swal.fire({ icon: 'error', title: error.code || 'No fue posible registrar', text: error.message }); }
    finally { button.disabled = !navigator.onLine; button.innerHTML = '<i class="bi bi-shield-check"></i> Registrar inspección'; }
  });

  document.querySelectorAll('[data-quality-approve]').forEach((button) => button.addEventListener('click', async () => {
    const disposition = await Swal.fire({ title: 'Dictamen de calidad', input: 'select', inputOptions: { accepted: 'Aceptar', released: 'Liberar', hold: 'Retener', rejected: 'Rechazar' }, inputPlaceholder: 'Selecciona disposición', showCancelButton: true, confirmButtonText: 'Continuar', cancelButtonText: 'Cancelar', inputValidator: (value) => value ? undefined : 'Selecciona una disposición' });
    if (!disposition.isConfirmed) return;
    let reason = '';
    if (['hold', 'rejected'].includes(disposition.value)) {
      const motive = await Swal.fire({ title: 'Motivo obligatorio', input: 'textarea', inputAttributes: { maxlength: '255' }, showCancelButton: true, confirmButtonText: 'Confirmar dictamen', inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres' });
      if (!motive.isConfirmed) return;
      reason = motive.value.trim();
    }
    try {
      await GMar.api(`/internal/v1/quality/inspections/${button.dataset.qualityApprove}/approve`, { method: 'POST', body: JSON.stringify({ disposition: disposition.value, quality_grade: button.dataset.grade, reason }) });
      await Swal.fire({ icon: 'success', title: 'Dictamen confirmado' }); location.reload();
    } catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Dictamen rechazado', text: error.message }); }
  }));
})();
