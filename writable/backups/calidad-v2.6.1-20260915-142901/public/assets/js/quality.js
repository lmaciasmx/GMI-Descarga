(() => {
  'use strict';

  const form = document.querySelector('[data-quality-inspection]');
  const weighing = document.getElementById('qualityWeighing');
  const summary = document.querySelector('[data-quality-unit-summary]');
  const notes = document.getElementById('qualityNotes');
  const noteCount = document.querySelector('[data-quality-note-count]');

  const dispositionLabel = (value) => ({
    accepted: 'Sin incidencia', observed: 'Observación', hold: 'Retener tina',
    hold_lot: 'Retener lote', rejected: 'Rechazar'
  })[value] || value;

  const qualityErrors = {
    QUALITY_DEFECT_REQUIRES_HOLD: {
      title: 'El defecto requiere una disposición distinta',
      text: 'El defecto seleccionado requiere retener o rechazar. Cambia el resultado antes de registrar la inspección.'
    },
    WEIGHING_NOT_INSPECTABLE: {
      title: 'La tina no está disponible para inspección',
      text: 'El pesaje fue reversado o ya no pertenece al flujo activo.'
    },
    OPEN_QUALITY_INSPECTION_EXISTS: {
      title: 'Ya existe una inspección pendiente',
      text: 'Dictamina o anula la inspección pendiente antes de registrar otra.'
    },
    QUALITY_REASON_REQUIRED: {
      title: 'Motivo requerido',
      text: 'Describe el motivo de la retención, rechazo o liberación con al menos 5 caracteres.'
    },
    QUALITY_ANNUL_APPROVAL_REQUIRED: {
      title: 'Se requiere autorización',
      text: 'Esta inspección ya afectó inventario. Un usuario con permiso de aprobación debe anularla.'
    },
    QUALITY_ANNUL_NOT_ALLOWED: {
      title: 'No puedes anular esta inspección',
      text: 'Sólo puedes anular tus propias inspecciones o solicitarlo a un aprobador de Calidad.'
    },
    QUALITY_ANNUL_STOCK_CHANGED: {
      title: 'No es posible anular automáticamente',
      text: 'El inventario cambió después del dictamen. Revisa el historial antes de revertir esta inspección.'
    },
    QUALITY_INSPECTION_ALREADY_ANNULLED: {
      title: 'La inspección ya está anulada',
      text: 'No es necesario realizar otra anulación.'
    },
    QUALITY_INSPECTION_ALREADY_SUPERSEDED: {
      title: 'La inspección ya fue corregida',
      text: 'Ya existe una inspección que sustituye a este registro.'
    },
    VALIDATION_FAILED: {
      title: 'Revisa la información capturada',
      text: 'Hay uno o más campos incompletos o con un formato no válido.'
    }
  };

  const showQualityError = (error, fallbackTitle) => {
    const friendly = qualityErrors[error?.code] || null;
    return Swal.fire({
      icon: 'error',
      title: friendly?.title || fallbackTitle || 'No fue posible completar la operación',
      text: friendly?.text || error?.message || 'Intenta nuevamente o solicita apoyo al administrador.'
    });
  };

  const refreshUnitSummary = () => {
    const option = weighing?.selectedOptions?.[0];
    if (!summary || !option || !option.value) {
      if (summary) summary.hidden = true;
      return;
    }
    summary.hidden = false;
    summary.querySelector('[data-quality-lot]').textContent = option.dataset.lot || '—';
    summary.querySelector('[data-quality-species]').textContent = option.dataset.species || '—';
    summary.querySelector('[data-quality-size]').textContent = option.dataset.size || '—';
    const path = option.dataset.location || '';
    const parts = path.split('/').filter(Boolean);
    const row = parts.length > 1 ? parts[1] : '';
    summary.querySelector('[data-quality-location]').textContent = [option.dataset.chamber, row].filter(Boolean).join(' · ') || '—';
  };

  weighing?.addEventListener('change', refreshUnitSummary);
  if (globalThis.jQuery && weighing) jQuery(weighing).on('select2:select select2:clear', refreshUnitSummary);

  notes?.addEventListener('input', () => {
    if (noteCount) noteCount.textContent = String(notes.value.length);
  });

  document.querySelector('[data-quality-clear]')?.addEventListener('click', () => {
    form?.reset();
    if (globalThis.jQuery && weighing) jQuery(weighing).val('').trigger('change');
    if (summary) summary.hidden = true;
    if (noteCount) noteCount.textContent = '0';
  });

  document.querySelector('[data-quality-scan]')?.addEventListener('click', () => {
    const search = document.querySelector('input[name="q"]');
    if (search) {
      search.focus();
      search.select();
    }
  });

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();
    const values = new FormData(form);
    const disposition = String(values.get('disposition') || 'accepted');
    const observations = String(values.get('observations') || '').trim();
    const selectedDefects = Array.from(form.querySelectorAll('input[name="defects"]:checked'));
    const requiresHold = selectedDefects.some((input) => input.dataset.requiresHold === '1');

    if (!values.get('weighing_id')) {
      return Swal.fire({ icon: 'warning', title: 'Selecciona una tina', text: 'Elige el marbete o tina que corresponde a la muestra.' });
    }
    if (requiresHold && ['accepted', 'observed'].includes(disposition)) {
      return Swal.fire({ icon: 'warning', title: 'El defecto requiere retención', text: 'Selecciona Retener tina, Retener lote o Rechazar.' });
    }
    if (['hold', 'hold_lot', 'rejected'].includes(disposition) && observations.length < 5) {
      return Swal.fire({ icon: 'warning', title: 'Motivo requerido', text: 'Describe el motivo de la retención o rechazo con al menos 5 caracteres.' });
    }

    if (['hold', 'hold_lot', 'rejected'].includes(disposition)) {
      const confirmation = await Swal.fire({
        icon: 'warning',
        title: dispositionLabel(disposition),
        text: disposition === 'hold_lot'
          ? 'Esta decisión puede retener todas las existencias activas del lote.'
          : 'Esta decisión afectará la disponibilidad del inventario.',
        showCancelButton: true,
        confirmButtonText: 'Confirmar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#b42318'
      });
      if (!confirmation.isConfirmed) return;
    }

    const supersedes = Number(values.get('supersedes_inspection_id') || 0);
    const payload = {
      weighing_id: Number(values.get('weighing_id')),
      quality_grade: String(values.get('quality_grade') || '').trim(),
      defect_percent: values.get('defect_percent') === '' ? null : Number(values.get('defect_percent')),
      temperature_c: values.get('temperature_c') === '' ? null : Number(values.get('temperature_c')),
      disposition,
      observations,
      auto_grade: false,
      defects: selectedDefects.map((input) => ({ defect_id: Number(input.value) }))
    };
    if (supersedes > 0) payload.supersedes_inspection_id = supersedes;

    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const result = await GMar.api('/internal/v1/quality/inspections', { method: 'POST', body: JSON.stringify(payload) });
      const message = result.requires_approval
        ? 'La inspección quedó registrada y requiere dictamen para afectar inventario.'
        : (supersedes > 0 ? 'La corrección quedó registrada y vinculada con la inspección anulada.' : 'La inspección quedó registrada correctamente.');
      await Swal.fire({ icon: 'success', title: supersedes > 0 ? 'Corrección registrada' : 'Inspección registrada', text: message });
      location.href = '/operacion/calidad';
    } catch (error) {
      await showQualityError(error, 'No fue posible registrar la inspección');
    } finally {
      button.disabled = !navigator.onLine;
      button.innerHTML = '<i class="bi bi-shield-check"></i> Registrar inspección';
    }
  });

  document.querySelectorAll('[data-quality-approve]').forEach((button) => button.addEventListener('click', async () => {
    const proposed = button.dataset.proposed || 'hold';
    const options = {
      accepted: 'Sin incidencia', observed: 'Observación', hold: 'Retener tina',
      hold_lot: 'Retener lote', rejected: 'Rechazar'
    };
    const disposition = await Swal.fire({
      title: 'Dictamen de calidad',
      input: 'select',
      inputOptions: options,
      inputValue: proposed,
      showCancelButton: true,
      confirmButtonText: 'Continuar',
      cancelButtonText: 'Cancelar',
      inputValidator: (value) => value ? undefined : 'Selecciona una disposición'
    });
    if (!disposition.isConfirmed) return;

    let reason = '';
    if (['hold', 'hold_lot', 'rejected'].includes(disposition.value)) {
      const motive = await Swal.fire({
        title: 'Motivo obligatorio', input: 'textarea', inputAttributes: { maxlength: '255' },
        showCancelButton: true, confirmButtonText: 'Confirmar dictamen', cancelButtonText: 'Cancelar',
        inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres'
      });
      if (!motive.isConfirmed) return;
      reason = motive.value.trim();
    }

    try {
      await GMar.api(`/internal/v1/quality/inspections/${button.dataset.qualityApprove}/approve`, {
        method: 'POST', body: JSON.stringify({ disposition: disposition.value, quality_grade: button.dataset.grade, reason })
      });
      await Swal.fire({ icon: 'success', title: 'Dictamen confirmado' });
      location.reload();
    } catch (error) {
      await showQualityError(error, 'No fue posible confirmar el dictamen');
    }
  }));

  document.querySelectorAll('[data-quality-release]').forEach((button) => button.addEventListener('click', async () => {
    const isLot = button.dataset.releaseType === 'released_lot';
    const motive = await Swal.fire({
      icon: 'question', title: isLot ? 'Liberar lote' : 'Liberar tina',
      input: 'textarea', inputPlaceholder: 'Motivo de liberación...', inputAttributes: { maxlength: '255' },
      showCancelButton: true, confirmButtonText: 'Liberar', cancelButtonText: 'Cancelar',
      inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres'
    });
    if (!motive.isConfirmed) return;
    try {
      await GMar.api(`/internal/v1/quality/inspections/${button.dataset.qualityRelease}/approve`, {
        method: 'POST', body: JSON.stringify({ disposition: button.dataset.releaseType, reason: motive.value.trim() })
      });
      await Swal.fire({ icon: 'success', title: isLot ? 'Lote liberado' : 'Tina liberada' });
      location.reload();
    } catch (error) {
      await showQualityError(error, 'No fue posible liberar');
    }
  }));

  const annulInspection = async (inspectionId, correctionMode) => {
    const reason = await Swal.fire({
      icon: 'warning',
      title: correctionMode ? 'Corregir inspección' : 'Anular inspección',
      text: correctionMode
        ? 'La inspección actual quedará anulada y podrás registrar la captura correcta.'
        : 'La inspección permanecerá en el historial como anulada. Si afectó inventario, se intentará revertir de forma segura.',
      input: 'textarea',
      inputPlaceholder: correctionMode ? 'Motivo de la corrección...' : 'Motivo de la anulación...',
      inputAttributes: { maxlength: '255' },
      showCancelButton: true,
      confirmButtonText: correctionMode ? 'Anular y corregir' : 'Anular inspección',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#b42318',
      inputValidator: (value) => value.trim().length >= 5 ? undefined : 'Captura al menos 5 caracteres'
    });
    if (!reason.isConfirmed) return;

    try {
      await GMar.api(`/internal/v1/quality/inspections/${inspectionId}/annul`, {
        method: 'POST', body: JSON.stringify({ reason: reason.value.trim() })
      });
      if (correctionMode) {
        await Swal.fire({ icon: 'success', title: 'Inspección anulada', text: 'Ahora registra la inspección correcta. Puedes elegir otra tina si la selección original fue incorrecta.' });
        location.href = `/operacion/calidad?correct_from=${inspectionId}`;
        return;
      }
      await Swal.fire({ icon: 'success', title: 'Inspección anulada', text: 'El registro se conserva en el historial para trazabilidad.' });
      location.reload();
    } catch (error) {
      await showQualityError(error, 'No fue posible anular la inspección');
    }
  };

  document.querySelectorAll('[data-quality-annul]').forEach((button) => button.addEventListener('click', () => {
    annulInspection(Number(button.dataset.qualityAnnul), false);
  }));
  document.querySelectorAll('[data-quality-correct]').forEach((button) => button.addEventListener('click', () => {
    annulInspection(Number(button.dataset.qualityCorrect), true);
  }));

  const historySearch = document.querySelector('[data-quality-history-search]');
  historySearch?.addEventListener('input', () => {
    const needle = historySearch.value.trim().toLowerCase();
    document.querySelectorAll('[data-quality-history-table] tbody tr').forEach((row) => {
      row.hidden = needle !== '' && !row.textContent.toLowerCase().includes(needle);
    });
  });
})();
