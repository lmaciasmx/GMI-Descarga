(() => {
  'use strict';
  document.querySelectorAll('[data-audit-detail]').forEach((button) => button.addEventListener('click', () => {
    const data = JSON.parse(document.querySelector(`#audit-${button.dataset.auditDetail}`).textContent);
    const safe = (value) => String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
    const html = `<div class="text-start"><h3 class="h6">Antes</h3><pre class="audit-json">${safe(JSON.stringify(data.before, null, 2))}</pre><h3 class="h6">Después</h3><pre class="audit-json">${safe(JSON.stringify(data.after, null, 2))}</pre></div>`;
    Swal.fire({ title: 'Detalle auditado', html, width: 820, confirmButtonText: 'Cerrar' });
  }));
})();
