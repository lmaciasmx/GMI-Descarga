<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<?php
$statusLabels = [
    'available' => 'Disponible',
    'reserved' => 'Reservado',
    'held' => 'Retenido',
    'rejected' => 'Rechazado',
    'in_transit' => 'En tránsito',
    'dispatched' => 'Despachado',
    'exhausted' => 'Agotado',
    'received' => 'Recibido',
];
$qualityLabels = [
    'sin_incidencia' => 'Sin incidencia',
    'observado' => 'Observado',
    'rechazado' => 'Rechazado',
    'liberado' => 'Liberado',
    'revision' => 'En revisión',
];
$statusClass = [
    'available' => 'success',
    'reserved' => 'primary',
    'held' => 'warning',
    'rejected' => 'danger',
    'in_transit' => 'info',
    'dispatched' => 'secondary',
    'exhausted' => 'secondary',
    'received' => 'info',
];
$qualityClass = [
    'sin_incidencia' => 'success',
    'observado' => 'warning',
    'rechazado' => 'danger',
    'liberado' => 'primary',
    'revision' => 'secondary',
];
$kg = static fn ($value): string => number_format((float) $value, 3);
$tons = static fn ($value): string => number_format(((float) $value) / 1000, 3);

$query = $_GET;
$pageUrl = static function (int $targetPage) use ($query): string {
    $params = $query;
    $params['pagina'] = $targetPage;
    return current_url() . '?' . http_build_query($params);
};
$modeUrl = static function (string $mode) use ($query): string {
    $params = $query;
    $params['vista'] = $mode;
    $params['pagina'] = 1;
    return current_url() . '?' . http_build_query($params);
};
$summaryDetailUrl = static function (array $row) use ($query, $groupBy): string {
    $params = $query;
    $params['vista'] = 'tinas';
    $params['pagina'] = 1;
    if ($groupBy === 'producto') {
        $params['species_id'] = (int) ($row['species_id'] ?? 0);
        $params['size_grade_id'] = (int) ($row['size_grade_id'] ?? 0);
    } elseif ($groupBy === 'lote') {
        $params['lot_id'] = (int) ($row['lot_id'] ?? 0);
    } elseif ($groupBy === 'camara') {
        $params['chamber_id'] = (int) ($row['chamber_id'] ?? 0);
    } elseif ($groupBy === 'marea') {
        $params['voyage_id'] = (int) ($row['voyage_id'] ?? 0);
    }
    return current_url() . '?' . http_build_query($params);
};
?>

