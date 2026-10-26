<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/reports-center.css?v=360') ?>">

<?php
$enabled = array_fill_keys($enabledReportKeys ?? [], true);
$exportable = array_fill_keys($exportableReportKeys ?? [], true);
$from = $filters['from'] ?? '';
$to = $filters['to'] ?? '';

$reportUrl = static function (string $key) use ($from, $to): string {
    $query = ['report' => $key];
    if ($from) {
        $query['from'] = $from;
    }
    if ($to) {
        $query['to'] = $to;
    }
    return site_url('reportes') . '?' . http_build_query($query);
};

$exportUrl = static function (string $key, string $format) use ($from, $to): string {
    $query = [
        'report' => $key,
        'format' => $format,
    ];
    if ($from) {
        $query['from'] = $from;
    }
    if ($to) {
        $query['to'] = $to;
    }

    return site_url('reportes') . '?' . http_build_query($query);
};

$humanizeTechnical = static function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '—';
    }

    $normalized = strtolower($value);

    $translations = [
        // Movimientos de inventario
        'reservation' => 'Reserva',
        'reservation_release' => 'Liberación de reserva',
        'transfer_shipment' => 'Salida por traspaso',
        'transfer_receipt' => 'Recepción de traspaso',
        'sale_dispatch' => 'Salida por venta',
        'receipt' => 'Recepción',
        'adjustment' => 'Ajuste',
        'relocation' => 'Reubicación',
        'warehouse_entry' => 'Entrada a almacén',
        'warehouse_exit' => 'Salida de almacén',
        'production_issue' => 'Salida a producción',

        // Tipos de solicitud / operación
        'transfer' => 'Traspaso',
        'sale' => 'Venta',
        'raw_material' => 'Materia prima',
        'raw material' => 'Materia prima',
        'production' => 'Producción',
        'return' => 'Devolución',
        'warehouse_request' => 'Solicitud de almacén',
        'warehouse request' => 'Solicitud de almacén',
        'warehouse-transfer' => 'Traspaso entre almacenes',
        'warehouse_transfer' => 'Traspaso entre almacenes',
        'warehouse transfer' => 'Traspaso entre almacenes',
        'manual' => 'Manual',
        'automatic' => 'Automático',

        // Estados comunes
        'draft' => 'Borrador',
        'pending' => 'Pendiente',
        'programmed' => 'Programado',
        'assigned' => 'Asignado',
        'accepted' => 'Aceptado',
        'rejected' => 'Rechazado',
        'supplying' => 'En surtido',
        'supplied' => 'Surtido',
        'supply_completed' => 'Surtido finalizado',
        'receiving' => 'En recepción',
        'received' => 'Recibido',
        'confirmed' => 'Confirmada',
        'confirm' => 'Confirmada',
        'validated' => 'Validado',
        'approved' => 'Aprobado',
        'created' => 'Creado',
        'open' => 'Abierto',
        'processing' => 'En proceso',
        'ready' => 'Listo',
        'closed' => 'Cerrado',
        'cancelled' => 'Cancelado',
        'canceled' => 'Cancelado',
        'available' => 'Disponible',
        'reserved' => 'Reservado',
        'in_transit' => 'En tránsito',
        'in transit' => 'En tránsito',
        'in-progress' => 'En proceso',
        'in_progress' => 'En proceso',
        'in progress' => 'En proceso',
        'finished' => 'Finalizado',
        'finish' => 'Finalizado',
        'done' => 'Finalizado',
        'on_hold' => 'En espera',
        'on hold' => 'En espera',
        'paused' => 'En pausa',
        'scheduled' => 'Programado',
        'shipping' => 'En salida',
        'shipped' => 'Enviado',
        'dispatching' => 'En despacho',
        'dispatched' => 'Despachado',
        'partially_received' => 'Recibido parcialmente',
        'partially received' => 'Recibido parcialmente',
        'partially_supplied' => 'Surtido parcialmente',
        'partially supplied' => 'Surtido parcialmente',
        'exhausted' => 'Agotado',
        'completed' => 'Completado',
        'complete' => 'Completado',
        'annulled' => 'Anulado',
        'active' => 'Activo',
        'inactive' => 'Inactivo',

        // Prioridades
        'urgent' => 'Urgente',
        'high' => 'Alta',
        'normal' => 'Normal',
        'low' => 'Baja',
    ];

    if (isset($translations[$normalized])) {
        return $translations[$normalized];
    }

    // Respaldo seguro para cualquier nuevo código técnico.
    // Normaliza snake_case, kebab-case y texto con espacios.
    $readable = str_replace(['_', '-'], ' ', $normalized);
    $readable = preg_replace('/\s+/', ' ', trim($readable)) ?: $readable;

    return ucfirst($readable);
};

