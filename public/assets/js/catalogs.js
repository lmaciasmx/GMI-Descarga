(() => {
  'use strict';

  const form = document.querySelector('[data-catalog-form]');
  const modalElement = document.querySelector('#catalogModal');
  if (!form || !modalElement) return;

  const context = JSON.parse(document.querySelector('#catalogContext').textContent);
  const rows = JSON.parse(document.querySelector('#catalogRows').textContent);
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  const isPrinterCatalog = context.catalog === 'printers';
  const isScaleCatalog = context.catalog === 'scales';
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

  function applyScaleDefaults(row) {
    if (!isScaleCatalog || row) return;
    const defaults = {
      model: 'Báscula serial / RS-232',
      connection_type: 'usb_serial',
      adapter_key: 'usb_serial',
      agent_url: 'http://127.0.0.1:18182',
      baud_rate: '9600',
      data_bits: '8',
      parity: 'none',
      stop_bits: '1',
      read_command: 'P',
      command_terminator: 'none',
      response_timeout_ms: '2000',
      settle_delay_ms: '250',
      stable_reads: '2',
      stable_tolerance_kg: '0.050',
      weight_regex: '[-+]?[0-9]+(?:[.,][0-9]+)?',
      unit: 'kg',
    };
    Object.entries(defaults).forEach(([name, value]) => {
      const input = form.elements.namedItem(name);
      if (input) input.value = value;
    });
  }

  function refreshScaleMode() {
    if (!isScaleCatalog) return;
    const connection = form.elements.namedItem('connection_type')?.value || 'usb_serial';
    const adapter = form.elements.namedItem('adapter_key');
    if (adapter && ['simulator', 'rs232', 'usb_serial', 'tcp', 'middleware'].includes(connection)) adapter.value = connection;
    const serial = ['rs232', 'usb_serial'].includes(connection);
    const physical = connection !== 'simulator';
    const agentFields = new Set(['agent_url']);
    const serialFields = new Set(['port_name','baud_rate','data_bits','parity','stop_bits','read_command','command_terminator','response_timeout_ms','settle_delay_ms','stable_reads','stable_tolerance_kg','weight_regex','unit']);
    form.querySelectorAll('[data-scale-field]').forEach((wrapper) => {
      const name = wrapper.dataset.scaleField;
      if (agentFields.has(name)) wrapper.hidden = !physical;
      else if (serialFields.has(name)) wrapper.hidden = !serial;
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
    applyScaleDefaults(row);
    refreshPrinterMode();
    refreshScaleMode();
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

  function scaleAgentUrl(row) {
    return String(row.agent_url || 'http://127.0.0.1:18182').replace(/\/$/, '');
  }

  async function scaleAgentFetch(row, path, options = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 12000);
    try {
      const response = await fetch(`${scaleAgentUrl(row)}${path}`, {
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
      if (error.name === 'AbortError') throw new Error('El agente de báscula no respondió a tiempo.');
      if (error instanceof TypeError) throw new Error('No fue posible conectar con el agente de báscula. Verifica que esté iniciado en 127.0.0.1:18182.');
      throw error;
    } finally {
      clearTimeout(timer);
    }
  }

  function scaleConfiguration(row) {
    return {
      scale_identifier: row.station_code || row.code || 'BASCULA',
      station_code: row.station_code || '',
      model: row.model || '',
      port_name: String(row.port_name || '').toUpperCase(),
      baud_rate: Number(row.baud_rate || 9600),
      data_bits: Number(row.data_bits || 8),
      parity: row.parity || 'none',
      stop_bits: String(row.stop_bits || '1'),
      read_command: row.read_command ?? 'P',
      command_terminator: row.command_terminator || 'none',
      response_timeout_ms: Number(row.response_timeout_ms || 2000),
      settle_delay_ms: Number(row.settle_delay_ms || 250),
      stable_reads: Number(row.stable_reads || 2),
      stable_tolerance_kg: Number(row.stable_tolerance_kg || 0.050),
      weight_regex: row.weight_regex || '[-+]?[0-9]+(?:[.,][0-9]+)?',
      unit: 'kg',
    };
  }

  async function recordScaleTest(row, status, message, reading = null) {
    const payload = {
      last_test_at: nowSql(),
      last_test_status: status,
      last_test_message: String(message).slice(0, 255),
    };
    if (reading && Number(reading.weight) > 0) {
      payload.last_read_weight_kg = Number(reading.weight).toFixed(3);
      payload.last_raw_value = String(reading.original_value || '').slice(0, 255);
    }
    try {
      await GMar.api(`/internal/v1/catalogs/scales/${row.id}`, { method: 'PUT', body: JSON.stringify(payload) });
    } catch (error) {
      console.warn('No se pudo guardar el resultado de la prueba de báscula.', error);
    }
  }

  async function detectScalePorts() {
    const urlInput = form.elements.namedItem('agent_url');
    const pseudoRow = { agent_url: urlInput?.value || 'http://127.0.0.1:18182' };
    Swal.fire({ title: 'Buscando puertos COM…', text: 'Consultando los puertos seriales disponibles en esta estación.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
      const result = await scaleAgentFetch(pseudoRow, '/api/v1/ports', { method: 'GET', headers: {} });
      const ports = Array.isArray(result.ports) ? result.ports : [];
      if (!ports.length) throw new Error('Windows no reportó puertos COM disponibles.');
      const options = {};
      ports.forEach((port) => { const name = String(port.name || port); options[name] = `${name}${port.description ? ' · ' + port.description : ''}`; });
      const selection = await Swal.fire({
        title: 'Selecciona el puerto de la báscula', input: 'select', inputOptions: options, inputPlaceholder: 'Puerto COM',
        showCancelButton: true, confirmButtonText: 'Usar puerto', cancelButtonText: 'Cancelar',
        inputValidator: (value) => !value ? 'Selecciona un puerto.' : undefined,
      });
      if (selection.isConfirmed) form.elements.namedItem('port_name').value = selection.value;
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'No se pudieron detectar puertos', text: error.message });
    }
  }

  async function testScaleAgent(row) {
    if (!row) return;
    Swal.fire({ title: 'Probando báscula…', text: `Validando ${row.port_name || 'puerto serial'} sin registrar un pesaje.`, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
      const status = await scaleAgentFetch(row, '/api/v1/status', { method: 'GET', headers: {} });
      const result = await scaleAgentFetch(row, '/api/v1/test-connection', { method: 'POST', body: JSON.stringify({ transport: row.connection_type, configuration: scaleConfiguration(row) }) });
      const message = `Agente ${status.version || ''} activo. ${result.port_name || row.port_name || 'Puerto'} disponible.`.trim();
      await recordScaleTest(row, 'ok', message);
      await Swal.fire({ icon: 'success', title: 'Báscula disponible', html: `<b>${escapeHtml(result.port_name || row.port_name || '')}</b><br><small>${escapeHtml(result.message || 'Puerto abierto y cerrado correctamente.')}</small>` });
      location.reload();
    } catch (error) {
      await recordScaleTest(row, 'error', error.message);
      Swal.fire({ icon: 'error', title: 'No se pudo validar la báscula', text: error.message });
    }
  }

  async function readScale(row) {
    if (!row) return;
    Swal.fire({ title: 'Leyendo báscula…', text: `Enviando comando ${row.read_command || 'P'} a ${row.port_name || 'la báscula'}.`, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
      const reading = await scaleAgentFetch(row, '/api/v1/read', { method: 'POST', body: JSON.stringify({ transport: row.connection_type, configuration: scaleConfiguration(row) }) });
      const message = `Lectura ${Number(reading.weight).toFixed(3)} kg · ${reading.stable ? 'estable' : 'no estable'}.`;
      await recordScaleTest(row, reading.stable ? 'ok' : 'error', message, reading);
      await Swal.fire({
        icon: reading.stable ? 'success' : 'warning',
        title: `${Number(reading.weight).toFixed(3)} kg`,
        html: `<div class="text-start"><b>Puerto:</b> ${escapeHtml(row.port_name || '')}<br><b>Estado:</b> ${reading.stable ? 'Estable' : 'No estable'}<br><b>RAW:</b> <code>${escapeHtml(reading.original_value || '')}</code></div>`,
      });
      location.reload();
    } catch (error) {
      await recordScaleTest(row, 'error', error.message);
      Swal.fire({ icon: 'error', title: 'No se pudo leer el peso', text: error.message });
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
      html: `Se enviará el <b>prototipo del marbete</b> con la leyenda <b>PRUEBA DE IMPRESIÓN - NO USAR EN OPERACIÓN</b> a <b>${escapeHtml(row.windows_printer_name || row.name)}</b>.`,
      showCancelButton: true,
      confirmButtonText: '<i class="bi bi-printer"></i> Imprimir prueba',
      cancelButtonText: 'Cancelar',
    });
    if (!confirm.isConfirmed) return;

    Swal.fire({ title: 'Enviando impresión…', text: 'La PC43t imprimirá el prototipo real marcado claramente como PRUEBA.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
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

          // Datos exclusivos para la impresión de prueba.
          is_test: true,
          test_legend: 'PRUEBA DE IMPRESION - NO USAR EN OPERACION',

          // Prototipo visual del marbete real.
          sample_label: {
            species_name: 'ATUN ALETA AMARILLA',
            species_code: 'ALBACORA',
            size: '20 - 40',
            lot: 'A260915-01',
            vessel: 'MARIA FERNANDA',
            voyage: '2',
            date: '15/09/2026',
            net_weight_kg: '850.000',
            tare_kg: '28.500',
            penalty_percent: '1.50',
            rsa: 'RSAMZ-2026-0342',
            tub: 'T-0158',
            barcode: 'ETQ-20260915-000158',
          },
        }),
      });
      const message = `Prueba enviada a ${row.windows_printer_name}. Trabajo ${result.job_id || 'aceptado'}.`;
      await recordPrinterTest(row, 'ok', message);
      await Swal.fire({ icon: 'success', title: 'Prueba enviada', text: 'Verifica que el prototipo salió completo y que la leyenda PRUEBA DE IMPRESIÓN sea claramente visible.' });
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
  document.querySelectorAll('[data-scale-test-agent]').forEach((button) => button.addEventListener('click', () => testScaleAgent(rows[button.dataset.scaleTestAgent])));
  document.querySelectorAll('[data-scale-read]').forEach((button) => button.addEventListener('click', () => readScale(rows[button.dataset.scaleRead])));
  form.elements.namedItem('connection_mode')?.addEventListener('change', refreshPrinterMode);
  form.elements.namedItem('connection_type')?.addEventListener('change', refreshScaleMode);
  document.querySelector('[data-printer-detect]')?.addEventListener('click', detectWindowsPrinters);
  document.querySelector('[data-scale-detect]')?.addEventListener('click', detectScalePorts);

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
