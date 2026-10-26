<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$countStatus = ['counting' => 'En conteo', 'counted' => 'Captura finalizada', 'reconciled' => 'Conciliado', 'planned' => 'Planeado'];
$resultLabel = ['pending' => 'Pendiente', 'match' => 'Correcta', 'relocated' => 'Reubicada', 'relocation_applied' => 'Reubicación aplicada', 'missing' => 'No encontrada', 'unexpected' => 'No esperada'];
?>
<div class="d-flex justify-content-end align-items-center mb-3">
  <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#startCountModal"><i class="bi bi-plus-lg me-1"></i>Nuevo conteo</button>
</div>

<div class="card mb-4"><div class="card-header fw-semibold">Conteos realizados</div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Folio</th><th>Alcance</th><th>Inicio</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach ($counts as $count): ?><tr><td class="fw-semibold"><?= esc($count['folio']) ?></td><td><?= esc($count['warehouse_name']) ?><br><small class="text-secondary"><?= esc($count['chamber_name'] ?? 'Todo el almacén') ?></small></td><td><?= esc($count['frozen_at'] ?? '—') ?></td><td><span class="badge text-bg-light"><?= esc($countStatus[$count['status']] ?? $count['status']) ?></span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/inventario/conteos?count=<?= (int) $count['id'] ?>">Abrir</a></td></tr><?php endforeach ?>
</tbody></table></div></div>

