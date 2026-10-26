(() => {
  'use strict';

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const csrfHeader = document.querySelector('meta[name="csrf-header"]')?.content || 'X-CSRF-TOKEN';

  const idempotencyKey = () => globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;

  async function api(url, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    if (!navigator.onLine && !['GET', 'HEAD'].includes(method)) {
      throw new Error('Sin red: la operación no fue enviada ni confirmada.');
    }
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (options.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
    if (csrfMeta?.content) headers.set(csrfHeader, csrfMeta.content);
    if (!['GET', 'HEAD'].includes(method) && !headers.has('Idempotency-Key')) headers.set('Idempotency-Key', idempotencyKey());

    const response = await fetch(url, { ...options, method, headers, credentials: 'same-origin' });
    const refreshedCsrf = response.headers.get('X-CSRF-TOKEN');
    if (refreshedCsrf && csrfMeta) csrfMeta.content = refreshedCsrf;
    const body = await response.json().catch(() => ({ success: false, error: { message: 'Respuesta no válida del servidor.' } }));
    if (!response.ok || body.success === false) {
      const error = new Error(body.error?.message || `Error HTTP ${response.status}`);
      error.code = body.error?.code;
      error.details = body.error?.details;
      throw error;
    }
    return body.data;
  }

  function setNetworkState() {
    const online = navigator.onLine;
    document.body.classList.toggle('is-offline', !online);
    document.querySelectorAll('[data-network-status]').forEach((element) => {
      element.classList.toggle('online', online);
      element.classList.toggle('offline', !online);
      element.querySelector('[data-status-text]').textContent = online ? 'Red disponible' : 'Sin conexión';
    });
    document.querySelectorAll('.no-offline-confirm').forEach((button) => { button.disabled = !online; });
  }

  function initializeTables() {
    if (!globalThis.jQuery || !jQuery.fn.DataTable) return;
    jQuery('[data-datatable]').each(function () {
      if (jQuery.fn.dataTable.isDataTable(this)) return;
      const title = this.dataset.exportTitle;
      jQuery(this).DataTable({
        pageLength: 25,
        responsive: true,
        order: [],
        layout: title && jQuery.fn.dataTable.Buttons ? {
          topStart: { buttons: [
            { extend: 'csvHtml5', text: '<i class="bi bi-filetype-csv"></i> CSV', title, bom: true },
            { extend: 'excelHtml5', text: '<i class="bi bi-file-earmark-excel"></i> Excel', title }
          ] },
          topEnd: 'search'
        } : undefined,
        language: {
          search: 'Buscar:', lengthMenu: 'Mostrar _MENU_', info: '_START_–_END_ de _TOTAL_',
          infoEmpty: 'Sin registros', zeroRecords: 'Sin coincidencias', emptyTable: 'Sin datos disponibles',
          paginate: { first: 'Primera', previous: 'Anterior', next: 'Siguiente', last: 'Última' }
        }
      });
    });
  }

  function initializeSelects() {
    if (!globalThis.jQuery || !jQuery.fn.select2) {
        return;
    }

    jQuery('[data-select2]').each(function () {
        const element = this;
        const $element = jQuery(element);

        /*
         * data-select2 se usa en nuestras vistas como marcador,
         * pero Select2 también utiliza la clave "select2"
         * internamente. Se elimina antes de inicializar para
         * evitar que lo interprete como una instancia existente.
         */
        element.removeAttribute('data-select2');

        const cached = jQuery.data(element, 'select2');

        if (
            cached !== undefined
            && cached !== null
            && typeof cached.destroy !== 'function'
        ) {
            jQuery.removeData(element, 'select2');
        }

        if ($element.hasClass('select2-hidden-accessible')) {
            return;
        }

        $element.select2({
            theme: 'default',
            width: '100%',
            language: {
                noResults: () => 'Sin resultados'
            }
        });
    });
}

  function initializeFlash() {
    const flash = document.querySelector('[data-flash-message]');
    if (!flash || !globalThis.Swal) return;
    Swal.fire({ icon: flash.dataset.flashType || 'info', text: flash.dataset.flashMessage, confirmButtonColor: '#0d5f82' });
  }

  function printHtml(fragment, title = 'Etiqueta') {
    const frame = document.createElement('iframe');
    frame.title = title;
    frame.setAttribute('aria-hidden', 'true');
    frame.style.position = 'fixed';
    frame.style.width = '1px';
    frame.style.height = '1px';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.border = '0';
    frame.style.opacity = '0';
    frame.addEventListener('load', () => {
      window.setTimeout(() => {
        frame.contentWindow?.focus();
        frame.contentWindow?.print();
      }, 80);
    }, { once: true });
    frame.srcdoc = `<!doctype html><html lang="es"><head><meta charset="utf-8"><title>${String(title).replace(/[<>]/g, '')}</title></head><body>${fragment}</body></html>`;
    document.body.appendChild(frame);
    window.setTimeout(() => frame.remove(), 120000);
  }

  document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
  window.addEventListener('online', setNetworkState);
  window.addEventListener('offline', setNetworkState);
 
  globalThis.GMar = { api, idempotencyKey, setNetworkState, printHtml };
  setNetworkState();
  
    try {
    initializeTables();
	} catch (error) {
		console.error('[GMar] Error inicializando DataTables:', error);
	}

	try {
		initializeSelects();
	} catch (error) {
		console.error('[GMar] Error inicializando Select2:', error);
	}

	try {
		initializeFlash();
	} catch (error) {
		console.error('[GMar] Error inicializando mensajes:', error);
	}
  initializeTables();
  initializeSelects();
  initializeFlash();

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    form.querySelectorAll('[type="submit"]').forEach((button) => {
      button.disabled = true;
      button.dataset.originalText = button.textContent;
      button.textContent = 'Procesando…';
    });
  });

  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/service-worker.js').catch(() => {});

  
})();
