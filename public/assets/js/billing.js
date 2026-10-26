(() => {
  'use strict';
  const context = JSON.parse(document.querySelector('#billingContext').textContent);
  const form = document.querySelector('[data-billing-form]');
  form?.addEventListener('submit', async (event) => {
    event.preventDefault(); event.stopImmediatePropagation(); const values = new FormData(form); const status = values.get('fiscal_status'); const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const payload = { company_id: context.companyId, site_id: context.siteId, external_system: 'INVoiice', external_document_id: String(values.get('external_document_id')).trim(), document_type: 'CFDI', fiscal_status: status, fiscal_uuid: String(values.get('fiscal_uuid') || '').trim() || null };
    if (values.get('sales_order_id')) payload.sales_order_id = Number(values.get('sales_order_id')); if (status === 'issued') payload.issued_at = now; if (status === 'canceled') payload.canceled_at = now;
    try { const result = await GMar.api('/internal/v1/billing/references', { method: 'POST', body: JSON.stringify(payload) }); await Swal.fire({ icon: 'success', title: 'Referencia registrada', text: result.inventory_changed ? 'Inventario afectado' : 'Inventario físico sin cambios' }); location.reload(); }
    catch (error) { Swal.fire({ icon: 'error', title: error.code || 'No se guardó', text: error.message }); }
  });
})();
