<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="/assets/css/inventory-movements-ui.css?v=1.0.0">
<?= $this->endSection() ?>
<?= $this->section('pageActions') ?>
<?php if ($canMove || $canAdjust): ?>
<button class="btn btn-primary btn-operational" type="button" data-bs-toggle="modal" data-bs-target="#movementModal"><i class="bi bi-plus-lg"></i> Nuevo movimiento</button>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$typeLabels = [
    'receipt' => 'Recepción', 'relocation' => 'Reubicación', 'return' => 'Devolución', 'loss' => 'Merma',
    'adjustment_increase' => 'Ajuste positivo', 'adjustment_decrease' => 'Ajuste negativo',
    'reservation' => 'Reserva', 'reservation_release' => 'Liberación reserva', 'production_issue' => 'Surtido',
    'sale_dispatch' => 'Salida venta', 'transfer_out' => 'Traspaso salida', 'transfer_in' => 'Traspaso entrada',
    'reversal' => 'Reverso',
];
$queryBase = $_GET;
?>
<div hidden id="movementContext"><?= json_encode(['scope' => $scope], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></div>

<div class="movement-page">

<div class="card mb-3 movement-filter-card">
    <div class="card-body">
        <form method="get" class="movement-filter-form <?= empty($scope['warehouse_id']) ? 'has-warehouse' : '' ?>">
            <div class="movement-filter-field movement-filter-search">
                <label class="form-label">Buscar</label>
                <input class="form-control" name="q" value="<?= esc($filters['q']) ?>" placeholder="Folio, tina, lote o motivo">
            </div>
            <div class="movement-filter-field">
                <label class="form-label">Tipo</label>
                <select class="form-select" name="movement_type">
                    <option value="">Todos</option>
                    <?php foreach ($typeLabels as $value => $label): ?>
                    <option value="<?= esc($value) ?>" <?= $filters['movement_type'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php if (empty($scope['warehouse_id'])): ?>
            <div class="movement-filter-field">
                <label class="form-label">Almacén</label>
                <select class="form-select" name="warehouse_id">
                    <option value="">Todos</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                    <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $filters['warehouse_id'] === (int) $warehouse['id'] ? 'selected' : '' ?>><?= esc($warehouse['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <?php endif ?>
            <div class="movement-filter-field"><label class="form-label">Desde</label><input class="form-control" type="date" name="date_from" value="<?= esc($filters['date_from']) ?>"></div>
            <div class="movement-filter-field"><label class="form-label">Hasta</label><input class="form-control" type="date" name="date_to" value="<?= esc($filters['date_to']) ?>"></div>
            <div class="movement-filter-actions"><a class="btn btn-light" href="<?= current_url() ?>">Limpiar</a><button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i> Filtrar</button></div>
        </form>
    </div>
</div>

<div class="card movement-history-card">
    <div class="card-header d-flex justify-content-between align-items-center"><span>Histórico de movimientos</span><span class="text-secondary small"><?= number_format($totalRows) ?> documentos</span></div>
    <div class="card-body p-0">
        <div class="table-responsive movement-history-scroll">
            <table class="table table-hover align-middle mb-0 movement-history-table">
                <thead><tr><th>Folio</th><th>Tipo</th><th>Tina(s) / Lote(s)</th><th>Peso ref.</th><th>Fecha</th><th>Motivo</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                <?php $directReversals = ['relocation', 'loss', 'adjustment_increase', 'adjustment_decrease']; foreach ($documents as $document): ?>
                    <tr>
                        <td><strong><?= esc($document['folio']) ?></strong><small class="d-block text-secondary">#<?= (int) $document['id'] ?></small></td>
                        <td><?= esc($typeLabels[$document['movement_type']] ?? str_replace('_', ' ', $document['movement_type'])) ?></td>
                        <td><span class="d-block"><?= esc($document['unit_codes'] ?: '—') ?></span><small class="text-secondary"><?= esc($document['lot_codes'] ?: '—') ?></small></td>
                        <td><?= number_format((float) $document['total_kg'], 3) ?> kg</td>
                        <td><?= esc($document['confirmed_at'] ?: '—') ?></td>
                        <td><?= esc($document['reason_code'] ?: '—') ?></td>
                        <td><?php if (! empty($document['reversal_document_id'])): ?><span class="badge text-bg-secondary">Compensado</span><?php else: ?><span class="badge-state <?= esc($document['status']) ?>"><?= esc($document['status']) ?></span><?php endif ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-primary" type="button" data-movement-view="<?= (int) $document['id'] ?>"><i class="bi bi-eye"></i> Ver</button>
                            <?php if (empty($document['reversal_document_id']) && $canReverse && $document['status'] === 'confirmed' && in_array($document['movement_type'], $directReversals, true)): ?>
                            <button class="btn btn-sm btn-outline-danger" type="button" data-movement-reverse="<?= (int) $document['id'] ?>">Reversar</button>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($documents === []): ?><tr><td colspan="8" class="text-center text-secondary py-5">No hay movimientos con los filtros seleccionados.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3" aria-label="Paginación"><ul class="pagination justify-content-end mb-0">
<?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): $params = array_merge($queryBase, ['pagina' => $p]); ?>
<li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query($params) ?>"><?= $p ?></a></li>
<?php endfor ?>
</ul></nav>
<?php endif ?>

<?php if ($canMove || $canAdjust): ?>
<div class="modal fade movement-create-modal" id="movementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><form data-movement-form>
        <div class="modal-header movement-modal-header">
            <div>
                <div class="movement-modal-kicker"><i class="bi bi-arrow-left-right"></i> Inventario</div>
                <h2 class="modal-title fs-5 mb-0">Nuevo movimiento</h2>
                <small class="text-secondary">Sólo operaciones manuales autorizadas.</small>
            </div>
            <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <section class="movement-form-section">
                <div class="movement-form-section-title"><span>1</span><div><strong>Movimiento y tina</strong><small>Selecciona la operación y la unidad sobre la que trabajarás.</small></div></div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Tipo</label><select class="form-select" name="movement_type" required><?php if ($canMove): ?><option value="relocation">Reubicación</option><?php endif ?><?php if ($canAdjust): ?><option value="loss">Merma / castigo físico</option><option value="adjustment_increase">Ajuste positivo</option><option value="adjustment_decrease">Ajuste negativo</option><?php endif ?></select></div>
                    <div class="col-md-8"><label class="form-label">Tina</label><select class="form-select" id="movementUnitSelect" name="stock_unit_id" required><option value=""></option></select><small class="text-secondary movement-field-help">Escribe al menos 2 caracteres del número de tina, marbete o lote.</small></div>
                </div>
            </section>

            <div class="movement-unit-summary d-none" data-unit-summary><div class="movement-unit-summary-grid">
                <div><span>Especie / talla</span><strong data-unit-product>—</strong></div>
                <div><span>Lote</span><strong data-unit-lot>—</strong></div>
                <div><span>Estado</span><strong data-unit-status>—</strong></div>
                <div><span>Peso actual</span><strong data-unit-weight>—</strong></div>
                <div><span>Reservado / disponible</span><strong data-unit-available>—</strong></div>
                <div><span>Ubicación actual</span><strong data-unit-location>—</strong></div>
            </div></div>

            <section class="movement-form-section">
                <div class="movement-form-section-title"><span>2</span><div><strong>Destino y motivo</strong><small>Completa únicamente los datos requeridos para el tipo seleccionado.</small></div></div>
                <div class="row g-3">
                    <div class="col-md-7" data-relocation-field>
                        <label class="form-label">Nueva ubicación</label>
                        <div class="row g-2">
                            <div class="col-md-5"><select class="form-select" name="chamber_id"><option value="">Selecciona cámara...</option></select></div>
                            <div class="col-md-7"><select class="form-select" name="to_location_id" disabled><option value="">Selecciona cámara...</option></select></div>
                        </div>
                        <small class="text-secondary movement-field-help">Selecciona Cámara y Fila. La posición interna libre se asigna automáticamente.</small>
                    </div>
                    <div class="col-md-5 d-none" data-quantity-field><label class="form-label">Cantidad kg</label><input class="form-control" name="quantity_kg" type="number" min="0.001" step="0.001"></div>
                    <div class="col-md-5"><label class="form-label">Motivo</label><input class="form-control" name="reason_code" maxlength="50" placeholder="Ej. REUBICACION_OPERATIVA"></div>
                    <div class="col-12"><label class="form-label">Observaciones</label><textarea class="form-control movement-observations" name="observations" rows="2" maxlength="1000" placeholder="Información adicional del movimiento (opcional)"></textarea></div>
                </div>
            </section>

            <div class="movement-info-note"><i class="bi bi-info-circle"></i><div><strong>Operaciones desde su módulo de origen</strong><span>Ventas, surtidos y traspasos se ejecutan desde sus módulos. La devolución manual queda fuera de esta pantalla hasta definir su flujo documental.</span></div></div>
        </div>
        <div class="modal-footer movement-modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-operational no-offline-confirm" type="submit"><i class="bi bi-check2-circle"></i> Confirmar movimiento</button></div>
    </form></div></div>
</div>
<?php endif ?>

<div class="modal fade movement-detail-modal" id="movementDetailModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
<div class="modal-header movement-modal-header"><div><h2 class="modal-title fs-5 mb-0">Detalle del movimiento</h2><small class="text-secondary" data-detail-subtitle></small></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
<div class="modal-body" data-movement-detail><div class="text-center text-secondary py-5">Selecciona un movimiento.</div></div>
<div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?><script src="/assets/js/movements.js?v=2.0.3"></script><?= $this->endSection() ?>
