(() => {
  'use strict';

  const ctxNode = document.getElementById('wr-context');
  if (!ctxNode) return;

  let context = {};
  try { context = JSON.parse(ctxNode.textContent || '{}'); } catch (_) { return; }
  if (context.mode !== 'outgoing') return;

  const $ = (id) => document.getElementById(id);
  const original = $('wr-outgoing-request');
  const visual = $('wr-outgoing-request-v2');

  // La card funcional #wr-outgoing-request es ahora la única representación
  // visible de la solicitud. El espejo V2 se conserva sólo por compatibilidad
  // interna, pero nunca debe ocupar espacio ni generar scroll/duplicados.
  if (visual) {
    visual.style.setProperty('display', 'none', 'important');
    visual.setAttribute('aria-hidden', 'true');
  }

  const count = $('wr-v2-request-count');
  const submit = $('wr-unit-submit');
  const communication = $('wr-v2-communication');
  const communicationLabel = $('wr-v2-communication-label');
  const footerFolio = $('wr-v2-footer-folio');
  const footerDestination = $('wr-v2-footer-destination');
  const footerDestinationLabel = $('wr-v2-footer-destination-label');
  const footerDate = $('wr-v2-footer-date');
  const footerTime = $('wr-v2-footer-time');
  const weightRaw = $('wr-weight-raw');
  const weight = $('wr-weight');

  if (!original || !visual) return;

  let syncing = false;

  const esc = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const text = (node) => String(node?.textContent || '').trim();

  const alternatingMap = (container) => {
    const result = {};
    if (!container) return result;
    const children = [...container.children];
    for (let i = 0; i < children.length - 1; i += 2) {
      const key = text(children[i]).replace(/:$/, '');
      const value = text(children[i + 1]);
      if (key) result[key] = value;
    }
    return result;
  };

  const progressPercent = () => {
    const bar = original.querySelector('.wr-request-progress > div > span');
    const inline = String(bar?.style?.width || '').match(/([\d.]+)%/);
    if (inline) return Math.max(0, Math.min(100, Number(inline[1]) || 0));
    const label = text(original.querySelector('.wr-request-progress small'));
    const match = label.match(/(\d+(?:\.\d+)?)%/);
    return match ? Math.max(0, Math.min(100, Number(match[1]) || 0)) : 0;
  };

  const statusInfo = () => {
    const statusNode = original.querySelector('.wr-history-status, .wr-request-folio-line .badge');
    return text(statusNode) || 'En surtido';
  };

  const renderRequest = () => {
    if (syncing) return;
    syncing = true;

    try {
      const folio = text(original.querySelector('.wr-request-folio-line > strong'));
      if (!folio) {
        visual.innerHTML = '<div class="wr-v2-request-empty"><i class="bi bi-file-earmark-text"></i><span>Escanea una tina para identificar la solicitud.</span></div>';
        if (count) count.textContent = '0 de 0';
        if (footerFolio) footerFolio.textContent = 'Sin folio';
        if (footerDestination) footerDestination.textContent = '—';
        if (footerDestinationLabel) footerDestinationLabel.textContent = 'Destino:';
        return;
      }

      const grids = original.querySelectorAll('.wr-request-detected-grid > div');
      const left = alternatingMap(grids[0]);
      const right = alternatingMap(grids[1]);

      const isSale = !!original.querySelector('.wr-history-type.sale') || /venta a cliente/i.test(left['Tipo'] || '');
      const type = isSale ? 'Venta a cliente' : (left['Tipo'] || 'Solicitud');
      const origin = left['Origen'] || '—';
      const destinationRaw = left['Destino'] || '—';
      const destination = destinationRaw.replace(/^Cliente:\s*/i, '') || '—';
      const product = right['Producto actual'] || '—';
      const lot = right['Lote'] || 'Cualquier lote';
      const status = statusInfo();
      const pct = progressPercent();
      const progressText = text(original.querySelector('.wr-request-titlebar > strong')) ||
        text(original.querySelector('.wr-request-progress small'));

      if (count) count.textContent = '1 de 1';
      if (footerFolio) footerFolio.textContent = folio;
      if (footerDestination) footerDestination.textContent = destination;
      if (footerDestinationLabel) footerDestinationLabel.textContent = isSale ? 'Cliente:' : 'Destino:';

      // La vista funcional original está oculta visualmente en el rediseño V2,
      // pero ahí vive la lógica de "Finalizar salida". Copiamos únicamente
      // ese bloque hacia la tarjeta visible para conservar la lógica existente.
      const finalizeSource = [...original.querySelectorAll('.alert')]
        .find((node) => /finalizar salida/i.test(text(node)));
      const finalizeButton = finalizeSource?.querySelector('[data-action="finalize-sale"]') || null;
      const finalizeReady = Boolean(isSale && finalizeButton && !finalizeButton.disabled);
      const finalizeButtonHtml = finalizeReady
        ? finalizeButton.outerHTML.replace(
            'btn btn-sm btn-primary flex-shrink-0',
            'btn btn-sm btn-primary ms-auto flex-shrink-0 wr-v2-finalize-sale'
          )
        : '';
      const finalizePendingHtml = isSale && finalizeSource && !finalizeReady
        ? '<span class="wr-v2-request-badge status ms-auto"><i class="bi bi-hourglass-split"></i>Cierre pendiente</span>'
        : '';

      visual.innerHTML = `
        <div class="wr-v2-request-folio-line">
          <span class="wr-v2-request-folio-label">Folio</span>
          <strong class="wr-v2-request-folio">${esc(folio)}</strong>
        </div>
        <div class="wr-v2-request-folio-line mt-1">
          <span class="wr-v2-request-badge type">${esc(type)}</span>
          <span class="wr-v2-request-badge status">${esc(status)}</span>
          ${finalizeButtonHtml}
          ${finalizePendingHtml}
        </div>
        <div class="wr-v2-request-progress">
          <div class="wr-v2-request-progress-track"><span style="width:${pct}%"></span></div>
          <strong>${pct}%</strong>
          <small>${esc(progressText)}</small>
        </div>
        <div class="wr-v2-request-info">
          <div class="wr-v2-request-info-column">
            <div class="wr-v2-request-info-item">
              <i class="bi bi-person"></i>
              <div><span>${isSale ? 'Cliente' : 'Destino'}</span><strong>${esc(destination)}</strong></div>
            </div>
            <div class="wr-v2-request-info-item">
              <i class="bi bi-building"></i>
              <div><span>Origen</span><strong>${esc(origin)}</strong></div>
            </div>
          </div>
          <div class="wr-v2-request-info-column">
            <div class="wr-v2-request-info-item">
              <i class="bi bi-fish"></i>
              <div><span>Producto actual</span><strong>${esc(product)}</strong></div>
            </div>
            <div class="wr-v2-request-info-item">
              <i class="bi bi-tag"></i>
              <div><span>Lote</span><strong>${esc(lot)}</strong></div>
            </div>
          </div>
        </div>
      `;
    } finally {
      syncing = false;
    }
  };

  const decorateSubmit = () => {
    if (!submit) return;

    const plain = text(submit);
    if (!/registrar salida/i.test(plain) && !submit.querySelector('.wr-v2-submit-main')) return;

    if (!submit.querySelector('.wr-v2-submit-main')) {
      submit.innerHTML = `
        <span class="wr-v2-submit-main"><i class="bi bi-send-fill"></i> Registrar salida</span>
        <span class="wr-v2-submit-sub">Confirma el peso y registra la tina en la solicitud.</span>
      `;
    }
  };

  const decorateReading = () => {
    const row = document.querySelector('.wr-weight-label-row');
    if (!row) return;

    let badge = row.querySelector('.wr-v2-reading-state');
    const raw = text(weightRaw);
    const current = text(weight);
    const hasReading = current && current !== '—' && current !== 'Sin lectura' &&
      raw && !/^sin lectura$/i.test(raw);

    if (hasReading) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'wr-v2-reading-state';
        row.appendChild(badge);
      }
      badge.textContent = /estable/i.test(raw) ? 'Lectura estable' : 'Lectura capturada';
    } else if (badge) {
      badge.remove();
    }
  };

  const setConnection = () => {
    if (!communication || !communicationLabel) return;
    const online = navigator.onLine !== false;
    communication.classList.toggle('online', online);
    communication.classList.toggle('offline', !online);
    communicationLabel.textContent = online ? 'En línea' : 'Sin conexión';
  };

  const refreshClock = () => {
    const now = new Date();
    if (footerDate) {
      footerDate.textContent = new Intl.DateTimeFormat('es-MX', {
        day:'2-digit', month:'short', year:'numeric',
      }).format(now);
    }
    if (footerTime) {
      footerTime.textContent = new Intl.DateTimeFormat('es-MX', {
        hour:'2-digit', minute:'2-digit', hour12:false,
      }).format(now);
    }
  };

  const scheduleSync = () => {
    window.requestAnimationFrame(() => {
      renderRequest();
      decorateSubmit();
      decorateReading();
    });
  };

  const observer = new MutationObserver(scheduleSync);
  observer.observe(original, { childList:true, subtree:true, characterData:true, attributes:true });

  if (submit) {
    new MutationObserver(decorateSubmit).observe(submit, { childList:true, subtree:true, characterData:true });
  }
  if (weightRaw) {
    new MutationObserver(decorateReading).observe(weightRaw, { childList:true, subtree:true, characterData:true });
  }
  if (weight) {
    new MutationObserver(decorateReading).observe(weight, { childList:true, subtree:true, characterData:true });
  }

  window.addEventListener('online', setConnection);
  window.addEventListener('offline', setConnection);

  setConnection();
  refreshClock();
  window.setInterval(refreshClock, 30000);
  scheduleSync();
})();