$compactLocation = static function (mixed $value): string {
    if ($value === null || trim((string) $value) === '') {
        return '—';
    }

    $text = trim((string) $value);
    $parts = array_values(array_filter(
        array_map('trim', explode('/', trim($text, '/'))),
        static fn (string $part): bool => $part !== ''
    ));

    if ($parts === []) {
        return $text;
    }

    $camera = null;
    $row = null;

    foreach ($parts as $part) {
        $upper = strtoupper($part);

        if ($camera === null && preg_match('/^(CAM|CAMARA|CÁMARA)[-_ ]?\d+/u', $upper)) {
            $camera = $part;
            continue;
        }

        if ($row === null && preg_match('/^(F|FILA)[-_ ]?\d+/u', $upper)) {
            $row = $part;
        }
    }

    if ($camera !== null || $row !== null) {
        return implode(' / ', array_values(array_filter([$camera, $row])));
    }

    // Para rutas estándar /CAM-01/F01/P14/E02, conservar sólo los dos primeros niveles.
    if (count($parts) >= 2) {
        return $parts[0] . ' / ' . $parts[1];
    }

    return $parts[0];
};

$formatDate = static function (mixed $value): string {
    if ($value === null || trim((string) $value) === '') {
        return '—';
    }

    $text = trim((string) $value);

    try {
        $date = new \DateTimeImmutable($text);
    } catch (\Throwable) {
        return $text;
    }

    $hasTime = preg_match('/\d{1,2}:\d{2}/', $text) === 1;

    return $date->format($hasTime ? 'd/m/Y H:i' : 'd/m/Y');
};

$formatCell = static function (string $key, mixed $value) use ($humanizeTechnical, $compactLocation, $formatDate): string {
    if ($value === null || $value === '') {
        return '—';
    }

    if (
        str_ends_with($key, '_at')
        || str_contains($key, 'date')
        || in_array($key, ['occurred_at', 'received_at', 'requested_at', 'shipped_at', 'dispatched_at', 'required_at'], true)
    ) {
        return $formatDate($value);
    }

    if (
        str_contains($key, 'location')
        || in_array($key, ['from_location', 'to_location', 'location_path'], true)
    ) {
        return $compactLocation($value);
    }

    if (
        in_array($key, [
            'movement_type',
            'request_type',
            'transfer_type',
            'status',
            'priority',
            'destination',
        ], true)
    ) {
        return $humanizeTechnical((string) $value);
    }

    if (is_numeric($value)) {
        if (
            str_contains($key, 'weight')
            || str_contains($key, '_kg')
            || str_contains($key, 'delta')
        ) {
            return number_format((float) $value, 3);
        }

        if (in_array($key, ['units', 'line_count', 'plan_lines', 'year', 'annual_number'], true)) {
            return number_format((int) $value);
        }
    }

    return (string) $value;
};

