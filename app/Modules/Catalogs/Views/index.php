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

$isPrinterCatalog = $selected === 'printers';
$isScaleCatalog = $selected === 'scales';
$isChamberMapCatalog = in_array($selected, ['chambers', 'locations'], true);
?>
<?= $this->extend('layouts/app') ?>
<?php if ($isPrinterCatalog || $isScaleCatalog): ?><?= $this->section('styles') ?><link rel="stylesheet" href="/assets/css/printers-catalog.css?v=scale-1.0.4"><?= $this->endSection() ?><?php endif ?>
<?= $this->section('pageActions') ?>
<div class="d-flex gap-2 flex-wrap justify-content-end">
  <?php if ($isChamberMapCatalog): ?>
    <a class="btn btn-outline-primary" href="/inventario/camaras">
      <i class="bi bi-grid-3x3-gap"></i> Mapa de cámaras
    </a>
  <?php endif ?>
  <button class="btn btn-primary btn-operational" type="button" data-catalog-new><i class="bi bi-plus-lg"></i> Nuevo registro</button>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if ($isPrinterCatalog): ?>
<div class="printer-master-hero mb-3">
  <div>
    <span class="printer-master-kicker"><i class="bi bi-printer-fill"></i> Centro de impresión</span>
    <h2>Configura una vez, imprime desde cualquier operación</h2>
    <p>La PC43t conectada por USB se atiende mediante el Agente Local Grupo Mar instalado en la estación. Este catálogo conserva qué impresora corresponde a cada estación y permite probarla sin crear movimientos operativos.</p>
  </div>
  <div class="printer-master-flow" aria-label="Flujo de impresión"><span>Sistema web</span><i class="bi bi-arrow-right"></i><span>Agente local</span><i class="bi bi-arrow-right"></i><span>Windows / USB</span><i class="bi bi-arrow-right"></i><strong>PC43t</strong></div>
</div>
<?php endif ?>

<?php if ($isScaleCatalog): ?>
<div class="printer-master-hero scale-master-hero mb-3">
  <div>
    <span class="printer-master-kicker"><i class="bi bi-speedometer2"></i> Centro de básculas</span>
    <h2>Configura y valida la báscula antes de usarla en operación</h2>
    <p>La báscula serial se conecta a Windows mediante el convertidor USB/RS-232 y el Agente Local Grupo Mar. Desde aquí puedes detectar el puerto COM, probar comunicación y leer un peso sin crear movimientos de inventario.</p>
  </div>
  <div class="printer-master-flow" aria-label="Flujo de báscula"><span>Báscula</span><i class="bi bi-arrow-right"></i><span>COM / USB serial</span><i class="bi bi-arrow-right"></i><span>Agente local</span><i class="bi bi-arrow-right"></i><strong>Sistema web</strong></div>
</div>
<?php endif ?>

<?php if ($isScaleCatalog): ?>
<div class="card printer-agent-card scale-agent-card mb-3">
  <div class="card-body">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
      <div>
        <div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-usb-plug-fill text-primary"></i><strong>Grupo Mar Scale Agent · Serial</strong></div>
        <div class="text-secondary small">Instálalo en la estación que tenga el convertidor USB/RS-232. Escucha únicamente en <code>127.0.0.1:18182</code> y no registra inventario por sí mismo.</div>
      </div>
      <a class="btn btn-outline-primary flex-shrink-0" href="/downloads/GrupoMar-ScaleAgent-Serial.zip" download><i class="bi bi-download"></i> Descargar agente</a>
    </div>
    <div class="row g-3 mt-1">
      <div class="col-lg-8"><label class="form-label mb-1">Iniciar agente manualmente</label><div class="printer-command"><code>node C:\Agente\ScaleAgent\server.js</code></div></div>
      <div class="col-lg-4"><label class="form-label mb-1">Comprobar estado</label><div class="printer-command"><code>http://127.0.0.1:18182/api/v1/status</code></div></div>
    </div>
    <div class="small text-secondary mt-2">Configuración inicial sugerida: <b>9600 · 8N1 · comando P · kg</b>. El puerto COM se detecta en cada estación y no queda fijado en código.</div>
  </div>
</div>
<?php endif ?>

