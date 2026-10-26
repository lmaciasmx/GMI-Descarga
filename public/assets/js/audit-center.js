(() => {
  'use strict';

  const modalElement = document.getElementById('auditDetailModal');
  const modalBody = modalElement?.querySelector('[data-audit-modal-body]');
  const modal = modalElement && window.bootstrap ? new bootstrap.Modal(modalElement) : null;

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-audit-open]');
    if (!button || !modal || !modalBody) return;

    const id = button.getAttribute('data-audit-open');
    const template = document.getElementById(`audit-detail-${id}`);
    if (!template) return;

    modalBody.replaceChildren(template.content.cloneNode(true));
    modal.show();
  });

  if (!window.jQuery?.fn?.DataTable) return;

  const $ = window.jQuery;

  const initTable = (selector, options = {}) => {
    if (!$(selector).length || $.fn.dataTable.isDataTable(selector)) return;

    $(selector).DataTable({
      pageLength: options.pageLength || 25,
      lengthMenu: [25, 50, 100],
      order: options.order || [[0, 'desc']],
      autoWidth: false,
      scrollX: true,
      scrollCollapse: true,
      language: {
        search: 'Buscar en resultados:',
        lengthMenu: 'Mostrar _MENU_',
        info: '_START_-_END_ de _TOTAL_ eventos',
        infoEmpty: 'Sin eventos',
        emptyTable: 'Sin eventos',
        zeroRecords: 'Sin coincidencias',
        paginate: { previous: 'Anterior', next: 'Siguiente' }
      }
    });
  };

  initTable('#auditEventsTable', { pageLength: 25, order: [[0, 'desc']] });
  initTable('#auditSecurityTable', { pageLength: 25, order: [[0, 'desc']] });
})();
