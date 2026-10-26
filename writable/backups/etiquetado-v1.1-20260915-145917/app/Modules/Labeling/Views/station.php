<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<div class="status-strip">
  <div class="status-pill" data-network-status><span class="status-dot"></span><span data-status-text>Red</span></div>
  <?php $connected = $printerStatus['connected'] ?? null; ?>
  <div class="status-pill <?= $connected === true ? 'online' : ($connected === false ? 'offline' : 'warning') ?>"><span class="status-dot"></span><span>Impresión: <?= esc(str_replace('_', ' ', (string) ($printerStatus['status'] ?? 'sin estado'))) ?></span></div>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div hidden id="labelStationData"><?= json_encode(['templates' => $templates, 'printers' => $printers, 'canPrint' => $canPrint, 'canReprint' => $canReprint], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></div>

<div class="card mb-3">
  <div class="card-header">Configuración de esta estación</div>
  <div class="card-body industrial-form">
    <div class="row g-3 align-items-end">
      <div class="col-lg-6">
        <label class="form-label" for="labelTemplate">Plantilla para impresión inicial</label>
        <select id="labelTemplate" class="form-select" data-select2 <?= $canPrint ? '' : 'disabled' ?>>
          <option value="">Selecciona…</option>
          <?php foreach ($templates as $template): ?><option value="<?= (int) $template['id'] ?>"><?= esc($template['code'] . ' · v' . $template['version'] . ' · ' . strtoupper($template['format'])) ?></option><?php endforeach ?>
        </select>
      </div>
      <div class="col-lg-6">
        <label class="form-label" for="labelPrinter">Impresora / contingencia</label>
        <select id="labelPrinter" class="form-select" data-select2>
          <option value="">Adaptador predeterminado sin equipo físico</option>
          <?php foreach ($printers as $printer): ?><option value="<?= (int) $printer['id'] ?>"><?= esc($printer['code'] . ' · ' . $printer['name'] . ' · ' . $printer['adapter_key']) ?></option><?php endforeach ?>
        </select>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><div><span>Pesajes etiquetables</span><small class="d-block text-secondary">La impresión inicial es única. Toda copia posterior exige motivo y genera un nuevo trabajo auditable.</small></div></div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle" data-datatable data-export-title="Etiquetado-pesajes">
        <thead><tr><th>Pesaje</th><th>Tina</th><th>Lote</th><th>Producto</th><th>Neto aceptado</th><th>Etiqueta</th><th>Último trabajo</th><th>Acción</th></tr></thead>
        <tbody>
          <?php foreach ($weighings as $weighing): $hasInitial = (int) $weighing['initial_print_count'] > 0; ?>
            <tr>
              <td><strong><?= esc($weighing['folio']) ?></strong><small class="d-block text-secondary"><?= esc($weighing['confirmed_at']) ?></small></td>
              <td class="fw-bold fs-5"><?= esc($weighing['tub_code']) ?></td>
              <td><?= esc($weighing['lot_code']) ?></td>
              <td><?= esc($weighing['species_name'] . ' · ' . $weighing['size_name']) ?></td>
              <td><?= number_format((float) $weighing['accepted_net_kg'], 3) ?> kg</td>
              <td><?= $weighing['label_id'] ? '<strong>' . esc($weighing['label_code']) . '</strong>' : '<span class="text-secondary">Pendiente</span>' ?></td>
              <td>
                <?php if ($weighing['last_job_id']): ?>
                  <span class="badge-state <?= esc((string) $weighing['last_print_result']) ?>">#<?= (int) $weighing['last_print_number'] ?> · <?= esc(str_replace('_', ' ', (string) $weighing['last_print_result'])) ?></span>
                  <?php if ($weighing['last_output_path']): ?><a class="d-block small mt-1" href="/operacion/etiquetas/trabajos/<?= (int) $weighing['last_job_id'] ?>/archivo"><i class="bi bi-file-earmark-pdf"></i> Descargar PDF</a><?php endif ?>
                <?php else: ?><span class="text-secondary">Sin impresión</span><?php endif ?>
              </td>
              <td class="text-nowrap">
                <?php if ($canPrint && ! $hasInitial): ?>
                  <button class="btn btn-primary btn-operational no-offline-confirm" type="button" data-print-weighing="<?= (int) $weighing['id'] ?>" data-existing-template="<?= (int) ($weighing['label_template_id'] ?? 0) ?>"><i class="bi bi-printer"></i> <?= $weighing['label_id'] ? 'Reintentar' : 'Imprimir' ?></button>
                <?php elseif ($canReprint && $weighing['label_id'] && $weighing['label_status'] !== 'canceled'): ?>
                  <button class="btn btn-outline-primary btn-operational no-offline-confirm" type="button" data-reprint-label="<?= (int) $weighing['label_id'] ?>" data-label-code="<?= esc($weighing['label_code'], 'attr') ?>"><i class="bi bi-printer-fill"></i> Reimprimir</button>
                <?php else: ?>—<?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="/assets/js/labels.js"></script><?= $this->endSection() ?>