$columnClass = static function (string $key): string {
    $classes = ['rc-col'];

    if (
        str_ends_with($key, '_at')
        || str_contains($key, 'date')
        || in_array($key, ['occurred_at', 'received_at', 'requested_at', 'shipped_at', 'dispatched_at', 'required_at'], true)
    ) {
        $classes[] = 'rc-col-date';
    } elseif (
        str_contains($key, 'location')
        || in_array($key, ['from_location', 'to_location', 'location_path'], true)
    ) {
        $classes[] = 'rc-col-location';
    } elseif (
        str_contains($key, 'weight')
        || str_contains($key, '_kg')
        || str_contains($key, 'delta')
        || in_array($key, ['units', 'line_count', 'plan_lines'], true)
    ) {
        $classes[] = 'rc-col-number';
        $classes[] = 'text-end';
    } elseif (
        str_contains($key, 'folio')
        || str_contains($key, 'code')
        || in_array($key, ['unit_code', 'tub_code', 'lot_code', 'annual_number'], true)
    ) {
        $classes[] = 'rc-col-code';
    } elseif (
        in_array($key, ['movement_type', 'request_type', 'transfer_type', 'status', 'priority'], true)
    ) {
        $classes[] = 'rc-col-enum';
    } elseif (
        str_contains($key, 'warehouse')
        || in_array($key, ['customer', 'vessel_name', 'destination'], true)
    ) {
        $classes[] = 'rc-col-name';
    }

    return implode(' ', $classes);
};

$historyStatusLabel = static function (string $status): string {
    return match (strtolower($status)) {
        'completed' => 'Completado',
        'error' => 'Error',
        'processing' => 'Procesando',
        default => ucfirst(str_replace('_', ' ', strtolower($status))),
    };
};

