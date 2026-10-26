<?= $this->extend('layouts/app') ?>

<?= $this->section('pageActions') ?>
<?php if ($canCreate): ?>
<button class="btn btn-primary btn-operational" type="button" data-bs-toggle="modal" data-bs-target="#requestModal">
    <i class="bi bi-plus-lg"></i> Nueva solicitud
</button>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$warehouseLabel = static function (?string $site, ?string $code, ?string $name): string {
    return implode(' · ', array_values(array_filter(
        [trim((string) $site), trim((string) $code), trim((string) $name)],
        static fn (string $value): bool => $value !== ''
    )));
};
?>
<div hidden id="requestContext"><?= json_encode([
    'scope' => $scope,
    'scopeWarehouse' => $scopeWarehouse,
    'requesterName' => $requesterName,
    'sizes' => $sizes,
    'canTransfer' => $canTransfer,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></div>

<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body py-3">
        <div class="d-flex flex-column flex-lg-row gap-3 align-items-lg-center justify-content-between">
            <div>
                <div class="fw-semibold mb-1"><i class="bi bi-diagram-3 me-1"></i> Flujo profesional de solicitudes</div>
                <div class="text-secondary small">La solicitud registra la necesidad. En traspasos, el almacén que necesita material es el destino y el origen se elige después de autorizar, según disponibilidad real.</div>
            </div>
            <div class="d-flex flex-wrap gap-2 small">
                <span class="badge text-bg-light border">1 · Solicitud</span>
                <span class="badge text-bg-light border">2 · Autorización</span>
                <span class="badge text-bg-light border">3 · Origen y reserva</span>
                <span class="badge text-bg-light border">4 · Traspaso / surtido</span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Demanda de materia prima</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" data-datatable data-export-title="Solicitudes-materia-prima">
                <thead>
                <tr>
                    <th>Folio</th><th>Tipo</th><th>Solicitante</th><th>Almacén surtidor / origen</th><th>Destino</th><th>Prioridad</th><th>Requerido</th><th>Solicitado kg</th><th>Reservado kg</th><th>Cobertura</th><th>Estado</th><th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $request):
                    $coverage = (float) $request['requested_kg'] > 0 ? (float) $request['reserved_kg'] / (float) $request['requested_kg'] * 100 : 0;
                    $isTransferRequest = ($request['request_type'] ?? 'production') === 'warehouse_transfer';
                    $sourceLabel = $isTransferRequest
                        ? 'Se define al surtir'
                        : (! empty($request['source_warehouse_id'])
                            ? $warehouseLabel($request['source_site_name'] ?? null, $request['source_warehouse_code'] ?? null, $request['source_warehouse_name'] ?? null)
                            : 'Solicitud histórica · origen por instalación');
                    $destinationLabel = $isTransferRequest
                        ? $warehouseLabel($request['destination_site_name'] ?? null, $request['destination_warehouse_code'] ?? null, $request['destination_warehouse_name'] ?? null)
                        : (string) $request['destination'];
                ?>
                    <tr>
                        <td class="fw-bold"><?= esc($request['folio']) ?></td>
                        <td><span class="badge <?= $isTransferRequest ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= $isTransferRequest ? 'Traspaso' : 'Producción' ?></span></td>
                        <td><?= esc($request['requester_username'] ?? '—') ?></td>
                        <td>
                            <?php if ($isTransferRequest): ?>
                                <span class="text-secondary"><i class="bi bi-hourglass-split me-1"></i><?= esc($sourceLabel) ?></span>
                            <?php else: ?>
                                <?= esc($sourceLabel) ?>
                            <?php endif ?>
                        </td>
                        <td><?= esc($destinationLabel !== '' ? $destinationLabel : $request['destination']) ?></td>
                        <td><?= esc($request['priority']) ?></td>
                        <td><?= esc($request['required_at']) ?></td>
                        <td><?= number_format((float) $request['requested_kg'], 3) ?></td>
                        <td><?= number_format((float) $request['reserved_kg'], 3) ?></td>
                        <td>
                            <div class="progress" role="progressbar" aria-valuenow="<?= (int) min(100, $coverage) ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar" style="width:<?= (int) min(100, $coverage) ?>%"><?= number_format($coverage, 0) ?>%</div>
                            </div>
                        </td>
                        <td><span class="badge-state <?= esc($request['status']) ?>"><?= esc(str_replace('_', ' ', $request['status'])) ?></span></td>
                        <td class="text-nowrap">
                            <?php if ($canApprove && $request['status'] === 'requested'): ?>
                                <button class="btn btn-sm btn-success" data-request-authorize="<?= (int) $request['id'] ?>" data-approved="1">Autorizar</button>
                                <button class="btn btn-sm btn-outline-danger" data-request-authorize="<?= (int) $request['id'] ?>" data-approved="0">Rechazar</button>
                            <?php elseif ($canReserve && in_array($request['status'], ['authorized', 'reserved', 'partially_fulfilled'], true)): ?>
                                <button class="btn btn-sm btn-primary" data-request-reserve="<?= (int) $request['id'] ?>" data-request-type="<?= esc($request['request_type'] ?? 'production', 'attr') ?>">
                                    <?= $isTransferRequest ? 'Analizar disponibilidad' : 'Seleccionar unidades' ?>
                                </button>
                            <?php else: ?>—<?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canReserve): ?>
