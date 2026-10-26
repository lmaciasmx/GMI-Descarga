(() => {
  'use strict';

  const form = document.querySelector('[data-catalog-form]');
  const modalElement = document.querySelector('#catalogModal');

  if (!form || !modalElement) return;

  const context = JSON.parse(document.querySelector('#catalogContext').textContent);
  const rows = JSON.parse(document.querySelector('#catalogRows').textContent);
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

  const isLocations = context.catalog === 'locations';

  let editingId = null;

  function resetErrors() {
    form.querySelectorAll('.is-invalid').forEach((element) => {
      element.classList.remove('is-invalid');
    });

    form.querySelectorAll('[data-field-error]').forEach((element) => {
      element.textContent = '';
    });
  }

  function refreshSelect2(element) {
    if (!element || !globalThis.jQuery || !jQuery.fn.select2) return;

    const $element = jQuery(element);

    if ($element.hasClass('select2-hidden-accessible')) {
      $element.trigger('change.select2');
      return;
    }

    $element.select2({
      dropdownParent: jQuery(modalElement),
      width: '100%',
    });
  }

  function initializeSelect2() {
    if (!globalThis.jQuery || !jQuery.fn.select2) return;

    jQuery('[data-select2-modal]').each(function () {
      const $element = jQuery(this);

      if ($element.hasClass('select2-hidden-accessible')) {
        $element.trigger('change.select2');
        return;
      }

      $element.select2({
        dropdownParent: jQuery(modalElement),
        width: '100%',
      });
    });
  }

  function setSelectValue(element, value) {
    if (!element) return;

    element.value = value === null || value === undefined ? '' : String(value);

    if (globalThis.jQuery && jQuery.fn.select2 && jQuery(element).hasClass('select2-hidden-accessible')) {
      jQuery(element).trigger('change.select2');
    }
  }

  function visibleOption(option, visible) {
    if (!option || option.value === '') return;

    option.hidden = !visible;
    option.disabled = !visible;
  }

  function locationElements() {
    return {
      warehouse: form.elements.namedItem('warehouse_id'),
      chamber: form.elements.namedItem('chamber_id'),
      parent: form.elements.namedItem('parent_id'),
      type: form.elements.namedItem('location_type'),
      code: form.elements.namedItem('code'),
      name: form.elements.namedItem('name'),
      path: form.elements.namedItem('path'),
      maxUnits: form.elements.namedItem('max_units'),
      maxWeight: form.elements.namedItem('max_weight_kg'),
      segregation: form.elements.namedItem('segregation_rule'),
      active: form.elements.namedItem('active'),
      pathPreview: document.querySelector('#locationPathPreview'),
      parentHelp: document.querySelector('#locationParentHelp'),
      typeHelp: document.querySelector('#locationTypeHelp'),
      capacityHelp: document.querySelector('#locationCapacityHelp'),
    };
  }

  function selectedOption(select) {
    if (!select || select.selectedIndex < 0) return null;
    return select.options[select.selectedIndex] || null;
  }

  function normalizeLocationType(type) {
    // Compatibilidad defensiva por si existe algún dato previo con "stack".
    return type === 'stack' ? 'stack_level' : type;
  }

  function filterChambers() {
    if (!isLocations) return;

    const { warehouse, chamber } = locationElements();
    if (!warehouse || !chamber) return;

    const warehouseId = String(warehouse.value || '');
    const currentValue = chamber.value;

    Array.from(chamber.options).forEach((option) => {
      if (option.value === '') return;

      const matches = warehouseId !== ''
        && String(option.dataset.warehouseId || '') === warehouseId;

      visibleOption(option, matches);
    });

    const selected = selectedOption(chamber);

    if (
      selected
      && selected.value !== ''
      && (
        selected.hidden
        || selected.disabled
        || String(selected.dataset.warehouseId || '') !== warehouseId
      )
    ) {
      chamber.value = '';
    }

    if (currentValue !== chamber.value) {
      setSelectValue(chamber, chamber.value);
    } else {
      refreshSelect2(chamber);
    }
  }

  function allowedParentTypes(type) {
    switch (normalizeLocationType(type)) {
      case 'position':
        return ['row', 'aisle'];

      case 'stack_level':
        return ['position'];

      default:
        return [];
    }
  }

  function updateParentHelp(type) {
    if (!isLocations) return;

    const { parentHelp } = locationElements();
    if (!parentHelp) return;

    switch (normalizeLocationType(type)) {
      case 'position':
        parentHelp.textContent = 'Selecciona como padre una Fila o un Pasillo de la misma cámara.';
        break;

      case 'stack_level':
        parentHelp.textContent = 'Selecciona como padre una Posición de la misma cámara.';
        break;

      default:
        parentHelp.textContent = 'Este tipo de ubicación no requiere ubicación padre.';
        break;
    }
  }

  function filterParents() {
    if (!isLocations) return;

    const { warehouse, chamber, parent, type } = locationElements();
    if (!warehouse || !chamber || !parent || !type) return;

    const warehouseId = String(warehouse.value || '');
    const chamberId = String(chamber.value || '');
    const currentType = normalizeLocationType(type.value);
    const allowedTypes = allowedParentTypes(currentType);
    const currentValue = parent.value;

    Array.from(parent.options).forEach((option) => {
      if (option.value === '') return;

      const optionType = normalizeLocationType(option.dataset.locationType || '');
      const sameWarehouse = warehouseId !== ''
        && String(option.dataset.warehouseId || '') === warehouseId;
      const sameChamber = chamberId !== ''
        && String(option.dataset.chamberId || '') === chamberId;
      const correctType = allowedTypes.includes(optionType);
      const isSelf = editingId !== null && Number(option.value) === Number(editingId);

      visibleOption(option, sameWarehouse && sameChamber && correctType && !isSelf);
    });

    if (allowedTypes.length === 0) {
      parent.value = '';
      parent.disabled = true;
    } else {
      parent.disabled = false;

      const selected = selectedOption(parent);
      if (
        selected
        && selected.value !== ''
        && (selected.hidden || selected.disabled)
      ) {
        parent.value = '';
      }
    }

    if (currentValue !== parent.value) {
      setSelectValue(parent, parent.value);
    } else {
      refreshSelect2(parent);
    }

    updateParentHelp(currentType);
  }

  function locationCodeSegment() {
    if (!isLocations) return '';

    const { chamber, parent, code } = locationElements();

    const fullCode = String(code?.value || '').trim();
    if (fullCode === '') return '';

    const parentOption = selectedOption(parent);
    const parentCode = String(parentOption?.dataset.code || '').trim();

    if (parentCode !== '' && fullCode.startsWith(`${parentCode}-`)) {
      return fullCode.substring(parentCode.length + 1);
    }

    const chamberOption = selectedOption(chamber);
    const chamberCode = String(chamberOption?.dataset.code || '').trim();

    if (chamberCode !== '' && fullCode.startsWith(`${chamberCode}-`)) {
      return fullCode.substring(chamberCode.length + 1);
    }

    const pieces = fullCode.split('-').filter(Boolean);
    return pieces.length > 0 ? pieces[pieces.length - 1] : fullCode;
  }

  function buildLocationPath() {
    if (!isLocations) return;

    const {
      chamber,
      parent,
      code,
      path,
      pathPreview,
    } = locationElements();

    if (!path) return;

    const fullCode = String(code?.value || '').trim();
    const chamberOption = selectedOption(chamber);
    const chamberCode = String(chamberOption?.dataset.code || '').trim();
    const parentOption = selectedOption(parent);
    const parentPath = String(parentOption?.dataset.path || '').trim();
    const segment = locationCodeSegment();

    let generatedPath = '';

    if (fullCode === '' || chamberCode === '' || segment === '') {
      generatedPath = '';
    } else if (parent && !parent.disabled && parent.value !== '' && parentPath !== '') {
      generatedPath = `${parentPath.replace(/\/+$/, '')}/${segment}`;
    } else {
      generatedPath = `/${chamberCode}/${segment}`;
    }

    path.value = generatedPath;

    if (pathPreview) {
      pathPreview.textContent = generatedPath !== ''
        ? generatedPath
        : 'Se generará automáticamente.';
    }
  }

  function configureLocationCapacity() {
    if (!isLocations) return;

    const { type, maxUnits, capacityHelp } = locationElements();
    if (!type || !maxUnits) return;

    const currentType = normalizeLocationType(type.value);

    if (currentType === 'stack_level') {
      maxUnits.value = '1';
      maxUnits.readOnly = true;

      if (capacityHelp) {
        capacityHelp.textContent = 'Una estiba final admite una tina; capacidad fijada en 1.';
      }

      return;
    }

    maxUnits.readOnly = false;

    if (capacityHelp) {
      capacityHelp.textContent = 'Opcional para niveles superiores de la jerarquía.';
    }
  }

  function configureLocationForm() {
    if (!isLocations) return;

    filterChambers();
    filterParents();
    configureLocationCapacity();
    buildLocationPath();
  }

  function bindLocationEvents() {
    if (!isLocations) return;

    const {
      warehouse,
      chamber,
      parent,
      type,
      code,
    } = locationElements();

    warehouse?.addEventListener('change', () => {
      setSelectValue(chamber, '');
      setSelectValue(parent, '');
      configureLocationForm();
    });

    chamber?.addEventListener('change', () => {
      setSelectValue(parent, '');
      filterParents();
      buildLocationPath();
    });

    type?.addEventListener('change', () => {
      setSelectValue(parent, '');
      filterParents();
      configureLocationCapacity();
      buildLocationPath();
    });

    parent?.addEventListener('change', buildLocationPath);
    code?.addEventListener('input', buildLocationPath);
    code?.addEventListener('change', buildLocationPath);

    if (globalThis.jQuery) {
      jQuery(warehouse).on('select2:select select2:clear', () => {
        setSelectValue(chamber, '');
        setSelectValue(parent, '');
        configureLocationForm();
      });

      jQuery(chamber).on('select2:select select2:clear', () => {
        setSelectValue(parent, '');
        filterParents();
        buildLocationPath();
      });

      jQuery(type).on('select2:select select2:clear', () => {
        setSelectValue(parent, '');
        filterParents();
        configureLocationCapacity();
        buildLocationPath();
      });

      jQuery(parent).on('select2:select select2:clear', buildLocationPath);
    }
  }

  function openForm(row = null) {
    form.reset();
    resetErrors();

    editingId = row?.id || null;

    if (!row && form.elements.namedItem('active')) {
      form.elements.namedItem('active').checked = true;
    }

    document.querySelector('#catalogModalTitle').textContent = editingId
      ? `Editar registro #${editingId}`
      : 'Nuevo registro';

    form.querySelectorAll('[name]').forEach((element) => {
      if (!row || element.name === 'configuration') return;

      if (element.type === 'checkbox') {
        element.checked = Number(row[element.name]) === 1;
        return;
      }

      if (row[element.name] !== null && row[element.name] !== undefined) {
        let value = row[element.name];

        if (isLocations && element.name === 'location_type') {
          value = normalizeLocationType(value);
        }

        element.value = value;
      }
    });

    initializeSelect2();

    if (isLocations) {
      configureLocationForm();

      // En edición el padre debe recuperarse después de aplicar los filtros.
      if (row?.parent_id) {
        const parent = form.elements.namedItem('parent_id');

        if (parent) {
          parent.disabled = false;
          setSelectValue(parent, row.parent_id);
          filterParents();

          if (!parent.disabled) {
            setSelectValue(parent, row.parent_id);
          }
        }
      }

      configureLocationCapacity();
      buildLocationPath();
    }

    modal.show();
  }

  function payloadFromForm() {
    if (isLocations) {
      // Garantiza que path corresponda al padre/código actuales.
      buildLocationPath();
    }

    const payload = {};

    form.querySelectorAll('[name]').forEach((element) => {
      if (element.disabled) {
        // Para parent_id deshabilitado enviamos vacío, pues es permit_empty.
        if (isLocations && element.name === 'parent_id') {
          payload[element.name] = '';
        }
        return;
      }

      if (element.type === 'checkbox') {
        payload[element.name] = element.checked;
      } else if (
        context.json_fields.includes(element.name)
        && editingId
        && element.value.trim() === ''
      ) {
        return;
      } else {
        payload[element.name] = element.value;
      }
    });

    return payload;
  }

  function showFieldErrors(details = {}) {
    Object.entries(details).forEach(([field, message]) => {
      const input = form.elements.namedItem(field);
      const feedback = form.querySelector(
        `[data-field-error="${CSS.escape(field)}"]`
      );

      input?.classList.add('is-invalid');

      if (feedback) {
        feedback.textContent = String(message);
      }
    });
  }

  document.querySelector('[data-catalog-new]')?.addEventListener(
    'click',
    () => openForm()
  );

  document.querySelectorAll('[data-catalog-edit]').forEach((button) => {
    button.addEventListener('click', () => {
      openForm(rows[button.dataset.catalogEdit]);
    });
  });

  document.querySelectorAll('[data-catalog-deactivate]').forEach((button) => {
    button.addEventListener('click', async () => {
      const result = await Swal.fire({
        icon: 'warning',
        title: '¿Desactivar registro?',
        text: 'Se conservará el historial y dejará de ofrecerse en nuevas operaciones.',
        showCancelButton: true,
        confirmButtonText: 'Desactivar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#b42318',
      });

      if (!result.isConfirmed) return;

      try {
        await GMar.api(
          `/internal/v1/catalogs/${context.catalog}/${button.dataset.catalogDeactivate}/deactivate`,
          {
            method: 'POST',
            body: '{}',
          }
        );

        await Swal.fire({
          icon: 'success',
          title: 'Registro desactivado',
          timer: 1200,
          showConfirmButton: false,
        });

        location.reload();
      } catch (error) {
        Swal.fire({
          icon: 'error',
          title: 'No se pudo desactivar',
          text: error.message,
        });
      }
    });
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    resetErrors();

    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;

    try {
      const url = editingId
        ? `/internal/v1/catalogs/${context.catalog}/${editingId}`
        : `/internal/v1/catalogs/${context.catalog}`;

      await GMar.api(url, {
        method: editingId ? 'PUT' : 'POST',
        body: JSON.stringify(payloadFromForm()),
      });

      modal.hide();

      await Swal.fire({
        icon: 'success',
        title: 'Catálogo actualizado',
        timer: 1200,
        showConfirmButton: false,
      });

      location.reload();
    } catch (error) {
      showFieldErrors(error.details);

      Swal.fire({
        icon: 'error',
        title: 'No se pudo guardar',
        text: error.message,
      });
    } finally {
      submit.disabled = !navigator.onLine;
      submit.innerHTML = '<i class="bi bi-check2-circle"></i> Guardar';
    }
  });

  bindLocationEvents();
  initializeSelect2();
})();
