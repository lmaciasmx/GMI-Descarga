(() => {
  const source = document.querySelector('#speciesChartData');
  const canvas = document.querySelector('#speciesChart');

  if (!source || !canvas || !globalThis.Chart) return;

  let rows = [];

  try {
    rows = JSON.parse(source.textContent || '[]');
  } catch (_) {
    rows = [];
  }

  if (!Array.isArray(rows) || rows.length === 0) return;

  const palette = [
    '#136ab1',
    '#3d91d5',
    '#25aeba',
    '#6bc89a',
    '#c6d2dc'
  ];

  new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels: rows.map((row) => String(row.name || 'Especie')),
      datasets: [{
        data: rows.map((row) => Number(row.kg || 0)),
        backgroundColor: rows.map((_, index) => palette[index % palette.length]),
        borderColor: '#ffffff',
        borderWidth: 2,
        hoverOffset: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '68%',
      animation: {
        duration: 350
      },
      plugins: {
        legend: {
          display: false
        },
        tooltip: {
          callbacks: {
            label(context) {
              const kg = Number(context.raw || 0);
              return `${context.label}: ${(kg / 1000).toFixed(2)} t`;
            }
          }
        }
      }
    }
  });
})();