<style>
.inventory-shell{display:grid;gap:16px}.inventory-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}.inventory-kpi{border:1px solid #dfe7ef;border-radius:14px;background:#fff;padding:14px 16px;min-height:95px}.inventory-kpi .label{font-size:.76rem;color:#65788b;font-weight:700;text-transform:uppercase;letter-spacing:.03em}.inventory-kpi .value{font-size:1.45rem;font-weight:800;color:#183b56;line-height:1.15;margin-top:6px}.inventory-kpi .hint{font-size:.75rem;color:#8292a2;margin-top:5px}.inventory-filter-card{border:1px solid #dfe7ef;border-radius:14px;overflow:hidden}.inventory-filter-card .card-header{background:#fff;border-bottom:1px solid #e5edf4}.inventory-filter-grid{display:grid;grid-template-columns:2fr repeat(4,minmax(150px,1fr));gap:10px}.inventory-filter-grid .wide{grid-column:span 2}.inventory-viewbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px}.inventory-view-toggle{display:inline-flex;border:1px solid #d5e0ea;border-radius:10px;padding:3px;background:#f6f9fb}.inventory-view-toggle a{padding:7px 12px;border-radius:8px;text-decoration:none;color:#52687b;font-weight:700;font-size:.88rem}.inventory-view-toggle a.active{background:#fff;color:#0d6efd;box-shadow:0 1px 4px rgba(0,0,0,.08)}.inventory-table th{font-size:.74rem;text-transform:uppercase;letter-spacing:.035em;color:#5b7083;white-space:nowrap}.inventory-table td{vertical-align:middle}.inventory-main{font-weight:800;color:#173b57}.inventory-sub{font-size:.78rem;color:#77899a}.inventory-location{font-weight:700}.inventory-location small{display:block;font-weight:400;color:#7c8d9d;margin-top:2px}.inventory-summary-link{text-decoration:none;color:inherit}.inventory-summary-link:hover .inventory-main{color:#0d6efd}.badge-soft{display:inline-flex;align-items:center;border-radius:999px;padding:.38rem .62rem;font-size:.76rem;font-weight:800}.badge-soft.success{background:#e9f7ef;color:#187844}.badge-soft.primary{background:#e9f2ff;color:#0b5bc2}.badge-soft.warning{background:#fff2cf;color:#956100}.badge-soft.danger{background:#ffe8e8;color:#b42318}.badge-soft.info{background:#e6f6fb;color:#136a83}.badge-soft.secondary{background:#edf1f4;color:#596a78}.inventory-empty{text-align:center;padding:48px 20px;color:#6f8192}.inventory-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.inventory-pagination .pages{display:flex;gap:5px;align-items:center}.inventory-pagination a,.inventory-pagination span.page-current{min-width:34px;height:34px;display:grid;place-items:center;border:1px solid #d9e3ec;border-radius:8px;text-decoration:none;color:#36566e;background:#fff}.inventory-pagination span.page-current{background:#0d6efd;color:#fff;border-color:#0d6efd}.filter-actions{display:flex;gap:8px;align-items:end}.filter-summary{font-size:.8rem;color:#718596}.inventory-alert{border:0;background:#eff7ff;color:#285a7e;border-radius:12px}.inventory-card{border:1px solid #dfe7ef;border-radius:14px;overflow:hidden}.inventory-card .card-header{background:#fff}.inventory-card .card-footer{background:#fff;border-top:1px solid #e7eef4}@media(max-width:1400px){.inventory-kpis{grid-template-columns:repeat(3,1fr)}.inventory-filter-grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:900px){.inventory-kpis{grid-template-columns:repeat(2,1fr)}.inventory-filter-grid{grid-template-columns:1fr 1fr}.inventory-filter-grid .wide{grid-column:span 2}}@media(max-width:600px){.inventory-kpis{grid-template-columns:1fr}.inventory-filter-grid{grid-template-columns:1fr}.inventory-filter-grid .wide{grid-column:span 1}}

/* ==============================================================
   Inventario · ajuste visual controlado 1.0.0
   Exclusivamente presentación. No altera filtros ni consultas.
   ============================================================== */
.inventory-view-left{
    display:flex;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
    min-width:0;
}

.inventory-group-form{
    display:grid;
    grid-template-columns:auto minmax(175px,220px);
    align-items:center;
    gap:8px;
    margin:0;
    min-width:0;
}

.inventory-group-label{
    margin:0;
    white-space:nowrap;
    line-height:1;
}

.inventory-group-select{
    width:100%;
    min-width:175px;
    max-width:220px;
}

.inventory-table-wrap{
    width:100%;
    max-width:100%;
    overflow-x:auto;
    overflow-y:hidden;
    scrollbar-gutter:stable;
    -webkit-overflow-scrolling:touch;
}

.inventory-table-wrap::-webkit-scrollbar{
    height:10px;
}

.inventory-table-wrap::-webkit-scrollbar-thumb{
    background:#bdcbd5;
    border:2px solid #f7fafb;
    border-radius:999px;
}

.inventory-table-wrap::-webkit-scrollbar-track{
    background:#f7fafb;
}

.inventory-summary-table{
    width:100%!important;
    min-width:1080px;
    table-layout:auto;
}

.inventory-tubs-table{
    width:100%!important;
    min-width:1320px;
    table-layout:auto;
}

.inventory-summary-table th,
.inventory-summary-table td,
.inventory-tubs-table th,
.inventory-tubs-table td{
    white-space:nowrap;
}

.inventory-summary-table th:first-child,
.inventory-summary-table td:first-child{
    min-width:185px;
}

.inventory-summary-table th:nth-child(4),
.inventory-summary-table td:nth-child(4),
.inventory-summary-table th:nth-child(5),
.inventory-summary-table td:nth-child(5),
.inventory-summary-table th:nth-child(6),
.inventory-summary-table td:nth-child(6),
.inventory-summary-table th:nth-child(7),
.inventory-summary-table td:nth-child(7){
    min-width:125px;
}

.inventory-summary-table th:nth-child(8),
.inventory-summary-table td:nth-child(8){
    min-width:145px;
}

.inventory-summary-table th:last-child,
.inventory-summary-table td:last-child{
    min-width:90px;
}

.inventory-tubs-table th:nth-child(1),
.inventory-tubs-table td:nth-child(1){
    min-width:190px;
}

.inventory-tubs-table th:nth-child(2),
.inventory-tubs-table td:nth-child(2){
    min-width:165px;
}

.inventory-tubs-table th:nth-child(3),
.inventory-tubs-table td:nth-child(3){
    min-width:130px;
}

.inventory-tubs-table th:nth-child(4),
.inventory-tubs-table td:nth-child(4){
    min-width:180px;
}

.inventory-tubs-table th:nth-child(5),
.inventory-tubs-table td:nth-child(5),
.inventory-tubs-table th:nth-child(6),
.inventory-tubs-table td:nth-child(6),
.inventory-tubs-table th:nth-child(7),
.inventory-tubs-table td:nth-child(7){
    min-width:125px;
}

.inventory-tubs-table th:nth-child(8),
.inventory-tubs-table td:nth-child(8){
    min-width:125px;
}

.inventory-tubs-table th:nth-child(9),
.inventory-tubs-table td:nth-child(9){
    min-width:115px;
}

.inventory-tubs-table th:nth-child(10),
.inventory-tubs-table td:nth-child(10){
    min-width:155px;
}

.inventory-location{
    display:inline-flex;
    align-items:center;
    gap:5px;
    font-weight:700;
    white-space:nowrap;
}

.inventory-location .inventory-location-separator{
    color:#9aa9b6;
    font-weight:500;
}

@media(max-width:767.98px){
    .inventory-viewbar{
        align-items:flex-start;
    }

    .inventory-view-left{
        width:100%;
    }

    .inventory-group-form{
        grid-template-columns:auto minmax(165px,1fr);
        width:min(100%,320px);
    }

    .inventory-group-select{
        max-width:none;
    }
}
</style>

<div class="inventory-shell">
    <div class="alert inventory-alert mb-0">
        <i class="bi bi-box-seam me-2"></i>
        Las tinas ingresan al inventario como <strong>Disponibles</strong>. Calidad sólo cambia el estado cuando existe una incidencia, retención o rechazo específico.
    </div>

    <div class="inventory-kpis">
        <div class="inventory-kpi"><div class="label">Existencia total</div><div class="value"><?= $tons($kpis['total_kg'] ?? 0) ?> t</div><div class="hint"><?= $kg($kpis['total_kg'] ?? 0) ?> kg</div></div>
        <div class="inventory-kpi"><div class="label">Disponible</div><div class="value"><?= $tons($kpis['available_kg'] ?? 0) ?> t</div><div class="hint">Saldo utilizable</div></div>
        <div class="inventory-kpi"><div class="label">Reservado</div><div class="value"><?= $tons($kpis['reserved_kg'] ?? 0) ?> t</div><div class="hint">Comprometido para salida</div></div>
        <div class="inventory-kpi"><div class="label">Retenido</div><div class="value"><?= $tons($kpis['held_kg'] ?? 0) ?> t</div><div class="hint">Sólo incidencias especiales</div></div>
        <div class="inventory-kpi"><div class="label">Tinas</div><div class="value"><?= number_format((int) ($kpis['units'] ?? 0)) ?></div><div class="hint">Unidades en la vista</div></div>
        <div class="inventory-kpi"><div class="label">Lotes</div><div class="value"><?= number_format((int) ($kpis['lots'] ?? 0)) ?></div><div class="hint">Lotes distintos</div></div>
    </div>

    <form method="get" class="card inventory-filter-card">
        <input type="hidden" name="vista" value="<?= esc($viewMode) ?>">
        <input type="hidden" name="agrupar" value="<?= esc($groupBy) ?>">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div>
                <strong><i class="bi bi-funnel me-2"></i>Filtros de inventario</strong>
                <div class="filter-summary">Los totales y resultados se recalculan sobre los filtros aplicados.</div>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="<?= esc(current_url()) ?>"><i class="bi bi-arrow-counterclockwise"></i> Limpiar</a>
        </div>
        <div class="card-body">
            <div class="inventory-filter-grid">
                <div class="wide">
                    <label class="form-label">Búsqueda rápida</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input class="form-control" type="search" name="q" value="<?= esc($filters['q']) ?>" placeholder="Marbete, tina, lote, barco, especie o ubicación...">
                    </div>
                </div>

                <div>
                    <label class="form-label">Frigorífico</label>
                    <select class="form-select" name="warehouse_id" <?= ! empty($scope['warehouse_id']) ? 'disabled' : '' ?>>
                        <option value="0">Todos</option>
                        <?php foreach ($filterOptions['warehouses'] as $item): ?>
                            <option value="<?= (int) $item['id'] ?>" <?= (int) $filters['warehouse_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?php if (! empty($scope['warehouse_id'])): ?><input type="hidden" name="warehouse_id" value="<?= (int) $scope['warehouse_id'] ?>"><?php endif ?>
                </div>

                <div>
                    <label class="form-label">Cámara</label>
                    <select class="form-select" name="chamber_id" id="inventoryChamber">
                        <option value="0">Todas</option>
                        <?php foreach ($filterOptions['chambers'] as $item): ?>
                            <option value="<?= (int) $item['id'] ?>" data-warehouse="<?= (int) $item['warehouse_id'] ?>" <?= (int) $filters['chamber_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['name'] ?: $item['code']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Fila / Pasillo</label>
                    <select class="form-select" name="sector_id" id="inventorySector">
                        <option value="0">Todos</option>
                        <?php foreach ($filterOptions['sectors'] as $item): ?>
                            <option value="<?= (int) $item['id'] ?>" data-chamber="<?= (int) $item['chamber_id'] ?>" <?= (int) $filters['sector_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Especie</label>
                    <select class="form-select" name="species_id" id="inventorySpecies">
                        <option value="0">Todas</option>
                        <?php foreach ($filterOptions['species'] as $item): ?>
                            <option value="<?= (int) $item['id'] ?>" <?= (int) $filters['species_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Talla</label>
                    <select class="form-select" name="size_grade_id" id="inventorySize">
                        <option value="0">Todas</option>
                        <?php foreach ($filterOptions['sizes'] as $item): ?>
                            <option value="<?= (int) $item['id'] ?>" data-species="<?= (int) ($item['species_id'] ?? 0) ?>" <?= (int) $filters['size_grade_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Lote</label>
                    <select class="form-select" name="lot_id">
                        <option value="0">Todos</option>
                        <?php foreach ($filterOptions['lots'] as $item): ?>
                            <option value="<?= (int) $item['id'] ?>" <?= (int) $filters['lot_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['code']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Estado</label>
                    <select class="form-select" name="status">
                        <option value="">Todos</option>
                        <?php foreach ($filterOptions['statuses'] as $key => $label): ?>
                            <option value="<?= esc($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Calidad</label>
                    <select class="form-select" name="quality">
                        <option value="">Todas</option>
                        <?php foreach ($filterOptions['qualities'] as $key => $label): ?>
                            <option value="<?= esc($key) ?>" <?= $filters['quality'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Barco / Marea</label>
                    <select class="form-select" name="voyage_id">
                        <option value="0">Todas</option>
                        <?php foreach ($filterOptions['voyages'] as $item): ?>
                            <option value="<?= (int) $item['id'] ?>" <?= (int) $filters['voyage_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= esc($item['vessel_code']) ?> · <?= (int) $item['annual_number'] ?>/<?= (int) $item['year'] ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Entrada desde</label>
                    <input class="form-control" type="date" name="date_from" value="<?= esc($filters['date_from']) ?>">
                </div>

                <div>
                    <label class="form-label">Entrada hasta</label>
                    <input class="form-control" type="date" name="date_to" value="<?= esc($filters['date_to']) ?>">
                </div>

                <div>
                    <label class="form-label">Registros por página</label>
                    <select class="form-select" name="por_pagina">
                        <?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel-fill"></i> Aplicar filtros</button>
                </div>
            </div>
        </div>
    </form>

    <div class="card inventory-card">
        <div class="card-header inventory-viewbar">
            <div class="inventory-view-left">
                <div class="inventory-view-toggle">
                    <a class="<?= $viewMode === 'resumen' ? 'active' : '' ?>" href="<?= esc($modeUrl('resumen')) ?>"><i class="bi bi-grid-3x3-gap me-1"></i>Resumen</a>
                    <a class="<?= $viewMode === 'tinas' ? 'active' : '' ?>" href="<?= esc($modeUrl('tinas')) ?>"><i class="bi bi-box-seam me-1"></i>Por tina</a>
                </div>
                <?php if ($viewMode === 'resumen'): ?>
                    <form method="get" class="inventory-group-form" id="inventoryGroupForm">
                        <?php foreach ($_GET as $key => $value): if (in_array($key, ['agrupar', 'pagina'], true) || is_array($value)) continue; ?>
                            <input type="hidden" name="<?= esc($key) ?>" value="<?= esc((string) $value) ?>">
                        <?php endforeach ?>
                        <input type="hidden" name="vista" value="resumen">
                        <label class="small text-secondary fw-bold inventory-group-label" for="inventoryGroup">Agrupar por</label>
                        <select class="form-select form-select-sm inventory-group-select" id="inventoryGroup" name="agrupar">
                            <option value="producto" <?= $groupBy === 'producto' ? 'selected' : '' ?>>Especie / Talla</option>
                            <option value="lote" <?= $groupBy === 'lote' ? 'selected' : '' ?>>Lote</option>
                            <option value="camara" <?= $groupBy === 'camara' ? 'selected' : '' ?>>Cámara</option>
                            <option value="marea" <?= $groupBy === 'marea' ? 'selected' : '' ?>>Barco / Marea</option>
                        </select>
                    </form>
                <?php endif ?>
            </div>
            <div class="text-secondary small">
                <?= number_format($totalRows) ?> <?= $viewMode === 'resumen' ? 'grupo(s)' : 'tina(s)' ?>
            </div>
        </div>

        <div class="table-responsive inventory-table-wrap">
            <?php if ($viewMode === 'resumen'): ?>
                <table class="table table-hover mb-0 inventory-table inventory-summary-table">
                    <thead><tr><th>Grupo</th><th class="text-end">Lotes</th><th class="text-end">Tinas</th><th class="text-end">Existencia kg</th><th class="text-end">Reservado kg</th><th class="text-end">Disponible kg</th><th class="text-end">Retenido kg</th><th>Última entrada</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><a class="inventory-summary-link" href="<?= esc($summaryDetailUrl($row)) ?>"><div class="inventory-main"><?= esc($row['group_label']) ?></div><div class="inventory-sub"><?= esc($row['group_subtitle'] ?? '') ?></div></a></td>
                            <td class="text-end"><?= number_format((int) $row['lot_count']) ?></td>
                            <td class="text-end fw-bold"><?= number_format((int) $row['unit_count']) ?></td>
                            <td class="text-end"><?= $kg($row['total_kg']) ?></td>
                            <td class="text-end"><?= $kg($row['reserved_kg']) ?></td>
                            <td class="text-end fw-bold text-success"><?= $kg($row['available_kg']) ?></td>
                            <td class="text-end <?= (float) $row['held_kg'] > 0 ? 'text-warning fw-bold' : '' ?>"><?= $kg($row['held_kg']) ?></td>
                            <td><?= esc($row['last_received_at']) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc($summaryDetailUrl($row)) ?>">Ver tinas</a></td>
                        </tr>
                    <?php endforeach ?>
                    <?php if ($rows === []): ?><tr><td colspan="9"><div class="inventory-empty"><i class="bi bi-search fs-2 d-block mb-2"></i>No hay existencias que coincidan con los filtros.</div></td></tr><?php endif ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table class="table table-hover mb-0 inventory-table inventory-tubs-table">
                    <thead><tr><th>Marbete / Tina</th><th>Especie / Talla</th><th>Lote</th><th>Ubicación</th><th class="text-end">Existencia kg</th><th class="text-end">Reserva kg</th><th class="text-end">Disponible kg</th><th>Calidad</th><th>Estado</th><th>Entrada</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): $available = max(0, (float) $row['on_hand_weight_kg'] - (float) $row['reserved_weight_kg']); ?>
                        <tr>
                            <td><div class="inventory-main"><?= esc($row['unit_code']) ?></div><div class="inventory-sub">Tina <?= esc($row['tub_code']) ?> · <?= esc($row['vessel_code']) ?> <?= (int) $row['annual_number'] ?>/<?= (int) $row['year'] ?></div></td>
                            <td><div class="inventory-main"><?= esc($row['species_name']) ?></div><div class="inventory-sub"><?= esc($row['size_name']) ?></div></td>
                            <td><?= esc($row['lot_code']) ?></td>
                            <td><div class="inventory-location"><span><?= esc($row['chamber_name'] ?: $row['chamber_code'] ?: $row['warehouse_name']) ?></span><span class="inventory-location-separator">·</span><span><?= esc($row['sector_name'] ?: 'Sin fila') ?></span></div></td>
                            <td class="text-end"><?= $kg($row['on_hand_weight_kg']) ?></td>
                            <td class="text-end"><?= $kg($row['reserved_weight_kg']) ?></td>
                            <td class="text-end fw-bold"><?= $kg(in_array($row['status'], ['available', 'reserved'], true) ? $available : 0) ?></td>
                            <td><span class="badge-soft <?= esc($qualityClass[$row['quality_state']] ?? 'secondary') ?>"><?= esc($qualityLabels[$row['quality_state']] ?? 'Sin incidencia') ?></span><?php if (! empty($row['inspection_quality_grade'])): ?><div class="inventory-sub mt-1"><?= esc($row['inspection_quality_grade']) ?></div><?php endif ?></td>
                            <td><span class="badge-soft <?= esc($statusClass[$row['status']] ?? 'secondary') ?>"><?= esc($statusLabels[$row['status']] ?? ucfirst(str_replace('_', ' ', $row['status']))) ?></span><?php if ($row['status'] === 'held' && ! empty($row['hold_reason'])): ?><div class="inventory-sub mt-1"><?= esc($row['hold_reason']) ?></div><?php endif ?></td>
                            <td><?= esc($row['received_at']) ?></td>
                        </tr>
                    <?php endforeach ?>
                    <?php if ($rows === []): ?><tr><td colspan="10"><div class="inventory-empty"><i class="bi bi-search fs-2 d-block mb-2"></i>No hay tinas que coincidan con los filtros.</div></td></tr><?php endif ?>
                    </tbody>
                </table>
            <?php endif ?>
        </div>

        <div class="card-footer inventory-pagination">
            <div class="text-secondary small">
                <?php if ($totalRows > 0): ?>Mostrando <?= number_format((($page - 1) * $perPage) + 1) ?>–<?= number_format(min($page * $perPage, $totalRows)) ?> de <?= number_format($totalRows) ?><?php else: ?>Sin resultados<?php endif ?>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="pages">
                    <?php if ($page > 1): ?><a href="<?= esc($pageUrl($page - 1)) ?>" aria-label="Anterior"><i class="bi bi-chevron-left"></i></a><?php endif ?>
                    <?php $from = max(1, $page - 2); $to = min($totalPages, $page + 2); ?>
                    <?php if ($from > 1): ?><a href="<?= esc($pageUrl(1)) ?>">1</a><?php if ($from > 2): ?><span class="px-1">…</span><?php endif ?><?php endif ?>
                    <?php for ($i = $from; $i <= $to; $i++): ?>
                        <?php if ($i === $page): ?><span class="page-current"><?= $i ?></span><?php else: ?><a href="<?= esc($pageUrl($i)) ?>"><?= $i ?></a><?php endif ?>
                    <?php endfor ?>
                    <?php if ($to < $totalPages): ?><?php if ($to < $totalPages - 1): ?><span class="px-1">…</span><?php endif ?><a href="<?= esc($pageUrl($totalPages)) ?>"><?= $totalPages ?></a><?php endif ?>
                    <?php if ($page < $totalPages): ?><a href="<?= esc($pageUrl($page + 1)) ?>" aria-label="Siguiente"><i class="bi bi-chevron-right"></i></a><?php endif ?>
                </div>
            <?php endif ?>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/inventory.js"></script>
<?= $this->endSection() ?>
