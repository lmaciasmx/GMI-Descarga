(() => {
  'use strict';
  const source = document.querySelector('#receivingData');
  if (!source || !globalThis.GMar) return;
  const data = JSON.parse(source.textContent || '{}');
  const warehouse = document.querySelector('#warehouseId');
  const location = document.querySelector('#locationId');
  const scanner = document.querySelector('#scanLabel');
  const checks = () => Array.from(document.querySelectorAll('.receive-check'));

  function clean(value) { return String(value).replace(/[&<>"']/g, ''); }
  function populateLocations() {
    const rows = data.locations.filter((item) => Number(item.warehouse_id) === Number(warehouse.value));
    location.innerHTML = ['<option value="">Selecciona…</option>'].concat(rows.map((item) => '<option value="' + Number(item.id) + '">' + clean(item.path) + ' · ' + clean(item.name) + '</option>')).join('');
    if (globalThis.jQuery) jQuery(location).trigger('change.select2');
  }

  warehouse.addEventListener('change', populateLocations);
  document.querySelector('#selectAll').addEventListener('change', (event) => checks().forEach((check) => { check.checked = event.target.checked; }));
  scanner.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const code = scanner.value.trim();
    const row = Array.from(document.querySelectorAll('#receivingTable tbody tr')).find((item) => item.dataset.label === code);
    if (!row) {
      Swal.fire({ icon: 'warning', text: 'El marbete ' + (code || '(vacío)') + ' no está pendiente en esta instalación.' });
    } else {
      row.querySelector('.receive-check').checked = true;
      row.classList.add('table-success');
      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    scanner.value = '';
  });

  document.querySelector('#confirmReception').addEventListener('click', async () => {
    const weighingIds = checks().filter((check) => check.checked).map((check) => Number(check.value));
    if (!warehouse.value || !location.value || weighingIds.length === 0) {
      return Swal.fire({ icon: 'warning', text: 'Selecciona almacén, ubicación y al menos un marbete.' });
    }
    const confirmation = await Swal.fire({ icon: 'question', title: 'Confirmar recepción física', text: weighingIds.length + ' unidad(es) entrarán al inventario.', showCancelButton: true, confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar', confirmButtonColor: '#16845b' });
    if (!confirmation.isConfirmed) return;
    const button = document.querySelector('#confirmReception'); button.disabled = true;
    try {
      const result = await GMar.api('/internal/v1/receptions', { method: 'POST', body: JSON.stringify({ company_id: Number(data.companyId), site_id: Number(data.siteId), warehouse_id: Number(warehouse.value), location_id: Number(location.value), weighing_ids: weighingIds }) });
      await Swal.fire({ icon: 'success', title: result.folio, text: result.stock_units.length + ' unidad(es) creadas en inventario.' });
      window.location.reload();
    } catch (error) {
      await Swal.fire({ icon: 'error', title: error.code || 'Recepción rechazada', text: error.message });
    } finally { button.disabled = !navigator.onLine; }
  });
})();
