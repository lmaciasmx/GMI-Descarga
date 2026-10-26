<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<div class="status-strip">
  <div class="status-pill" data-network-status><span class="status-dot"></span><span data-status-text>Red</span></div>
  <div class="status-pill online"><span class="status-dot"></span><span><?= count($plans) ?> plan(es) en operación</span></div>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div hidden id="dischargeData"><?= json_encode(['plans' => $plans, 'lines' => $lines, 'tanks' => $tanks, 'tubs' => $tubs, 'sizes' => $sizes, 'currentUserId' => $currentUserId], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></div>

<div class="row g-3">
  <div class="col-xl-5">
    <div class="card position-sticky" style="top:84px">
      <div class="card-header">Iniciar llenado</div>
      <div class="card-body industrial-form">
        <?php if ($plans === []): ?>
          <div class="alert alert-warning mb-0">No existe un plan con estado <strong>en operación</strong> para este alcance. El supervisor debe aprobarlo e iniciarlo desde Planeación.</div>
        <?php else: ?>
          <form id="fillingForm">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label" for="dischargePlan">Plan, embarcación y marea</label>
                <select class="form-select" id="dischargePlan" required data-select2>
                  <option value="">Selecciona…</option>
                  <?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>"><?= esc($plan['folio'] . ' · ' . $plan['vessel_code'] . ' · marea ' . $plan['annual_number'] . ' · RSA ' . ($plan['rsa_code'] ?? '—')) ?></option><?php endforeach ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label" for="planComposition">Tanque real y composición planeada</label>
                <select class="form-select" id="planComposition" required data-select2 disabled><option value="">Selecciona primero un plan…</option></select>
                <div class="form-text" id="certificationSummary">MSC, Dolphin Safe y temperatura se heredan del plan.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="fillingSize">Talla operativa</label>
                <select class="form-select" id="fillingSize" required data-select2 disabled><option value="">Selecciona composición…</option></select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="fillingTub">Tina disponible</label>
                <select class="form-select" id="fillingTub" required data-select2>
                  <option value="">Selecciona…</option>
                  <?php foreach ($tubs as $tub): ?><option value="<?= (int) $tub['id'] ?>"><?= esc($tub['code'] . ($tub['barcode'] ? ' · ' . $tub['barcode'] : '')) ?></option><?php endforeach ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label" for="sourceTanks">Tanques de procedencia</label>
                <select class="form-select" id="sourceTanks" multiple required data-select2 disabled></select>
                <div class="form-text">El tanque real queda incluido obligatoriamente; agrega otros cuando exista mezcla de procedencia.</div>
              </div>
              <div class="col-12">
                <label class="form-label" for="fillingNotes">Observaciones</label>
                <textarea class="form-control" id="fillingNotes" rows="2" maxlength="1000"></textarea>
              </div>
              <div class="col-12 d-grid">
                <button class="btn btn-primary btn-operational no-offline-confirm" type="submit"><i class="bi bi-play-circle"></i> Iniciar llenado</button>
              </div>
            </div>
          </form>
        <?php endif ?>
      </div>
    </div>
  </div>

  <div class="col-xl-7">
    <div class="card">
      <div class="card-header"><div><span>Flujo de tinas</span><small class="d-block text-secondary">Cerrar un llenado cambia la tina a “pendiente de pesaje”; no crea inventario.</small></div></div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover align-middle" data-datatable data-export-title="Descarga-llenados">
            <thead><tr><th>Tina</th><th>Plan</th><th>Tanque real</th><th>Procedencia</th><th>Producto</th><th>Inicio</th><th>Estado</th><th>Acción</th></tr></thead>
            <tbody>
              <?php foreach ($fillings as $filling): ?>
                <tr>
                  <td class="fw-bold fs-5"><?= esc($filling['tub_code']) ?></td>
                  <td><?= esc($filling['plan_folio']) ?></td>
                  <td><?= esc($filling['tank_number']) ?></td>
                  <td><?= esc($filling['source_tanks']) ?></td>
                  <td><?= esc($filling['species_name'] . ' · ' . $filling['size_name']) ?></td>
                  <td><?= esc($filling['started_at']) ?></td>
                  <td><span class="badge-state <?= esc($filling['status']) ?>"><?= esc(str_replace('_', ' ', $filling['status'])) ?></span></td>
                  <td><?php if ($filling['status'] === 'filling'): ?><button class="btn btn-success btn-operational no-offline-confirm" type="button" data-complete-filling="<?= (int) $filling['id'] ?>" data-tub-code="<?= esc($filling['tub_code'], 'attr') ?>"><i class="bi bi-check2-circle"></i> Cerrar y enviar a pesaje</button><?php else: ?><span class="text-secondary">En cola de pesaje</span><?php endif ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="/assets/js/discharge-station.js"></script><?= $this->endSection() ?>