<div class="card mt-4">
    <div class="card-header">
        <div>
            <span>Reservas y surtido</span>
            <small class="d-block text-secondary">La reserva fija físicamente qué tinas se utilizarán. En traspaso, todas deben pertenecer a un único almacén origen.</small>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" data-datatable data-export-title="Reservas-surtido">
                <thead><tr><th>Reserva</th><th>Solicitud</th><th>Tipo</th><th>Origen</th><th>Destino</th><th>Unidades</th><th>Reservado kg</th><th>Surtido/Recibido kg</th><th>Traspaso</th><th>Vence</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($reservations as $reservation):
                    $isTransferReservation = ($reservation['request_type'] ?? 'production') === 'warehouse_transfer';
                    $originLabel = $warehouseLabel($reservation['origin_site_name'] ?? null, $reservation['origin_warehouse_code'] ?? null, $reservation['origin_warehouse_name'] ?? null);
                    $destinationLabel = $isTransferReservation
                        ? $warehouseLabel($reservation['destination_site_name'] ?? null, $reservation['destination_warehouse_code'] ?? null, $reservation['destination_warehouse_name'] ?? null)
                        : (string) $reservation['destination'];
                ?>
                    <tr>
                        <td class="fw-bold"><?= esc($reservation['folio']) ?></td>
                        <td><?= esc($reservation['request_folio']) ?></td>
                        <td><span class="badge <?= $isTransferReservation ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= $isTransferReservation ? 'Traspaso' : 'Producción' ?></span></td>
                        <td><?= esc($originLabel !== '' ? $originLabel : '—') ?></td>
                        <td><?= esc($destinationLabel !== '' ? $destinationLabel : $reservation['destination']) ?></td>
                        <td><?= (int) $reservation['unit_count'] ?></td>
                        <td><?= number_format((float) $reservation['reserved_kg'], 3) ?></td>
                        <td><?= number_format((float) $reservation['fulfilled_kg'], 3) ?></td>
                        <td><?= ! empty($reservation['transfer_folios']) ? esc($reservation['transfer_folios']) : '—' ?></td>
                        <td><?= esc($reservation['expires_at']) ?></td>
                        <td><span class="badge-state <?= esc($reservation['status']) ?>"><?= esc(str_replace('_', ' ', $reservation['status'])) ?></span></td>
                        <td class="text-nowrap">
                            <?php if ($isTransferReservation): ?>
                                <?php if ($reservation['status'] === 'active' && (int) $reservation['transfer_count'] === 0 && $canTransfer): ?>
                                    <button class="btn btn-sm btn-primary no-offline-confirm" data-reservation-transfer="<?= (int) $reservation['id'] ?>" data-reservation-folio="<?= esc($reservation['folio'], 'attr') ?>">Generar traspaso</button>
                                <?php elseif ($reservation['status'] === 'active' && (int) $reservation['transfer_count'] === 0 && ! $canTransfer): ?>
                                    <span class="text-secondary small">Requiere permiso de traspasos</span>
                                <?php elseif (in_array($reservation['status'], ['in_transfer', 'fulfilled'], true)): ?>
                                    <span class="text-secondary small">Seguimiento en Traspasos</span>
                                <?php endif ?>
                            <?php else: ?>
                                <?php if ($reservation['status'] === 'active'): ?>
                                    <button class="btn btn-sm btn-success no-offline-confirm" data-reservation-fulfill="<?= (int) $reservation['id'] ?>" data-reservation-folio="<?= esc($reservation['folio'], 'attr') ?>">Surtir</button>
                                <?php endif ?>
                            <?php endif ?>

                            <?php if ($reservation['status'] === 'active'): ?>
                                <button class="btn btn-sm btn-outline-danger no-offline-confirm" data-reservation-release="<?= (int) $reservation['id'] ?>" data-reservation-folio="<?= esc($reservation['folio'], 'attr') ?>">Liberar</button>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif ?>