<?php if ($selected !== null): ?>
<div hidden id="countContext"><?= json_encode(['id' => (int) $selected['id'], 'status' => $selected['status']], JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?></div>
<div hidden id="countRows"><?= json_encode(array_values($rows), JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?></div>
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div><span class="fw-semibold"><?= esc($selected['folio']) ?></span><small class="d-block text-secondary fw-normal"><?= esc($selected['warehouse_name'] . ' · ' . ($selected['chamber_name'] ?? 'Todo el almacén')) ?></small></div>
    <div class="d-flex gap-2"><?php if ($selected['status'] === 'counting'): ?><button class="btn btn-success" type="button" data-count-finish>Finalizar conteo</button><?php elseif ($selected['status'] === 'counted'): ?><button class="btn btn-warning" type="button" data-count-reconcile>Revisar y conciliar</button><?php endif ?></div>
  </div>
  <div class="card-body">
    <div class="row g-2 mb-4">
      <?php $kpis = [
        ['expected', 'Esperadas', (int) $summary['expected']],
        ['pending', 'Pendientes', (int) $summary['pending']],
        ['match', 'Correctas', (int) $summary['match']],
        ['relocated', 'Reubicadas', (int) $summary['relocated']],
        ['missing', 'No encontradas', (int) $summary['missing']],
        ['unexpected', 'No esperadas', (int) $summary['unexpected']],
      ]; ?>
      <?php foreach ($kpis as [$filter, $label, $value]): ?>
      <div class="col-6 col-lg-2">
        <button type="button" class="btn border rounded p-3 h-100 w-100 text-start bg-body count-kpi" data-count-kpi="<?= esc($filter) ?>" data-count-kpi-label="<?= esc($label) ?>">
          <small class="text-secondary d-block"><?= esc($label) ?></small>
          <span class="fs-4 fw-bold"><?= $value ?></span>
          <small class="d-block text-primary mt-1">Ver detalle</small>
        </button>
      </div>
      <?php endforeach ?>
    </div>

    <?php if ($selected['status'] === 'counting'): ?>
    <div class="border rounded p-3 mb-4 bg-body-tertiary">
      <div class="row g-3 align-items-end">
        <div class="col-lg-8"><label class="form-label" for="countScanner">Escanear o buscar tina</label><div class="input-group"><span class="input-group-text"><i class="bi bi-qr-code-scan"></i></span><input class="form-control form-control-lg" id="countScanner" autocomplete="off" placeholder="Tina, unidad o QR del marbete"><button class="btn btn-primary" type="button" data-count-lookup>Buscar</button></div></div>
        <div class="col-lg-4"><small class="text-secondary">El conteo registra presencia y Cámara/Fila. El peso del inventario no se modifica.</small></div>
      </div>
      <div class="mt-3 d-none" id="countUnitCard">
        <div class="card"><div class="card-body">
          <div class="d-flex justify-content-between gap-3 mb-3"><div><div class="fs-5 fw-bold" data-unit-title></div><div class="text-secondary" data-unit-subtitle></div></div><span class="badge text-bg-light align-self-start" data-unit-result></span></div>
          <div class="row g-3 mb-3">
            <div class="col-md-3"><small class="text-secondary d-block">Especie</small><strong data-unit-species>—</strong></div>
            <div class="col-md-3"><small class="text-secondary d-block">Talla</small><strong data-unit-size>—</strong></div>
            <div class="col-md-3"><small class="text-secondary d-block">Lote</small><strong data-unit-lot>—</strong></div>
            <div class="col-md-3"><small class="text-secondary d-block">Ubicación esperada</small><strong data-unit-expected>—</strong></div>
          </div>
          <div class="row g-3 align-items-end">
            <div class="col-md-5"><label class="form-label" for="countActualChamber">Cámara encontrada</label><select class="form-select" id="countActualChamber"><option value="">Seleccione…</option></select></div>
            <div class="col-md-5"><label class="form-label" for="countActualRow">Fila encontrada</label><select class="form-select" id="countActualRow"><option value="">Seleccione…</option></select></div>
            <div class="col-md-2 d-grid"><button class="btn btn-primary" type="button" data-count-found>Confirmar</button></div>
          </div>
          <div class="mt-3"><button class="btn btn-outline-danger d-none" type="button" data-count-missing>No encontrada</button></div>
        </div></div>
      </div>
    </div>
    <?php endif ?>

    <h2 class="h6 mb-2">Últimos registros y diferencias</h2>
    <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Tina</th><th>Producto</th><th>Esperada</th><th>Encontrada</th><th>Resultado</th><th>Hora</th></tr></thead><tbody>
    <?php if ($observations === []): ?><tr><td colspan="6" class="text-center text-secondary py-4">Todavía no hay capturas.</td></tr><?php endif ?>
    <?php foreach ($observations as $row): ?><tr><td class="fw-semibold"><?= esc($row['tub_code'] ?: $row['unit_code']) ?></td><td><?= esc(trim(($row['species_name'] ?? '') . ' · ' . ($row['size_name'] ?? ''), ' ·')) ?><br><small class="text-secondary"><?= esc($row['lot_code'] ?? '') ?></small></td><td><?= esc($row['expected_row'] ?: '—') ?></td><td><?= esc($row['counted_row'] ?: '—') ?></td><td><?= esc($resultLabel[$row['result']] ?? $row['result']) ?></td><td><?= esc($row['counted_at'] ?? '—') ?></td></tr><?php endforeach ?>
    </tbody></table></div>
  </div>
</div>
<?php endif ?>


<div class="modal fade" id="countItemsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div><h2 class="modal-title fs-5" data-count-items-title>Detalle del conteo</h2><small class="text-secondary">Consulta paginada; no se cargan todas las tinas en el navegador.</small></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 mb-3">
          <div class="col-md-9"><div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="search" class="form-control" id="countItemsSearch" placeholder="Buscar por tina, unidad, lote, especie o talla"><button class="btn btn-outline-primary" type="button" data-count-items-search>Buscar</button></div></div>
          <div class="col-md-3 text-md-end"><small class="text-secondary" data-count-items-total></small></div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Tina</th><th>Producto</th><th>Lote</th><th>Esperada</th><th>Encontrada</th><th>Resultado</th><th class="text-end"></th></tr></thead>
            <tbody data-count-items-body><tr><td colspan="7" class="text-center text-secondary py-4">Selecciona un indicador.</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <small class="text-secondary" data-count-items-page></small>
        <div class="btn-group"><button class="btn btn-outline-secondary" type="button" data-count-items-prev>Anterior</button><button class="btn btn-outline-secondary" type="button" data-count-items-next>Siguiente</button></div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="startCountModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><form data-count-start><div class="modal-header"><h2 class="modal-title fs-5">Nuevo conteo</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label" for="countWarehouse">Almacén</label><select class="form-select" id="countWarehouse" name="warehouse_id" required><option value="">Seleccione…</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>"><?= esc($warehouse['code'] . ' · ' . $warehouse['name']) ?></option><?php endforeach ?></select></div><div class="mb-3"><label class="form-label" for="countChamber">Cámara opcional</label><select class="form-select" id="countChamber" name="chamber_id"><option value="">Todo el almacén</option><?php foreach ($chambers as $chamber): ?><option value="<?= (int) $chamber['id'] ?>" data-warehouse="<?= (int) $chamber['warehouse_id'] ?>"><?= esc($chamber['code'] . ' · ' . $chamber['name']) ?></option><?php endforeach ?></select></div><label class="form-label" for="countObservations">Observaciones</label><textarea class="form-control" id="countObservations" name="observations" rows="3" maxlength="1000"></textarea></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Iniciar conteo</button></div></form></div></div></div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="/assets/js/inventory-counts.js?v=2.0.2"></script><?= $this->endSection() ?>
