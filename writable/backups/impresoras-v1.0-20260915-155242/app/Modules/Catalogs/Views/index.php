<?php
$fieldLabels = ['id' => 'ID'];
foreach ($definition['fields'] as $field => $metadata) {
    $fieldLabels[$field] = $metadata['label'];
}

$optionLabels = [];
foreach ($sources as $source => $items) {
    foreach ($items as $item) {
        $optionLabels[$source][(string) $item['id']] =
            $item['name']
            ?? $item['trade_name']
            ?? trim(($item['code'] ?? '') . ' #' . $item['id']);
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

$isLocations = $selected === 'locations';

$sourceOptionText = static function (array $item): string {
    $code = trim((string) ($item['code'] ?? ''));
    $name = trim((string) ($item['name'] ?? $item['trade_name'] ?? ''));

    if ($code !== '' && $name !== '') {
        return $code . ' · ' . $name;
    }

    if ($name !== '') {
        return $name;
    }

    if ($code !== '') {
        return $code;
    }

    return '#' . ($item['id'] ?? '');
};
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('pageActions') ?>
<div class="d-flex gap-2 flex-wrap justify-content-end">
  <?php if (in_array($selected, ['chambers', 'locations'], true)): ?>
    <a class="btn btn-outline-primary" href="/inventario/camaras">
      <i class="bi bi-grid-3x3-gap"></i> Mapa de cámaras
    </a>
  <?php endif ?>

  <button class="btn btn-primary btn-operational" type="button" data-catalog-new>
    <i class="bi bi-plus-lg"></i> Nuevo registro
  </button>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="row g-3">
  <div class="col-xl-3">
    <div class="card catalog-nav-card">
      <div class="card-header">Catálogos administrables</div>
      <div class="list-group list-group-flush catalog-nav">
        <?php foreach ($definitions as $key => $item): ?>
          <a
            class="list-group-item list-group-item-action <?= $key === $selected ? 'active' : '' ?>"
            href="/catalogos?catalog=<?= esc($key) ?>"
          >
            <span><?= esc($item['title']) ?></span>
            <i class="bi bi-chevron-right"></i>
          </a>
        <?php endforeach ?>
      </div>
    </div>
  </div>

  <div class="col-xl-9">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <div>
          <span class="d-block"><?= esc($definition['title']) ?></span>
          <small class="text-secondary fw-normal">
            Los registros usados en operaciones se conservan mediante baja lógica.
          </small>
        </div>
        <span class="badge text-bg-light"><?= count($rows) ?> registros</span>
      </div>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover" data-datatable>
            <thead>
              <tr>
                <?php foreach ($definition['list'] as $field): ?>
                  <th><?= esc($fieldLabels[$field] ?? ucwords(str_replace('_', ' ', $field))) ?></th>
                <?php endforeach ?>
                <th>Acciones</th>
              </tr>
            </thead>

            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <?php foreach ($definition['list'] as $field): ?>
                  <td>
                    <?php if ($field === 'active'): ?>
                      <span class="badge-state <?= (int) $row[$field] === 1 ? 'available' : 'canceled' ?>">
                        <?= (int) $row[$field] === 1 ? 'Activo' : 'Inactivo' ?>
                      </span>
                    <?php else: ?>
                      <?= esc($displayValue($field, $row[$field] ?? null)) ?>
                    <?php endif ?>
                  </td>
                <?php endforeach ?>

                <td class="text-nowrap">
                  <button
                    class="btn btn-sm btn-outline-primary"
                    type="button"
                    data-catalog-edit="<?= (int) $row['id'] ?>"
                  >
                    <i class="bi bi-pencil"></i> Editar
                  </button>

                  <?php if ((int) ($row['active'] ?? 1) === 1): ?>
                    <button
                      class="btn btn-sm btn-outline-danger"
                      type="button"
                      data-catalog-deactivate="<?= (int) $row['id'] ?>"
                      title="Desactivar"
                    >
                      <i class="bi bi-slash-circle"></i>
                    </button>
                  <?php endif ?>
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

<div
  class="modal fade"
  id="catalogModal"
  tabindex="-1"
  aria-labelledby="catalogModalTitle"
  aria-hidden="true"
>
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form data-catalog-form>
        <div class="modal-header">
          <div>
            <h2 class="modal-title fs-5" id="catalogModalTitle">Nuevo registro</h2>
            <small class="text-secondary"><?= esc($definition['title']) ?></small>
          </div>

          <button
            type="button"
            class="btn-close"
            data-bs-dismiss="modal"
            aria-label="Cerrar"
          ></button>
        </div>

        <div class="modal-body">
          <?php if ($isLocations): ?>

            <!-- =========================================================
                 FORMULARIO ESPECIAL: UBICACIONES
                 ========================================================= -->
            <div class="row g-3 industrial-form">

              <div class="col-md-6">
                <label class="form-label" for="field-warehouse_id">Almacén</label>
                <select
                  class="form-select"
                  name="warehouse_id"
                  id="field-warehouse_id"
                  data-select2-modal
                >
                  <option value="">Seleccione...</option>
                  <?php foreach (($sources['warehouses'] ?? []) as $item): ?>
                    <option
                      value="<?= (int) $item['id'] ?>"
                      data-code="<?= esc((string) ($item['code'] ?? '')) ?>"
                    >
                      <?= esc($sourceOptionText($item)) ?>
                    </option>
                  <?php endforeach ?>
                </select>
                <div class="invalid-feedback" data-field-error="warehouse_id"></div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="field-chamber_id">Cámara</label>
                <select
                  class="form-select"
                  name="chamber_id"
                  id="field-chamber_id"
                  data-select2-modal
                >
                  <option value="">Seleccione...</option>
                  <?php foreach (($sources['chambers'] ?? []) as $item): ?>
                    <option
                      value="<?= (int) $item['id'] ?>"
                      data-warehouse-id="<?= (int) ($item['warehouse_id'] ?? 0) ?>"
                      data-code="<?= esc((string) ($item['code'] ?? '')) ?>"
                    >
                      <?= esc($sourceOptionText($item)) ?>
                    </option>
                  <?php endforeach ?>
                </select>
                <div class="invalid-feedback" data-field-error="chamber_id"></div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="field-location_type">Tipo</label>
                <select
                  class="form-select"
                  name="location_type"
                  id="field-location_type"
                  data-select2-modal
                >
                  <option value="">Seleccione...</option>
                  <?php foreach (($definition['fields']['location_type']['options'] ?? []) as $value => $label): ?>
                    <option value="<?= esc((string) $value) ?>"><?= esc($label) ?></option>
                  <?php endforeach ?>
                </select>
                <div class="invalid-feedback" data-field-error="location_type"></div>
                <div class="form-text" id="locationTypeHelp">
                  Selecciona el nivel físico de la ubicación.
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="field-parent_id">Ubicación padre</label>
                <select
                  class="form-select"
                  name="parent_id"
                  id="field-parent_id"
                  data-select2-modal
                >
                  <option value="">Sin ubicación padre</option>
                  <?php foreach (($sources['locations'] ?? []) as $item): ?>
                    <option
                      value="<?= (int) $item['id'] ?>"
                      data-warehouse-id="<?= (int) ($item['warehouse_id'] ?? 0) ?>"
                      data-chamber-id="<?= (int) ($item['chamber_id'] ?? 0) ?>"
                      data-location-type="<?= esc((string) ($item['location_type'] ?? '')) ?>"
                      data-path="<?= esc((string) ($item['path'] ?? '')) ?>"
                      data-code="<?= esc((string) ($item['code'] ?? '')) ?>"
                    >
                      <?= esc($sourceOptionText($item)) ?>
                    </option>
                  <?php endforeach ?>
                </select>
                <div class="invalid-feedback" data-field-error="parent_id"></div>
                <div class="form-text" id="locationParentHelp">
                  Las filas y pasillos no requieren ubicación padre.
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="field-code">Código</label>
                <input
                  class="form-control"
                  type="text"
                  name="code"
                  id="field-code"
                  maxlength="40"
                  autocomplete="off"
                  placeholder="Ej. CAM-01-F13-P14-E06"
                >
                <div class="invalid-feedback" data-field-error="code"></div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="field-name">Nombre</label>
                <input
                  class="form-control"
                  type="text"
                  name="name"
                  id="field-name"
                  maxlength="120"
                  autocomplete="off"
                  placeholder="Ej. Fila 13 - Posición 14 - Estiba 06"
                >
                <div class="invalid-feedback" data-field-error="name"></div>
              </div>

              <!-- path se envía al backend, pero no se captura manualmente -->
              <input type="hidden" name="path" id="field-path">

              <div class="col-12">
                <div class="border rounded-3 p-3 bg-body-tertiary">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi bi-diagram-3 text-primary"></i>
                    <strong>Ruta jerárquica</strong>
                  </div>
                  <div class="font-monospace small text-secondary" id="locationPathPreview">
                    Se generará automáticamente.
                  </div>
                  <div class="invalid-feedback d-block" data-field-error="path"></div>
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="field-max_units">Capacidad de tinas</label>
                <input
                  class="form-control"
                  type="number"
                  name="max_units"
                  id="field-max_units"
                  min="1"
                  step="1"
                >
                <div class="invalid-feedback" data-field-error="max_units"></div>
                <div class="form-text" id="locationCapacityHelp">
                  Para una estiba final se establece automáticamente en 1.
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="field-max_weight_kg">Capacidad kg</label>
                <input
                  class="form-control"
                  type="number"
                  name="max_weight_kg"
                  id="field-max_weight_kg"
                  min="0"
                  step="0.001"
                >
                <div class="invalid-feedback" data-field-error="max_weight_kg"></div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="field-segregation_rule">Segregación</label>
                <select
                  class="form-select"
                  name="segregation_rule"
                  id="field-segregation_rule"
                  data-select2-modal
                >
                  <?php foreach (($definition['fields']['segregation_rule']['options'] ?? []) as $value => $label): ?>
                    <option value="<?= esc((string) $value) ?>"><?= esc($label) ?></option>
                  <?php endforeach ?>
                </select>
                <div class="invalid-feedback" data-field-error="segregation_rule"></div>
              </div>

              <div class="col-md-6 d-flex align-items-end">
                <div class="form-check form-switch mb-2">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    name="active"
                    id="field-active"
                    value="1"
                  >
                  <label class="form-check-label fw-bold" for="field-active">
                    Activo
                  </label>
                  <div class="form-text">
                    Activo indica que la ubicación existe en el catálogo.
                  </div>
                </div>
              </div>

            </div>

          <?php else: ?>

            <!-- =========================================================
                 FORMULARIO GENÉRICO: RESTO DE CATÁLOGOS
                 ========================================================= -->
            <div class="row g-3 industrial-form">
              <?php foreach ($definition['fields'] as $field => $metadata): ?>
                <div class="<?= ($metadata['type'] ?? null) === 'textarea' ? 'col-12' : 'col-md-6' ?>">

                  <?php if (($metadata['type'] ?? null) === 'checkbox'): ?>

                    <div class="form-check form-switch mt-4">
                      <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        name="<?= esc($field) ?>"
                        id="field-<?= esc($field) ?>"
                        value="1"
                      >
                      <label
                        class="form-check-label fw-bold"
                        for="field-<?= esc($field) ?>"
                      >
                        <?= esc($metadata['label']) ?>
                      </label>
                    </div>

                  <?php else: ?>

                    <label class="form-label" for="field-<?= esc($field) ?>">
                      <?= esc($metadata['label']) ?>
                    </label>

                    <?php if (($metadata['type'] ?? null) === 'select'): ?>

                      <select
                        class="form-select"
                        name="<?= esc($field) ?>"
                        id="field-<?= esc($field) ?>"
                        data-select2-modal
                      >
                        <option value="">Seleccione...</option>

                        <?php foreach (($metadata['options'] ?? []) as $value => $label): ?>
                          <option value="<?= esc((string) $value) ?>">
                            <?= esc($label) ?>
                          </option>
                        <?php endforeach ?>

                        <?php foreach (($sources[$metadata['source'] ?? ''] ?? []) as $item): ?>
                          <option value="<?= (int) $item['id'] ?>">
                            <?= esc($sourceOptionText($item)) ?>
                          </option>
                        <?php endforeach ?>
                      </select>

                    <?php elseif (($metadata['type'] ?? null) === 'textarea'): ?>

                      <textarea
                        class="form-control"
                        rows="4"
                        name="<?= esc($field) ?>"
                        id="field-<?= esc($field) ?>"
                        <?= ($metadata['json'] ?? false)
                            ? 'placeholder="{&quot;clave&quot;: &quot;valor&quot;} · al editar, vacío conserva la configuración"'
                            : '' ?>
                      ></textarea>

                    <?php else: ?>

                      <input
                        class="form-control"
                        type="<?= esc($metadata['type'] ?? 'text') ?>"
                        name="<?= esc($field) ?>"
                        id="field-<?= esc($field) ?>"
                        step="<?= esc($metadata['step'] ?? '') ?>"
                      >

                    <?php endif ?>

                    <div
                      class="invalid-feedback"
                      data-field-error="<?= esc($field) ?>"
                    ></div>

                  <?php endif ?>
                </div>
              <?php endforeach ?>
            </div>

          <?php endif ?>
        </div>

        <div class="modal-footer">
          <button
            type="button"
            class="btn btn-light"
            data-bs-dismiss="modal"
          >
            Cancelar
          </button>

          <button
            class="btn btn-primary btn-operational no-offline-confirm"
            type="submit"
          >
            <i class="bi bi-check2-circle"></i> Guardar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div hidden id="catalogRows"><?= json_encode(
    array_column($rows, null, 'id'),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
) ?></div>

<div hidden id="catalogContext"><?= json_encode([
    'catalog' => $selected,
    'json_fields' => array_keys(array_filter(
        $definition['fields'],
        static fn ($item) => $item['json'] ?? false
    )),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/catalogs.js"></script>
<?= $this->endSection() ?>
