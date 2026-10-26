<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<div class="status-strip"><div class="status-pill" data-network-status><span class="status-dot"></span><span data-status-text>Red</span></div></div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $scope = session('operational_scope'); ?>
<div hidden id="receivingData"><?= json_encode(['companyId' => $scope['company_id'], 'siteId' => $scope['site_id'], 'locations' => $locations], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></div>
<div class="card mb-3">
  <div class="card-header">Destino de recepción</div>
  <div class="card-body industrial-form">
    <div class="row g-3">
      <div class="col-lg-4"><label class="form-label" for="warehouseId">Frigorífico / almacén</label><select id="warehouseId" class="form-select" data-select2><option value="">Selecciona…</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>"><?= esc($warehouse['code'] . ' · ' . $warehouse['name']) ?></option><?php endforeach ?></select></div>
      <div class="col-lg-4"><label class="form-label" for="locationId">Cámara / ubicación</label><select id="locationId" class="form-select" data-select2><option value="">Selecciona almacén…</option></select></div>
      <div class="col-lg-4"><label class="form-label" for="scanLabel">Escanear QR o código</label><input id="scanLabel" class="form-control scan-target" autocomplete="off" placeholder="Lee el marbete y presiona Enter"></div>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center"><span>Pesajes etiquetados pendientes</span><span class="badge text-bg-warning"><?= count($pending) ?></span></div>
  <div class="card-body">
    <div class="table-responsive"><table class="table table-hover" id="receivingTable"><thead><tr><th><input class="form-check-input" type="checkbox" id="selectAll" aria-label="Seleccionar todo"></th><th>Marbete</th><th>Tina</th><th>Pesaje</th><th>Lote</th><th>Producto</th><th>Neto aceptado kg</th><th>Confirmado</th></tr></thead><tbody>
    <?php foreach ($pending as $row): ?><tr data-label="<?= esc($row['label_code']) ?>"><td><input class="form-check-input receive-check" type="checkbox" value="<?= (int) $row['id'] ?>"></td><td class="fw-bold"><?= esc($row['label_code']) ?></td><td><?= esc($row['tub_code']) ?></td><td><?= esc($row['folio']) ?></td><td><?= esc($row['lot_code']) ?></td><td><?= esc($row['species_name']) ?><br><small class="text-secondary"><?= esc($row['size_name']) ?></small></td><td class="fw-bold"><?= number_format((float) $row['accepted_net_kg'], 3) ?></td><td><?= esc($row['confirmed_at']) ?></td></tr><?php endforeach ?>
    </tbody></table></div>
    <div class="d-grid d-md-flex justify-content-md-end mt-3"><button id="confirmReception" type="button" class="btn btn-success btn-operational no-offline-confirm"><i class="bi bi-box-arrow-in-down"></i> Confirmar recepción seleccionada</button></div>
  </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="/assets/js/receiving.js"></script><?= $this->endSection() ?>
