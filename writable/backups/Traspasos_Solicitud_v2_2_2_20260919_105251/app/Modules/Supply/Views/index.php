<?= $this->extend('layouts/app') ?>

<?= $this->section('pageActions') ?>
<?php if ($canCreate): ?>
<button class="btn btn-primary btn-operational" type="button" data-bs-toggle="modal" data-bs-target="#requestModal">
    <i class="bi bi-plus-lg"></i> Nueva solicitud
</button>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div hidden id="requestContext"><?= json_encode([
    'scope' => $scope,
    'sizes' => $sizes,
    'warehouses' => $destinationWarehouses,
    'canTransfer' => $canTransfer,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></div>

<div class="alert alert-info d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div>
        <strong>Dos tipos de solicitud.</strong>
        La salida a producción conserva el flujo actual. La solicitud de traspaso reserva tinas completas y, después, genera el traspaso logístico ligado a la solicitud.
    </div>
</div>

<div class="card">
    <div class="card-header">Demanda de materia prima</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" data-datatable data-export-title="Solicitudes-materia-prima">
                <thead>
                <tr>
                    <th>Folio</th><th>Tipo</th><th>Destino</th><th>Prioridad</th><th>Requerido</th><th>Líneas</th><th>Solicitado kg</th><th>Reservado kg</th><th>Cobertura</th><th>Estado</th><th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $request):
                    $coverage = (float) $request['requested_kg'] > 0 ? (float) $request['reserved_kg'] / (float) $request['requested_kg'] * 100 : 0;
                    $isTransferRequest = ($request['request_type'] ?? 'production') === 'warehouse_transfer';
                ?>
                    <tr>
                        <td class="fw-bold"><?= esc($request['folio']) ?></td>
                        <td>
                            <span class="badge <?= $isTransferRequest ? 'text-bg-primary' : 'text-bg-secondary' ?>">
                                <?= $isTransferRequest ? 'Traspaso' : 'Producción' ?>
                            </span>
                        </td>
                        <td><?= esc($request['destination']) ?></td>
                        <td><?= esc($request['priority']) ?></td>
                        <td><?= esc($request['required_at']) ?></td>
                        <td><?= (int) $request['line_count'] ?></td>
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
                                <button class="btn btn-sm btn-primary" data-request-reserve="<?= (int) $request['id'] ?>" data-request-type="<?= esc($request['request_type'] ?? 'production', 'attr') ?>">Seleccionar unidades</button>
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
            <small class="d-block text-secondary">Producción se surte como salida. En traspaso, la reserva se convierte en uno o más traspasos ligados y la existencia sólo cambia de almacén.</small>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" data-datatable data-export-title="Reservas-surtido">
                <thead><tr><th>Reserva</th><th>Solicitud</th><th>Tipo</th><th>Destino</th><th>Unidades</th><th>Reservado kg</th><th>Surtido/Recibido kg</th><th>Traspaso</th><th>Vence</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($reservations as $reservation):
                    $isTransferReservation = ($reservation['request_type'] ?? 'production') === 'warehouse_transfer';
                ?>
                    <tr>
                        <td class="fw-bold"><?= esc($reservation['folio']) ?></td>
                        <td><?= esc($reservation['request_folio']) ?></td>
                        <td><span class="badge <?= $isTransferReservation ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= $isTransferReservation ? 'Traspaso' : 'Producción' ?></span></td>
                        <td><?= esc($reservation['destination']) ?></td>
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
                    <div><h2 class="modal-title fs-5 mb-1">Nueva solicitud de materia prima</h2><small class="text-secondary">Define si termina en producción o en otro almacén.</small></div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body industrial-form">
                    <input type="hidden" name="company_id" value="<?= (int) $scope['company_id'] ?>">
                    <input type="hidden" name="site_id" value="<?= (int) $scope['site_id'] ?>">
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

                        <div class="col-12" data-production-destination>
                            <label class="form-label">Destino de producción</label>
                            <input class="form-control" name="destination" maxlength="120" placeholder="Ej. Limpieza / Línea 1">
                        </div>

                        <div class="col-12" data-transfer-destination hidden>
                            <label class="form-label">Almacén destino</label>
                            <select class="form-select" name="destination_warehouse_id">
                                <option value="">Seleccione…</option>
                                <?php foreach ($destinationWarehouses as $warehouse): ?>
                                    <option value="<?= (int) $warehouse['id'] ?>"><?= esc($warehouse['site_name'] . ' · ' . $warehouse['code'] . ' · ' . $warehouse['name']) ?></option>
                                <?php endforeach ?>
                            </select>
                            <div class="form-text">La solicitud se administra desde tu alcance actual; al reservar sólo se mostrarán tinas del origen y nunca del almacén destino.</div>
                        </div>

                        <div class="col-md-4"><label class="form-label">Especie</label><select class="form-select" name="species_id" required><option value="">Seleccione…</option><?php foreach ($species as $item): ?><option value="<?= (int) $item['id'] ?>"><?= esc($item['code'] . ' · ' . $item['name']) ?></option><?php endforeach ?></select></div>
                        <div class="col-md-4"><label class="form-label">Talla</label><select class="form-select" name="size_grade_id"><option value="">Cualquier talla</option><?php foreach ($sizes as $item): ?><option value="<?= (int) $item['id'] ?>" data-species="<?= (int) ($item['species_id'] ?? 0) ?>"><?= esc($item['name']) ?></option><?php endforeach ?></select></div>
                        <div class="col-md-2"><label class="form-label">Calidad</label><input class="form-control" name="quality_grade" maxlength="20" placeholder="Opcional"></div>
                        <div class="col-md-2"><label class="form-label">kg solicitados</label><input class="form-control" name="requested_weight_kg" type="number" min="0.001" step="0.001" required></div>
                        <div class="col-12"><label class="form-label">Observaciones</label><textarea class="form-control" name="observations" rows="2" maxlength="1000"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-operational no-offline-confirm" type="submit">Enviar solicitud</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif ?>

<div class="modal fade" id="reserveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form data-reserve-form>
                <div class="modal-header"><div><h2 class="modal-title fs-5">Seleccionar unidades específicas</h2><small data-reservation-help>La disponibilidad se recalcula en el servidor al confirmar.</small></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
                <div class="modal-body" data-reservation-candidates><div class="text-center py-5"><div class="spinner-border"></div></div></div>
                <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-operational no-offline-confirm" type="submit">Confirmar reserva</button></div>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?><script src="/assets/js/requests.js?v=2.1.0"></script><?= $this->endSection() ?>
