(() => {
  'use strict';

  const contextNode = document.getElementById('wr-context');
  if (!contextNode) return;

  let context = {};
  try {
    context = JSON.parse(contextNode.textContent || '{}');
  } catch (_) {
    return;
  }

  if (context.mode !== 'outgoing') return;

  const original = document.getElementById('wr-outgoing-request');
  const visual = document.getElementById('wr-outgoing-request-v2');
  if (!original || !visual || !window.GMar || typeof window.GMar.api !== 'function') return;

  const saleRoot = '/internal/v1/sales/requests';
  const historyRoot = '/internal/v1/sales/requests/history';

  let refreshTimer = null;
  let requestSeq = 0;
  let currentSaleId = 0;
  let currentFolio = '';

  const num = (value) => Number(value || 0);
  const kg = (value) => num(value).toLocaleString('es-MX', {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });

  const isSaleContext = () =>
    Boolean(original.querySelector('.wr-history-type.sale')) ||
    /venta a cliente/i.test(original.textContent || '');

  const folioFromDom = () =>
    String(original.querySelector('.wr-request-folio-line > strong')?.textContent || '').trim();

  const removePanel = () => {
    document.getElementById('wr-sale-finalize-bridge')?.remove();
  };

  const showMessage = (message, type='success') => {
    const box = document.getElementById('wr-message');
    if (!box) return;
    box.className = 'alert alert-' + type;
    box.textContent = message;
    box.classList.remove('d-none');
  };

  const ensureStyles = () => {
    if (document.getElementById('wr-sale-finalize-bridge-style')) return;
    const style = document.createElement('style');
    style.id = 'wr-sale-finalize-bridge-style';
    style.textContent = `
      #wr-sale-finalize-bridge{
        flex:0 0 auto;
        padding:12px 14px;
        border-top:1px solid #e0e8f0;
        background:#fff;
      }
      #wr-sale-finalize-bridge .wr-finalize-button{
        width:100%;
        min-height:48px;
        display:flex;
        align-items:center;
        justify-content:center;
        border-radius:9px;
        font-weight:850;
        white-space:nowrap;
      }
      #wr-sale-finalize-dialog{
        width:min(620px,calc(100vw - 32px));
        border:0;
        border-radius:14px;
        padding:0;
        box-shadow:0 22px 70px rgba(15,38,65,.25);
      }
      #wr-sale-finalize-dialog::backdrop{
        background:rgba(10,25,45,.44);
      }
      #wr-sale-finalize-dialog .wr-finalize-dialog-body{
        padding:20px;
      }
    `;
    document.head.appendChild(style);
  };

  const findSaleId = async (folio) => {
    const params = new URLSearchParams({
      page: '1',
      per_page: '20',
      type: 'sale',
      search: folio,
    });

    const result = await GMar.api(historyRoot + '?' + params.toString());
    const rows = Array.isArray(result?.rows) ? result.rows : [];
    const exact = rows.find((row) =>
      String(row?.source || '') === 'sale' &&
      String(row?.folio || '').trim() === folio
    );

    return num(exact?.id);
  };

  const getDetail = (saleId) => GMar.api(saleRoot + '/' + saleId);

  const createDialog = () => {
    let dialog = document.getElementById('wr-sale-finalize-dialog');
    if (dialog) return dialog;

    dialog = document.createElement('dialog');
    dialog.id = 'wr-sale-finalize-dialog';
    dialog.innerHTML = `
      <form method="dialog" class="wr-finalize-dialog-body" id="wr-sale-finalize-form">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
          <div>
            <h2 class="h5 mb-1">Finalizar salida de venta</h2>
            <div class="small text-secondary" id="wr-sale-finalize-dialog-summary"></div>
          </div>
          <button type="button" class="btn-close" id="wr-sale-finalize-dialog-close" aria-label="Cerrar"></button>
        </div>

        <div class="alert alert-info small">
          La venta se cerrará con las tinas completas ya despachadas.
          No se agregará otra tina sólo para completar una diferencia de kilos.
        </div>

        <div id="wr-sale-finalize-reason-wrap">
          <label class="form-label" for="wr-sale-finalize-reason">Motivo de la diferencia</label>
          <textarea
            class="form-control"
            id="wr-sale-finalize-reason"
            rows="3"
            minlength="5"
            maxlength="1000"
            placeholder="Ej. Diferencia por peso real de las tinas completas despachadas."
          ></textarea>
        </div>

        <div id="wr-sale-finalize-dialog-error" class="alert alert-danger d-none mt-3 mb-0"></div>

        <div class="d-flex justify-content-end gap-2 mt-4">
          <button type="button" class="btn btn-outline-secondary" id="wr-sale-finalize-dialog-cancel">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="wr-sale-finalize-dialog-submit">
            Finalizar salida
          </button>
        </div>
      </form>
    `;

    document.body.appendChild(dialog);

    const close = () => {
      if (dialog.open) dialog.close();
    };

    dialog.querySelector('#wr-sale-finalize-dialog-close')?.addEventListener('click', close);
    dialog.querySelector('#wr-sale-finalize-dialog-cancel')?.addEventListener('click', close);

    return dialog;
  };

  const openFinalizeDialog = async (saleId) => {
    const dialog = createDialog();
    const error = dialog.querySelector('#wr-sale-finalize-dialog-error');
    const submit = dialog.querySelector('#wr-sale-finalize-dialog-submit');

    try {
      error.classList.add('d-none');
      error.textContent = '';
      submit.disabled = true;

      const detail = await getDetail(saleId);
      const closure = detail?.closure || {};
      const order = detail?.order || {};

      if (String(order.status || '') === 'dispatched') {
        throw new Error('Esta venta ya está finalizada.');
      }

      if (!closure.can_finalize) {
        throw new Error(closure.message || 'La venta todavía no cumple el criterio de cierre.');
      }

      const requested = num(closure.requested_kg);
      const dispatched = num(closure.dispatched_kg);
      const difference = num(closure.difference_kg);
      const requiresReason = closure.requires_reason === true || num(closure.requires_reason) === 1;

      const summary = dialog.querySelector('#wr-sale-finalize-dialog-summary');
      summary.textContent =
        'Solicitado ' + kg(requested) + ' kg · ' +
        'Despachado ' + kg(dispatched) + ' kg · ' +
        'Diferencia ' + kg(difference) + ' kg';

      const reasonWrap = dialog.querySelector('#wr-sale-finalize-reason-wrap');
      const reason = dialog.querySelector('#wr-sale-finalize-reason');
      reasonWrap.classList.toggle('d-none', !requiresReason);
      reason.required = requiresReason;
      reason.value = '';

      submit.disabled = false;

      const form = dialog.querySelector('#wr-sale-finalize-form');
      form.onsubmit = async (event) => {
        event.preventDefault();

        try {
          error.classList.add('d-none');
          error.textContent = '';

          const fresh = await getDetail(saleId);
          const freshClosure = fresh?.closure || {};
          if (!freshClosure.can_finalize) {
            throw new Error(freshClosure.message || 'La venta dejó de cumplir el criterio de cierre.');
          }

          const freshRequiresReason =
            freshClosure.requires_reason === true ||
            num(freshClosure.requires_reason) === 1;

          const reasonValue = freshRequiresReason ? String(reason.value || '').trim() : '';
          if (freshRequiresReason && reasonValue.length < 5) {
            throw new Error('Indica un motivo de al menos cinco caracteres.');
          }

          submit.disabled = true;

          await GMar.api(saleRoot + '/' + saleId + '/finalize', {
            method: 'POST',
            body: JSON.stringify({ reason: reasonValue }),
          });

          dialog.close();
          removePanel();
          showMessage('Salida de venta finalizada correctamente.', 'success');

          // Recarga para que toda la estación vuelva a consultar el estado terminal
          // desde el backend y no queden datos visuales antiguos.
          window.setTimeout(() => window.location.reload(), 700);
        } catch (err) {
          submit.disabled = false;
          error.textContent = String(err?.message || 'No fue posible finalizar la salida.');
          error.classList.remove('d-none');
        }
      };

      if (!dialog.open) dialog.showModal();
      if (requiresReason) {
        window.setTimeout(() => reason.focus(), 50);
      }
    } catch (err) {
      submit.disabled = false;
      error.textContent = String(err?.message || 'No fue posible validar la venta.');
      error.classList.remove('d-none');
      if (!dialog.open) dialog.showModal();
    }
  };

  const renderPanel = (saleId, detail) => {
    const order = detail?.order || {};
    const closure = detail?.closure || {};
    const canFinalize = closure.can_finalize === true || num(closure.can_finalize) === 1;

    // El cierre sólo aparece cuando realmente está permitido.
    // No mostramos textos, porcentajes ni estados adicionales en la tarjeta:
    // toda esa información ya existe en la estación.
    if (String(order.status || '') === 'dispatched' || !canFinalize) {
      removePanel();
      return;
    }

    // Evita duplicar el botón que el adaptador V2 pudiera haber espejado
    // dentro de la sección de solicitud.
    visual.querySelectorAll('[data-action="finalize-sale"]').forEach((button) => {
      button.style.display = 'none';
    });

    const manifestBody = document.getElementById('wr-outgoing-manifest');
    const manifestCard = manifestBody?.closest('.wr-outgoing-manifest-card');
    if (!manifestCard) {
      removePanel();
      return;
    }

    let panel = document.getElementById('wr-sale-finalize-bridge');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'wr-sale-finalize-bridge';
      panel.setAttribute('aria-label', 'Finalizar salida de venta');
    }

    // Footer real de la tarjeta: queda fuera del área con scroll.
    if (panel.parentElement !== manifestCard) {
      manifestCard.appendChild(panel);
    }

    panel.innerHTML = `
      <button type="button" class="btn btn-primary wr-finalize-button" id="wr-sale-finalize-bridge-button">
        <i class="bi bi-check2-circle me-1"></i>Finalizar salida
      </button>
    `;

    panel.querySelector('#wr-sale-finalize-bridge-button')
      ?.addEventListener('click', () => openFinalizeDialog(saleId));
  };

  const refresh = async () => {
    const seq = ++requestSeq;

    if (!isSaleContext()) {
      currentSaleId = 0;
      currentFolio = '';
      removePanel();
      return;
    }

    const folio = folioFromDom();
    if (!folio) {
      removePanel();
      return;
    }

    try {
      let saleId = currentSaleId;

      if (!saleId || folio !== currentFolio) {
        saleId = await findSaleId(folio);
        if (seq !== requestSeq) return;

        currentSaleId = saleId;
        currentFolio = folio;
      }

      if (!saleId) {
        removePanel();
        return;
      }

      const detail = await getDetail(saleId);
      if (seq !== requestSeq) return;

      renderPanel(saleId, detail);
    } catch (_) {
      // No interferir con el flujo principal de Salidas.
      // Si la consulta auxiliar falla, el flujo normal permanece intacto.
    }
  };

  const scheduleRefresh = () => {
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(refresh, 120);
  };

  ensureStyles();

  new MutationObserver(scheduleRefresh).observe(original, {
    childList: true,
    subtree: true,
    characterData: true,
    attributes: true,
  });

  // También vigila la tarjeta visible por si el adaptador V2 la reconstruye.
  new MutationObserver(() => {
    if (!document.getElementById('wr-sale-finalize-bridge')) scheduleRefresh();
  }).observe(visual, {
    childList: true,
    subtree: true,
  });

  scheduleRefresh();
})();
