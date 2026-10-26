(() => {
  const root = document.querySelector('[data-reports-center]');
  if (!root) return;

  const search = document.getElementById('reportCatalogSearch');
  const cards = [...document.querySelectorAll('[data-report-card]')];

  search?.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    cards.forEach(card => {
      const haystack = (card.dataset.search || '').toLowerCase();
      card.hidden = q !== '' && !haystack.includes(q);
    });
  });

  if (window.jQuery?.fn?.DataTable) {
    const $ = window.jQuery;
    if ($('#activeReportTable').length && !$.fn.dataTable.isDataTable('#activeReportTable')) {
      $('#activeReportTable').DataTable({
        pageLength: 15,
        order: [],
        autoWidth: false,
        scrollX: true,
        scrollCollapse: true,
        language: {
          search: 'Buscar:',
          lengthMenu: 'Mostrar _MENU_',
          info: '_START_-_END_ de _TOTAL_',
          emptyTable: 'Sin datos para mostrar',
          zeroRecords: 'Sin coincidencias',
          paginate: { previous: 'Anterior', next: 'Siguiente' }
        }
      });
    }
  }

  const data = window.GRUPOMAR_REPORTS || {};
  const chartEl = document.getElementById('activeReportChart');
  if (chartEl && window.Chart && data.chart === 'species' && Array.isArray(data.rows)) {
    const labels = data.rows.map(r => r.species || 'Sin especie');
    const values = data.rows.map(r => Number(r.on_hand_kg || 0));

    new Chart(chartEl, {
      type: 'doughnut',
      data: { labels, datasets: [{ data: values }] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: { boxWidth: 10, usePointStyle: true }
          }
        }
      }
    });
  }
  if (window.jQuery?.fn?.DataTable) {
    const $ = window.jQuery;
    const historySelector = '#reportExportHistoryTable';

    if ($(historySelector).length && !$.fn.dataTable.isDataTable(historySelector)) {
      $(historySelector).DataTable({
        pageLength: 10,
        lengthChange: false,
        order: [[0, 'desc']],
        autoWidth: false,
        language: {
          search: 'Buscar:',
          info: '_START_-_END_ de _TOTAL_ exportaciones',
          emptyTable: 'Sin exportaciones registradas',
          zeroRecords: 'Sin coincidencias',
          paginate: { previous: 'Anterior', next: 'Siguiente' }
        }
      });
    }
  }

})();