$historyPeriod = static function (?string $from, ?string $to): string {
    if (! $from && ! $to) {
        return 'Sin filtro';
    }

    $format = static function (?string $value): string {
        if (! $value) {
            return '—';
        }

        try {
            return (new \DateTimeImmutable($value))->format('d/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    };

    return $format($from) . ' – ' . $format($to);
};

$groups = [
    'Operación' => [
        'discharge.summary' => 'Descargas',
        'warehouse.entries' => 'Entradas a frigorífico',
    ],
    'Inventario' => [
        'inventory.current' => 'Existencias',
        'inventory.movements' => 'Movimientos / Kardex',
    ],
    'Solicitudes y movimientos' => [
        'requests.warehouse' => 'Solicitudes',
        'transfers.summary' => 'Traspasos',
        'production.raw-material' => 'Materia prima',
        'sales.dispatches' => 'Ventas',
    ],
    'Trazabilidad' => [
        'traceability.unit' => 'Por tina',
    ],
    'Ejecutivos' => [
        'general.summary' => 'Resumen general',
    ],
];

$groupIcons = [
    'Operación' => 'bi-gear',
    'Inventario' => 'bi-boxes',
    'Solicitudes y movimientos' => 'bi-arrow-left-right',
    'Trazabilidad' => 'bi-diagram-3',
    'Ejecutivos' => 'bi-bar-chart',
];

$featured = [
    'inventory.current',
    'general.summary',
    'inventory.movements',
    'requests.warehouse',
    'traceability.unit',
];
?>

<div class="reports-center" data-reports-center>
  <section class="rc-context-bar">
    <div class="rc-context-copy">
      <span class="rc-kicker">GRUPOMAR · CENTRO DE REPORTES</span>
      <span class="rc-context-separator"></span>
      <span>Consulta y analiza la operación sin alterar los procesos operativos.</span>
    </div>
    <button class="btn btn-light rc-history-btn" type="button" disabled title="Disponible en una fase posterior">
      <i class="bi bi-clock-history"></i> Historial
    </button>
  </section>

  <form class="rc-filter-shell" method="get" action="<?= site_url('reportes') ?>">
    <input type="hidden" name="report" value="<?= esc($activeReportKey) ?>">
    <div class="rc-filter-grid">
      <label class="rc-field">
        <span>Desde</span>
        <input type="date" name="from" value="<?= esc($from) ?>" class="form-control">
      </label>
      <label class="rc-field">
        <span>Hasta</span>
        <input type="date" name="to" value="<?= esc($to) ?>" class="form-control">
      </label>
      <div class="rc-field">
        <span>Compañía</span>
        <div class="rc-readonly-filter"><?= esc($scope['company_name'] ?? 'Actual') ?></div>
      </div>
      <div class="rc-field">
        <span>Sitio</span>
        <div class="rc-readonly-filter"><?= esc($scope['site_name'] ?? 'Actual') ?></div>
      </div>
      <div class="rc-field">
        <span>Almacén</span>
        <div class="rc-readonly-filter"><?= esc($scope['warehouse_name'] ?? 'Actual') ?></div>
      </div>
      <button type="button" class="btn btn-outline-secondary rc-more-filter" disabled>
        <i class="bi bi-funnel"></i> Más filtros
      </button>
      <button type="submit" class="btn btn-primary rc-apply-filter">
        <i class="bi bi-search"></i> Aplicar
      </button>
    </div>
  </form>

  <section class="rc-kpis">
    <a class="rc-kpi rc-kpi-link" href="<?= esc($reportUrl('inventory.current')) ?>">
      <div class="rc-kpi-icon"><i class="bi bi-box-seam"></i></div>
      <div><span>Existencias actuales</span><strong><?= number_format((float)($overview['stock_kg'] ?? 0), 3) ?> kg</strong><small><?= number_format((int)($overview['stock_units'] ?? 0)) ?> tinas</small></div>
    </a>
    <a class="rc-kpi rc-kpi-link" href="<?= esc($reportUrl('inventory.movements')) ?>">
      <div class="rc-kpi-icon"><i class="bi bi-arrow-left-right"></i></div>
      <div><span>Movimientos</span><strong><?= number_format((int)($overview['movements'] ?? 0)) ?></strong><small><?= ($from || $to) ? 'Dentro del periodo' : 'Todos los registrados' ?></small></div>
    </a>
    <a class="rc-kpi rc-kpi-link" href="<?= esc($reportUrl('requests.warehouse')) ?>">
      <div class="rc-kpi-icon"><i class="bi bi-file-earmark-text"></i></div>
      <div><span>Solicitudes</span><strong><?= number_format((int)($overview['requests'] ?? 0)) ?></strong><small><?= ($from || $to) ? 'Dentro del periodo' : 'Todas las registradas' ?></small></div>
    </a>
    <a class="rc-kpi rc-kpi-link" href="<?= esc($reportUrl('traceability.unit')) ?>">
      <div class="rc-kpi-icon"><i class="bi bi-upc-scan"></i></div>
      <div><span>Trazabilidad</span><strong><?= number_format((int)($overview['traceability'] ?? 0)) ?></strong><small>Tinas/unidades visibles</small></div>
    </a>
    <a class="rc-kpi rc-kpi-link" href="<?= esc($reportUrl('general.summary')) ?>">
      <div class="rc-kpi-icon"><i class="bi bi-pie-chart"></i></div>
      <div><span>Resumen general</span><strong><?= number_format((int)($overview['species_count'] ?? 0)) ?></strong><small>Especies con existencia</small></div>
    </a>
  </section>

  <section class="rc-search-row">
    <div class="rc-search">
      <i class="bi bi-search"></i>
      <input type="search" id="reportCatalogSearch" placeholder="Buscar reporte... (ej. existencias, movimientos, solicitudes)">
    </div>
  </section>

  <section class="rc-section">
    <div class="rc-section-heading">
      <div><span class="rc-eyebrow">Acceso rápido</span><h2>Más utilizados</h2></div>
    </div>
    <div class="rc-feature-grid rc-feature-grid-five">
      <?php foreach ($featured as $key):
          $item = $catalog[$key];
          $available = isset($enabled[$key]);
          $active = $key === $activeReportKey;
      ?>
        <article class="rc-feature-card <?= $active ? 'is-active' : '' ?>" data-report-card data-search="<?= esc(strtolower($item['title'].' '.$item['description'])) ?>">
          <div class="rc-feature-top">
            <div class="rc-feature-icon"><i class="bi <?= esc($item['icon']) ?>"></i></div>
            <?= $active ? '<span class="rc-current">En pantalla</span>' : '' ?>
          </div>
          <h3><?= esc($item['title']) ?></h3>
          <p><?= esc($item['description']) ?></p>
          <div class="rc-actions">
            <a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= esc($reportUrl($key)) ?>"><i class="bi bi-eye"></i> Vista</a>
            <?php if (isset($exportable[$key])): ?>
              <a class="btn btn-sm btn-outline-secondary" href="<?= esc($exportUrl($key, 'pdf')) ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
              <a class="btn btn-sm btn-outline-secondary" href="<?= esc($exportUrl($key, 'xlsx')) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
            <?php else: ?>
              <button type="button" class="btn btn-sm btn-outline-secondary" disabled>PDF</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Excel</button>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="rc-main-grid">
    <div class="rc-catalog rc-main-column">
      <div class="rc-section-heading"><div><span class="rc-eyebrow">Biblioteca</span><h2>Catálogo de reportes</h2></div></div>

      <?php foreach ($groups as $groupTitle => $items): ?>
        <article class="rc-group-card" data-report-card data-search="<?= esc(strtolower($groupTitle.' '.implode(' ', $items))) ?>">
          <header><i class="bi <?= esc($groupIcons[$groupTitle]) ?>"></i><h3><?= esc($groupTitle) ?></h3></header>
          <div class="rc-group-items">
            <?php foreach ($items as $key => $label):
                $available = isset($enabled[$key]);
                $active = $key === $activeReportKey;
            ?>
              <?php if ($available): ?>
                <a class="rc-report-row rc-report-link <?= $active ? 'is-active' : '' ?>" href="<?= esc($reportUrl($key)) ?>">
                  <span><?= esc($label) ?></span>
                  <?= $active ? '<span class="rc-current">Activo</span>' : '<span class="rc-available">Disponible</span>' ?>
                  <i class="bi bi-chevron-right"></i>
                </a>
              <?php else: ?>
                <button type="button" class="rc-report-row" disabled>
                  <span><?= esc($label) ?></span><span class="rc-soon-label">Próxima fase</span><i class="bi bi-chevron-right"></i>
                </button>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="rc-preview-stack rc-main-column">
      <article class="rc-preview-card rc-live-report">
        <header class="rc-preview-header">
          <div>
            <span class="rc-eyebrow">Reporte activo</span>
            <h2><?= esc($activeReport['title']) ?></h2>
            <p><?= esc($activeReport['description']) ?></p>
          </div>
          <span class="rc-live"><i class="bi bi-circle-fill"></i> Datos actuales</span>
        </header>

        <?php if (! empty($report['kpis'])): ?>
          <div class="rc-report-kpis">
            <?php foreach ($report['kpis'] as $kpi): ?>
              <div class="rc-report-kpi"><span><?= esc($kpi['label']) ?></span><strong><?= esc($kpi['value']) ?></strong></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (! empty($report['rows'])): ?>
          <div class="table-responsive rc-dynamic-table-wrap">
            <table class="table align-middle rc-table" id="activeReportTable">
              <thead>
                <tr>
                  <?php foreach (($report['columns'] ?? []) as $key => $label): ?>
                    <th class="<?= esc($columnClass((string)$key)) ?>"><?= esc($label) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($report['rows'] as $row): ?>
                  <tr>
                    <?php foreach (($report['columns'] ?? []) as $key => $label): ?>
                      <td class="<?= esc($columnClass((string)$key)) ?>"><?= esc($formatCell((string)$key, $row[$key] ?? null)) ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="rc-empty-state">
            <i class="bi bi-inbox"></i>
            <strong>Sin datos para este reporte</strong>
            <span>No hay registros dentro del alcance y filtros seleccionados.</span>
          </div>
        <?php endif; ?>

        <?php if (($report['chart'] ?? null) === 'species' && ! empty($report['rows'])): ?>
          <div class="rc-inline-chart">
            <div class="rc-inline-chart-copy">
              <span class="rc-eyebrow">Distribución</span>
              <strong>Existencia por especie</strong>
            </div>
            <div class="rc-inline-chart-canvas"><canvas id="activeReportChart"></canvas></div>
          </div>
        <?php endif; ?>
      </article>

      <article class="rc-preview-card rc-export-note">
        <div>
          <span class="rc-eyebrow">Exportaciones</span>
          <h3>PDF y Excel</h3>
          <?php if (isset($exportable[$activeReportKey])): ?>
            <p>PDF ejecutivo y Excel detallado usando el mismo alcance, filtros y traducciones de la vista.</p>
          <?php else: ?>
            <p>La exportación de este reporte se habilitará en la siguiente ampliación.</p>
          <?php endif; ?>
        </div>
        <div class="rc-actions">
          <?php if (isset($exportable[$activeReportKey])): ?>
            <a class="btn btn-sm btn-outline-danger" href="<?= esc($exportUrl($activeReportKey, 'pdf')) ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
            <a class="btn btn-sm btn-outline-success" href="<?= esc($exportUrl($activeReportKey, 'xlsx')) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
          <?php else: ?>
            <button class="btn btn-sm btn-outline-secondary" disabled>PDF</button>
            <button class="btn btn-sm btn-outline-secondary" disabled>Excel</button>
          <?php endif; ?>
        </div>
      </article>
    </div>
  </section>

  <section class="rc-history-section">
    <article class="rc-preview-card">
      <header class="rc-preview-header rc-history-header">
        <div>
          <span class="rc-eyebrow">Auditoría</span>
          <h2>Historial de exportaciones</h2>
          <p>Últimas exportaciones generadas dentro del alcance operativo actual.</p>
        </div>
        <span class="rc-history-count"><?= number_format(count($exportHistory ?? [])) ?> recientes</span>
      </header>

      <?php if (! empty($exportHistory)): ?>
        <div class="table-responsive">
          <table class="table align-middle rc-table" id="reportExportHistoryTable">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Reporte</th>
                <th>Periodo</th>
                <th>Formato</th>
                <th>Usuario</th>
                <th class="text-end">Registros</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($exportHistory as $item): ?>
                <?php
                  $status = strtolower((string) ($item['status'] ?? ''));
                  $statusClass = match ($status) {
                      'completed' => 'is-completed',
                      'error' => 'is-error',
                      default => 'is-processing',
                  };
                ?>
                <tr>
                  <td class="rc-col-date"><?= esc($formatDate($item['generated_at'] ?? null)) ?></td>
                  <td class="rc-col-name"><?= esc($item['report_title'] ?? $item['report_key'] ?? 'Reporte') ?></td>
                  <td><?= esc($historyPeriod($item['date_from'] ?? null, $item['date_to'] ?? null)) ?></td>
                  <td><span class="rc-format-badge"><?= esc(strtoupper((string) ($item['format'] ?? ''))) ?></span></td>
                  <td><?= esc($item['user_name'] ?? 'Usuario') ?></td>
                  <td class="text-end rc-col-number"><?= number_format((int) ($item['row_count'] ?? 0)) ?></td>
                  <td><span class="rc-history-status <?= esc($statusClass) ?>"><?= esc($historyStatusLabel($status)) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="rc-empty-state rc-history-empty">
          <i class="bi bi-clock-history"></i>
          <strong>Aún no hay exportaciones registradas</strong>
          <span>Cuando se genere un PDF o Excel desde este Centro de Reportes aparecerá aquí.</span>
        </div>
      <?php endif; ?>
    </article>
  </section>
</div>

<script>
window.GRUPOMAR_REPORTS = <?= json_encode([
    'activeKey' => $activeReportKey,
    'columns' => $report['columns'] ?? [],
    'rows' => $report['rows'] ?? [],
    'chart' => $report['chart'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= base_url('assets/js/reports.js?v=360') ?>"></script>
<?= $this->endSection() ?>
