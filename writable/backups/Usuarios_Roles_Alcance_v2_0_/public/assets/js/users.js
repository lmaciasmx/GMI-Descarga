(() => {
  'use strict';
  const form = document.querySelector('[data-user-create]');
  form?.addEventListener('submit', async (event) => {
    event.preventDefault(); event.stopImmediatePropagation();
    const values = Object.fromEntries(new FormData(form).entries());
    values.company_id = Number(values.company_id); values.site_id = Number(values.site_id);
    if (values.warehouse_id) values.warehouse_id = Number(values.warehouse_id); else delete values.warehouse_id;
    try { await GMar.api('/internal/v1/users', { method: 'POST', body: JSON.stringify(values) }); await Swal.fire({ icon: 'success', title: 'Usuario creado' }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'No se creó el usuario', text: error.message }); }
  });
  document.querySelectorAll('[data-user-group]').forEach((select) => select.addEventListener('change', async () => {
    const confirmation = await Swal.fire({ icon: 'warning', title: 'Cambiar rol efectivo', text: 'El cambio modificará permisos de backend de inmediato.', showCancelButton: true, confirmButtonText: 'Cambiar', cancelButtonText: 'Cancelar' });
    if (!confirmation.isConfirmed) return location.reload();
    try { await GMar.api(`/internal/v1/users/${select.dataset.userGroup}/group`, { method: 'PUT', body: JSON.stringify({ group: select.value }) }); Swal.fire({ icon: 'success', title: 'Rol actualizado', timer: 1200, showConfirmButton: false }); }
    catch (error) { await Swal.fire({ icon: 'error', title: error.code || 'No se actualizó', text: error.message }); location.reload(); }
  }));
  document.querySelectorAll('[data-user-status]').forEach((button) => button.addEventListener('click', async () => {
    const active = button.dataset.active !== '1';
    const confirmation = await Swal.fire({ icon: 'question', title: active ? '¿Activar usuario?' : '¿Desactivar usuario?', showCancelButton: true, confirmButtonText: active ? 'Activar' : 'Desactivar', cancelButtonText: 'Cancelar' });
    if (!confirmation.isConfirmed) return;
    try { await GMar.api(`/internal/v1/users/${button.dataset.userStatus}/status`, { method: 'PUT', body: JSON.stringify({ active }) }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'Cambio rechazado', text: error.message }); }
  }));
})();
