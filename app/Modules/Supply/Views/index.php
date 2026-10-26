<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="/assets/css/requests-history.css?v=1.0.3">
<?= $this->endSection() ?>

<?= $this->section('pageActions') ?>
<div class="d-flex gap-2 flex-wrap justify-content-end">
    <a class="btn btn-primary" href="/solicitudes/almacenes">
        <i class="bi bi-arrow-left-right me-1"></i> Gestionar solicitudes
    </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$warehouseLabel = static function (?string $site, ?string $code, ?string $name): string {
    return implode(' · ', array_values(array_filter(
        [trim((string) $site), trim((string) $code), trim((string) $name)],
        static fn (string $value): bool => $value !== ''
    )));
};

$currentWarehouseId = (int) ($scopeWarehouse['id'] ?? 0);

$statusLabels = [
    'open' => 'Pendiente',
    'accepted' => 'Aceptada',
    'preparing' => 'Preparando',
    'dispatching' => 'En salida',
    'dispatched' => 'En recepción',
    'closed' => 'Cerrada',
    'rejected' => 'Rechazada',
];

$statusClasses = [
    'open' => 'is-pending',
    'accepted' => 'is-accepted',
    'preparing' => 'is-preparing',
    'dispatching' => 'is-dispatching',
    'dispatched' => 'is-dispatched',
    'closed' => 'is-closed',
    'rejected' => 'is-rejected',
];
?>