<?php if ($isPrinterCatalog): ?>
<div class="card printer-agent-card mb-3">
  <div class="card-body">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
      <div>
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-cpu-fill text-primary"></i>
          <strong>Agente local de impresión Node.js</strong>
        </div>
        <div class="text-secondary small">
          Instala este paquete en cada estación que tenga una PC43t conectada por USB. El agente escucha únicamente en <code>127.0.0.1:18181</code>.
        </div>
      </div>
      <a class="btn btn-outline-primary flex-shrink-0" href="/downloads/GrupoMar-PrintAgent-PC43t.zip" download>
        <i class="bi bi-download"></i> Descargar agente
      </a>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-lg-8">
        <label class="form-label mb-1">Iniciar agente manualmente</label>
        <div class="printer-command"><code>node C:\Agente\PrintAgent\server.js</code></div>
      </div>
      <div class="col-lg-4">
        <label class="form-label mb-1">Comprobar estado</label>
        <div class="printer-command"><code>http://127.0.0.1:18181/api/v1/status</code></div>
      </div>
    </div>
    <div class="small text-secondary mt-2">
      El ZIP incluye <code>server.js</code>, <code>print-raw.ps1</code>, configuración de ejemplo y un acceso <code>INICIAR-AGENTE.cmd</code>.
    </div>
  </div>
</div>
<?php endif ?>

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
                  <?php if ($isPrinterCatalog && ($row['connection_mode'] ?? '') === 'local_agent'): ?>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-printer-test-agent="<?= (int) $row['id'] ?>" title="Comprobar agente e impresora de Windows"><i class="bi bi-usb-plug"></i> Probar</button>
                    <button class="btn btn-sm btn-primary" type="button" data-printer-test-print="<?= (int) $row['id'] ?>" title="Imprimir marbete ficticio"><i class="bi bi-printer"></i> Imprimir prueba</button>
                  <?php endif ?>
                  <?php if ($isScaleCatalog && ($row['connection_type'] ?? '') !== 'simulator'): ?>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-scale-test-agent="<?= (int) $row['id'] ?>" title="Comprobar agente y puerto serial"><i class="bi bi-usb-plug"></i> Probar</button>
                    <button class="btn btn-sm btn-primary" type="button" data-scale-read="<?= (int) $row['id'] ?>" title="Solicitar una lectura de peso"><i class="bi bi-speedometer2"></i> Leer peso</button>
                  <?php endif ?>
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
  <div class="modal-dialog modal-lg modal-dialog-scrollable catalog-modal-dialog"><div class="modal-content">
    <form data-catalog-form class="catalog-modal-form">
      <div class="modal-header"><div><h2 class="modal-title fs-5" id="catalogModalTitle">Nuevo registro</h2><small class="text-secondary"><?= esc($definition['title']) ?></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body"><div class="row g-3 industrial-form">
        <?php foreach ($definition['fields'] as $field => $metadata): ?>
          <?php if (($metadata['hidden'] ?? false) === true) { continue; } ?>
          <div class="<?= ($metadata['type'] ?? null) === 'textarea' ? 'col-12' : 'col-md-6' ?>" data-printer-field="<?= esc($field) ?>" data-scale-field="<?= esc($field) ?>"<?= isset($metadata['printer_mode']) ? ' data-printer-mode="' . esc($metadata['printer_mode']) . '"' : '' ?>>
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
                <?php if ($isPrinterCatalog && $field === 'windows_printer_name'): ?><button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-printer-detect><i class="bi bi-search"></i> Detectar impresoras de Windows</button><?php endif ?>
                <?php if ($isScaleCatalog && $field === 'port_name'): ?><button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-scale-detect><i class="bi bi-search"></i> Detectar puertos COM</button><?php endif ?>
              <?php endif ?>
              <div class="invalid-feedback" data-field-error="<?= esc($field) ?>"></div>
            <?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
      <?php if ($isPrinterCatalog): ?>
        <div class="printer-help mt-3"><i class="bi bi-info-circle"></i><div><strong>PC43t por USB:</strong> usa <b>Agente local</b>, selecciona el nombre exacto instalado en Windows y configura la impresora en modo <b>ZSim / ZPL</b>. La prueba no genera un marbete operativo.</div></div>
      <?php endif ?>
      <?php if ($isScaleCatalog): ?>
        <div class="printer-help mt-3"><i class="bi bi-info-circle"></i><div><strong>Báscula serial:</strong> primero detecta el COM, guarda la configuración y usa <b>Probar</b>. Después usa <b>Leer peso</b>. Estas acciones sólo validan hardware; no generan pesajes, solicitudes ni movimientos de inventario.</div></div>
      <?php endif ?>
      </div>
      <div class="modal-footer catalog-modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-operational no-offline-confirm" type="submit"><i class="bi bi-check2-circle"></i> Guardar</button></div>
    </form>
  </div></div>
</div>
<div hidden id="catalogRows"><?= json_encode(array_column($rows, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></div>
<div hidden id="catalogContext"><?= json_encode(['catalog' => $selected, 'json_fields' => array_keys(array_filter($definition['fields'], static fn ($item) => $item['json'] ?? false))], JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?></div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="/assets/js/catalogs.js?v=scale-1.0"></script><?= $this->endSection() ?>
