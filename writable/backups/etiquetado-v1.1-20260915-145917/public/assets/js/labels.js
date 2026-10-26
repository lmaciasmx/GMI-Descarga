(() => {
  'use strict';

  const source = document.querySelector('#labelStationData');
  if (!source || !globalThis.GMar) return;
  const data = JSON.parse(source.textContent || '{}');
  const template = document.querySelector('#labelTemplate');
  const printer = document.querySelector('#labelPrinter');

  const handleOutput = async (result, title) => {
    if (result.print?.document_html) {
      GMar.printHtml(result.print.document_html, title);
    }
    const footer = result.print?.download_available
      ? `<br><a class="btn btn-outline-primary mt-3" href="/operacion/etiquetas/trabajos/${Number(result.print_job_id)}/archivo"><i class="bi bi-file-earmark-pdf"></i> Descargar PDF</a>`
      : '';
    await Swal.fire({ icon: 'success', title, html: `Resultado: <strong>${String(result.print?.status || 'procesado').replaceAll('_', ' ')}</strong>${footer}`, confirmButtonColor: '#0d5f82' });
    location.reload();
  };

  document.querySelectorAll('[data-print-weighing]').forEach((button) => button.addEventListener('click', async () => {
    const existingTemplate = Number(button.dataset.existingTemplate || 0);
    const templateId = existingTemplate || Number(template.value || 0);
    if (!templateId) return Swal.fire({ icon: 'warning', text: 'Selecciona una plantilla de impresión.' });
    const confirmation = await Swal.fire({ icon: 'question', title: existingTemplate ? 'Reintentar impresión inicial' : 'Confirmar impresión inicial', text: 'Se registrará el trabajo, la plantilla y el usuario solicitante.', showCancelButton: true, confirmButtonText: 'Imprimir', cancelButtonText: 'Cancelar' });
    if (!confirmation.isConfirmed) return;
    button.disabled = true;
    try {
      const result = await GMar.api('/internal/v1/labels/print', { method: 'POST', body: JSON.stringify({ weighing_id: Number(button.dataset.printWeighing), template_id: templateId, printer_id: printer.value ? Number(printer.value) : null }) });
      await handleOutput(result, `Etiqueta ${result.label.code}`);
    } catch (error) {
      await Swal.fire({ icon: 'error', title: error.code || 'Impresión rechazada', text: error.message });
      button.disabled = !navigator.onLine;
    }
  }));

  document.querySelectorAll('[data-reprint-label]').forEach((button) => button.addEventListener('click', async () => {
    const prompt = await Swal.fire({ title: `Reimprimir ${button.dataset.labelCode}`, input: 'textarea', inputLabel: 'Motivo obligatorio', inputPlaceholder: 'Ej. etiqueta dañada durante maniobra', showCancelButton: true, confirmButtonText: 'Reimprimir', cancelButtonText: 'Cancelar', inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres' });
    if (!prompt.isConfirmed) return;
    button.disabled = true;
    try {
      const result = await GMar.api(`/internal/v1/labels/${button.dataset.reprintLabel}/reprint`, { method: 'POST', body: JSON.stringify({ reason: prompt.value.trim(), printer_id: printer.value ? Number(printer.value) : null }) });
      await handleOutput(result, `Reimpresión #${result.print_number}`);
    } catch (error) {
      await Swal.fire({ icon: 'error', title: error.code || 'Reimpresión rechazada', text: error.message });
      button.disabled = !navigator.onLine;
    }
  }));
})();
