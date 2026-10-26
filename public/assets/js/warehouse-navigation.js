(() => {
  'use strict';

  const normalizePath = (href) => {
    if (!href) return '';
    try {
      return new URL(href, window.location.origin).pathname.replace(/\/+$/, '') || '/';
    } catch (_) {
      return '';
    }
  };

  const anchors = () => Array.from(document.querySelectorAll('a[href]'));
  const findLink = (path) => anchors().find((link) => normalizePath(link.getAttribute('href')) === path);
  const navContainer = (link) => link?.closest('li, .nav-item, .menu-item, .sidebar-item, .pc-item') || link;

  const hideLegacy = (path) => {
    const link = findLink(path);
    const item = navContainer(link);
    if (!item) return false;
    item.hidden = true;
    item.dataset.warehouseLegacyHidden = '1';
    return true;
  };

  const setLabel = (root, text) => {
    const label = root.querySelector('.pc-mtext, .nav-label, .menu-text, .sidebar-label, [data-nav-label]');
    if (label) {
      label.textContent = text;
      return;
    }

    const link = root.matches?.('a') ? root : root.querySelector('a[href]');
    if (!link) return;

    const spans = Array.from(link.querySelectorAll('span')).filter((span) => !span.querySelector('i, svg'));
    const candidate = spans.find((span) => span.textContent.trim().length > 0);
    if (candidate) {
      candidate.textContent = text;
      return;
    }

    Array.from(link.childNodes).forEach((node) => {
      if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) node.remove();
    });
    link.append(document.createTextNode(` ${text}`));
  };

  const addMenuItem = ({ sourcePath, href, label, marker, icon }) => {
    if (findLink(href) || document.querySelector(`[data-${marker}]`)) return true;

    const sourceLink = findLink(sourcePath);
    const sourceItem = navContainer(sourceLink);
    if (!sourceItem || !sourceItem.parentNode) return false;

    const clone = sourceItem.cloneNode(true);
    clone.hidden = false;
    clone.removeAttribute('data-warehouse-legacy-hidden');
    clone.dataset[marker] = '1';

    const link = clone.matches?.('a') ? clone : clone.querySelector('a[href]');
    if (!link) return false;

    link.setAttribute('href', href);
    link.removeAttribute('aria-current');
    link.classList.remove('active');
    clone.classList.remove('active');
    clone.querySelectorAll('.active').forEach((item) => item.classList.remove('active'));
    setLabel(clone, label);

    const iconElement = clone.querySelector('i');
    if (iconElement && icon) iconElement.className = icon;

    sourceItem.insertAdjacentElement('afterend', clone);
    return true;
  };

  const apply = () => {
    const discharge = findLink('/operacion/descarga');
    const weighing = findLink('/operacion/pesaje');
    const labels = findLink('/operacion/etiquetas');
    const receiving = findLink('/operacion/recepcion');

    // Sólo sustituye visualmente los tres pasos cuando el usuario ya tenía
    // acceso a todos ellos. Así un rol especializado no pierde su navegación
    // histórica por una capacidad que la estación unificada todavía no reúne.
    const canUseUnifiedEntry = Boolean(discharge && weighing && labels);
    if (canUseUnifiedEntry) {
      const sourcePath = findLink('/operacion/planes') ? '/operacion/planes' : '/operacion/descarga';
      const added = addMenuItem({
        sourcePath,
        href: '/operacion/entrada-frigorifico',
        label: 'Entrada a Frigorífico',
        marker: 'warehouseEntryNav',
        icon: 'bi bi-box-arrow-in-down',
      });

      if (added) {
        ['/operacion/descarga', '/operacion/pesaje', '/operacion/etiquetas'].forEach(hideLegacy);
      }
    }

    // Recepción se sustituye por Acomodo únicamente cuando el usuario ya tenía
    // acceso al flujo receptor. Las rutas antiguas permanecen disponibles.
    if (receiving) {
      const sourcePath = findLink('/operacion/calidad') ? '/operacion/calidad' : '/operacion/recepcion';
      const added = addMenuItem({
        sourcePath,
        href: '/operacion/acomodo-tinas',
        label: 'Acomodo de tinas',
        marker: 'warehousePlacementNav',
        icon: 'bi bi-box-seam',
      });
      if (added) hideLegacy('/operacion/recepcion');
    }

    const current = window.location.pathname.replace(/\/+$/, '') || '/';
    const currentLink = findLink(current);
    const currentItem = navContainer(currentLink);
    if (currentLink) currentLink.classList.add('active');
    if (currentItem && currentItem !== currentLink) currentItem.classList.add('active');
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply, { once: true });
  } else {
    apply();
  }
})();
