(() => {
  'use strict';

  const UI_VERSION = '2.3.3';
  const source = document.getElementById('warehouseEntryData');
  if (!source) return;

  if (!globalThis.GMar?.api) {
    console.error(`[WarehouseEntry ${UI_VERSION}] GMar.api no está disponible.`);
    return;
  }

  console.info(`[WarehouseEntry] UI ${UI_VERSION} cargada`);
  document.documentElement.dataset.warehouseEntryUi = UI_VERSION;

  const data = JSON.parse(source.textContent || '{}');
  const byId = (id) => document.getElementById(id);
  const number = (value) => Number(value || 0);
  const kg = (value) => `${number(value).toFixed(3)} kg`;
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;',
  }[char]));
  const shortSectorCode = (code) => String(code || '').match(/([FP]\d+)$/)?.[1] || String(code || '');

  const field = {
    plan: byId('entryPlanId'),
    tubSearch: byId('entryTubSearch'),
    tubId: byId('entryTubId'),
    tubResults: byId('entryTubResults'),
    species: byId('entrySpeciesId'),
    size: byId('entrySizeId'),
    actualTank: byId('entryActualTankId'),
    sourceTanks: byId('entrySourceTankIds'),
    sorterSearch: byId('entrySorterSearch'),
    sorterId: byId('entrySorterId'),
    sorterResults: byId('entrySorterResults'),
    labelerSearch: byId('entryLabelerSearch'),
    labelerId: byId('entryLabelerId'),
    labelerResults: byId('entryLabelerResults'),
    warehouse: byId('entryWarehouseId'),
    chamber: byId('entryChamberId'),
    sector: byId('entrySectorId'),
    penalty: byId('entryPenaltyPercent'),
    reason: byId('entryReasonCode'),
    msc: byId('entryMscCode'),
    dolphin: byId('entryDolphinCode'),
    temperature: byId('entryTemperature'),
    observations: byId('entryObservations'),
  };

  if (Object.values(field).some((element) => !element)) {
    console.error(`[WarehouseEntry ${UI_VERSION}] La vista y el JavaScript no corresponden a la misma versión.`);
    return;
  }

  let selectedTub = null;
  let reading = null;
  let matchingLine = null;
  let tubTimer = null;
  const participantTimers = new Map();

  const warehouseKey = Number(data.warehouse?.id || field.warehouse.value || 0);
  const STATION_SETTINGS_KEY = `gmar.entry.station.${warehouseKey}`;
  const STICKY_STATE_KEY = `gmar.entry.sticky.${warehouseKey}`;
  const settingsField = {
    scale: byId('entryStationScale'),
    printer: byId('entryStationPrinter'),
    keepValues: byId('entryKeepValues'),
    autoPrint: byId('entryAutoPrint'),
    save: byId('entrySaveStationSettings'),
    clear: byId('entryClearSticky'),
    status: byId('entryStationDeviceStatus'),
  };

  const readJsonStorage = (key, fallback = null) => {
    try { return JSON.parse(localStorage.getItem(key) || 'null') ?? fallback; } catch (_) { return fallback; }
  };

  const defaultStationSettings = {
    keepValues: true,
    autoPrint: true,
    scaleId: Number(data.scale?.id || 0),
    printerId: Number(data.printer?.id || 0),
  };
  let stationSettings = { ...defaultStationSettings, ...(readJsonStorage(STATION_SETTINGS_KEY, {}) || {}) };

  const selectedScale = () => (data.scales || []).find((row) => Number(row.id) === Number(stationSettings.scaleId)) || data.scale || null;
  const selectedPrinter = () => {
    if (Number(stationSettings.printerId) === 0) {
      return stationSettings.autoPrint ? (data.printer || null) : null;
    }
    return (data.printers || []).find((row) => Number(row.id) === Number(stationSettings.printerId)) || data.printer || null;
  };

  const printerAdaptersForDriver = (driver) => {
    const normalized = String(driver || '').toLowerCase();
    if (normalized === 'pdf') return ['pdf'];
    if (normalized === 'browser') return ['browser'];
    if (['middleware', 'thermal', 'zpl'].includes(normalized)) return ['middleware', 'zpl'];
    return [];
  };

  const isLocalAgentScale = (scale) => {
    if (!scale) return false;
    const connection = String(scale.connection_type || '').toLowerCase();
    const adapter = String(scale.adapter_key || '').toLowerCase();
    return ['usb_serial', 'rs232'].includes(connection)
      && adapter === connection
      && Boolean(String(scale.agent_url || '').trim())
      && Boolean(String(scale.port_name || '').trim());
  };

  const isScaleCompatible = (scale) => Boolean(scale)
    && (
      isLocalAgentScale(scale)
      || (
        String(scale.adapter_key || '') === String(data.scaleDriver || '')
        && String(scale.connection_type || '') === String(data.scaleDriver || '')
      )
    );

  const normalizeScaleSelection = () => {
    const current = (data.scales || []).find(
      (row) => Number(row.id) === Number(stationSettings.scaleId)
    ) || null;

    if (current && isScaleCompatible(current)) {
      return;
    }

    const preferred = data.scale && isScaleCompatible(data.scale)
      ? data.scale
      : (data.scales || []).find((row) => isLocalAgentScale(row))
        || (data.scales || []).find((row) => isScaleCompatible(row))
        || null;

    if (!preferred?.id) {
      return;
    }

    stationSettings.scaleId = Number(preferred.id);
    localStorage.setItem(STATION_SETTINGS_KEY, JSON.stringify(stationSettings));
  };

  normalizeScaleSelection();

  const scaleAgentUrl = (scale) => String(
    scale?.agent_url || 'http://127.0.0.1:18182'
  ).replace(/\/$/, '');

  const scaleAgentConfiguration = (scale) => ({
    scale_identifier: scale?.station_code || scale?.code || 'BASCULA',
    station_code: scale?.station_code || '',
    model: scale?.model || '',
    port_name: String(scale?.port_name || '').toUpperCase(),
    baud_rate: Number(scale?.baud_rate || 9600),
    data_bits: Number(scale?.data_bits || 8),
    parity: scale?.parity || 'none',
    stop_bits: String(scale?.stop_bits || '1'),
    read_command: scale?.read_command ?? 'P',
    command_terminator: scale?.command_terminator || 'none',
    response_timeout_ms: Number(scale?.response_timeout_ms || 2000),
    settle_delay_ms: Number(scale?.settle_delay_ms || 250),
    stable_reads: Number(scale?.stable_reads || 2),
    stable_tolerance_kg: Number(scale?.stable_tolerance_kg || 0.050),
    weight_regex: scale?.weight_regex || '[-+]?[0-9]+(?:[.,][0-9]+)?',
    unit: 'kg',
  });

  const scaleAgentFetch = async (scale, path, options = {}) => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 12000);

    try {
      const response = await fetch(`${scaleAgentUrl(scale)}${path}`, {
        ...options,
        signal: controller.signal,
        mode: 'cors',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/json',
          ...(options.headers || {}),
        },
      });

      const payload = await response.json().catch(() => ({}));

      if (!response.ok || payload.success === false) {
        throw new Error(
          payload?.error?.message
          || payload?.message
          || `El agente respondió HTTP ${response.status}.`
        );
      }

      return payload.data || payload;
    } catch (error) {
      if (error?.name === 'AbortError') {
        throw new Error('El agente de báscula no respondió a tiempo.');
      }

      if (error instanceof TypeError) {
        throw new Error(
          'No fue posible conectar con el agente de báscula. '
          + `Verifica que esté iniciado en ${scaleAgentUrl(scale)}.`
        );
      }

      throw error;
    } finally {
      window.clearTimeout(timer);
    }
  };


  const isLocalAgentPrinter = (printer) => {
    if (!printer) return false;
    const mode = String(printer.connection_mode || '').toLowerCase();
    const adapter = String(printer.adapter_key || '').toLowerCase();
    const windowsName = String(printer.windows_printer_name || '').trim();
    const agentUrl = String(printer.agent_url || '').trim();

    // Configuración completa actual o compatibilidad con registros antiguos.
    return ['middleware', 'zpl'].includes(adapter)
      && Boolean(windowsName)
      && (mode === 'local_agent' || Boolean(agentUrl));
  };

  const isPrinterCompatible = (printer) => {
    if (!stationSettings.autoPrint) return true;
    if (isLocalAgentPrinter(printer)) return true;
    const adapters = printerAdaptersForDriver(data.printerDriver);
    if (!printer) return ['pdf', 'browser'].includes(String(data.printerDriver || ''));
    return adapters.includes(String(printer.adapter_key || ''));
  };


  // Normaliza configuraciones antiguas guardadas por esta estación.
  // Si quedó seleccionada una impresora incompatible o printerId=0,
  // prioriza automáticamente la PC43t configurada mediante agente local.
  const normalizeStationPrinter = () => {
    if (!stationSettings.autoPrint) return;

    const current = selectedPrinter();
    if (current && isPrinterCompatible(current)) return;

    const preferred = (data.printers || []).find((row) => isLocalAgentPrinter(row)) || data.printer || null;
    if (!preferred?.id) return;

    stationSettings.printerId = Number(preferred.id);
    try {
      localStorage.setItem(STATION_SETTINGS_KEY, JSON.stringify(stationSettings));
    } catch (_) {
      // La impresión puede continuar aunque el navegador no permita persistencia local.
    }
  };

  normalizeStationPrinter();

  const currentPlan = () => (data.plans || []).find((row) => Number(row.id) === Number(field.plan.value));
  const planLines = () => {
    const plan = currentPlan();
    return plan ? (data.lines || []).filter((row) => Number(row.discharge_plan_id) === Number(plan.id)) : [];
  };
  const voyageTanks = () => {
    const plan = currentPlan();
    return plan ? (data.tanks || []).filter((row) => Number(row.voyage_id) === Number(plan.voyage_id)) : [];
  };
  const currentTub = () => selectedTub;
  const selectedNumber = (id) => Number(byId(id)?.value || 0);

  const selectedSourceTankIds = () => Array.from(field.sourceTanks.selectedOptions || [])
    .map((option) => Number(option.value))
    .filter(Boolean);

  const refreshMultiSelect = () => {
    if (!globalThis.jQuery?.fn?.select2) return;
    const $element = jQuery(field.sourceTanks);
    if (!$element.hasClass('select2-hidden-accessible')) {
      $element.select2({
        width: '100%',
        placeholder: 'Selecciona uno o varios tanques...',
        closeOnSelect: false,
        language: { noResults: () => 'Sin resultados' },
      });
    } else {
      $element.trigger('change.select2');
    }
  };

  const setOptions = (select, rows, placeholder = 'Selecciona...', selected = '') => {
    const selectedValues = Array.isArray(selected) ? selected.map(String) : [String(selected || '')];
    select.replaceChildren();

    if (!select.multiple && placeholder !== null) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = placeholder;
      select.appendChild(option);
    }

    rows.forEach((row) => {
      const option = document.createElement('option');
      option.value = String(row.value);
      option.textContent = String(row.label);
      if (selectedValues.includes(option.value)) option.selected = true;
      Object.entries(row.dataset || {}).forEach(([key, value]) => { option.dataset[key] = String(value); });
      select.appendChild(option);
    });

    if (select === field.sourceTanks) refreshMultiSelect();
  };

  const setGroupedOptions = (select, planned, others, placeholder, selected) => {
    const selectedValue = String(selected || '');
    select.replaceChildren();

    const base = document.createElement('option');
    base.value = '';
    base.textContent = placeholder;
    select.appendChild(base);

    const appendGroup = (label, rows) => {
      if (!rows.length) return;
      const group = document.createElement('optgroup');
      group.label = label;
      rows.forEach((row) => {
        const option = document.createElement('option');
        option.value = String(row.value);
        option.textContent = String(row.label);
        if (option.value === selectedValue) option.selected = true;
        group.appendChild(option);
      });
      select.appendChild(group);
    };

    appendGroup('En plan', planned);
    appendGroup('Otros', others);
  };

  const productPlanLines = () => {
    const speciesId = selectedNumber('entrySpeciesId');
    const sizeId = selectedNumber('entrySizeId');
    if (!speciesId || !sizeId) return [];

    return planLines().filter((line) => (
      Number(line.species_id) === speciesId
      && (!line.size_grade_id || Number(line.size_grade_id) === sizeId)
    ));
  };

  const exactPlanLine = () => {
    const tankId = selectedNumber('entryActualTankId');
    const sizeId = selectedNumber('entrySizeId');
    if (!tankId) return null;

    const candidates = productPlanLines().filter((line) => Number(line.voyage_tank_id) === tankId);
    return candidates.find((line) => Number(line.size_grade_id || 0) === sizeId) || candidates[0] || null;
  };

  const updatePlanHeader = () => {
    const plan = currentPlan();
    byId('planPlanned').textContent = kg(plan?.planned_kg);
    byId('planProcessed').textContent = kg(plan?.processed_kg);
    byId('planPending').textContent = kg(plan?.pending_kg);

    const identity = plan
      ? `${plan.folio} · ${plan.vessel_code} · ${plan.vessel_name} · marea ${plan.annual_number}/${plan.year} · RSA ${plan.rsa_code || 'sin RSA'}`
      : 'Selecciona un plan para comenzar.';

    byId('planIdentity').textContent = identity;
    const stationPlan = byId('stationPlanSummary');
    if (stationPlan) stationPlan.textContent = plan ? `${plan.folio} · marea ${plan.annual_number}/${plan.year}` : 'Operación en piso';

    if (plan) localStorage.setItem('gmar.entry.plan', String(plan.id));
  };

  const updateSpecies = () => {
    const previous = field.species.value;
    const lines = planLines();
    const plannedIds = new Set(lines.map((line) => Number(line.species_id)).filter(Boolean));
    const planned = [];
    const others = [];

    (data.species || []).forEach((row) => {
      const labelBase = row.code && row.code !== row.name ? `${row.code} · ${row.name}` : `${row.name || row.code || ''}`;
      const item = { value: Number(row.id), label: plannedIds.has(Number(row.id)) ? `★ ${labelBase}` : labelBase };
      (plannedIds.has(Number(row.id)) ? planned : others).push(item);
    });

    planned.sort((a, b) => a.label.localeCompare(b.label));
    others.sort((a, b) => a.label.localeCompare(b.label));
    setGroupedOptions(field.species, planned, others, currentPlan() ? 'Selecciona especie...' : 'Selecciona plan...', previous);

    if (previous && ![...planned, ...others].some((row) => String(row.value) === String(previous))) field.species.value = '';
    updateSizes();
  };

  const compatibleSizes = (speciesId) => (data.sizes || []).filter((row) => {
    const owner = row.species_id;
    return speciesId && (owner === null || owner === undefined || owner === '' || Number(owner) === speciesId);
  });

  const updateSizes = () => {
    const speciesId = selectedNumber('entrySpeciesId');
    const previous = field.size.value;
    const lines = planLines().filter((line) => Number(line.species_id) === speciesId);
    const plannedSizeIds = new Set(lines.filter((line) => line.size_grade_id).map((line) => Number(line.size_grade_id)));
    const genericPlan = lines.some((line) => !line.size_grade_id);
    const planned = [];
    const others = [];

    compatibleSizes(speciesId).forEach((row) => {
      const inPlan = genericPlan || plannedSizeIds.has(Number(row.id));
      const base = row.code && row.code !== row.name ? `${row.code} · ${row.name}` : `${row.name || row.code || ''}`;
      const item = { value: Number(row.id), label: inPlan ? `★ ${base}` : base };
      (inPlan ? planned : others).push(item);
    });

    planned.sort((a, b) => a.label.localeCompare(b.label));
    others.sort((a, b) => a.label.localeCompare(b.label));
    setGroupedOptions(field.size, planned, others, speciesId ? 'Selecciona talla...' : 'Selecciona especie...', previous);

    if (previous && ![...planned, ...others].some((row) => String(row.value) === String(previous))) field.size.value = '';
    updateActualTanks();
  };

  const updateActualTanks = () => {
    const previous = field.actualTank.value;
    const speciesId = selectedNumber('entrySpeciesId');
    const sizeId = selectedNumber('entrySizeId');

    if (!currentPlan() || !speciesId || !sizeId) {
      setOptions(field.actualTank, [], currentPlan() ? 'Selecciona especie y talla...' : 'Selecciona plan...');
      updateSourceTanks();
      updateProductStatus();
      return;
    }

    const matching = productPlanLines();
    let rows;

    if (matching.length) {
      const tankIds = new Set(matching.map((line) => Number(line.voyage_tank_id)));
      rows = voyageTanks()
        .filter((tank) => tankIds.has(Number(tank.id)))
        .map((tank) => ({ value: tank.id, label: `★ ${tank.tank_number}` }));
    } else {
      rows = voyageTanks().map((tank) => ({ value: tank.id, label: String(tank.tank_number) }));
    }

    const selected = rows.some((row) => String(row.value) === String(previous)) ? previous : (rows.length === 1 ? String(rows[0].value) : '');
    setOptions(field.actualTank, rows, rows.length ? 'Selecciona tanque...' : 'Sin tanques disponibles', selected);
    updateSourceTanks();
    updateProductStatus();
  };

  const updateSourceTanks = () => {
    const actualId = selectedNumber('entryActualTankId');
    const previous = selectedSourceTankIds();
    const rows = voyageTanks().map((tank) => ({
      value: tank.id,
      label: Number(tank.id) === actualId ? `★ ${tank.tank_number}` : String(tank.tank_number),
    }));

    const allowed = new Set(rows.map((row) => Number(row.value)));
    const selected = previous.filter((id) => allowed.has(id));
    if (actualId && allowed.has(actualId) && !selected.includes(actualId)) selected.push(actualId);
    setOptions(field.sourceTanks, rows, null, selected);
  };

  const ensureActualTankInSources = () => {
    const actualId = selectedNumber('entryActualTankId');
    if (!actualId) return;
    const selected = selectedSourceTankIds();
    if (selected.includes(actualId)) return;
    Array.from(field.sourceTanks.options).forEach((option) => {
      if (Number(option.value) === actualId) option.selected = true;
    });
    refreshMultiSelect();
  };

  const updateProductStatus = () => {
    matchingLine = exactPlanLine();
    const complete = selectedNumber('entryPlanId') && selectedNumber('entrySpeciesId') && selectedNumber('entrySizeId') && selectedNumber('entryActualTankId');
    const pill = byId('entryPlanProductStatus');
    const box = byId('entryOutOfPlanBox');

    if (!complete) {
      pill.className = 'ws-status-pill';
      pill.querySelector('span:last-child').textContent = 'Sin producto';
      box.classList.add('d-none');
      return;
    }

    if (matchingLine) {
      pill.className = 'ws-status-pill online';
      pill.querySelector('span:last-child').textContent = 'En plan';
      box.classList.add('d-none');
      field.msc.value = matchingLine.msc_code || '';
      field.dolphin.value = matchingLine.dolphin_safe_code || '';
      field.temperature.value = matchingLine.freezing_temperature_c ?? '';
    } else {
      pill.className = 'ws-status-pill warning';
      pill.querySelector('span:last-child').textContent = 'Fuera de plan';
      box.classList.remove('d-none');
    }
  };

  const hideTubResults = () => field.tubResults.classList.add('d-none');

  const chooseTub = (row) => {
    selectedTub = row;
    field.tubId.value = String(row.id);
    field.tubSearch.value = String(row.code || row.barcode || '');
    byId('entryTubSelected').innerHTML = `<i class="bi bi-check-circle-fill"></i><span>${escapeHtml(row.code)} · tara ${number(row.current_tare_kg).toFixed(3)} kg</span>`;
    hideTubResults();
    calculate();
  };

  const searchTub = async () => {
    const query = field.tubSearch.value.trim();
    if (query.length < 1) {
      hideTubResults();
      return;
    }

    try {
      const response = await GMar.api(`/internal/v1/warehouse-entry/tubs/search?q=${encodeURIComponent(query)}`);
      const items = response.items || [];
      field.tubResults.replaceChildren();

      if (!items.length) {
        field.tubResults.innerHTML = '<div class="px-3 py-2 text-secondary small">Sin tinas disponibles.</div>';
      } else {
        items.forEach((row) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'ws-search-item';
          button.innerHTML = `<i class="bi bi-box"></i><span><strong>${escapeHtml(row.code)}</strong><small class="d-block">${escapeHtml(row.barcode || '')} · tara ${number(row.current_tare_kg).toFixed(3)} kg</small></span>`;
          button.addEventListener('click', () => chooseTub(row));
          field.tubResults.appendChild(button);
        });
      }
      field.tubResults.classList.remove('d-none');
    } catch (error) {
      byId('entryTubSelected').innerHTML = `<i class="bi bi-exclamation-circle"></i><span>${escapeHtml(error.message || 'No fue posible buscar la tina')}</span>`;
      console.error(`[WarehouseEntry ${UI_VERSION}] búsqueda de tina`, error);
    }
  };

  const queueTubSearch = () => {
    if (selectedTub && field.tubSearch.value.trim() !== String(selectedTub.code || '').trim()) {
      selectedTub = null;
      field.tubId.value = '';
      byId('entryTubSelected').innerHTML = '<i class="bi bi-upc-scan"></i><span>Sin tina seleccionada</span>';
      calculate();
    }
    clearTimeout(tubTimer);
    tubTimer = setTimeout(searchTub, 180);
  };

  const stickySnapshot = () => ({
    planId: selectedNumber('entryPlanId'),
    speciesId: selectedNumber('entrySpeciesId'),
    sizeId: selectedNumber('entrySizeId'),
    actualTankId: selectedNumber('entryActualTankId'),
    sourceTankIds: selectedSourceTankIds(),
    sorter: { id: Number(field.sorterId.value || 0), name: field.sorterSearch.value.trim() },
    labeler: { id: Number(field.labelerId.value || 0), name: field.labelerSearch.value.trim() },
    chamberId: selectedNumber('entryChamberId'),
    sectorId: selectedNumber('entrySectorId'),
    penaltyPercent: field.penalty.value,
    reasonCode: field.reason.value,
    mscCode: field.msc.value,
    dolphinCode: field.dolphin.value,
    temperature: field.temperature.value,
    observations: field.observations.value,
  });

  const saveStickyState = () => {
    if (!stationSettings.keepValues) {
      localStorage.removeItem(STICKY_STATE_KEY);
      return;
    }
    localStorage.setItem(STICKY_STATE_KEY, JSON.stringify(stickySnapshot()));
  };

  const updateConfirmButton = () => {
    const button = byId('entryConfirm');
    if (!button) return;
    button.innerHTML = stationSettings.autoPrint
      ? '<i class="bi bi-printer me-1"></i> CONFIRMAR E IMPRIMIR MARBETE'
      : '<i class="bi bi-check2-circle me-1"></i> CONFIRMAR ENTRADA';
  };

  const updateStationDeviceStatus = (previewModal = false) => {
    const previewSettings = previewModal ? {
      ...stationSettings,
      keepValues: settingsField.keepValues ? Boolean(settingsField.keepValues.checked) : Boolean(stationSettings.keepValues),
      autoPrint: settingsField.autoPrint ? Boolean(settingsField.autoPrint.checked) : Boolean(stationSettings.autoPrint),
      scaleId: Number(settingsField.scale?.value || 0),
      printerId: Number(settingsField.printer?.value || 0),
    } : stationSettings;
    const scale = (data.scales || []).find((row) => Number(row.id) === Number(previewSettings.scaleId)) || data.scale || null;
    const printer = Number(previewSettings.printerId) === 0
      ? null
      : (data.printers || []).find((row) => Number(row.id) === Number(previewSettings.printerId)) || data.printer || null;
    const scaleOk = isScaleCompatible(scale);
    const localAgent = isLocalAgentPrinter(printer);
    const adapters = printerAdaptersForDriver(data.printerDriver);
    const printerOk = !previewSettings.autoPrint
      || localAgent
      || (printer ? adapters.includes(String(printer.adapter_key || '')) : ['pdf', 'browser'].includes(String(data.printerDriver || '')));
    const direct = previewSettings.autoPrint
      && printerOk
      && (localAgent || ['middleware', 'thermal', 'zpl'].includes(String(data.printerDriver || '').toLowerCase()));

    if (settingsField.status) {
      settingsField.status.className = `alert ${scaleOk && printerOk ? 'alert-success' : 'alert-warning'} mb-0 small`;
      const printMode = !previewSettings.autoPrint
        ? 'sin impresión automática'
        : direct
          ? (localAgent ? 'impresión directa PC43t por agente local' : 'impresión directa por middleware/ZPL')
          : String(data.printerDriver || '') === 'browser'
            ? 'impresión por navegador (puede mostrar diálogo)'
            : 'salida PDF / no directa';
      const scaleMode = isLocalAgentScale(scale)
        ? `agente local · ${String(scale.connection_type || '').replaceAll('_', ' ')}`
        : `driver ${data.scaleDriver || 'sin configurar'}`;
      settingsField.status.innerHTML = `<strong>Báscula:</strong> ${escapeHtml(scale?.name || 'sin seleccionar')} · ${escapeHtml(scaleMode)}<br><strong>Impresión:</strong> ${escapeHtml(printer?.name || 'sin impresora')} · ${escapeHtml(printMode)}${scaleOk && printerOk ? '' : '<br><span class="fw-semibold">La selección no es compatible con esta estación.</span>'}`;
    }

    const scaleStatus = byId('warehouseScaleStatus');
    if (scaleStatus) {
      scaleStatus.className = `ws-status-pill ${scaleOk ? 'warning' : 'offline'}`;
      scaleStatus.querySelector('span:last-child').textContent = scale ? String(scale.name || scale.code) : 'Sin báscula';
    }
    const printerStatus = byId('warehousePrinterStatus');
    if (printerStatus) {
      printerStatus.className = `ws-status-pill ${printerOk && previewSettings.autoPrint ? (direct ? 'online' : 'warning') : (previewSettings.autoPrint ? 'offline' : 'warning')}`;
      printerStatus.querySelector('span:last-child').textContent = previewSettings.autoPrint
        ? (printer ? String(printer.name || printer.code) : 'Salida del driver')
        : 'Impresión manual';
    }
    updateConfirmButton();
  };

  const applyStationSettingsToModal = () => {
    const scale = selectedScale();
    const printer = selectedPrinter();
    stationSettings.scaleId = Number(scale?.id || 0);
    stationSettings.printerId = Number(printer?.id || 0);
    if (settingsField.scale) settingsField.scale.value = stationSettings.scaleId ? String(stationSettings.scaleId) : '';
    if (settingsField.printer) settingsField.printer.value = stationSettings.printerId ? String(stationSettings.printerId) : '';
    if (settingsField.keepValues) settingsField.keepValues.checked = Boolean(stationSettings.keepValues);
    if (settingsField.autoPrint) settingsField.autoPrint.checked = Boolean(stationSettings.autoPrint);
    updateStationDeviceStatus();
  };

  const clearStickyState = async () => {
    const confirmed = !globalThis.Swal || (await Swal.fire({
      icon: 'question',
      title: 'Limpiar datos conservados',
      text: 'La siguiente captura iniciará sin plan, producto, tanques, personal ni destino preseleccionados. La configuración de báscula e impresora se conserva.',
      showCancelButton: true,
      confirmButtonText: 'Limpiar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#b42318',
    })).isConfirmed;
    if (!confirmed) return;
    localStorage.removeItem(STICKY_STATE_KEY);
    localStorage.removeItem('gmar.entry.plan');
    localStorage.removeItem('gmar.entry.sorter');
    localStorage.removeItem('gmar.entry.labeler');
    window.location.reload();
  };

  const persistStationSettings = () => {
    stationSettings = {
      keepValues: settingsField.keepValues ? Boolean(settingsField.keepValues.checked) : Boolean(stationSettings.keepValues),
      autoPrint: settingsField.autoPrint ? Boolean(settingsField.autoPrint.checked) : Boolean(stationSettings.autoPrint),
      scaleId: Number(settingsField.scale?.value || 0),
      printerId: Number(settingsField.printer?.value || 0),
    };
    localStorage.setItem(STATION_SETTINGS_KEY, JSON.stringify(stationSettings));
    if (!stationSettings.keepValues) localStorage.removeItem(STICKY_STATE_KEY);
    else saveStickyState();
    updateStationDeviceStatus();
    checkScale();
    const modalElement = byId('entryStationSettingsModal');
    if (modalElement && globalThis.bootstrap?.Modal) bootstrap.Modal.getOrCreateInstance(modalElement).hide();
  };

  const participantConfig = (role) => role === 'sorter'
    ? { search: field.sorterSearch, id: field.sorterId, results: field.sorterResults, selected: byId('entrySorterSelected'), storage: 'gmar.entry.sorter' }
    : { search: field.labelerSearch, id: field.labelerId, results: field.labelerResults, selected: byId('entryLabelerSelected'), storage: 'gmar.entry.labeler' };

  const hideParticipantResults = (role) => participantConfig(role).results.classList.add('d-none');

  const selectParticipant = (role, item) => {
    const config = participantConfig(role);
    config.id.value = String(item.id);
    config.search.value = String(item.name);
    config.selected.classList.remove('d-none');
    config.selected.innerHTML = `<i class="bi bi-check-circle-fill"></i><span>${escapeHtml(item.name)}</span>`;
    hideParticipantResults(role);
    localStorage.setItem(config.storage, JSON.stringify({ id: Number(item.id), name: String(item.name) }));
  };

  const createParticipant = async (role, name) => {
    const config = participantConfig(role);
    config.search.disabled = true;
    try {
      const response = await GMar.api('/internal/v1/warehouse-entry/participants', {
        method: 'POST',
        body: JSON.stringify({ name, role }),
      });
      selectParticipant(role, response.item);
      saveStickyState();
      if (globalThis.Swal && response.created) {
        await Swal.fire({ icon: 'success', title: 'Participante registrado', text: response.item.name, timer: 1100, showConfirmButton: false });
      }
    } catch (error) {
      if (globalThis.Swal) await Swal.fire({ icon: 'error', title: 'No fue posible registrar', text: error.message });
    } finally {
      config.search.disabled = false;
      config.search.focus();
    }
  };

  const searchParticipant = async (role) => {
    const config = participantConfig(role);
    const query = config.search.value.trim();
    if (query.length < 2) {
      hideParticipantResults(role);
      return;
    }

    try {
      const response = await GMar.api(`/internal/v1/warehouse-entry/participants/search?role=${encodeURIComponent(role)}&q=${encodeURIComponent(query)}`);
      const items = response.items || [];
      config.results.replaceChildren();

      items.forEach((item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ws-autocomplete-item';
        button.innerHTML = `<i class="bi bi-person"></i><span><strong>${escapeHtml(item.name)}</strong><small class="d-block">${escapeHtml(item.code)}</small></span>`;
        button.addEventListener('click', () => selectParticipant(role, item));
        config.results.appendChild(button);
      });

      const exact = items.some((item) => String(item.name).localeCompare(query, 'es', { sensitivity: 'base' }) === 0);
      if (!exact) {
        const create = document.createElement('button');
        create.type = 'button';
        create.className = 'ws-autocomplete-item create';
        create.innerHTML = `<i class="bi bi-plus-circle"></i><span>Registrar “${escapeHtml(query)}”</span>`;
        create.addEventListener('click', () => createParticipant(role, query));
        config.results.appendChild(create);
      }

      config.results.classList.remove('d-none');
    } catch (error) {
      console.error(`[WarehouseEntry ${UI_VERSION}] participantes`, error);
      config.results.innerHTML = `<div class="px-3 py-2 text-danger small">${escapeHtml(error.message || 'No fue posible consultar')}</div>`;
      config.results.classList.remove('d-none');
    }
  };

  const bindParticipant = (role) => {
    const config = participantConfig(role);
    config.search.addEventListener('input', () => {
      const saved = JSON.parse(localStorage.getItem(config.storage) || 'null');
      if (!saved || config.search.value.trim() !== String(saved.name || '').trim()) {
        config.id.value = '';
        config.selected.classList.add('d-none');
      }
      clearTimeout(participantTimers.get(role));
      participantTimers.set(role, setTimeout(() => searchParticipant(role), 200));
    });

    config.search.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') hideParticipantResults(role);
      if (event.key === 'Enter') {
        const first = config.results.querySelector('button');
        if (first) { event.preventDefault(); first.click(); }
      }
    });

    const saved = JSON.parse(localStorage.getItem(config.storage) || 'null');
    if (saved?.id && saved?.name) selectParticipant(role, saved);
  };

  const loadChambers = async () => {
    const warehouseId = Number(data.warehouse?.id || field.warehouse.value || 0);
    field.chamber.disabled = true;
    field.sector.disabled = true;
    setOptions(field.sector, [], 'Selecciona cámara...');
    byId('entryDestinationMessage').classList.add('d-none');

    if (!warehouseId) {
      setOptions(field.chamber, [], 'Alcance sin frigorífico');
      return;
    }

    let items = (data.chambers || []).filter((row) => Number(row.warehouse_id) === warehouseId);
    if (!items.length) {
      try {
        const response = await GMar.api(`/internal/v1/warehouse-entry/warehouses/${warehouseId}/chambers`);
        items = response.items || [];
      } catch (error) {
        setOptions(field.chamber, [], 'No fue posible cargar cámaras');
        const message = byId('entryDestinationMessage');
        message.className = 'alert alert-danger mt-2';
        message.textContent = error.message;
        return;
      }
    }

    const rows = items.map((row) => ({ value: row.id, label: `${row.code} · ${row.name}` }));
    setOptions(field.chamber, rows, rows.length ? 'Selecciona cámara...' : 'Sin cámaras activas');
    field.chamber.disabled = rows.length === 0;

    if (!rows.length) {
      const message = byId('entryDestinationMessage');
      message.className = 'alert alert-warning mt-2';
      message.textContent = 'El frigorífico del alcance actual no tiene cámaras activas configuradas.';
    }
  };

  const loadSectors = async () => {
    const chamberId = selectedNumber('entryChamberId');
    field.sector.disabled = true;
    if (!chamberId) {
      setOptions(field.sector, [], 'Selecciona cámara...');
      return;
    }

    setOptions(field.sector, [], 'Consultando disponibilidad...');
    try {
      const response = await GMar.api(`/internal/v1/warehouse-entry/chambers/${chamberId}/sectors`);
      if (response.layout_configured === false) {
        setOptions(field.sector, [], 'Layout físico pendiente');
        const message = byId('entryDestinationMessage');
        message.className = 'alert alert-warning mt-2';
        message.textContent = 'La cámara seleccionada todavía no tiene filas/pasillos y estibas configurados en el layout físico.';
        return;
      }
      const rows = (response.sectors || []).map((row) => ({
        value: row.id,
        label: `${shortSectorCode(row.code)} · ${row.available} libres`,
        dataset: { available: row.available },
      }));
      setOptions(field.sector, rows, rows.length ? 'Selecciona fila / pasillo...' : 'Sin filas/pasillos disponibles');
      if (rows.length) byId('entryDestinationMessage').classList.add('d-none');
      Array.from(field.sector.options).forEach((option) => {
        if (option.value && Number(option.dataset.available || 0) <= 0) option.disabled = true;
      });
      field.sector.disabled = rows.length === 0;
    } catch (error) {
      setOptions(field.sector, [], 'No fue posible consultar disponibilidad');
      const message = byId('entryDestinationMessage');
      message.className = 'alert alert-danger mt-2';
      message.textContent = error.message;
    }
  };

  const updatePlan = async () => {
    updatePlanHeader();
    updateSpecies();
    await loadChambers();
    updateProductStatus();
  };

  const calculate = () => {
    const gross = number(reading?.weight);
    const tare = number(currentTub()?.current_tare_kg);
    const physical = Math.max(0, gross - tare);
    const percent = Math.min(100, Math.max(0, number(field.penalty.value)));
    const penalty = physical * percent / 100;
    const accepted = Math.max(0, physical - penalty);

    byId('entryGrossDisplay').textContent = gross.toFixed(3);
    byId('entryTareDisplay').textContent = kg(tare);
    byId('entryNetDisplay').textContent = kg(physical);
    byId('entryPenaltyKgDisplay').textContent = kg(penalty);
    byId('entryAcceptedDisplay').textContent = kg(accepted);
  };

  const checkScale = async () => {
    const status = byId('warehouseScaleStatus');
    const scale = selectedScale();

    if (!scale?.id) {
      status.className = 'ws-status-pill offline';
      status.querySelector('span:last-child').textContent = 'Sin báscula';
      return;
    }

    try {
      if (isLocalAgentScale(scale)) {
        await scaleAgentFetch(scale, '/api/v1/status', {
          method: 'GET',
          headers: {},
        });

        status.className = 'ws-status-pill online';
        status.querySelector('span:last-child').textContent = String(
          scale.name || scale.code || 'Agente de báscula activo'
        );
        return;
      }

      const response = await GMar.api('/internal/v1/scale/status');
      const connected = response.status?.connected ?? (response.status === 'connected');
      status.className = `ws-status-pill ${connected ? 'online' : 'warning'}`;
      const label = response.status?.status
        || response.status?.state
        || (connected ? 'Conectada' : 'Disponible');
      status.querySelector('span:last-child').textContent = String(label).replaceAll('_', ' ');
    } catch (_) {
      status.className = 'ws-status-pill offline';
      status.querySelector('span:last-child').textContent = 'Sin comunicación';
    }
  };

  const readScale = async () => {
    const scale = selectedScale();
    if (!scale?.id) return;

    if (!isScaleCompatible(scale)) {
      if (globalThis.Swal) {
        await Swal.fire({
          icon: 'warning',
          title: 'Configuración de báscula',
          text: 'La báscula seleccionada no es compatible con esta estación.',
        });
      }
      return;
    }

    const button = byId('entryReadScale');
    const status = byId('entryReadingStatus');
    button.disabled = true;
    status.className = 'ws-scale-state';
    status.textContent = 'Esperando peso estable...';

    try {
      if (isLocalAgentScale(scale)) {
        const localReading = await scaleAgentFetch(scale, '/api/v1/read', {
          method: 'POST',
          body: JSON.stringify({
            transport: scale.connection_type,
            configuration: scaleAgentConfiguration(scale),
          }),
        });

        reading = await GMar.api('/internal/v1/scale/capture-local', {
          method: 'POST',
          body: JSON.stringify({
            scale_id: Number(scale.id),
            reading: localReading,
          }),
        });
      } else {
        reading = await GMar.api('/internal/v1/scale/read-stable', {
          method: 'POST',
          body: JSON.stringify({ scale_id: Number(scale.id) }),
        });
      }

      status.className = 'ws-scale-state stable';
      status.textContent = `● Peso estable · ${reading.scale_identifier || scale.code || ''} · ${reading.captured_at || reading.received_at || ''}`;
      calculate();
    } catch (error) {
      reading = null;
      calculate();
      status.className = 'ws-scale-state';
      status.textContent = 'Sin lectura estable.';

      if (globalThis.Swal) {
        await Swal.fire({
          icon: 'error',
          title: 'Báscula no disponible',
          text: error.message,
        });
      }
    } finally {
      button.disabled = !navigator.onLine;
    }
  };

  const sendPreparedLabelToLocalAgent = async (printResult) => {
    const agent = printResult?.local_agent;
    const jobId = Number(printResult?.print_job_id || 0);
    if (!agent || !jobId) return null;

    let successful = false;
    let providerReference = null;
    let errorMessage = null;

    try {
      const base = String(agent.agent_url || 'http://127.0.0.1:18181').replace(/\/$/, '');
      const response = await fetch(`${base}/api/v1/print`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({
          printer_name: String(agent.printer_name || ''),
          format: 'zpl',
          content: String(agent.content || ''),
        }),
      });

      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload?.success !== true) {
        throw new Error(payload?.error?.message || `El agente respondió HTTP ${response.status}.`);
      }

      successful = payload?.data?.successful !== false;
      providerReference = payload?.data?.job_id ? String(payload.data.job_id) : null;
      if (!successful) errorMessage = payload?.error?.message || 'El agente no confirmó la impresión.';
    } catch (error) {
      errorMessage = error?.message || 'No fue posible comunicarse con el agente local de impresión.';
    }

    try {
      await GMar.api(`/internal/v1/labels/jobs/${jobId}/local-result`, {
        method: 'POST',
        body: JSON.stringify({
          successful: successful ? 1 : 0,
          provider_reference: providerReference,
          error_message: errorMessage,
        }),
      });
    } catch (callbackError) {
      // Si la PC43t ya imprimió, no debemos reportar falsamente que no imprimió.
      if (successful) {
        return {
          successful: true,
          providerReference,
          warning: 'El marbete se envió a la PC43t, pero no se pudo actualizar el estado del trabajo en el servidor.',
        };
      }
      errorMessage = `${errorMessage || 'Impresión no confirmada.'} ${callbackError?.message || ''}`.trim();
    }

    if (!successful) throw new Error(errorMessage || 'La PC43t no confirmó la impresión.');
    return { successful: true, providerReference, warning: null };
  };

  const validateBeforeConfirm = () => {
    const required = [
      ['plan', selectedNumber('entryPlanId')],
      ['tina', Number(field.tubId.value || 0)],
      ['especie', selectedNumber('entrySpeciesId')],
      ['talla', selectedNumber('entrySizeId')],
      ['tanque del plan', selectedNumber('entryActualTankId')],
      ['sorteador', Number(field.sorterId.value || 0)],
      ['etiquetador', Number(field.labelerId.value || 0)],
      ['cámara', selectedNumber('entryChamberId')],
      ['fila / pasillo', selectedNumber('entrySectorId')],
    ];
    const missing = required.find(([, value]) => !value);
    if (missing) return `Falta seleccionar ${missing[0]}.`;

    const sources = selectedSourceTankIds();
    if (!sources.includes(selectedNumber('entryActualTankId'))) return 'Los tanques del producto deben incluir el tanque del plan.';
    const scale = selectedScale();
    const printer = selectedPrinter();
    if (!scale?.id) return 'Selecciona una báscula para esta estación.';
    if (!isScaleCompatible(scale)) return 'La báscula seleccionada no coincide con el driver técnico activo.';
    if (stationSettings.autoPrint && !isPrinterCompatible(printer)) return 'La impresora seleccionada no coincide con el driver técnico activo.';
    if (!reading?.id || !reading?.stable) return 'Obtén una lectura estable de la báscula.';
    if (number(reading.weight) <= number(currentTub()?.current_tare_kg)) return 'El peso bruto debe ser mayor que la tara de la tina.';
    if (number(field.penalty.value) > 0 && !field.reason.value.trim()) return 'Indica el motivo del castigo.';
    if (!matchingLine && (!field.msc.value || !field.dolphin.value || field.temperature.value === '')) return 'La captura fuera de plan requiere MSC, Dolphin Safe y temperatura.';
    if (!data.template?.id) return 'No hay una plantilla de marbete configurada.';
    return null;
  };

  const confirmEntry = async () => {
    ensureActualTankInSources();
    updateProductStatus();
    const issue = validateBeforeConfirm();
    if (issue) {
      if (globalThis.Swal) return Swal.fire({ icon: 'warning', text: issue });
      window.alert(issue);
      return;
    }

    const plan = currentPlan();
    const tub = currentTub();
    const accepted = Math.max(0, number(reading.weight) - number(tub?.current_tare_kg));
    const acceptedAfterPenalty = accepted * (1 - Math.min(100, number(field.penalty.value)) / 100);

    const confirmation = await Swal.fire({
      icon: 'question',
      title: `Confirmar tina ${escapeHtml(tub?.code || '')}`,
      html: `<strong>${acceptedAfterPenalty.toFixed(3)} kg aceptados</strong><br>${escapeHtml(plan?.folio || '')}<br><small>Se registrará llenado, pesaje, marbete, recepción e inventario. La ubicación interna se asignará automáticamente dentro de la fila seleccionada.</small>`,
      showCancelButton: true,
      confirmButtonText: stationSettings.autoPrint ? 'Confirmar e imprimir' : 'Confirmar entrada',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#198754',
    });
    if (!confirmation.isConfirmed) return;

    const payload = {
      plan_id: selectedNumber('entryPlanId'),
      tub_id: Number(field.tubId.value),
      actual_tank_id: selectedNumber('entryActualTankId'),
      species_id: selectedNumber('entrySpeciesId'),
      size_grade_id: selectedNumber('entrySizeId'),
      source_tank_ids: selectedSourceTankIds(),
      sorter_participant_id: Number(field.sorterId.value),
      labeler_participant_id: Number(field.labelerId.value),
      warehouse_id: selectedNumber('entryWarehouseId'),
      chamber_id: selectedNumber('entryChamberId'),
      sector_location_id: selectedNumber('entrySectorId'),
      scale_id: Number(selectedScale()?.id || 0),
      scale_reading_id: Number(reading.id),
      gross_weight_kg: Number(reading.weight),
      penalty_percent: number(field.penalty.value),
      weight_source: reading.weight_source || 'scale',
      original_value: reading.original_value || null,
      device_timestamp: reading.captured_at || reading.device_timestamp || null,
      reason_code: field.reason.value.trim() || null,
      msc_code: matchingLine?.msc_code || field.msc.value || null,
      dolphin_safe_code: matchingLine?.dolphin_safe_code || field.dolphin.value || null,
      freezing_temperature_c: matchingLine?.freezing_temperature_c ?? (field.temperature.value === '' ? null : Number(field.temperature.value)),
      template_id: Number(data.template.id),
      printer_id: selectedPrinter()?.id ? Number(selectedPrinter().id) : null,
      print_requested: stationSettings.autoPrint ? 1 : 0,
      observations: field.observations.value.trim() || null,
    };

    const button = byId('entryConfirm');
    button.disabled = true;

    try {
      const result = await GMar.api('/internal/v1/warehouse-entry', { method: 'POST', body: JSON.stringify(payload) });

      if (result.print_result?.ok && result.print_result?.local_agent) {
        try {
          const local = await sendPreparedLabelToLocalAgent(result.print_result);
          result.print_result.print = { ...(result.print_result.print || {}), status: 'sent' };
          if (local?.warning) result.print_result.message = local.warning;
        } catch (printError) {
          result.print_result.ok = false;
          result.print_result.message = printError?.message || 'La operación quedó guardada, pero no fue posible imprimir el marbete.';
        }
      }

      if (result.print_result?.ok && result.print_result.print?.document_html) {
        GMar.printHtml(result.print_result.print.document_html, result.label.code);
      }

      const printMessage = result.print_result?.requested === false
        ? '<span class="text-secondary fw-semibold">Entrada guardada sin impresión automática.</span>'
        : result.print_result?.ok
          ? `Impresión: <strong>${escapeHtml(result.print_result.print?.status || 'procesada')}</strong>${result.print_result?.message ? `<br><span class="text-warning">${escapeHtml(result.print_result.message)}</span>` : ''}`
          : `<span class="text-warning fw-semibold">La operación quedó guardada, pero la impresión falló.</span><br>${escapeHtml(result.print_result?.message || '')}`;

      await Swal.fire({
        icon: result.print_result?.ok || result.print_result?.requested === false ? 'success' : 'warning',
        title: `Tina ${escapeHtml(tub?.code || '')} registrada`,
        html: `Pesaje <strong>${escapeHtml(result.weighing.folio || result.weighing.id)}</strong> · Marbete <strong>${escapeHtml(result.label.code)}</strong><br>${number(result.weighing.accepted_net_kg).toFixed(3)} kg aceptados<br>Recepción <strong>${escapeHtml(result.reception?.folio || '')}</strong><br>Ubicación inicial: <strong>${escapeHtml([result.initial_location?.chamber_code, result.initial_location?.sector_code, result.initial_location?.position_code, result.initial_location?.code].filter(Boolean).join(' · '))}</strong><hr>${printMessage}`,
        confirmButtonText: 'Siguiente tina',
      });

      localStorage.setItem('gmar.entry.plan', String(payload.plan_id));
      if (stationSettings.keepValues) saveStickyState();
      else localStorage.removeItem(STICKY_STATE_KEY);
      window.location.reload();
    } catch (error) {
      await Swal.fire({ icon: 'error', title: error.code || 'Entrada rechazada', text: error.message });
      button.disabled = !navigator.onLine;
    }
  };

  field.plan.addEventListener('change', async () => { await updatePlan(); saveStickyState(); });
  field.tubSearch.addEventListener('input', queueTubSearch);
  field.tubSearch.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') hideTubResults();
    if (event.key === 'Enter') {
      const first = field.tubResults.querySelector('button');
      if (first) { event.preventDefault(); first.click(); }
    }
  });
  field.species.addEventListener('change', () => { updateSizes(); saveStickyState(); });
  field.size.addEventListener('change', () => { updateActualTanks(); saveStickyState(); });
  field.actualTank.addEventListener('change', () => { updateSourceTanks(); updateProductStatus(); saveStickyState(); });
  field.sourceTanks.addEventListener('change', () => { ensureActualTankInSources(); saveStickyState(); });
  field.chamber.addEventListener('change', async () => { await loadSectors(); saveStickyState(); });
  field.sector.addEventListener('change', saveStickyState);
  field.penalty.addEventListener('input', () => { calculate(); saveStickyState(); });
  field.reason.addEventListener('input', saveStickyState);
  field.msc.addEventListener('change', saveStickyState);
  field.dolphin.addEventListener('change', saveStickyState);
  field.temperature.addEventListener('input', saveStickyState);
  field.observations.addEventListener('input', saveStickyState);
  byId('entryReadScale').addEventListener('click', readScale);
  byId('entryConfirm').addEventListener('click', confirmEntry);

  bindParticipant('sorter');
  bindParticipant('labeler');

  document.addEventListener('click', (event) => {
    if (!field.tubResults.contains(event.target) && event.target !== field.tubSearch) hideTubResults();
    ['sorter', 'labeler'].forEach((role) => {
      const config = participantConfig(role);
      if (!config.results.contains(event.target) && event.target !== config.search) hideParticipantResults(role);
    });
  });

  const restoreStickyState = async () => {
    const sticky = stationSettings.keepValues ? readJsonStorage(STICKY_STATE_KEY, null) : null;
    const savedPlan = String(sticky?.planId || localStorage.getItem('gmar.entry.plan') || '');
    if (savedPlan && Array.from(field.plan.options).some((option) => option.value === savedPlan)) field.plan.value = savedPlan;

    await updatePlan();
    if (!sticky) return;

    if (sticky.speciesId && Array.from(field.species.options).some((option) => Number(option.value) === Number(sticky.speciesId))) {
      field.species.value = String(sticky.speciesId);
      updateSizes();
    }
    if (sticky.sizeId && Array.from(field.size.options).some((option) => Number(option.value) === Number(sticky.sizeId))) {
      field.size.value = String(sticky.sizeId);
      updateActualTanks();
    }
    if (sticky.actualTankId && Array.from(field.actualTank.options).some((option) => Number(option.value) === Number(sticky.actualTankId))) {
      field.actualTank.value = String(sticky.actualTankId);
      updateSourceTanks();
    }
    if (Array.isArray(sticky.sourceTankIds)) {
      const wanted = new Set(sticky.sourceTankIds.map(Number));
      Array.from(field.sourceTanks.options).forEach((option) => { option.selected = wanted.has(Number(option.value)); });
      ensureActualTankInSources();
      refreshMultiSelect();
    }
    if (sticky.sorter?.id && sticky.sorter?.name) selectParticipant('sorter', sticky.sorter);
    if (sticky.labeler?.id && sticky.labeler?.name) selectParticipant('labeler', sticky.labeler);

    if (sticky.chamberId && Array.from(field.chamber.options).some((option) => Number(option.value) === Number(sticky.chamberId))) {
      field.chamber.value = String(sticky.chamberId);
      await loadSectors();
      if (sticky.sectorId && Array.from(field.sector.options).some((option) => Number(option.value) === Number(sticky.sectorId) && !option.disabled)) {
        field.sector.value = String(sticky.sectorId);
      }
    }

    field.penalty.value = sticky.penaltyPercent ?? '0';
    field.reason.value = sticky.reasonCode ?? '';
    field.msc.value = sticky.mscCode ?? field.msc.value;
    field.dolphin.value = sticky.dolphinCode ?? field.dolphin.value;
    field.temperature.value = sticky.temperature ?? field.temperature.value;
    field.observations.value = sticky.observations ?? '';
    updateProductStatus();
    calculate();
  };

  settingsField.save?.addEventListener('click', persistStationSettings);
  settingsField.clear?.addEventListener('click', clearStickyState);
  settingsField.scale?.addEventListener('change', () => updateStationDeviceStatus(true));
  settingsField.printer?.addEventListener('change', () => updateStationDeviceStatus(true));
  settingsField.keepValues?.addEventListener('change', () => updateStationDeviceStatus(true));
  settingsField.autoPrint?.addEventListener('change', () => updateStationDeviceStatus(true));

  const initialize = async () => {
    applyStationSettingsToModal();
    refreshMultiSelect();
    await restoreStickyState();
    calculate();
    updateStationDeviceStatus();
    await checkScale();
    field.tubSearch.focus();
  };

  initialize().catch((error) => console.error(`[WarehouseEntry ${UI_VERSION}] inicialización`, error));
})();
