(() => {
  'use strict';
  document.querySelectorAll('[data-setting-form]').forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault(); event.stopImmediatePropagation();
    const button = form.querySelector('[type="submit"]'); button.disabled = true;
    try {
      await GMar.api(`/internal/v1/settings/${form.dataset.settingForm}`, { method: 'PUT', body: JSON.stringify({ value: new FormData(form).get('value') }) });
      Swal.fire({ icon: 'success', title: 'Configuración actualizada', timer: 1300, showConfirmButton: false });
    } catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Cambio rechazado', text: error.message }); }
    finally { button.disabled = !navigator.onLine; }
  }));
})();
