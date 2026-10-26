(() => {
  'use strict';

  const source = document.querySelector('#labelStationData');
  if (!source || !globalThis.GMar) return;
  const data = JSON.parse(source.textContent || '{}');
  const template = document.querySelector('#labelTemplate');
  const printer = document.querySelector('#labelPrinter');
  const directPrint = document.querySelector('#labelDirectPrint');
  const search = document.querySelector('#labelQuickSearch');
  const statusFilter = document.querySelector('#labelStatusFilter');
  const workDate = document.querySelector('#labelWorkDate');
  const summary = document.querySelector('[data-station-summary]');
  const storageKey = 'gmar.label.station.v1';
  const modalElement = document.querySelector('#labelStationConfigModal');
  const modal = modalElement && globalThis.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalElement) : null;

  const loadConfig = () => {
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (_) { saved = {}; }
    if (template) template.value = String(saved.templateId || data.defaultTemplateId || '');
    if (printer) printer.value = saved.printerId ? String(saved.printerId) : '';
    if (directPrint) directPrint.checked = saved.directPrint !== false;
    updateSummary();
  };

  const stationConfig = () => ({
    templateId: Number(template?.value || data.defaultTemplateId || 0),
    printerId: printer?.value ? Number(printer.value) : null,
    directPrint: Boolean(directPrint?.checked),
  });

  const updateSummary = () => {
    if (!summary) return;
    const config = stationConfig();
    const printerText = printer?.selectedOptions?.[0]?.textContent?.trim() || 'Predeterminado';
    summary.innerHTML = `<strong>${config.directPrint ? 'Impresión directa' : 'Sólo generar'}</strong><br>${printerText}`;
  };

  document.querySelector('[data-label-station-config]')?.addEventListener('click', () => modal?.show());
  document.querySelector('[data-save-label-station]')?.addEventListener('click', () => {
    const config = stationConfig();
    if (!config.templateId) {
      Swal.fire({ icon: 'warning', text: 'Selecciona una plantilla de etiqueta.' });
      return;
    }
    localStorage.setItem(storageKey, JSON.stringify(config));
    updateSummary();
    modal?.hide();
    Swal.fire({ icon: 'success', title: 'Estación configurada', text: 'La configuración se guardó en este equipo.', timer: 1400, showConfirmButton: false });
  });

  const directPrintDocument = (result, title) => {
    if (!stationConfig().directPrint || !result.print?.document_html) return false;
    GMar.printHtml(result.print.document_html, title);
    return true;
  };

  const handleOutput = async (result, title) => {
    const printedDirectly = directPrintDocument(result, title);
    const footer = result.print?.download_available
      ? `<br><a class="btn btn-outline-primary mt-3" href="/operacion/etiquetas/trabajos/${Number(result.print_job_id)}/archivo"><i class="bi bi-file-earmark-pdf"></i> Descargar PDF</a>`
      : '';
    await Swal.fire({
      icon: 'success',
      title,
      html: `${printedDirectly ? '<div class="mb-2">Se abrió la impresión directa.</div>' : ''}Resultado: <strong>${String(result.print?.status || 'procesado').replaceAll('_', ' ')}</strong>${footer}`,
      confirmButtonColor: '#0d5f82'
    });
    location.reload();
  };

  const errorMessage = async (error, fallback) => {
    const technical = String(error?.code || '');
    const friendly = error?.message || fallback;
    await Swal.fire({ icon: 'error', title: fallback, text: friendly, footer: technical ? `<small>Referencia: ${technical}</small>` : undefined });
  };

  document.querySelectorAll('[data-print-weighing]').forEach((button) => button.addEventListener('click', async () => {
    const config = stationConfig();
    const existingTemplate = Number(button.dataset.existingTemplate || 0);
    const templateId = existingTemplate || config.templateId;
    if (!templateId) return Swal.fire({ icon: 'warning', text: 'Configura primero la plantilla de esta estación.' });
    const confirmation = await Swal.fire({ icon: 'question', title: existingTemplate ? 'Reintentar impresión inicial' : 'Imprimir etiqueta', text: config.directPrint ? 'La etiqueta se generará y se abrirá directamente para impresión.' : 'Se generará el trabajo de impresión.', showCancelButton: true, confirmButtonText: 'Imprimir', cancelButtonText: 'Cancelar' });
    if (!confirmation.isConfirmed) return;
    button.disabled = true;
    try {
      const result = await GMar.api('/internal/v1/labels/print', { method: 'POST', body: JSON.stringify({ weighing_id: Number(button.dataset.printWeighing), template_id: templateId, printer_id: config.printerId }) });

      if (result.local_agent) {
        Swal.fire({ title: 'Imprimiendo…', text: 'Enviando el marbete a la PC43t.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
          const agentResult = await localAgentPrint(result.local_agent);
          await completeLocalJob(result.print_job_id, true, agentResult.job_id || agentResult.provider_reference || null, null);
        } catch (agentError) {
          await completeLocalJob(result.print_job_id, false, null, agentError.message).catch(() => null);
          throw agentError;
        }

        await Swal.fire({ icon: 'success', title: `Etiqueta ${result.label.code}`, text: 'La etiqueta fue enviada correctamente a la PC43t y el trabajo quedó confirmado en el servidor.' });
        location.reload();
        return;
      }

      await handleOutput(result, `Etiqueta ${result.label.code}`);
    } catch (error) {
      await errorMessage(error, 'No se pudo imprimir');
      button.disabled = !navigator.onLine;
    }
  }));


  const localAgentPrint = async (payload) => {
    const agentUrl = String(payload?.agent_url || 'http://127.0.0.1:18181').replace(/\/$/, '');
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 12000);
    try {
      const response = await fetch(`${agentUrl}/api/v1/print`, {
        method: 'POST',
        mode: 'cors',
        cache: 'no-store',
        signal: controller.signal,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          printer_name: payload.printer_name,
          format: payload.format || 'zpl',
          content: payload.content || '',
        }),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok || body.success === false) {
        throw new Error(body?.error?.message || body?.message || `El agente respondió HTTP ${response.status}.`);
      }
      return body.data || body;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('El agente local no respondió a tiempo.');
      if (error instanceof TypeError) throw new Error('No fue posible conectar con el agente local de impresión. Verifica que Node esté iniciado.');
      throw error;
    } finally {
      clearTimeout(timer);
    }
  };

  const completeLocalJob = async (jobId, successful, providerReference = null, errorMessage = null) => {
    return GMar.api(`/internal/v1/labels/jobs/${Number(jobId)}/local-result`, {
      method: 'POST',
      body: JSON.stringify({
        successful: successful ? 1 : 0,
        provider_reference: providerReference,
        error_message: errorMessage,
      }),
    });
  };

  document.querySelectorAll('[data-reprint-label]').forEach((button) => button.addEventListener('click', async () => {
    const config = stationConfig();
    if (!config.printerId) {
      return Swal.fire({ icon: 'warning', text: 'Selecciona la impresora PC43t en Configuración de estación.' });
    }

    const prompt = await Swal.fire({
      title: `Reimprimir ${button.dataset.labelCode}`,
      html: `<div class="text-start small text-secondary mb-2">Tina ${String(button.dataset.tubCode || '')}</div>`,
      input: 'select',
      inputOptions: {
        'Etiqueta dañada durante maniobra': 'Etiqueta dañada',
        'Etiqueta ilegible': 'Etiqueta ilegible',
        'Etiqueta extraviada': 'Etiqueta extraviada',
        'Error de impresión': 'Error de impresión',
        'Otro motivo operativo': 'Otro motivo operativo'
      },
      inputPlaceholder: 'Selecciona el motivo',
      showCancelButton: true,
      confirmButtonText: 'Reimprimir',
      cancelButtonText: 'Cancelar',
      inputValidator: (value) => value ? undefined : 'Selecciona un motivo'
    });
    if (!prompt.isConfirmed) return;

    button.disabled = true;
    let prepared = null;
    try {
      Swal.fire({ title: 'Preparando reimpresión…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
      prepared = await GMar.api(`/internal/v1/labels/${button.dataset.reprintLabel}/reprint`, {
        method: 'POST',
        body: JSON.stringify({ reason: prompt.value, printer_id: config.printerId })
      });

      if (prepared.local_agent) {
        Swal.fire({ title: 'Imprimiendo…', text: 'Enviando el marbete a la PC43t.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
          const agentResult = await localAgentPrint(prepared.local_agent);
          await completeLocalJob(prepared.print_job_id, true, agentResult.job_id || agentResult.provider_reference || null, null);
        } catch (agentError) {
          await completeLocalJob(prepared.print_job_id, false, null, agentError.message).catch(() => null);
          throw agentError;
        }

        await Swal.fire({ icon: 'success', title: `Reimpresión #${prepared.print_number}`, text: 'La etiqueta fue enviada correctamente a la PC43t.' });
        location.reload();
        return;
      }

      await handleOutput(prepared, `Reimpresión #${prepared.print_number}`);
    } catch (error) {
      await errorMessage(error, 'No se pudo reimprimir');
      button.disabled = !navigator.onLine;
    }
  }));

  const table = globalThis.jQuery && jQuery.fn.DataTable && document.querySelector('#labelOperationsTable')
    ? jQuery('#labelOperationsTable').DataTable({
        pageLength: 50,
        lengthMenu: [25, 50, 100, 200, 400],
        order: [[0, 'desc']],
        deferRender: true,
        language: {
          search: 'Buscar:', lengthMenu: 'Mostrar _MENU_', info: '_START_–_END_ de _TOTAL_',
          infoEmpty: 'Sin registros', zeroRecords: 'Sin coincidencias', emptyTable: 'Sin datos disponibles',
          paginate: { first: 'Primera', previous: 'Anterior', next: 'Siguiente', last: 'Última' }
        }
      })
    : null;

  search?.addEventListener('input', () => table?.search(search.value).draw());
  statusFilter?.addEventListener('change', () => table?.column(5).search(statusFilter.value).draw());
  workDate?.addEventListener('change', () => {
    if (!workDate.value) return;
    const url = new URL(location.href);
    url.searchParams.set('fecha', workDate.value);
    location.assign(url.toString());
  });

  loadConfig();
})();
