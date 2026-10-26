<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="/assets/css/quality-ui.css">
<?= $this->endSection() ?>

<?= $this->section('pageActions') ?>
<span class="quality-rules-pill"><span class="status-dot"></span><span>Reglas automáticas pendientes de validar</span></span>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$resultLabels = [
    'accepted' => 'Sin incidencia',
    'observed' => 'Observación',
    'hold' => 'Retener tina',
    'hold_lot' => 'Retener lote',
    'released' => 'Liberada',
    'released_lot' => 'Lote liberado',
    'rejected' => 'Rechazada',
    'pending' => 'Pendiente',
    'annulled' => 'Anulada',
];
$resultClasses = [
    'accepted' => 'quality-ok',
    'observed' => 'quality-warning',
    'hold' => 'quality-danger',
    'hold_lot' => 'quality-danger',
    'released' => 'quality-ok',
    'released_lot' => 'quality-ok',
    'rejected' => 'quality-danger',
    'pending' => 'quality-neutral',
    'annulled' => 'quality-neutral',
];
$stateLabels = [
    'clear' => 'Sin incidencias',
    'observed' => 'Observación',
    'held' => 'Retenido',
    'rejected' => 'Rechazado',
];
$stateClasses = [
    'clear' => 'quality-ok',
    'observed' => 'quality-warning',
    'held' => 'quality-danger',
    'rejected' => 'quality-danger',
];
$query = $_GET;
$pageLink = static function (string $key, int $page) use ($query): string {
    $copy = $query;
    $copy[$key] = $page;
    return '/operacion/calidad?' . http_build_query($copy);
};
$viewLink = static function (string $view) use ($query): string {
    $copy = $query;
    $copy['view'] = $view;
    unset($copy['lot_page'], $copy['unit_page']);
    return '/operacion/calidad?' . http_build_query($copy);
};
$reportQuery = $query;
unset($reportQuery['lot_page'], $reportQuery['unit_page'], $reportQuery['history_page'], $reportQuery['correct_from']);
$reportSuffix = $reportQuery === [] ? '' : '?' . http_build_query($reportQuery);
?>
<?php if ($correctionSource !== null): ?>
<div hidden id="qualityCorrectionContext"><?= json_encode($correctionSource, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></div>
<?php endif ?>

<div class="quality-metrics mb-3">
    <div class="quality-metric"><div class="quality-metric-icon"><i class="bi bi-box-seam"></i></div><div><span>Lotes activos</span><strong><?= number_format((int) $metrics['active_lots']) ?></strong></div></div>
    <div class="quality-metric"><div class="quality-metric-icon"><i class="bi bi-droplet-half"></i></div><div><span>Muestras hoy</span><strong><?= number_format((int) $metrics['samples_today']) ?></strong></div></div>
    <div class="quality-metric"><div class="quality-metric-icon"><i class="bi bi-chat-left-text"></i></div><div><span>Observaciones</span><strong><?= number_format((int) $metrics['observations']) ?></strong></div></div>
    <div class="quality-metric quality-metric-alert"><div class="quality-metric-icon"><i class="bi bi-lock"></i></div><div><span>Retenciones activas</span><strong><?= number_format((int) $metrics['retentions']) ?></strong></div></div>
    <div class="quality-metric quality-metric-alert"><div class="quality-metric-icon"><i class="bi bi-x-octagon"></i></div><div><span>Rechazos</span><strong><?= number_format((int) $metrics['rejections']) ?></strong></div></div>
</div>

<form class="card quality-filters mb-3" method="get" action="/operacion/calidad" data-quality-filter-form>
    <input type="hidden" name="view" value="<?= esc($filters['view']) ?>">
    <div class="quality-filter-search">
        <i class="bi bi-search"></i>
        <input type="search" name="q" value="<?= esc($filters['q']) ?>" placeholder="Buscar marbete, tina, lote, barco o marea..." aria-label="Buscar">
    </div>
    <button class="btn btn-outline-primary quality-scan-button" type="button" data-quality-scan><i class="bi bi-upc-scan"></i> Escanear</button>
    <div><label>Fecha</label><input class="form-control" type="date" name="date" value="<?= esc($filters['date']) ?>"></div>
    <div><label>Barco / Marea</label><select class="form-select" name="voyage_id"><option value="0">Todas</option><?php foreach ($filterOptions['voyages'] as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $filters['voyage_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['vessel_code'] . ' · ' . $item['annual_number'] . '/' . $item['year']) ?></option><?php endforeach ?></select></div>
    <div><label>Especie</label><select class="form-select" name="species_id"><option value="0">Todas</option><?php foreach ($filterOptions['species'] as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $filters['species_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['code'] . ' · ' . $item['name']) ?></option><?php endforeach ?></select></div>
    <div><label>Talla</label><select class="form-select" name="size_grade_id"><option value="0">Todas</option><?php foreach ($filterOptions['sizes'] as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $filters['size_grade_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['name']) ?></option><?php endforeach ?></select></div>
    <div><label>Lote</label><select class="form-select" name="lot_id"><option value="0">Todos</option><?php foreach ($filterOptions['lots'] as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $filters['lot_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['code']) ?></option><?php endforeach ?></select></div>
    <div><label>Resultado</label><select class="form-select" name="result"><option value="">Todos</option><option value="clear" <?= $filters['result'] === 'clear' ? 'selected' : '' ?>>Sin incidencia</option><option value="observed" <?= $filters['result'] === 'observed' ? 'selected' : '' ?>>Observación</option><option value="held" <?= $filters['result'] === 'held' ? 'selected' : '' ?>>Retenido</option><option value="rejected" <?= $filters['result'] === 'rejected' ? 'selected' : '' ?>>Rechazado</option></select></div>
    <label class="quality-filter-check"><input class="form-check-input" type="checkbox" name="incidents_only" value="1" <?= $filters['incidents_only'] ? 'checked' : '' ?>> Sólo incidencias</label>
    <div class="quality-filter-actions"><button class="btn btn-primary" type="submit">Aplicar</button><a class="btn btn-light" href="/operacion/calidad">Limpiar</a></div>
</form>

<?php if ($correctionSource !== null): ?>
<div class="alert alert-warning quality-correction-banner d-flex align-items-start justify-content-between gap-3" role="alert">
    <div><strong><i class="bi bi-pencil-square"></i> Corrigiendo INSP-<?= str_pad((string) $correctionSource['id'], 6, '0', STR_PAD_LEFT) ?></strong><div>La inspección anterior quedó anulada. Selecciona la tina correcta y registra la nueva inspección; ambas quedarán vinculadas para trazabilidad.</div></div>
    <a class="btn btn-sm btn-outline-secondary" href="/operacion/calidad">Cancelar corrección</a>
</div>
<?php endif ?>

<div class="quality-main-grid mb-3">
    <section class="card quality-list-card">
        <div class="card-header quality-card-heading">
            <div><strong><?= $filters['view'] === 'lot' ? 'Lotes activos' : 'Tinas / marbetes' ?></strong><small><?= $filters['view'] === 'lot' ? 'Control por lote, muestras e incidencias' : 'Detalle de unidades físicas' ?></small></div>
            <div class="quality-view-toggle"><a class="<?= $filters['view'] === 'lot' ? 'active' : '' ?>" href="<?= esc($viewLink('lot')) ?>">Por lote</a><a class="<?= $filters['view'] === 'tub' ? 'active' : '' ?>" href="<?= esc($viewLink('tub')) ?>">Por tina</a></div>
        </div>
        <div class="card-body p-0">
            <?php if ($filters['view'] === 'lot'): ?>
                <div class="table-responsive quality-table-scroll">
                    <table class="table quality-table mb-0">
                        <thead><tr><th>Lote</th><th>Producto</th><th class="text-end">Tinas</th><th class="text-end">Peso</th><th class="text-end">Muestras</th><th>Estado</th><th>Acción</th></tr></thead>
                        <tbody>
                        <?php foreach ($lots as $row): ?>
                            <tr>
                                <td><strong class="quality-code"><?= esc($row['lot_code']) ?></strong><small><?= esc($row['vessel_code'] . ' · ' . $row['annual_number'] . '/' . $row['year']) ?></small></td>
                                <td><?= esc($row['species_name']) ?><small><?= esc($row['sizes'] ?: 'Sin talla') ?></small></td>
                                <td class="text-end fw-semibold"><?= number_format((int) $row['tub_count']) ?></td>
                                <td class="text-end"><?= number_format((float) $row['weight_kg'], 3) ?> kg</td>
                                <td class="text-end"><?= number_format((int) $row['sample_count']) ?></td>
                                <td><span class="quality-status <?= esc($stateClasses[$row['quality_state']] ?? 'quality-neutral') ?>"><span></span><?= esc($stateLabels[$row['quality_state']] ?? 'Sin incidencias') ?></span></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="/operacion/calidad?<?= esc(http_build_query(array_merge($_GET, ['lot_id' => (int) $row['lot_id'], 'view' => 'tub']))) ?>"><i class="bi bi-eye"></i> Ver lote</a></td>
                            </tr>
                        <?php endforeach ?>
                        <?php if ($lots === []): ?><tr><td colspan="7" class="quality-empty">No hay lotes para los filtros seleccionados.</td></tr><?php endif ?>
                        </tbody>
                    </table>
                </div>
                <?php $pages = max(1, (int) ceil($lotTotal / $pageSize)); ?>
                <div class="quality-table-footer"><span>Mostrando <?= $lotTotal === 0 ? 0 : (($lotPage - 1) * $pageSize + 1) ?>–<?= min($lotPage * $pageSize, $lotTotal) ?> de <?= number_format($lotTotal) ?> lotes</span><div class="quality-pager"><?php if ($lotPage > 1): ?><a href="<?= esc($pageLink('lot_page', $lotPage - 1)) ?>"><i class="bi bi-chevron-left"></i></a><?php endif ?><span>Página <?= $lotPage ?> de <?= $pages ?></span><?php if ($lotPage < $pages): ?><a href="<?= esc($pageLink('lot_page', $lotPage + 1)) ?>"><i class="bi bi-chevron-right"></i></a><?php endif ?></div></div>
            <?php else: ?>
                <div class="table-responsive quality-table-scroll">
                    <table class="table quality-table mb-0">
                        <thead><tr><th>Marbete / Tina</th><th>Lote</th><th>Producto</th><th>Ubicación</th><th class="text-end">kg</th><th>Inventario</th><th>Calidad</th></tr></thead>
                        <tbody><?php foreach ($units as $row): ?><tr><td><strong class="quality-code"><?= esc($row['unit_code']) ?></strong><small>Tina <?= esc($row['tub_code']) ?></small></td><td><?= esc($row['lot_code']) ?></td><td><?= esc($row['species_name']) ?><small><?= esc($row['size_name']) ?></small></td><td><?= esc($row['chamber_name'] ?? $row['chamber_code'] ?? '—') ?><small><?= esc($row['location_path'] ?? '') ?></small></td><td class="text-end"><?= number_format((float) $row['on_hand_weight_kg'], 3) ?></td><td><span class="badge-state <?= esc($row['stock_status']) ?>"><?= esc($row['stock_status'] === 'held' ? 'Retenido' : ($row['stock_status'] === 'rejected' ? 'Rechazado' : 'Disponible')) ?></span></td><td><?= esc($resultLabels[$row['disposition'] ?? 'pending'] ?? 'Sin inspección') ?></td></tr><?php endforeach ?><?php if ($units === []): ?><tr><td colspan="7" class="quality-empty">No hay unidades para los filtros seleccionados.</td></tr><?php endif ?></tbody>
                    </table>
                </div>
                <?php $pages = max(1, (int) ceil($unitTotal / $pageSize)); ?>
                <div class="quality-table-footer"><span>Mostrando <?= $unitTotal === 0 ? 0 : (($unitPage - 1) * $pageSize + 1) ?>–<?= min($unitPage * $pageSize, $unitTotal) ?> de <?= number_format($unitTotal) ?> unidades</span><div class="quality-pager"><?php if ($unitPage > 1): ?><a href="<?= esc($pageLink('unit_page', $unitPage - 1)) ?>"><i class="bi bi-chevron-left"></i></a><?php endif ?><span>Página <?= $unitPage ?> de <?= $pages ?></span><?php if ($unitPage < $pages): ?><a href="<?= esc($pageLink('unit_page', $unitPage + 1)) ?>"><i class="bi bi-chevron-right"></i></a><?php endif ?></div></div>
            <?php endif ?>
        </div>
    </section>

    <section class="card quality-sample-card <?= $correctionSource !== null ? 'quality-sample-card-correction' : '' ?>" data-quality-sample-card>
        <div class="card-header quality-card-heading"><div><strong><?= $correctionSource !== null ? 'Corregir inspección INSP-' . str_pad((string) $correctionSource['id'], 6, '0', STR_PAD_LEFT) : 'Registrar muestra' ?></strong><small><?= $correctionSource !== null ? 'Estás creando una inspección sustituta; el registro anterior permanecerá anulado para trazabilidad.' : 'La inspección normal no bloquea inventario' ?></small></div><button class="btn btn-link btn-sm" type="button" data-quality-clear><?= $correctionSource !== null ? 'Restaurar datos originales' : 'Limpiar' ?></button></div>
        <div class="card-body">
            <form data-quality-inspection>
                <?php if ($correctionSource !== null): ?><input type="hidden" name="supersedes_inspection_id" value="<?= (int) $correctionSource['id'] ?>"><?php endif ?>
                <div class="mb-3"><label class="form-label" for="qualityWeighing">Marbete / Tina</label><select class="form-select" id="qualityWeighing" name="weighing_id" data-select2 required><option value="">Selecciona una unidad…</option><?php foreach ($candidates as $row): ?><option value="<?= (int) $row['weighing_id'] ?>" data-unit="<?= esc($row['unit_code']) ?>" data-tub="<?= esc($row['tub_code']) ?>" data-lot="<?= esc($row['lot_code']) ?>" data-species="<?= esc($row['species_name']) ?>" data-size="<?= esc($row['size_name']) ?>" data-chamber="<?= esc($row['chamber_name'] ?? $row['chamber_code'] ?? '') ?>" data-location="<?= esc($row['location_path'] ?? '') ?>"><?= esc($row['unit_code'] . ' · Tina ' . $row['tub_code'] . ' · ' . $row['lot_code']) ?></option><?php endforeach ?></select></div>

                <div class="quality-selected-unit" data-quality-unit-summary hidden>
                    <div><span>Lote</span><strong data-quality-lot>—</strong></div><div><span>Especie</span><strong data-quality-species>—</strong></div><div><span>Talla</span><strong data-quality-size>—</strong></div><div><span>Cámara / Fila</span><strong data-quality-location>—</strong></div>
                </div>

                <div class="row g-2 mt-1"><div class="col-md-4"><label class="form-label" for="qualityGrade">Grado manual</label><input class="form-control" id="qualityGrade" name="quality_grade" maxlength="20" placeholder="AAA / AA / A / B" required></div><div class="col-md-4"><label class="form-label" for="defectPercent">Defecto %</label><input class="form-control" id="defectPercent" name="defect_percent" type="number" min="0" max="100" step="0.001"></div><div class="col-md-4"><label class="form-label" for="temperature">Temperatura °C</label><input class="form-control" id="temperature" name="temperature_c" type="number" step="0.01"></div></div>

                <div class="mt-3"><span class="form-label d-block">Defectos observados</span><div class="quality-defect-grid quality-defect-grid-compact"><?php foreach ($defects as $defect): ?><label class="quality-defect"><input class="form-check-input" type="checkbox" name="defects" value="<?= (int) $defect['id'] ?>" data-requires-hold="<?= (int) $defect['requires_hold'] ?>"><span><?= esc($defect['name']) ?><?php if ((int) $defect['requires_hold'] === 1): ?><small>Exige retención</small><?php endif ?></span></label><?php endforeach ?></div></div>

                <div class="mt-3"><span class="form-label d-block">Resultado</span><div class="quality-result-grid"><label><input type="radio" name="disposition" value="accepted" checked><span class="quality-result-option quality-result-ok"><i class="bi bi-check-circle"></i> Sin incidencia</span></label><label><input type="radio" name="disposition" value="observed"><span class="quality-result-option quality-result-warning"><i class="bi bi-exclamation-circle"></i> Observación</span></label><label><input type="radio" name="disposition" value="hold"><span class="quality-result-option quality-result-danger"><i class="bi bi-lock"></i> Retener tina</span></label><label><input type="radio" name="disposition" value="hold_lot"><span class="quality-result-option quality-result-danger"><i class="bi bi-box-seam"></i> Retener lote</span></label><label><input type="radio" name="disposition" value="rejected"><span class="quality-result-option quality-result-danger"><i class="bi bi-x-octagon"></i> Rechazar</span></label></div></div>

                <div class="mt-3"><label class="form-label" for="qualityNotes">Observaciones</label><textarea class="form-control" id="qualityNotes" name="observations" rows="3" maxlength="1000" placeholder="Describe condiciones de la muestra, incidencias o motivo de retención..."></textarea><div class="quality-character-count"><span data-quality-note-count>0</span>/1000</div></div>
                <div class="quality-impact-note"><i class="bi bi-info-circle-fill"></i><span><strong>Sin incidencia</strong> y <strong>Observación</strong> no bloquean inventario. Retener o rechazar sí afecta disponibilidad.</span></div>
                <button class="btn <?= $correctionSource !== null ? 'btn-warning' : 'btn-primary' ?> btn-operational w-100 mt-3 no-offline-confirm" type="submit" data-quality-submit><i class="bi <?= $correctionSource !== null ? 'bi-pencil-square' : 'bi-shield-check' ?>"></i> <?= $correctionSource !== null ? 'Guardar corrección' : 'Registrar inspección' ?></button>
            </form>
        </div>
    </section>
</div>

<section class="card quality-history-card">
    <div class="card-header quality-card-heading"><div><strong>Historial de inspecciones y dictámenes</strong><small>Trazabilidad de muestras, observaciones, anulaciones y decisiones</small></div><div class="quality-history-tools"><div class="quality-history-search"><i class="bi bi-search"></i><input type="search" placeholder="Buscar en historial..." data-quality-history-search></div><a class="btn btn-sm btn-outline-success" href="/operacion/calidad/reporte.csv<?= esc($reportSuffix) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a><a class="btn btn-sm btn-outline-danger" href="/operacion/calidad/reporte.pdf<?= esc($reportSuffix) ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a></div></div>
    <div class="card-body p-0">
        <div class="table-responsive quality-history-scroll">
            <table class="table quality-table mb-0" data-quality-history-table>
                <thead><tr><th>Inspección</th><th>Marbete / Tina</th><th>Lote</th><th>Grado</th><th>Defecto</th><th>Temperatura</th><th>Resultado</th><th>Estado</th><th>Fecha y hora</th><th>Inspector</th><th>Acción</th></tr></thead>
                <tbody>
                <?php foreach ($inspections as $row): ?>
                    <?php
                    $isSensitiveApproved = $row['status'] === 'approved' && in_array($row['disposition'], ['hold', 'hold_lot', 'rejected'], true);
                    $canAnnulRow = $row['status'] !== 'annulled' && ($canApprove || ((int) $row['inspected_by'] === $currentUserId && ! $isSensitiveApproved));
                    ?>
                    <tr class="<?= $row['status'] === 'annulled' ? 'quality-row-annulled' : '' ?>">
                        <td><strong class="quality-code">INSP-<?= str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT) ?></strong><?php if (! empty($row['supersedes_inspection_id'])): ?><small>Corrige INSP-<?= str_pad((string) $row['supersedes_inspection_id'], 6, '0', STR_PAD_LEFT) ?></small><?php endif ?></td>
                        <td><?= esc($row['weighing_folio']) ?><small>Tina <?= esc($row['tub_code']) ?></small></td>
                        <td><?= esc($row['lot_code']) ?></td>
                        <td><?= esc($row['quality_grade'] ?? '—') ?></td>
                        <td><?= esc($row['defect_names'] ?: ($row['defect_percent'] === null ? '—' : number_format((float) $row['defect_percent'], 3) . '%')) ?></td>
                        <td><?= $row['temperature_c'] === null ? '—' : number_format((float) $row['temperature_c'], 2) . ' °C' ?></td>
                        <td><span class="quality-status <?= esc($row['status'] === 'annulled' ? 'quality-neutral' : ($resultClasses[$row['disposition']] ?? 'quality-neutral')) ?>"><span></span><?= esc($resultLabels[$row['disposition']] ?? $row['disposition']) ?></span></td>
                        <td><?php if ($row['status'] === 'annulled'): ?><strong class="text-danger">Anulada</strong><small><?= esc($row['annul_reason'] ?? '') ?></small><?php elseif ($row['status'] === 'submitted'): ?>Pendiente dictamen<?php else: ?>Completado<?php endif ?></td>
                        <td><?= esc($row['inspected_at']) ?></td>
                        <td><?= esc($row['inspector_name']) ?></td>
                        <td class="text-nowrap">
                            <?php if ($canApprove && $row['status'] === 'submitted'): ?><button class="btn btn-sm btn-outline-primary" type="button" data-quality-approve="<?= (int) $row['id'] ?>" data-grade="<?= esc($row['quality_grade'] ?? '') ?>" data-proposed="<?= esc($row['disposition']) ?>">Dictaminar</button><?php endif ?>
                            <?php if ($canApprove && $row['status'] === 'approved' && $row['disposition'] === 'hold'): ?><button class="btn btn-sm btn-outline-success" type="button" data-quality-release="<?= (int) $row['id'] ?>" data-release-type="released">Liberar</button><?php endif ?>
                            <?php if ($canApprove && $row['status'] === 'approved' && $row['disposition'] === 'hold_lot'): ?><button class="btn btn-sm btn-outline-success" type="button" data-quality-release="<?= (int) $row['id'] ?>" data-release-type="released_lot">Liberar lote</button><?php endif ?>
                            <?php if ($canAnnulRow): ?><button class="btn btn-sm btn-outline-danger" type="button" data-quality-annul="<?= (int) $row['id'] ?>">Anular</button><button class="btn btn-sm btn-outline-secondary" type="button" data-quality-correct="<?= (int) $row['id'] ?>">Corregir</button><?php elseif ($row['status'] === 'annulled' && ! empty($row['superseded_by_inspection_id'])): ?><span class="text-secondary small">→ INSP-<?= str_pad((string) $row['superseded_by_inspection_id'], 6, '0', STR_PAD_LEFT) ?></span><?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($inspections === []): ?><tr><td colspan="11" class="quality-empty">Sin inspecciones para los filtros seleccionados.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div>
        <?php $historyPages = max(1, (int) ceil($historyTotal / $pageSize)); ?><div class="quality-table-footer"><span>Mostrando <?= $historyTotal === 0 ? 0 : (($historyPage - 1) * $pageSize + 1) ?>–<?= min($historyPage * $pageSize, $historyTotal) ?> de <?= number_format($historyTotal) ?> inspecciones</span><div class="quality-pager"><?php if ($historyPage > 1): ?><a href="<?= esc($pageLink('history_page', $historyPage - 1)) ?>"><i class="bi bi-chevron-left"></i></a><?php endif ?><span>Página <?= $historyPage ?> de <?= $historyPages ?></span><?php if ($historyPage < $historyPages): ?><a href="<?= esc($pageLink('history_page', $historyPage + 1)) ?>"><i class="bi bi-chevron-right"></i></a><?php endif ?></div></div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/quality.js"></script>
<?= $this->endSection() ?>