<div hidden id="requestContext"><?= json_encode([
    'scope' => $scope,
    'scopeWarehouse' => $scopeWarehouse,
    'requesterName' => $requesterName,
    'sizes' => $sizes,
    'canTransfer' => $canTransfer,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></div>

<div class="rh-current-wrap">
        <?php if ($scopeWarehouse === null): ?>
            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle me-1"></i>
                Selecciona un almacén en <strong>Alcance</strong> para consultar las solicitudes actuales relacionadas con ese almacén.
            </div>
        <?php else: ?>

        <div class="rh-kpis">
            <div class="rh-kpi">
                <span class="rh-kpi-icon"><i class="bi bi-files"></i></span>
                <div><small>Total visible</small><strong><?= (int) ($currentSummary['total'] ?? 0) ?></strong></div>
            </div>
            <div class="rh-kpi">
                <span class="rh-kpi-icon"><i class="bi bi-hourglass-split"></i></span>
                <div><small>Pendientes</small><strong><?= (int) ($currentSummary['pending'] ?? 0) ?></strong></div>
            </div>
            <div class="rh-kpi">
                <span class="rh-kpi-icon"><i class="bi bi-arrow-repeat"></i></span>
                <div><small>En proceso</small><strong><?= (int) ($currentSummary['in_progress'] ?? 0) ?></strong></div>
            </div>
            <div class="rh-kpi">
                <span class="rh-kpi-icon"><i class="bi bi-check2-circle"></i></span>
                <div><small>Finalizadas</small><strong><?= (int) ($currentSummary['finalized'] ?? 0) ?></strong></div>
            </div>
        </div>

        <section class="rh-card">
            <div class="rh-card-head">
                <div>
                    <h3>Solicitudes registradas</h3>
                    <p>Datos del flujo vigente de solicitudes entre almacenes.</p>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="rh-clear-filters">
                    <i class="bi bi-eraser me-1"></i> Limpiar filtros
                </button>
            </div>

            <div class="rh-filter-grid">
                <div class="rh-filter-search">
                    <label class="form-label" for="rh-search">Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input class="form-control" id="rh-search" type="search" placeholder="Folio, almacén, empresa o solicitante">
                    </div>
                </div>
                <div>
                    <label class="form-label" for="rh-type">Tipo</label>
                    <select class="form-select" id="rh-type">
                        <option value="">Todos</option>
                        <option value="transfer">Traspaso interno</option>
                        <option value="production">Materia prima</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="rh-status">Estado</label>
                    <select class="form-select" id="rh-status">
                        <option value="">Todos</option>
                        <option value="open">Pendiente</option>
                        <option value="accepted">Aceptada</option>
                        <option value="preparing">Preparando</option>
                        <option value="dispatching">En salida</option>
                        <option value="dispatched">En recepción</option>
                        <option value="closed">Cerrada</option>
                        <option value="rejected">Rechazada</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="rh-relation">Relación</label>
                    <select class="form-select" id="rh-relation">
                        <option value="">Todas</option>
                        <option value="receiver">Solicité producto</option>
                        <option value="provider">Me solicitan producto</option>
                    </select>
                </div>
            </div>

            <div class="rh-table-wrap">
                <table class="table align-middle mb-0 rh-table">
                    <thead>
                    <tr>
                        <th>Solicitud</th>
                        <th>Proveedor → destino</th>
                        <th>Requerido</th>
                        <th>Producto solicitado</th>
                        <th>Relación</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                    </thead>
                    <tbody id="rh-current-body">
                    <?php foreach ($currentRequests as $request):
                        $type = (string) ($request['request_type'] ?? 'transfer');
                        $status = (string) ($request['status'] ?? '');
                        $relation = ((int) ($request['destination_warehouse_id'] ?? 0) === $currentWarehouseId) ? 'receiver' : 'provider';

                        $source = trim(implode(' · ', array_filter([
                            (string) ($request['source_company_name'] ?? ''),
                            (string) ($request['source_site_name'] ?? ''),
                            (string) ($request['source_warehouse_code'] ?? ''),
                            (string) ($request['source_warehouse_name'] ?? ''),
                        ], static fn (string $value): bool => trim($value) !== '')));

                        $destination = trim(implode(' · ', array_filter([
                            (string) ($request['destination_company_name'] ?? ''),
                            (string) ($request['destination_site_name'] ?? ''),
                            (string) ($request['destination_warehouse_code'] ?? ''),
                            (string) ($request['destination_warehouse_name'] ?? ''),
                        ], static fn (string $value): bool => trim($value) !== '')));

                        $searchText = mb_strtolower(implode(' ', [
                            (string) ($request['folio'] ?? ''),
                            $source,
                            $destination,
                            (string) ($request['requester_username'] ?? ''),
                        ]));
                    ?>
                    <tr
                        data-rh-row
                        data-type="<?= esc($type, 'attr') ?>"
                        data-status="<?= esc($status, 'attr') ?>"
                        data-relation="<?= esc($relation, 'attr') ?>"
                        data-search="<?= esc($searchText, 'attr') ?>"
                    >
                        <td>
                            <div class="rh-folio"><?= esc($request['folio']) ?></div>
                            <div class="rh-meta">
                                <span class="rh-type <?= $type === 'production' ? 'is-production' : 'is-transfer' ?>">
                                    <i class="bi <?= $type === 'production' ? 'bi-box-seam' : 'bi-arrow-left-right' ?>"></i>
                                    <?= $type === 'production' ? 'Materia prima' : 'Traspaso interno' ?>
                                </span>
                                <span><?= esc($request['created_at']) ?></span>
                            </div>
                            <small class="text-secondary"><?= esc($request['requester_username'] ?? 'Usuario') ?></small>
                        </td>
                        <td>
                            <div class="rh-route">
                                <div><span>Proveedor</span><strong><?= esc($source !== '' ? $source : '—') ?></strong></div>
                                <i class="bi bi-arrow-right"></i>
                                <div><span>Destino</span><strong><?= esc($destination !== '' ? $destination : '—') ?></strong></div>
                            </div>
                        </td>
                        <td class="text-nowrap">
                            <strong><?= esc($request['required_at']) ?></strong>
                        </td>
                        <td>
                            <div class="rh-product-summary">
                                <strong><?= number_format((float) ($request['requested_kg'] ?? 0), 3) ?> kg</strong>
                                <span><?= (int) ($request['line_count'] ?? 0) ?> línea(s)</span>
                                <?php if ((int) ($request['requested_units'] ?? 0) > 0): ?>
                                    <span><?= (int) $request['requested_units'] ?> tina(s) requeridas</span>
                                <?php endif ?>
                                <span><?= (int) ($request['linked_units'] ?? 0) ?> tina(s) vinculadas</span>
                            </div>
                        </td>
                        <td>
                            <span class="rh-relation <?= $relation === 'receiver' ? 'is-receiver' : 'is-provider' ?>">
                                <?= $relation === 'receiver' ? 'Solicité producto' : 'Me solicitan producto' ?>
                            </span>
                        </td>
                        <td>
                            <span class="rh-status <?= esc($statusClasses[$status] ?? 'is-unknown', 'attr') ?>">
                                <?= esc($statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status))) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary rh-detail-button"
                                data-request-id="<?= (int) $request['id'] ?>"
                                data-request-folio="<?= esc($request['folio'], 'attr') ?>"
                            >
                                <i class="bi bi-layout-text-window-reverse me-1"></i> Detalle
                            </button>
                        </td>
                    </tr>
                    <?php endforeach ?>
                    <tr id="rh-empty-row" class="<?= $currentRequests === [] ? '' : 'd-none' ?>">
                        <td colspan="7" class="text-center py-5 text-secondary">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            No hay solicitudes que coincidan con los filtros.
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="rh-table-footer">
                <span id="rh-visible-count"><?= count($currentRequests) ?></span>
                <span>solicitud(es) visibles</span>
            </div>
        </section>

        <div class="modal fade" id="historyRequestModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content rh-detail-modal">
                    <div class="modal-header">
                        <div>
                            <div class="rh-modal-kicker">Expediente de solicitud</div>
                            <h2 class="modal-title fs-5 mb-0" id="historyRequestModalLabel">Solicitud</h2>
                        </div>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body" id="rh-detail-content">
                        <div class="rh-detail-loading">
                            <div class="spinner-border" role="status"></div>
                            <span>Cargando solicitud...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php endif ?>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/requests-history.js?v=1.0.3"></script>
<?= $this->endSection() ?>