<?php if ($canCreate): ?>
<div class="modal fade" id="requestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form data-request-form>
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5 mb-1">Nueva solicitud de materia prima</h2>
                        <small class="text-secondary">Registra la necesidad; el sistema separa producción de movimientos entre almacenes.</small>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body industrial-form">
                    <input type="hidden" name="company_id" value="<?= (int) $scope['company_id'] ?>">
                    <input type="hidden" name="site_id" value="<?= (int) $scope['site_id'] ?>">

                    <?php if (! $scopeWarehouse): ?>
                        <div class="alert alert-warning">
                            <strong>Falta almacén operativo.</strong> Selecciona empresa, instalación y almacén en <strong>Cambiar alcance</strong> antes de enviar una solicitud.
                        </div>
                    <?php endif ?>

                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Tipo de solicitud</label>
                            <select class="form-select" name="request_type" required>
                                <option value="production">Salida a producción</option>
                                <option value="warehouse_transfer">Traspaso entre almacenes</option>
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label">Requerido</label><input class="form-control" name="required_at" type="datetime-local" required></div>
                        <div class="col-md-3"><label class="form-label">Prioridad</label><select class="form-select" name="priority"><option value="normal">Normal</option><option value="high">Alta</option><option value="urgent">Urgente</option><option value="low">Baja</option></select></div>

                        <div class="col-12" data-production-context>
                            <div class="border rounded-3 p-3 bg-body-tertiary">
                                <div class="row g-3 align-items-start">
                                    <div class="col-md-4">
                                        <div class="small text-secondary">Solicitante</div>
                                        <div class="fw-semibold"><?= esc($requesterName) ?></div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="small text-secondary">Almacén que surtirá producción</div>
                                        <div class="fw-semibold"><?= $scopeWarehouse ? esc($scopeWarehouse['site_name'] . ' · ' . $scopeWarehouse['code'] . ' · ' . $scopeWarehouse['name']) : 'Sin almacén seleccionado' ?></div>
                                    </div>
                                </div>
                                <div class="small text-secondary mt-2">Las tinas de esta solicitud sólo podrán reservarse en este almacén. El destino siguiente es un proceso productivo, no otro almacén.</div>
                            </div>
                        </div>

                        <div class="col-12" data-production-destination>
                            <label class="form-label">Destino de producción</label>
                            <input class="form-control" name="destination" maxlength="120" placeholder="Ej. Limpieza / Línea 1">
                        </div>

                        <div class="col-12" data-transfer-context hidden>
                            <div class="border border-primary-subtle rounded-3 p-3 bg-primary-subtle">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="small text-secondary">Solicitante</div>
                                        <div class="fw-semibold"><?= esc($requesterName) ?></div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="small text-secondary">Almacén solicitante / destino</div>
                                        <div class="fw-semibold"><?= $scopeWarehouse ? esc($scopeWarehouse['site_name'] . ' · ' . $scopeWarehouse['code'] . ' · ' . $scopeWarehouse['name']) : 'Sin almacén seleccionado' ?></div>
                                    </div>
                                </div>
                                <div class="small mt-2">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Este almacén es quien necesita y recibirá el producto. <strong>El origen no se decide aquí:</strong> después de autorizar se comparará disponibilidad por almacén y se elegirá uno antes de reservar las tinas.
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4"><label class="form-label">Especie</label><select class="form-select" name="species_id" required><option value="">Seleccione…</option><?php foreach ($species as $item): ?><option value="<?= (int) $item['id'] ?>"><?= esc($item['code'] . ' · ' . $item['name']) ?></option><?php endforeach ?></select></div>
                        <div class="col-md-4"><label class="form-label">Talla</label><select class="form-select" name="size_grade_id"><option value="">Cualquier talla</option><?php foreach ($sizes as $item): ?><option value="<?= (int) $item['id'] ?>" data-species="<?= (int) ($item['species_id'] ?? 0) ?>"><?= esc($item['name']) ?></option><?php endforeach ?></select></div>
                        <div class="col-md-2"><label class="form-label">Calidad</label><input class="form-control" name="quality_grade" maxlength="20" placeholder="Opcional"></div>
                        <div class="col-md-2"><label class="form-label">kg solicitados</label><input class="form-control" name="requested_weight_kg" type="number" min="0.001" step="0.001" required></div>
                        <div class="col-12"><label class="form-label">Observaciones</label><textarea class="form-control" name="observations" rows="2" maxlength="1000"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-operational no-offline-confirm" type="submit" <?= ! $scopeWarehouse ? 'disabled' : '' ?>>Enviar solicitud</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif ?>

<div class="modal fade" id="reserveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form data-reserve-form>
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" data-reservation-title>Seleccionar unidades específicas</h2>
                        <small class="text-secondary" data-reservation-help>La disponibilidad se recalcula en el servidor al confirmar.</small>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" data-reservation-candidates><div class="text-center py-5"><div class="spinner-border"></div></div></div>
                <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-operational no-offline-confirm" type="submit" data-reserve-submit>Confirmar reserva</button></div>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?><script src="/assets/js/requests.js?v=3.0.0"></script><?= $this->endSection() ?>
