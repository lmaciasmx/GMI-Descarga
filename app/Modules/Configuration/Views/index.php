<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="alert alert-warning d-flex gap-3 align-items-start"><i class="bi bi-exclamation-triangle-fill fs-4"></i><div><strong>Gobierno de reglas.</strong> Los cambios afectan operaciones nuevas y quedan auditados. La clasificación automática de calidad debe permanecer desactivada hasta resolver formalmente el traslape del 15%.</div></div>
<div class="row g-3">
<?php foreach ($settings as $setting): ?>
  <div class="col-xl-6"><div class="card h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2"><div><h2 class="h6 mb-1 font-monospace"><?= esc($setting['setting_key']) ?></h2><p class="small text-secondary mb-3"><?= esc($setting['description'] ?? 'Sin descripción') ?></p></div><span class="badge text-bg-light align-self-start"><?= esc($setting['value_type']) ?></span></div>
    <form data-setting-form="<?= (int) $setting['id'] ?>"><div class="input-group"><?php if ($setting['value_type'] === 'boolean'): ?><select class="form-select" name="value"><option value="true" <?= $setting['setting_value'] === 'true' ? 'selected' : '' ?>>true · activo</option><option value="false" <?= $setting['setting_value'] === 'false' ? 'selected' : '' ?>>false · inactivo</option></select><?php elseif ($setting['value_type'] === 'json'): ?><textarea class="form-control font-monospace" name="value" rows="3" maxlength="5000"><?= esc($setting['setting_value']) ?></textarea><?php else: ?><input class="form-control font-monospace" name="value" value="<?= esc($setting['setting_value']) ?>" maxlength="5000"><?php endif ?><button class="btn btn-outline-primary no-offline-confirm" type="submit"><i class="bi bi-save"></i> Guardar</button></div></form>
  </div></div></div>
<?php endforeach ?>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="/assets/js/configuration.js"></script><?= $this->endSection() ?>
