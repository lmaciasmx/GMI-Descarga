<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<div class="status-strip">
  <div class="status-pill" data-network-status><span class="status-dot"></span><span data-status-text>Red</span></div>
  <div class="status-pill warning" id="scaleStatus"><span class="status-dot"></span><span>Báscula: verificando</span></div>
  <div class="status-pill <?= ($printer['adapter_key'] ?? '') === 'pdf' ? 'warning' : 'online' ?>" id="printerStatus"><span class="status-dot"></span><span>Impresora: <?= esc($printer['name'] ?? 'no configurada') ?></span></div>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div hidden id="stationData"><?= json_encode(['plans' => $plans, 'tanks' => $tanks, 'tubs' => $tubs, 'species' => $species, 'sizes' => $sizes, 'pending' => $pending, 'sourcesByFilling' => $sourcesByFilling, 'template' => $template, 'printer' => $printer, 'scale' => $scale, 'estimatedTare' => $estimatedTare, 'canOperateDischarge' => $canOperateDischarge], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></div>

<div class="row g-3">
  <div class="col-xxl-7">
    <div class="card mb-3">
      <div class="card-header">1 · Contexto de la tina<?= $canOperateDischarge ? ' y contingencia de llenado' : ' lista para pesaje' ?></div>
      <div class="card-body industrial-form">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="pendingFilling">Tina lista para pesaje</label>
            <select id="pendingFilling" class="form-select" data-select2>
              <option value="">Nueva captura desde plan…</option>
              <?php foreach ($pending as $item): ?>
                <option value="<?= (int) $item['id'] ?>" data-tub="<?= (int) $item['tub_id'] ?>"><?= esc($item['tub_code'] . ' · ' . $item['species_name'] . ' · ' . $item['size_name'] . ' · tanque ' . $item['tank_number']) ?></option>
              <?php endforeach ?>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label" for="planId">Plan de descarga</label>
            <select id="planId" class="form-select" data-select2 <?= $canOperateDischarge ? '' : 'disabled' ?>>
              <option value="">Selecciona plan…</option>
              <?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>"><?= esc($plan['folio'] . ' · ' . $plan['vessel_code'] . ' · marea ' . $plan['annual_number']) ?></option><?php endforeach ?>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label">RSA</label><input id="rsaDisplay" class="form-control" value="Pendiente" readonly></div>
          <div class="col-md-4">
            <label class="form-label" for="tubId">Número de tina</label>
            <select id="tubId" class="form-select" data-select2 <?= $canOperateDischarge ? '' : 'disabled' ?>>
              <option value="">Selecciona…</option>
              <?php foreach ($tubs as $tub): ?><option value="<?= (int) $tub['id'] ?>" data-tare="<?= (float) $tub['current_tare_kg'] ?>" data-status="<?= esc($tub['status']) ?>"><?= esc($tub['code'] . ' · ' . $tub['status']) ?></option><?php endforeach ?>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label" for="actualTankId">Tanque real</label><select id="actualTankId" class="form-select" data-select2 <?= $canOperateDischarge ? '' : 'disabled' ?>><option value="">Selecciona…</option></select></div>
          <div class="col-md-4"><label class="form-label" for="sourceTankIds">Tanques de procedencia</label><select id="sourceTankIds" class="form-select" multiple data-select2 <?= $canOperateDischarge ? '' : 'disabled' ?>></select></div>
          <div class="col-md-6">
            <label class="form-label" for="speciesId">Especie / producto</label>
            <select id="speciesId" class="form-select" data-select2 <?= $canOperateDischarge ? '' : 'disabled' ?>><option value="">Selecciona…</option><?php foreach ($species as $item): ?><option value="<?= (int) $item['id'] ?>"><?= esc($item['code'] . ' · ' . $item['name']) ?></option><?php endforeach ?></select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="sizeId">Talla</label>
            <select id="sizeId" class="form-select" data-select2 <?= $canOperateDischarge ? '' : 'disabled' ?>><option value="">Selecciona…</option><?php foreach ($sizes as $item): ?><option value="<?= (int) $item['id'] ?>" data-species="<?= (int) ($item['species_id'] ?? 0) ?>"><?= esc($item['name']) ?></option><?php endforeach ?></select>
          </div>
          <input id="sorterId" type="hidden" value="<?= $canOperateDischarge ? $currentUserId : '' ?>"><input id="labelerId" type="hidden" value="">
          <?php if ($canOperateDischarge): ?><div class="col-12"><div class="alert alert-info small mb-0">Contingencia supervisada: el llenado se atribuirá a <strong><?= esc($currentUsername) ?></strong>. El flujo normal se registra en “Descarga y llenado”.</div></div>
          <div class="col-12 d-grid d-md-flex justify-content-md-end"><button type="button" class="btn btn-outline-primary btn-operational no-offline-confirm" id="prepareFilling"><i class="bi bi-check2-circle"></i> Cerrar llenado y preparar pesaje</button></div><?php endif ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">2 · Peso, tara y castigo</div>
      <div class="card-body industrial-form">
        <div class="weight-display mb-3" aria-live="polite"><strong id="grossDisplay">0.000</strong><span>kg bruto</span></div>
        <div class="d-grid mb-3"><button class="btn btn-primary btn-operational no-offline-confirm" type="button" id="readScale"><i class="bi bi-speedometer"></i> Leer peso estable</button></div>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label" for="weightSource">Fuente de peso</label><select id="weightSource" class="form-select"><option value="scale">Báscula / middleware</option><option value="manual">Manual de contingencia</option></select></div>
          <div class="col-md-4"><label class="form-label" for="grossWeight">Peso bruto kg</label><input id="grossWeight" type="number" min="0" step="0.001" class="form-control" readonly></div>
          <div class="col-md-4"><label class="form-label" for="tareSource">Fuente de tara</label><select id="tareSource" class="form-select"><option value="catalog">Catálogo de tina</option><option value="painted">Tara pintada</option><option value="estimated">Estimada de contingencia</option></select></div>
          <div class="col-md-4"><label class="form-label" for="declaredTare">Tara declarada kg</label><input id="declaredTare" type="number" min="0" step="0.001" class="form-control" disabled></div>
          <div class="col-md-4"><label class="form-label" for="penaltyPercent">Castigo %</label><input id="penaltyPercent" type="number" min="0" max="100" step="0.001" value="0" class="form-control"></div>
          <div class="col-md-4"><label class="form-label" for="reasonCode">Motivo contingencia / castigo</label><input id="reasonCode" maxlength="50" class="form-control" placeholder="Obligatorio cuando aplique"></div>
          <div class="col-12"><label class="form-label" for="observations">Observaciones</label><textarea id="observations" class="form-control" rows="2" maxlength="1000"></textarea></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xxl-5">
    <div class="card position-sticky" style="top:84px">
      <div class="card-header">3 · Resultado y confirmación</div>
      <div class="card-body">
        <div class="row g-2 mb-3">
          <div class="col-6"><div class="calculation-tile"><small>Tara aplicada</small><strong id="tareResult">0.000 kg</strong></div></div>
          <div class="col-6"><div class="calculation-tile"><small>Neto físico</small><strong id="physicalNetResult">0.000 kg</strong></div></div>
          <div class="col-6"><div class="calculation-tile"><small>Castigo</small><strong id="penaltyResult">0.000 kg</strong></div></div>
          <div class="col-6"><div class="calculation-tile"><small>Neto aceptado</small><strong id="acceptedResult">0.000 kg</strong></div></div>
        </div>
        <dl class="row small">
          <dt class="col-5">Lote</dt><dd class="col-7" id="lotStatus">Generación automática versionada</dd>
          <dt class="col-5">Calidad</dt><dd class="col-7"><span class="badge-state held">Pendiente de inspección</span></dd>
          <dt class="col-5">Lectura</dt><dd class="col-7" id="readingStatus">Sin lectura</dd>
          <dt class="col-5">Etiqueta</dt><dd class="col-7"><?= esc($template['code'] ?? 'Sin plantilla') ?> v<?= (int) ($template['version'] ?? 0) ?></dd>
        </dl>
        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="printAfter" checked><label class="form-check-label fw-semibold" for="printAfter">Imprimir etiqueta al confirmar</label></div>
        <div class="alert alert-warning small"><i class="bi bi-shield-exclamation"></i> Confirmar crea un registro inmutable. Las correcciones posteriores se realizan mediante reverso autorizado.</div>
        <div class="d-grid"><button type="button" id="confirmWeighing" class="btn btn-success btn-operational no-offline-confirm"><i class="bi bi-check2-square"></i> Confirmar pesaje</button></div>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="/assets/js/weighing-station.js"></script><?= $this->endSection() ?>
