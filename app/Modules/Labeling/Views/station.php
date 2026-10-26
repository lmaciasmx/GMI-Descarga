<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<div class="d-flex gap-2 align-items-center label-page-actions">
  <div class="status-pill label-printer-status <?= ($printerStatus['connected'] ?? null) === true ? 'online' : (($printerStatus['connected'] ?? null) === false ? 'offline' : 'warning') ?>">
    <span class="status-dot"></span>
    <span><?= esc($printerStatusLabel) ?></span>
  </div>
  <button class="btn btn-outline-primary label-station-config-btn" type="button" data-label-station-config><i class="bi bi-gear"></i> Configuración de estación</button>
</div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div hidden id="labelStationData"><?= json_encode([
  'templates' => $templates,
  'printers' => $printers,
  'canPrint' => $canPrint,
  'canReprint' => $canReprint,
  'printerStatus' => $printerStatus,
  'defaultTemplateId' => $defaultTemplateId,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></div>

<div class="label-ops-shell">
  <div class="label-kpis mb-3">
    <div class="label-kpi"><span>Pesadas hoy</span><strong><?= number_format($stats['total_today']) ?></strong></div>
    <div class="label-kpi"><span>Etiquetadas</span><strong><?= number_format($stats['labeled_today']) ?></strong></div>
    <div class="label-kpi"><span>Reimpresiones</span><strong><?= number_format($stats['reprints_today']) ?></strong></div>
    <div class="label-kpi"><span>Pendientes</span><strong><?= number_format($stats['pending_today']) ?></strong></div>
  </div>

  <div class="card label-toolbar-card mb-3">
    <div class="card-body py-3">
      <div class="row g-2 align-items-end">
        <div class="col-xl-5 col-lg-5">
          <label class="form-label small mb-1" for="labelQuickSearch">Buscar marbete, tina, lote o pesaje</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input id="labelQuickSearch" class="form-control" type="search" placeholder="Ej. TINA-0048, lote, folio…" autocomplete="off">
          </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4">
          <label class="form-label small mb-1" for="labelWorkDate">Día de operación</label>
          <input id="labelWorkDate" class="form-control" type="date" value="<?= esc($workDate, 'attr') ?>">
        </div>
        <div class="col-xl-3 col-lg-4 col-md-5">
          <label class="form-label small mb-1" for="labelStatusFilter">Mostrar</label>
          <select id="labelStatusFilter" class="form-select">
            <option value="">Todas</option>
            <option value="IMPRESA">Impresas</option>
            <option value="PENDIENTE">Pendientes</option>
            <option value="ERROR">Con error</option>
            <option value="REIMPRESION">Con reimpresión</option>
          </select>
        </div>
        <div class="col-xl-2 col-md-3 text-md-end">
          <div class="label-station-mini" data-station-summary>Estación sin configurar</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card label-results-card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <div>
        <strong>Etiquetas del <?= esc(date('d/m/Y', strtotime($workDate))) ?></strong>
        <small class="d-block text-secondary">Diseñada para operación intensiva. Reimpresión auditable y PDF 99×50 mm.</small>
      </div>
      <span class="badge text-bg-light border"><?= number_format(count($weighings)) ?> registros cargados</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive label-table-wrap">
        <table id="labelOperationsTable" class="table table-hover align-middle mb-0 label-operations-table">
          <thead>
            <tr>
              <th>Hora</th>
              <th>Tina / Pesaje</th>
              <th>Lote / Producto</th>
              <th class="text-end">Neto</th>
              <th>Etiqueta</th>
              <th>Estado</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($weighings as $weighing): ?>
              <?php
                $hasInitial = (int) $weighing['initial_print_count'] > 0;
                $hasReprints = (int) ($weighing['reprint_count'] ?? 0) > 0;
                $statusToken = $weighing['last_print_result'] === 'failed' ? 'ERROR' : ($hasReprints ? 'REIMPRESION' : ($hasInitial ? 'IMPRESA' : 'PENDIENTE'));
              ?>
              <tr data-label-status="<?= esc($statusToken, 'attr') ?>">
                <td data-order="<?= esc($weighing['confirmed_at'], 'attr') ?>"><strong><?= esc(date('H:i', strtotime($weighing['confirmed_at']))) ?></strong><small class="d-block text-secondary"><?= esc(date('d/m', strtotime($weighing['confirmed_at']))) ?></small></td>
                <td><strong class="label-tub-code"><?= esc($weighing['tub_code']) ?></strong><small class="d-block text-secondary"><?= esc($weighing['folio']) ?></small></td>
                <td><strong><?= esc($weighing['lot_code']) ?></strong><small class="d-block text-secondary"><?= esc($weighing['species_name'] . ' · ' . $weighing['size_name']) ?></small></td>
                <td class="text-end fw-semibold"><?= number_format((float) $weighing['accepted_net_kg'], 3) ?> kg</td>
                <td>
                  <?php if ($weighing['label_id']): ?>
                    <strong><?= esc($weighing['label_code']) ?></strong>
                    <?php if ($hasReprints): ?><small class="d-block text-secondary"><?= (int) $weighing['reprint_count'] ?> reimp.</small><?php endif ?>
                  <?php else: ?><span class="text-secondary">Sin generar</span><?php endif ?>
                </td>
                <td data-search="<?= esc($statusToken, 'attr') ?>">
                  <?php if ($statusToken === 'ERROR'): ?><span class="badge text-bg-danger">Error</span>
                  <?php elseif ($statusToken === 'REIMPRESION'): ?><span class="badge text-bg-warning">Reimpresa</span>
                  <?php elseif ($statusToken === 'IMPRESA'): ?><span class="badge text-bg-success">Impresa</span>
                  <?php else: ?><span class="badge text-bg-secondary">Pendiente</span><?php endif ?>
                </td>
                <td class="text-end text-nowrap label-actions">
                  <?php if ($weighing['label_id']): ?>
                    <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="/operacion/etiquetas/<?= (int) $weighing['label_id'] ?>/pdf" title="Ver PDF 99×50 mm"><i class="bi bi-file-earmark-pdf"></i><span> PDF</span></a>
                  <?php endif ?>
                  <?php if ($canPrint && ! $hasInitial): ?>
                    <button class="btn btn-sm btn-primary no-offline-confirm" type="button" data-print-weighing="<?= (int) $weighing['id'] ?>" data-existing-template="<?= (int) ($weighing['label_template_id'] ?? 0) ?>"><i class="bi bi-printer"></i><span> Imprimir</span></button>
                  <?php elseif ($canReprint && $weighing['label_id'] && $weighing['label_status'] !== 'canceled'): ?>
                    <button class="btn btn-sm btn-primary no-offline-confirm" type="button" data-reprint-label="<?= (int) $weighing['label_id'] ?>" data-label-code="<?= esc($weighing['label_code'], 'attr') ?>" data-tub-code="<?= esc($weighing['tub_code'], 'attr') ?>"><i class="bi bi-printer-fill"></i><span> Reimprimir</span></button>
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

<div class="modal fade" id="labelStationConfigModal" tabindex="-1" aria-labelledby="labelStationConfigTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div><h5 class="modal-title" id="labelStationConfigTitle">Configuración de esta estación</h5><small class="text-secondary">Se guarda únicamente en este navegador/equipo.</small></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="labelTemplate">Plantilla de etiqueta</label>
            <select id="labelTemplate" class="form-select">
              <option value="">Selecciona…</option>
              <?php foreach ($templates as $template): ?><option value="<?= (int) $template['id'] ?>"><?= esc($template['code'] . ' · v' . $template['version'] . ' · ' . strtoupper($template['format'])) ?></option><?php endforeach ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="labelPrinter">Impresora / adaptador</label>
            <select id="labelPrinter" class="form-select">
              <option value="">Predeterminado de la aplicación</option>
              <?php foreach ($printers as $printer): ?><option value="<?= (int) $printer['id'] ?>"><?= esc($printer['code'] . ' · ' . $printer['name']) ?></option><?php endforeach ?>
            </select>
          </div>
          <div class="col-12">
            <div class="form-check form-switch label-direct-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="labelDirectPrint" checked>
              <label class="form-check-label" for="labelDirectPrint"><strong>Imprimir directamente después de generar/reimprimir</strong><span class="d-block text-secondary small">Abre automáticamente el diálogo de impresión del navegador con la etiqueta 4×6. No necesitas abrir el PDF.</span></label>
            </div>
          </div>
          <div class="col-12">
            <div class="alert alert-light border mb-0"><i class="bi bi-info-circle me-1"></i> Para impresión silenciosa sin diálogo se utilizará el middleware local. Primero podemos validar formato y tamaño desde navegador.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" data-save-label-station><i class="bi bi-check2"></i> Guardar en esta estación</button>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('styles') ?><link rel="stylesheet" href="/assets/css/labels-ops.css"><?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="/assets/js/labels.js"></script><?= $this->endSection() ?>
