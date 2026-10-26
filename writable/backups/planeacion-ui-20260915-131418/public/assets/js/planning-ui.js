(() => {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const linesBody = document.querySelector('#planLines tbody');
  const detailBody = byId('planDetailLines');
  const detailSearch = byId('planDetailSearch');
  const validations = byId('planDetailValidations');

  const formatKg = (value) => `${Number(value || 0).toLocaleString('es-MX', { minimumFractionDigits: 3, maximumFractionDigits: 3 })} kg`;

  function updatePlanCompositionSummary() {
    if (!linesBody) return;
    const rows = Array.from(linesBody.querySelectorAll('tr'));
    const total = rows.reduce((sum, row) => sum + (Number(row.querySelector('[data-line="planned_weight_kg"]')?.value) || 0), 0);
    const label = `${rows.length} ${rows.length === 1 ? 'línea' : 'líneas'}`;
    if (byId('planLineCount')) byId('planLineCount').textContent = label;
    if (byId('planWeightTotal')) byId('planWeightTotal').textContent = formatKg(total);
    if (byId('planFooterLineCount')) byId('planFooterLineCount').textContent = String(rows.length);
    if (byId('planFooterWeight')) byId('planFooterWeight').textContent = formatKg(total);
  }

  function updateDetailCount() {
    if (!detailBody) return;
    const rows = Array.from(detailBody.querySelectorAll('tr'));
    const visible = rows.filter((row) => !row.hidden).length;
    const count = detailSearch?.value.trim() ? visible : rows.length;
    if (byId('planDetailLineCount')) byId('planDetailLineCount').textContent = `${count} ${count === 1 ? 'línea' : 'líneas'}`;
  }

  function filterDetailRows() {
    if (!detailBody) return;
    const term = String(detailSearch?.value || '').trim().toLocaleLowerCase('es-MX');
    Array.from(detailBody.querySelectorAll('tr')).forEach((row) => {
      row.hidden = Boolean(term) && !row.textContent.toLocaleLowerCase('es-MX').includes(term);
    });
    updateDetailCount();
  }

  function updateValidationSummary() {
    if (!validations) return;
    const items = Array.from(validations.children);
    const summary = byId('planValidationSummary');
    if (!summary) return;
    if (!items.length) {
      summary.textContent = 'Validando…';
      summary.className = 'planning-validation-summary';
      return;
    }
    const isOk = (element) => {
      const text = element.textContent.toLocaleLowerCase('es-MX');
      return element.querySelector('.text-success, .bg-success, .bi-check, .bi-check-circle, .bi-check-lg') !== null
        || text.includes('válid') || text.includes('correct') || text.includes('sin duplicados') || text.includes('capturada');
    };
    const ok = items.filter(isOk).length;
    summary.textContent = ok === items.length ? `${ok}/${items.length} correctas` : `${ok}/${items.length} correctas`;
    summary.className = `planning-validation-summary ${ok === items.length ? 'is-ok' : 'has-errors'}`;
    const details = byId('planValidationDetails');
    if (details && ok !== items.length) details.open = true;
  }

  if (linesBody) {
    linesBody.addEventListener('input', updatePlanCompositionSummary);
    linesBody.addEventListener('change', updatePlanCompositionSummary);
    new MutationObserver(updatePlanCompositionSummary).observe(linesBody, { childList: true, subtree: true });
    updatePlanCompositionSummary();
  }

  if (detailBody) {
    new MutationObserver(() => {
      filterDetailRows();
    }).observe(detailBody, { childList: true, subtree: true, characterData: true });
    updateDetailCount();
  }
  detailSearch?.addEventListener('input', filterDetailRows);

  if (validations) {
    new MutationObserver(updateValidationSummary).observe(validations, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class'] });
    updateValidationSummary();
  }

  document.querySelector('#planDetailModal')?.addEventListener('show.bs.modal', () => {
    if (detailSearch) detailSearch.value = '';
    filterDetailRows();
    const details = byId('planValidationDetails');
    if (details) details.open = false;
  });
})();
