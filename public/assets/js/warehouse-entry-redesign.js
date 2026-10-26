(() => {
  'use strict';

  const root = document.querySelector('[data-warehouse-station]');
  if (!root) return;

  const tareInput = document.querySelector('#entryTareInput');
  const compatibilityTare = document.querySelector('#entryTareDisplay');
  const scaleTare = document.querySelector('#entryTareScaleDisplay');
  const tubId = document.querySelector('#entryTubId');
  const grossDisplay = document.querySelector('#entryGrossDisplay');
  const netDisplay = document.querySelector('#entryNetDisplay');
  const penaltyDisplay = document.querySelector('#entryPenaltyKgDisplay');
  const acceptedDisplay = document.querySelector('#entryAcceptedDisplay');
  const penaltyPercent = document.querySelector('#entryPenaltyPercent');
  const tareState = document.querySelector('#entryTareEditState');
  const communication = document.querySelector('#warehouseCommunicationStatusMirror');
  const scaleStatus = document.querySelector('#warehouseScaleStatus');
  const printerStatus = document.querySelector('#warehousePrinterStatus');
  const scaleStatusMirror = document.querySelector('#warehouseScaleStatusMirror');
  const printerStatusMirror = document.querySelector('#warehousePrinterStatusMirror');

  if (!tareInput || !compatibilityTare) return;

  let lastTubId = '';
  let catalogTare = 0;
  let tareOverride = false;

  const numberFromText = (value) => {
    const normalized = String(value ?? '')
      .replace(/,/g, '')
      .replace(/[^\d.-]/g, '');
    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
  };

  const kg = (value) => `${Number(value || 0).toFixed(3)} kg`;

  const currentTare = () => {
    const value = Number.parseFloat(tareInput.value);
    return Number.isFinite(value) ? value : 0;
  };

  const setInputFromCatalog = () => {
    catalogTare = numberFromText(compatibilityTare.textContent);
    tareInput.value = catalogTare > 0 ? catalogTare.toFixed(3) : '0.000';
    tareInput.disabled = !(tubId && tubId.value);
    tareOverride = false;
    tareInput.classList.remove('ws-tare-overridden');

    if (tareState) tareState.textContent = '';
    if (scaleTare) scaleTare.textContent = kg(catalogTare);

    recalculate();
  };

  const recalculate = () => {
    const gross = numberFromText(grossDisplay?.textContent);
    const tare = currentTare();
    const penaltyPct = Math.max(
      0,
      Math.min(100, Number.parseFloat(penaltyPercent?.value || '0') || 0)
    );

    if (scaleTare) scaleTare.textContent = kg(tare);

    if (gross <= 0 || tare <= 0 || gross <= tare) {
      if (netDisplay) netDisplay.textContent = '0.000 kg';
      if (penaltyDisplay) penaltyDisplay.textContent = '0.000 kg';
      if (acceptedDisplay) acceptedDisplay.textContent = '0.000 kg';
      return;
    }

    const physicalNet = gross - tare;
    const penaltyKg = physicalNet * (penaltyPct / 100);
    const accepted = Math.max(0, physicalNet - penaltyKg);

    if (netDisplay) netDisplay.textContent = kg(physicalNet);
    if (penaltyDisplay) penaltyDisplay.textContent = kg(penaltyKg);
    if (acceptedDisplay) acceptedDisplay.textContent = kg(accepted);
  };

  const refreshOverrideState = () => {
    const tare = currentTare();
    tareOverride = tare > 0 && Math.abs(tare - catalogTare) >= 0.0005;

    tareInput.classList.toggle('ws-tare-overridden', tareOverride);

    if (tareState) {
      tareState.textContent = tareOverride ? 'Tara corregida para este pesaje' : '';
    }

    recalculate();
  };

  tareInput.addEventListener('input', refreshOverrideState);
  tareInput.addEventListener('change', refreshOverrideState);
  penaltyPercent?.addEventListener('input', recalculate);
  penaltyPercent?.addEventListener('change', recalculate);

  const tareObserver = new MutationObserver(() => {
    if (!tareOverride) {
      setInputFromCatalog();
    } else if (scaleTare) {
      scaleTare.textContent = kg(currentTare());
    }
  });
  tareObserver.observe(compatibilityTare, {
    childList: true,
    characterData: true,
    subtree: true
  });

  if (grossDisplay) {
    const grossObserver = new MutationObserver(() => queueMicrotask(recalculate));
    grossObserver.observe(grossDisplay, {
      childList: true,
      characterData: true,
      subtree: true
    });
  }

  // Programmatic changes to hidden input.value do not emit input/change,
  // therefore detect tub changes without modifying the original station JS.
  window.setInterval(() => {
    const currentTubId = String(tubId?.value || '');

    if (currentTubId === lastTubId) return;

    lastTubId = currentTubId;

    if (!currentTubId) {
      catalogTare = 0;
      tareOverride = false;
      tareInput.value = '0.000';
      tareInput.disabled = true;
      tareInput.classList.remove('ws-tare-overridden');
      if (tareState) tareState.textContent = '';
      if (scaleTare) scaleTare.textContent = '0.000 kg';
      recalculate();
      return;
    }

    // Let warehouse-entry.js finish rendering the selected tub first.
    window.setTimeout(setInputFromCatalog, 0);
  }, 200);

  const originalFetch = window.fetch.bind(window);

  const targetRequest = (url, method) => {
    const normalizedUrl = String(url || '');
    return method === 'POST'
      && /\/internal\/v1\/warehouse-entry(?:\?|$)/.test(normalizedUrl);
  };

  const enrichPayload = (payload) => {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
      return payload;
    }

    return {
      ...payload,
      declared_tare_kg: Number(currentTare().toFixed(3)),
      tare_override: tareOverride
    };
  };

  window.fetch = async (input, init) => {
    const isRequest = typeof Request !== 'undefined' && input instanceof Request;
    const url = isRequest ? input.url : String(input);
    const method = String(init?.method || (isRequest ? input.method : 'GET')).toUpperCase();

    if (!targetRequest(url, method)) {
      return originalFetch(input, init);
    }

    try {
      if (init && typeof init.body === 'string') {
        const payload = enrichPayload(JSON.parse(init.body));
        return originalFetch(input, {
          ...init,
          body: JSON.stringify(payload)
        });
      }

      if (isRequest) {
        const clone = input.clone();
        const text = await clone.text();

        if (text) {
          const payload = enrichPayload(JSON.parse(text));
          const request = new Request(input, {
            body: JSON.stringify(payload)
          });
          return originalFetch(request, init);
        }
      }
    } catch (_) {
      // If the request is not JSON, preserve original behaviour.
    }

    return originalFetch(input, init);
  };

  const copyStatus = (source, target) => {
    if (!source || !target) return;

    target.className = source.className;

    const sourceLabel = source.querySelector('span:last-child');
    const targetLabel = target.querySelector('span:last-child');

    if (sourceLabel && targetLabel) {
      targetLabel.textContent = sourceLabel.textContent || '';
    }
  };

  const refreshDeviceMirrors = () => {
    copyStatus(scaleStatus, scaleStatusMirror);
    copyStatus(printerStatus, printerStatusMirror);

    if (!communication) return;

    const label = communication.querySelector('span:last-child');
    const scaleUnavailable = scaleStatus?.classList.contains('error')
      || scaleStatus?.classList.contains('offline');

    const connected = navigator.onLine && !scaleUnavailable;

    communication.classList.toggle('online', connected);
    communication.classList.toggle('warning', !connected);
    communication.classList.toggle('error', false);
    communication.classList.toggle('offline', false);

    if (label) {
      label.textContent = connected ? 'Conectado' : 'Sin comunicación';
    }
  };

  window.addEventListener('online', refreshDeviceMirrors);
  window.addEventListener('offline', refreshDeviceMirrors);

  for (const status of [scaleStatus, printerStatus]) {
    if (!status) continue;

    const observer = new MutationObserver(refreshDeviceMirrors);
    observer.observe(status, {
      attributes: true,
      childList: true,
      subtree: true
    });
  }

  setInputFromCatalog();
  refreshDeviceMirrors();
})();
