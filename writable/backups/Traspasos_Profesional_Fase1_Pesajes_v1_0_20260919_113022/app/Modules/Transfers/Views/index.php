<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$statusLabels = [
    'requested' => 'Solicitado', 'authorized' => 'Autorizado', 'prepared' => 'Preparado',
    'shipped' => 'Embarcado', 'in_transit' => 'En tránsito', 'discrepancy' => 'Con diferencias',
    'received' => 'Recibido', 'canceled' => 'Cancelado',
];
?>
<div hidden id="transferContext"><?= json_encode([
    'companyId' => (int) $scope['company_id'],
    'siteId' => (int) $scope['site_id'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg"><div class="card h-100"><div class="card-body py-3"><div class="text-secondary small">Solicitados</div><div class="fs-4 fw-semibold"><?= (int) $summary['requested'] ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card h-100"><div class="card-body py-3"><div class="text-secondary small">Autorizados</div><div class="fs-4 fw-semibold"><?= (int) $summary['authorized'] ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card h-100"><div class="card-body py-3"><div class="text-secondary small">En tránsito</div><div class="fs-4 fw-semibold"><?= (int) $summary['in_transit'] ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card h-100"><div class="card-body py-3"><div class="text-secondary small">Con diferencias</div><div class="fs-4 fw-semibold"><?= (int) $summary['discrepancy'] ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card h-100"><div class="card-body py-3"><div class="text-secondary small">Recibidos</div><div class="fs-4 fw-semibold"><?= (int) $summary['received'] ?></div></div></div></div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <div>
            <div class="fw-semibold">Control de traspasos</div>
            <div class="text-secondary small">Los traspasos nacen desde una solicitud autorizada y aquí sólo se ejecutan, reciben y consultan.</div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" data-datatable data-export-title="Traspasos">
                <thead><tr><th>Folio</th><th>Tipo</th><th>Solicitud</th><th>Origen</th><th>Destino</th><th>Tinas</th><th>Peso kg</th><th>Solicitado</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($transfers as $transfer):
                    $originHere = (int) $transfer['origin_site_id'] === (int) $scope['site_id'] && (empty($scope['warehouse_id']) || (int) $transfer['origin_warehouse_id'] === (int) $scope['warehouse_id']);
                    $destinationHere = (int) $transfer['destination_site_id'] === (int) $scope['site_id'] && (empty($scope['warehouse_id']) || (int) $transfer['destination_warehouse_id'] === (int) $scope['warehouse_id']);
                ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($transfer['folio']) ?></td>
                        <td><span class="badge <?= ($transfer['transfer_type'] ?? 'administrative') === 'request' ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= ($transfer['transfer_type'] ?? 'administrative') === 'request' ? 'Por solicitud' : 'Administrativo' ?></span></td>
                        <td><?= ! empty($transfer['request_folio']) ? esc($transfer['request_folio']) : '—' ?></td>
                        <td><?= esc($transfer['origin_name']) ?></td>
                        <td><?= esc($transfer['destination_name']) ?></td>
                        <td><?= (int) $transfer['unit_count'] ?></td>
                        <td><?= number_format((float) $transfer['shipped_kg'], 3) ?></td>
                        <td><?= esc($transfer['requested_at']) ?></td>
                        <td><span class="badge-state <?= esc($transfer['status']) ?>"><?= esc($statusLabels[$transfer['status']] ?? $transfer['status']) ?></span></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-transfer-action="view" data-id="<?= (int) $transfer['id'] ?>"><i class="bi bi-eye"></i> Ver</button>
                            <?php if ($originHere && $transfer['status'] === 'requested'): ?>
                                <button class="btn btn-sm btn-success" type="button" data-transfer-action="authorize" data-id="<?= (int) $transfer['id'] ?>">Autorizar</button>
                            <?php elseif ($originHere && in_array($transfer['status'], ['authorized', 'prepared'], true)): ?>
                                <button class="btn btn-sm btn-primary" type="button" data-transfer-action="ship" data-id="<?= (int) $transfer['id'] ?>">Embarcar</button>
                            <?php elseif ($destinationHere && in_array($transfer['status'], ['in_transit', 'discrepancy'], true)): ?>
                                <button class="btn btn-sm btn-success" type="button" data-transfer-action="receive" data-id="<?= (int) $transfer['id'] ?>" data-destination="<?= (int) $transfer['destination_warehouse_id'] ?>">Recibir</button>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="transferDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h2 class="modal-title fs-5" data-detail-title>Detalle del traspaso</h2><div class="text-secondary small" data-detail-subtitle></div></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" data-transfer-detail><div class="text-center text-secondary py-5">Cargando…</div></div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<div class="modal fade" id="transferReceiveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <form data-receive-form>
            <div class="modal-header"><div><h2 class="modal-title fs-5">Recepción de traspaso</h2><div class="text-secondary small" data-receive-folio></div></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="transfer_id"><input type="hidden" name="destination_warehouse_id">
                <div class="alert alert-info py-2">Marca las tinas físicamente recibidas. Las no marcadas quedarán como <strong>No recibidas</strong>; no se elimina ni ajusta su inventario.</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6"><label class="form-label">Cámara</label><select class="form-select" name="chamber_id" required><option value="">Seleccione…</option></select></div>
                    <div class="col-md-6"><label class="form-label">Fila</label><select class="form-select" name="row_location_id" required disabled><option value="">Seleccione cámara…</option></select></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-semibold">Tinas del traspaso</div><div><button class="btn btn-sm btn-outline-secondary" type="button" data-receive-all>Marcar todas</button></div></div>
                <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th style="width:40px"></th><th>Tina</th><th>Lote</th><th>Especie</th><th>Talla</th><th>Peso kg</th><th>Estado</th></tr></thead><tbody data-receive-lines></tbody></table></div>
            </div>
            <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success no-offline-confirm" type="submit">Confirmar recepción</button></div>
        </form>
    </div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?><script src="/assets/js/transfers.js?v=2.2.2"></script><?= $this->endSection() ?>
