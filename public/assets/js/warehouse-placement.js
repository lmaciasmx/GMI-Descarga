(() => {
  'use strict';

  const source = document.querySelector('#warehousePlacementData');
  if (!source || !globalThis.GMar) return;
  const byId = (id) => document.getElementById(id);
  const shortSectorCode = (code) => String(code || '').match(/([FP]\d+)$/)?.[1] || String(code || '');
  const modalElement = byId('placementModal');
  if (!modalElement) return;
  const modal = globalThis.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalElement) : null;

  const setSectorOptions = (rows, placeholder = 'Selecciona fila / pasillo...') => {
    const select = byId('placementSectorId');
    const proposedSector = Number(byId('placementProposedSector').value || 0);
    select.innerHTML = `<option value="">${placeholder}</option>`;

    rows.forEach((row) => {
      const option = document.createElement('option');
      const available = Number(row.available || 0);
      option.value = String(row.id);
      option.textContent = `${shortSectorCode(row.code)} · ${available} disponible(s)`;
      option.dataset.available = String(available);
      // La fila inicial puede estar llena porque esta misma tina ya ocupa una estiba allí.
      if (available <= 0 && Number(row.id) !== proposedSector) option.disabled = true;
      select.appendChild(option);
    });
  };

  const updateChangeState = () => {
    const proposedChamber = Number(byId('placementProposedChamber').value || 0);
    const proposedSector = Number(byId('placementProposedSector').value || 0);
    const finalChamber = Number(byId('placementChamberId').value || 0);
    const finalSector = Number(byId('placementSectorId').value || 0);
    const changed = Boolean(
      finalChamber && finalSector && proposedChamber && proposedSector
      && (finalChamber !== proposedChamber || finalSector !== proposedSector)
    );

    byId('placementChangeAlert').classList.toggle('d-none', !changed);
    byId('placementReason').placeholder = changed
      ? 'Motivo obligatorio. Ej. fila inicial ocupada; se acomodó en otra fila.'
      : 'Observación opcional.';
  };

  const loadSectors = async (preferredSectorId = 0) => {
    const chamberId = Number(byId('placementChamberId').value || 0);
    const select = byId('placementSectorId');

    if (!chamberId) {
      setSectorOptions([], 'Selecciona cámara...');
      select.disabled = true;
      updateChangeState();
      return;
    }

    select.disabled = true;
    setSectorOptions([], 'Consultando filas / pasillos...');
    try {
      const response = await GMar.api(`/internal/v1/warehouse-entry/chambers/${chamberId}/sectors`);
      const rows = response.sectors || [];
      setSectorOptions(rows, rows.length ? 'Selecciona fila / pasillo...' : 'Sin filas / pasillos disponibles');
      select.disabled = rows.length === 0;

      if (preferredSectorId && Array.from(select.options).some((option) => Number(option.value) === Number(preferredSectorId))) {
        select.value = String(preferredSectorId);
      }
    } catch (error) {
      setSectorOptions([], 'No fue posible consultar disponibilidad');
      select.disabled = true;
      await Swal.fire({ icon: 'error', title: 'Ubicaciones no disponibles', text: error.message });
    } finally {
      updateChangeState();
    }
  };

  document.querySelectorAll('[data-place-destination]').forEach((button) => {
    button.addEventListener('click', async () => {
      const proposedChamber = Number(button.dataset.proposedChamber || 0);
      const proposedSector = Number(button.dataset.proposedSector || 0);

      byId('placementDestinationId').value = button.dataset.placeDestination || '';
      byId('placementProposedChamber').value = String(proposedChamber || '');
      byId('placementProposedSector').value = String(proposedSector || '');
      byId('placementSubject').textContent = `Marbete ${button.dataset.labelCode || ''}`;
      byId('placementTubCode').textContent = button.dataset.tubCode || '—';
      byId('placementSpeciesName').textContent = button.dataset.speciesName || '—';
      byId('placementSizeName').textContent = button.dataset.sizeName || '—';
      byId('placementLotCode').textContent = button.dataset.lotCode || '—';
      byId('placementReason').value = '';
      byId('placementChamberId').value = proposedChamber ? String(proposedChamber) : '';
      setSectorOptions([], 'Consultando filas / pasillos...');
      modal?.show();
      await loadSectors(proposedSector);
    });
  });

  byId('placementSearch')?.addEventListener('input', (event) => {
    const query = event.target.value.trim().toLocaleLowerCase('es');
    document.querySelectorAll('[data-placement-row]').forEach((row) => {
      row.classList.toggle('d-none', Boolean(query) && !String(row.dataset.search || '').includes(query));
    });
  });

  byId('placementChamberId').addEventListener('change', async () => {
    await loadSectors(0);
  });
  byId('placementSectorId').addEventListener('change', updateChangeState);

  byId('placementConfirm').addEventListener('click', async () => {
    const destinationId = Number(byId('placementDestinationId').value || 0);
    const chamberId = Number(byId('placementChamberId').value || 0);
    const sectorId = Number(byId('placementSectorId').value || 0);
    const proposedChamber = Number(byId('placementProposedChamber').value || 0);
    const proposedSector = Number(byId('placementProposedSector').value || 0);
    const reason = byId('placementReason').value.trim();
    const sectorText = byId('placementSectorId').selectedOptions[0]?.textContent || '';
    const chamberText = byId('placementChamberId').selectedOptions[0]?.textContent || '';
    const changed = chamberId !== proposedChamber || sectorId !== proposedSector;

    if (!destinationId || !chamberId || !sectorId) {
      return Swal.fire({ icon: 'warning', text: 'Selecciona la Cámara y Fila/Pasillo donde quedó la tina.' });
    }
    if (changed && reason.length < 5) {
      return Swal.fire({ icon: 'warning', text: 'Indica el motivo del cambio de Cámara o Fila/Pasillo.' });
    }

    const confirmation = await Swal.fire({
      icon: 'question',
      title: 'Confirmar acomodo',
      html: `Ubicación real: <strong>${chamberText} · ${sectorText}</strong><br><small>La existencia ya está registrada. El sistema confirmará o reubicará la tina y asignará automáticamente la ubicación interna libre de adentro hacia afuera.</small>`,
      showCancelButton: true,
      confirmButtonText: 'Confirmar acomodo',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#198754',
    });
    if (!confirmation.isConfirmed) return;

    const button = byId('placementConfirm');
    button.disabled = true;
    try {
      const result = await GMar.api(`/internal/v1/warehouse-entry/destinations/${destinationId}/place`, {
        method: 'POST',
        body: JSON.stringify({
          chamber_id: chamberId,
          sector_location_id: sectorId,
          reason: reason || null,
        }),
      });
      modal?.hide();

      const finalLocation = [
        result.final_location?.chamber_code,
        result.final_location?.sector_code,
        result.final_location?.position_code,
        result.final_location?.code,
      ].filter(Boolean).join(' · ');

      let detail = finalLocation
        ? `Ubicación interna confirmada: <strong>${finalLocation}</strong>.`
        : 'La ubicación física quedó confirmada.';
      if (result.relocation?.document_id) {
        detail += `<br>Se generó movimiento de reubicación <strong>#${Number(result.relocation.document_id)}</strong>.`;
      } else if (result.legacy_reception && result.reception?.folio) {
        detail += `<br>La tina provenía de una versión anterior y se completó la recepción <strong>${result.reception.folio}</strong>.`;
      }

      await Swal.fire({ icon: 'success', title: 'Acomodo confirmado', html: detail });
      window.location.reload();
    } catch (error) {
      await Swal.fire({ icon: 'error', title: error.code || 'Acomodo rechazado', text: error.message });
      button.disabled = !navigator.onLine;
    }
  });
})();
