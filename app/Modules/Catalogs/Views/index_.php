<?php
$fieldLabels = ['id' => 'ID'];
foreach ($definition['fields'] as $field => $metadata) {
    $fieldLabels[$field] = $metadata['label'];
}
$optionLabels = [];
foreach ($sources as $source => $items) {
    foreach ($items as $item) {
        $optionLabels[$source][(string) $item['id']] = $item['name'] ?? $item['trade_name'] ?? trim(($item['code'] ?? '') . ' #' . $item['id']);
    }
}
$displayValue = static function (string $field, mixed $value) use ($definition, $optionLabels): string {
    $metadata = $definition['fields'][$field] ?? [];
    if (($metadata['type'] ?? null) === 'checkbox') {
        return (int) $value === 1 ? 'Sí' : 'No';
    }
    if (isset($metadata['options'][(string) $value])) {
        return (string) $metadata['options'][(string) $value];
    }
    if (isset($metadata['source'], $optionLabels[$metadata['source']][(string) $value])) {
        return $optionLabels[$metadata['source']][(string) $value];
    }
    return (string) ($value ?? '—');
};
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<button class="btn btn-primary btn-operational" type="button" data-catalog-new><i class="bi bi-plus-lg"></i> Nuevo registro</button>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row g-3">
  <div class="col-xl-3">
    <div class="card catalog-nav-card">
      <div class="card-header">Catálogos administrables</div>
      <div class="list-group list-group-flush catalog-nav">
        <?php foreach ($definitions as $key => $item): ?>
          <a class="list-group-item list-group-item-action <?= $key === $selected ? 'active' : '' ?>" href="/catalogos?catalog=<?= esc($key) ?>">
            <span><?= esc($item['title']) ?></span><i class="bi bi-chevron-right"></i>
          </a>
        <?php endforeach ?>
      </div>
    </div>
  </div>
  <div class="col-xl-9">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <div><span class="d-block"><?= esc($definition['title']) ?></span><small class="text-secondary fw-normal">Los registros usados en operaciones se conservan mediante baja lógica.</small></div>
        <span class="badge text-bg-light"><?= count($rows) ?> registros</span>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover" data-datatable>
            <thead><tr><?php foreach ($definition['list'] as $field): ?><th><?= esc($fieldLabels[$field] ?? ucwords(str_replace('_', ' ', $field))) ?></th><?php endforeach ?><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <?php foreach ($definition['list'] as $field): ?>
                  <td><?php if ($field === 'active'): ?><span class="badge-state <?= (int) $row[$field] === 1 ? 'available' : 'canceled' ?>"><?= (int) $row[$field] === 1 ? 'Activo' : 'Inactivo' ?></span><?php else: ?><?= esc($displayValue($field, $row[$field] ?? null)) ?><?php endif ?></td>
                <?php endforeach ?>
                <td class="text-nowrap">
                  <button class="btn btn-sm btn-outline-primary" type="button" data-catalog-edit="<?= (int) $row['id'] ?>"><i class="bi bi-pencil"></i> Editar</button>
                  <?php if ((int) ($row['active'] ?? 1) === 1): ?><button class="btn btn-sm btn-outline-danger" type="button" data-catalog-deactivate="<?= (int) $row['id'] ?>"><i class="bi bi-slash-circle"></i></button><?php endif ?>
                </td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="catalogModal" tabindex="-1" aria-labelledby="catalogModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <form data-catalog-form>
      <div class="modal-header"><div><h2 class="modal-title fs-5" id="catalogModalTitle">Nuevo registro</h2><small class="text-secondary"><?= esc($definition['title']) ?></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body"><div class="row g-3 industrial-form">
        <?php foreach ($definition['fields'] as $field => $metadata): ?>
          <div class="<?= ($metadata['type'] ?? null) === 'textarea' ? 'col-12' : 'col-md-6' ?>">
            <?php if (($metadata['type'] ?? null) === 'checkbox'): ?>
              <div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" role="switch" name="<?= esc($field) ?>" id="field-<?= esc($field) ?>" value="1"><label class="form-check-label fw-bold" for="field-<?= esc($field) ?>"><?= esc($metadata['label']) ?></label></div>
            <?php else: ?>
              <label class="form-label" for="field-<?= esc($field) ?>"><?= esc($metadata['label']) ?></label>
              <?php if (($metadata['type'] ?? null) === 'select'): ?>
                <select class="form-select" name="<?= esc($field) ?>" id="field-<?= esc($field) ?>" data-select2-modal>
                  <option value="">Seleccione…</option>
                  <?php foreach (($metadata['options'] ?? []) as $value => $label): ?><option value="<?= esc((string) $value) ?>"><?= esc($label) ?></option><?php endforeach ?>
                  <?php foreach (($sources[$metadata['source'] ?? ''] ?? []) as $item): ?><option value="<?= (int) $item['id'] ?>"><?= esc(($item['code'] ?? '') . ' · ' . ($item['name'] ?? $item['trade_name'] ?? '#' . $item['id'])) ?></option><?php endforeach ?>
                </select>
              <?php elseif (($metadata['type'] ?? null) === 'textarea'): ?>
                <textarea class="form-control" rows="4" name="<?= esc($field) ?>" id="field-<?= esc($field) ?>" <?= ($metadata['json'] ?? false) ? 'placeholder="{&quot;clave&quot;: &quot;valor&quot;} · al editar, vacío conserva la configuración"' : '' ?>></textarea>
              <?php else: ?>
                <input class="form-control" type="<?= esc($metadata['type'] ?? 'text') ?>" name="<?= esc($field) ?>" id="field-<?= esc($field) ?>" step="<?= esc($metadata['step'] ?? '') ?>">
              <?php endif ?>
              <div class="invalid-feedback" data-field-error="<?= esc($field) ?>"></div>
            <?php endif ?>
          </div>
        <?php endforeach ?>
      </div></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-operational no-offline-confirm" type="submit"><i class="bi bi-check2-circle"></i> Guardar</button></div>
    </form>
  </div></div>
</div>
<div hidden id="catalogRows"><?= json_encode(array_column($rows, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></div>
<div hidden id="catalogContext"><?= json_encode(['catalog' => $selected, 'json_fields' => array_keys(array_filter($definition['fields'], static fn ($item) => $item['json'] ?? false))], JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?></div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="/assets/js/catalogs.js"></script><?= $this->endSection() ?>
