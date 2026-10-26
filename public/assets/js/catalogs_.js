(() => {
  'use strict';

  const form = document.querySelector('[data-catalog-form]');
  const modalElement = document.querySelector('#catalogModal');
  if (!form || !modalElement) return;

  const context = JSON.parse(document.querySelector('#catalogContext').textContent);
  const rows = JSON.parse(document.querySelector('#catalogRows').textContent);
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  const isPrinterCatalog = context.catalog === 'printers';
  let editingId = null;

  function resetErrors() {
    form.querySelectorAll('.is-invalid').forEach((element) => element.classList.remove('is-invalid'));
    form.querySelectorAll('[data-field-error]').forEach((element) => { element.textContent = ''; });
  }

  function applyPrinterDefaults(row) {
    if (!isPrinterCatalog || row) return;
    const defaults = {
      model: 'Honeywell / Intermec PC43t',
      printer_type: 'thermal',
      connection_mode: 'local_agent',
      adapter_key: 'middleware',
      agent_url: 'http://127.0.0.1:18181',
      printer_language: 'zpl',
      dpi: '203',
      label_width_mm: '99.00',
      label_height_mm: '50.00',
      darkness: '10',
      speed_ips: '3.0',
      copies: '1',
    };
    Object.entries(defaults).forEach(([name, value]) => {
      const input = form.elements.namedItem(name);
      if (input) input.value = value;
    });
  }

  function refreshPrinterMode() {
    if (!isPrinterCatalog) return;
    const mode = form.elements.namedItem('connection_mode')?.value || 'local_agent';
    form.querySelectorAll('[data-printer-mode]').forEach((wrapper) => {
      wrapper.hidden = wrapper.dataset.printerMode !== mode;
    });
    const adapter = form.elements.namedItem('adapter_key');
    const type = form.elements.namedItem('printer_type');
    const language = form.elements.namedItem('printer_language');
    if (mode === 'local_agent') {
      if (adapter) adapter.value = 'middleware';
      if (type) type.value = 'thermal';
      if (language) language.value = 'zpl';
    } else if (mode === 'network_tcp') {
      if (adapter) adapter.value = 'zpl';
      if (type) type.value = 'thermal';
      if (language && !['zpl', 'ipl'].includes(language.value)) language.value = 'zpl';
    } else if (mode === 'browser') {
      if (adapter) adapter.value = 'browser';
      if (type) type.value = 'browser';
      if (language) language.value = 'html';
    } else if (mode === 'pdf') {
      if (adapter) adapter.value = 'pdf';
      if (type) type.value = 'pdf';
      if (language) language.value = 'pdf';
    }
  }

  function openForm(row = null) {
    form.reset();
    resetErrors();
    editingId = row?.id || null;
    if (!row && form.elements.namedItem('active')) form.elements.namedItem('active').checked = true;
    if (!row && form.elements.namedItem('is_default')) form.elements.namedItem('is_default').checked = false;
    document.querySelector('#catalogModalTitle').textContent = editingId ? `Editar registro #${editingId}` : 'Nuevo registro';
    form.querySelectorAll('[name]').forEach((element) => {
      if (!row || element.name === 'configuration') return;
      if (element.type === 'checkbox') element.checked = Number(row[element.name]) === 1;
      else if (row[element.name] !== null && row[element.name] !== undefined) element.value = row[element.name];
    });
    applyPrinterDefaults(row);
    refreshPrinterMode();
    if (globalThis.jQuery && jQuery.fn.select2) {
      jQuery('[data-select2-modal]').select2({ dropdownParent: jQuery(modalElement), width: '100%' }).trigger('change');
    }
    modal.show();
  }

  function payloadFromForm() {
    const payload = {};
    form.querySelectorAll('[name]').forEach((element) => {
      if (element.type === 'checkbox') payload[element.name] = element.checked;
      else if (context.json_fields.includes(element.name) && editingId && element.value.trim() === '') return;
      else payload[element.name] = element.value;
    });
    return payload;
  }

  function showFieldErrors(details = {}) {
    Object.entries(details || {}).forEach(([field, message]) => {
      const input = form.elements.namedItem(field);
      const feedback = form.querySelector(`[data-field-error="${CSS.escape(field)}"]`);
      input?.classList.add('is-invalid');
      if (feedback) feedback.textContent = String(message);
    });
  }

  function nowSql() {
    const d = new Date();
    const pad = (value) => String(value).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  }

  async function recordPrinterTest(row, status, message) {
    try {
      await GMar.api(`/internal/v1/catalogs/printers/${row.id}`, {
        method: 'PUT',
        body: JSON.stringify({ last_test_at: nowSql(), last_test_status: status, last_test_message: String(message).slice(0, 255) }),
      });
    } catch (error) {
      console.warn('No se pudo guardar el resultado de la prueba de impresora.', error);
    }
  }

  function agentUrl(row) {
    return String(row.agent_url || 'http://127.0.0.1:18181').replace(/\/$/, '');
  }

  async function agentFetch(row, path, options = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 7000);
    try {
      const response = await fetch(`${agentUrl(row)}${path}`, {
        ...options,
        signal: controller.signal,
        mode: 'cors',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.success === false) {
        throw new Error(data?.error?.message || data?.message || `El agente respondió HTTP ${response.status}.`);
      }
      return data.data || data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('El agente local no respondió a tiempo.');
      if (error instanceof TypeError) throw new Error('No fue posible conectar con el agente local. Verifica que esté iniciado y que el dominio esté permitido en su configuración.');
      throw error;
    } finally {
      clearTimeout(timer);
    }
  }

  async function detectWindowsPrinters() {
    const urlInput = form.elements.namedItem('agent_url');
    const pseudoRow = { agent_url: urlInput?.value || 'http://127.0.0.1:18181' };
    Swal.fire({ title: 'Buscando impresoras…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
      const result = await agentFetch(pseudoRow, '/api/v1/printers', { method: 'GET', headers: {} });
      const printers = Array.isArray(result.printers) ? result.printers : [];
      if (!printers.length) throw new Error('Windows no reportó impresoras instaladas.');
      const options = {};
      printers.forEach((printer) => { options[printer.name] = `${printer.name}${printer.driver ? ' · ' + printer.driver : ''}`; });
      const selection = await Swal.fire({
        title: 'Selecciona la impresora',
        input: 'select',
        inputOptions: options,
        inputPlaceholder: 'Impresora de Windows',
        showCancelButton: true,
        confirmButtonText: 'Usar impresora',
        cancelButtonText: 'Cancelar',
        inputValidator: (value) => !value ? 'Selecciona una impresora.' : undefined,
      });
      if (selection.isConfirmed) form.elements.namedItem('windows_printer_name').value = selection.value;
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'No se pudieron detectar impresoras', text: error.message });
    }
  }

  async function testPrinterAgent(row) {
    if (!row) return;
    Swal.fire({ title: 'Probando estación…', text: 'Comprobando agente local e impresora de Windows.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
      const status = await agentFetch(row, '/api/v1/status', { method: 'GET', headers: {} });
      const printers = await agentFetch(row, '/api/v1/printers', { method: 'GET', headers: {} });
      const items = Array.isArray(printers.printers) ? printers.printers : [];
      const expected = String(row.windows_printer_name || '').trim().toLowerCase();
      const found = items.find((item) => String(item.name || '').trim().toLowerCase() === expected);
      if (!found) throw new Error(`El agente está activo, pero Windows no reporta la impresora “${row.windows_printer_name || 'sin nombre'}”.`);
      const message = `Agente ${status.version || ''} activo. Impresora ${found.name} detectada.`.trim();
      await recordPrinterTest(row, 'ok', message);
      await Swal.fire({ icon: 'success', title: 'Estación lista', html: `<b>${escapeHtml(found.name)}</b><br><small>${escapeHtml(found.driver || found.driverName || 'Impresora detectada')}</small>` });
      location.reload();
    } catch (error) {
      await recordPrinterTest(row, 'error', error.message);
      Swal.fire({ icon: 'error', title: 'No se pudo validar la impresora', text: error.message });
    }
  }

  async function printTestLabel(row) {
    if (!row) return;
    const confirm = await Swal.fire({
      icon: 'question',
      title: '¿Imprimir marbete de prueba?',
      html: `Se enviará una etiqueta <b>NO OPERATIVA</b> a <b>${escapeHtml(row.windows_printer_name || row.name)}</b>.`,
      showCancelButton: true,
      confirmButtonText: '<i class="bi bi-printer"></i> Imprimir prueba',
      cancelButtonText: 'Cancelar',
    });
    if (!confirm.isConfirmed) return;

    Swal.fire({ title: 'Enviando impresión…', text: 'La PC43t debe imprimir un marbete marcado como PRUEBA.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
      const result = await agentFetch(row, '/api/v1/test-print', {
        method: 'POST',
        body: JSON.stringify({
          printer_name: row.windows_printer_name,
          station_code: row.station_code,
          model: row.model,
          dpi: Number(row.dpi || 203),
          label_width_mm: Number(row.label_width_mm || 99),
          label_height_mm: Number(row.label_height_mm || 50),
          darkness: Number(row.darkness || 10),
          speed_ips: Number(row.speed_ips || 3),
          copies: Number(row.copies || 1),
          language: row.printer_language || 'zpl',
        }),
      });
      const message = `Prueba enviada a ${row.windows_printer_name}. Trabajo ${result.job_id || 'aceptado'}.`;
      await recordPrinterTest(row, 'ok', message);
      await Swal.fire({ icon: 'success', title: 'Prueba enviada', text: 'Verifica físicamente que la etiqueta salió completa, orientada y en una sola pieza.' });
      location.reload();
    } catch (error) {
      await recordPrinterTest(row, 'error', error.message);
      Swal.fire({ icon: 'error', title: 'No se pudo imprimir', text: error.message });
    }
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
  }

  document.querySelector('[data-catalog-new]')?.addEventListener('click', () => openForm());
  document.querySelectorAll('[data-catalog-edit]').forEach((button) => button.addEventListener('click', () => openForm(rows[button.dataset.catalogEdit])));
  document.querySelectorAll('[data-printer-test-agent]').forEach((button) => button.addEventListener('click', () => testPrinterAgent(rows[button.dataset.printerTestAgent])));
  document.querySelectorAll('[data-printer-test-print]').forEach((button) => button.addEventListener('click', () => printTestLabel(rows[button.dataset.printerTestPrint])));
  form.elements.namedItem('connection_mode')?.addEventListener('change', refreshPrinterMode);
  document.querySelector('[data-printer-detect]')?.addEventListener('click', detectWindowsPrinters);

  document.querySelectorAll('[data-catalog-deactivate]').forEach((button) => button.addEventListener('click', async () => {
    const result = await Swal.fire({ icon: 'warning', title: '¿Desactivar registro?', text: 'Se conservará el historial y dejará de ofrecerse en nuevas operaciones.', showCancelButton: true, confirmButtonText: 'Desactivar', cancelButtonText: 'Cancelar', confirmButtonColor: '#b42318' });
    if (!result.isConfirmed) return;
    try {
      await GMar.api(`/internal/v1/catalogs/${context.catalog}/${button.dataset.catalogDeactivate}/deactivate`, { method: 'POST', body: '{}' });
      await Swal.fire({ icon: 'success', title: 'Registro desactivado', timer: 1200, showConfirmButton: false });
      location.reload();
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'No se pudo desactivar', text: error.message });
    }
  }));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();
    resetErrors();
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
      const url = editingId ? `/internal/v1/catalogs/${context.catalog}/${editingId}` : `/internal/v1/catalogs/${context.catalog}`;
      await GMar.api(url, { method: editingId ? 'PUT' : 'POST', body: JSON.stringify(payloadFromForm()) });
      modal.hide();
      await Swal.fire({ icon: 'success', title: 'Catálogo actualizado', timer: 1200, showConfirmButton: false });
      location.reload();
    } catch (error) {
      showFieldErrors(error.details);
      Swal.fire({ icon: 'error', title: 'No se pudo guardar', text: error.message });
    } finally {
      submit.disabled = !navigator.onLine;
      submit.innerHTML = '<i class="bi bi-check2-circle"></i> Guardar';
    }
  });
})();
