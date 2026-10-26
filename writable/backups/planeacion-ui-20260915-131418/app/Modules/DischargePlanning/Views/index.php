<?= $this->extend('layouts/app') ?>

<?= $this->section('pageActions') ?>
<div class="d-flex flex-wrap gap-2">
    <?php if ($canManageVoyages): ?>
        <button class="btn btn-outline-primary btn-operational" type="button" data-bs-toggle="modal" data-bs-target="#voyageModal">
            <i class="bi bi-water"></i> Nueva marea
        </button>
        <button class="btn btn-outline-primary btn-operational" type="button" data-bs-toggle="modal" data-bs-target="#rsaModal">
            <i class="bi bi-file-earmark-check"></i> Registrar RSA
        </button>
    <?php endif ?>

    <?php if ($canManagePlans): ?>
        <button class="btn btn-primary btn-operational" type="button" data-plan-new data-bs-toggle="modal" data-bs-target="#planModal">
            <i class="bi bi-calendar-plus"></i> Nuevo plan
        </button>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<link rel="stylesheet" href="/assets/css/planning-ui.css">
<?php
$openPlans = array_values(array_filter(
    $plans,
    static fn (array $plan): bool => ! in_array($plan['status'], ['finished', 'canceled'], true)
));

$historyPlans = array_values(array_filter(
    $plans,
    static fn (array $plan): bool => in_array($plan['status'], ['finished', 'canceled'], true)
));

$validRsaCount = count(array_filter(
    $rsas,
    static function (array $rsa): bool {
        $today = date('Y-m-d');
        return ($rsa['issued_on'] === null || $rsa['issued_on'] <= $today)
            && ($rsa['expires_on'] === null || $rsa['expires_on'] >= $today);
    }
));

$statusLabels = [
    'planned' => 'Planeada',
    'unloading' => 'Descargando',
    'closed' => 'Cerrada',
    'canceled' => 'Cancelada',
    'draft' => 'Borrador',
    'approved' => 'Aprobado',
    'in_progress' => 'En operación',
    'paused' => 'Pausado',
    'finished' => 'Finalizado',
];
?>

<div hidden id="planningContext"><?= json_encode([
    'scope' => $scope,
    'voyages' => $voyages,
    'allVoyages' => $allVoyages,
    'voyageNumbers' => $voyageNumbers,
    'tanks' => $tanks,
    'rsas' => $rsas,
    'sizes' => $sizes,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card metric-card h-100" style="--accent:#00a6c7">
            <div class="card-body">
                <div class="metric-label">Mareas activas</div>
                <div class="metric-value"><?= count($voyages) ?></div>
                <small>Planeadas o descargando</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card metric-card h-100" style="--accent:#16845b">
            <div class="card-body">
                <div class="metric-label">RSA vigentes hoy</div>
                <div class="metric-value"><?= $validRsaCount ?></div>
                <small>La vigencia final se valida contra el inicio del plan</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card metric-card h-100" style="--accent:#d97706">
            <div class="card-body">
                <div class="metric-label">Planes abiertos</div>
                <div class="metric-value"><?= count($openPlans) ?></div>
                <small>Borrador a operación</small>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <span class="fw-semibold">Mareas activas</span>
            <div class="small text-secondary">La identidad de la marea es embarcación + año + número anual.</div>
        </div>
        <span class="badge text-bg-light"><?= count($voyages) ?></span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>Embarcación</th>
                    <th>Marea</th>
                    <th>Arribo</th>
                    <th>Estado</th>
                    <th>Planes abiertos</th>
                    <th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($voyages as $voyage): ?>
                    <?php $openCount = $openPlanCountByVoyage[(int) $voyage['id']] ?? 0; ?>
                    <tr>
                        <td><strong><?= esc($voyage['vessel_code']) ?></strong><br><small class="text-secondary"><?= esc($voyage['vessel_name']) ?></small></td>
                        <td><?= (int) $voyage['annual_number'] ?>/<?= (int) $voyage['year'] ?></td>
                        <td><?= esc($voyage['arrival_at'] ?? 'Sin definir') ?></td>
                        <td><span class="badge-state <?= esc($voyage['status']) ?>"><?= esc($statusLabels[$voyage['status']] ?? $voyage['status']) ?></span></td>
                        <td><?= $openCount ?></td>
                        <td class="text-nowrap">
                            <?php if ($canManageVoyages): ?>
                                <?php if ($voyage['status'] === 'unloading'): ?>
                                    <button class="btn btn-sm btn-success" type="button" data-voyage-transition="<?= (int) $voyage['id'] ?>" data-target="closed" data-label="<?= esc($voyage['vessel_code'] . ' · ' . $voyage['annual_number'] . '/' . $voyage['year']) ?>">Cerrar</button>
                                <?php endif ?>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-voyage-transition="<?= (int) $voyage['id'] ?>" data-target="canceled" data-open-plans="<?= $openCount ?>" data-label="<?= esc($voyage['vessel_code'] . ' · ' . $voyage['annual_number'] . '/' . $voyage['year']) ?>">Cancelar</button>
                            <?php else: ?>—<?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($voyages === []): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">No hay mareas activas en el alcance seleccionado.</td></tr>
                <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <span class="fw-semibold">Planes abiertos</span>
            <div class="small text-secondary">Revisa el detalle completo antes de aprobar.</div>
        </div>
        <span class="badge text-bg-light"><?= count($openPlans) ?></span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle"<?= $openPlans !== [] ? ' data-datatable data-export-title="Planes-descarga-abiertos"' : '' ?>>
                <thead>
                <tr>
                    <th>Folio</th><th>Embarcación</th><th>Marea</th><th>RSA</th><th>Ventana</th><th>Composición</th><th>Planeado</th><th>Estado</th><th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($openPlans as $plan): ?>
                    <tr>
                        <td class="fw-bold"><?= esc($plan['folio']) ?></td>
                        <td><strong><?= esc($plan['vessel_code']) ?></strong><br><small class="text-secondary"><?= esc($plan['vessel_name']) ?></small></td>
                        <td><?= (int) $plan['annual_number'] ?>/<?= (int) $plan['year'] ?></td>
                        <td>
                            <?= esc($plan['rsa_code'] ?? 'Sin RSA') ?>
                            <?php if ($plan['rsa_expires_on'] !== null): ?>
                                <br><small class="<?= $plan['rsa_expires_on'] < date('Y-m-d') ? 'text-danger' : 'text-secondary' ?>">Vence <?= esc($plan['rsa_expires_on']) ?></small>
                            <?php endif ?>
                        </td>
                        <td><?= esc($plan['planned_start_at'] ?? 'Sin definir') ?><br><small><?= esc($plan['planned_end_at'] ?? '') ?></small></td>
                        <td><?= (int) $plan['composition_count'] ?> línea(s)</td>
                        <td><?= number_format((float) $plan['planned_weight_kg'] / 1000, 3) ?> t</td>
                        <td><span class="badge-state <?= esc($plan['status']) ?>"><?= esc($statusLabels[$plan['status']] ?? $plan['status']) ?></span></td>
                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-plan-view="<?= (int) $plan['id'] ?>"><i class="bi bi-eye"></i> Ver</button>

                            <?php if ($plan['status'] === 'draft'): ?>
                                <button class="btn btn-sm btn-outline-primary" type="button" data-plan-edit="<?= (int) $plan['id'] ?>">Editar</button>
                                <button class="btn btn-sm btn-success" type="button" data-plan-review="<?= (int) $plan['id'] ?>">Revisar / aprobar</button>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-plan-transition="<?= (int) $plan['id'] ?>" data-target="canceled">Cancelar</button>
                            <?php elseif ($plan['status'] === 'approved'): ?>
                                <button class="btn btn-sm btn-primary" type="button" data-plan-transition="<?= (int) $plan['id'] ?>" data-target="in_progress">Iniciar</button>
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-plan-transition="<?= (int) $plan['id'] ?>" data-target="draft">Volver a borrador</button>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-plan-transition="<?= (int) $plan['id'] ?>" data-target="canceled">Cancelar</button>
                            <?php elseif ($plan['status'] === 'in_progress'): ?>
                                <button class="btn btn-sm btn-outline-warning" type="button" data-plan-transition="<?= (int) $plan['id'] ?>" data-target="paused">Pausar</button>
                                <button class="btn btn-sm btn-success" type="button" data-plan-transition="<?= (int) $plan['id'] ?>" data-target="finished">Finalizar</button>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-plan-transition="<?= (int) $plan['id'] ?>" data-target="canceled">Cancelar</button>
                            <?php elseif ($plan['status'] === 'paused'): ?>
                                <button class="btn btn-sm btn-primary" type="button" data-plan-transition="<?= (int) $plan['id'] ?>" data-target="in_progress">Reanudar</button>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-plan-transition="<?= (int) $plan['id'] ?>" data-target="canceled">Cancelar</button>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if ($openPlans === []): ?>
                    <tr><td colspan="9" class="text-center text-secondary py-4">No hay planes abiertos.</td></tr>
                <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="fw-semibold">Historial</span>
        <div class="small text-secondary">Los registros cancelados o finalizados no desaparecen; se conservan para trazabilidad.</div>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#historyVoyages" type="button">Mareas (<?= count($voyageHistory) ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#historyPlans" type="button">Planes (<?= count($historyPlans) ?>)</button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="historyVoyages">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead><tr><th>Embarcación</th><th>Marea</th><th>Estado</th><th>Arribo</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($voyageHistory as $voyage): ?>
                            <tr>
                                <td><strong><?= esc($voyage['vessel_code']) ?></strong><br><small class="text-secondary"><?= esc($voyage['vessel_name']) ?></small></td>
                                <td><?= (int) $voyage['annual_number'] ?>/<?= (int) $voyage['year'] ?></td>
                                <td><span class="badge-state <?= esc($voyage['status']) ?>"><?= esc($statusLabels[$voyage['status']] ?? $voyage['status']) ?></span></td>
                                <td><?= esc($voyage['arrival_at'] ?? 'Sin definir') ?></td>
                                <td>
                                    <?php if ($voyage['status'] === 'canceled' && $canManageVoyages): ?>
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-voyage-transition="<?= (int) $voyage['id'] ?>" data-target="planned" data-label="<?= esc($voyage['vessel_code'] . ' · ' . $voyage['annual_number'] . '/' . $voyage['year']) ?>">Restaurar</button>
                                    <?php else: ?>—<?php endif ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                        <?php if ($voyageHistory === []): ?><tr><td colspan="5" class="text-center text-secondary py-3">Sin historial de mareas.</td></tr><?php endif ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="tab-pane fade" id="historyPlans">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead><tr><th>Folio</th><th>Embarcación</th><th>Marea</th><th>Estado</th><th>Planeado</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($historyPlans as $plan): ?>
                            <tr>
                                <td class="fw-bold"><?= esc($plan['folio']) ?></td>
                                <td><?= esc($plan['vessel_code'] . ' · ' . $plan['vessel_name']) ?></td>
                                <td><?= (int) $plan['annual_number'] ?>/<?= (int) $plan['year'] ?></td>
                                <td><span class="badge-state <?= esc($plan['status']) ?>"><?= esc($statusLabels[$plan['status']] ?? $plan['status']) ?></span></td>
                                <td><?= number_format((float) $plan['planned_weight_kg'] / 1000, 3) ?> t</td>
                                <td class="text-nowrap">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-plan-view="<?= (int) $plan['id'] ?>"><i class="bi bi-eye"></i> Ver</button>
                                    <?php if ($plan['status'] === 'canceled' && in_array($plan['voyage_status'], ['planned', 'unloading'], true)): ?>
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-plan-duplicate="<?= (int) $plan['id'] ?>">Duplicar</button>
                                    <?php elseif ($plan['status'] === 'canceled' && $plan['voyage_status'] === 'canceled'): ?>
                                        <span class="badge text-bg-light">Restaura la marea primero</span>
                                    <?php endif ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                        <?php if ($historyPlans === []): ?><tr><td colspan="6" class="text-center text-secondary py-3">Sin historial de planes.</td></tr><?php endif ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- NUEVA MAREA -->
<div class="modal fade" id="voyageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form data-voyage-form>
                <div class="modal-header">
                    <div><h2 class="modal-title fs-5">Nueva marea</h2><small class="text-secondary">La embarcación puede pertenecer a otra empresa propietaria.</small></div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body industrial-form">
                    <div class="alert alert-info">Los tanques físicos activos de la embarcación se copiarán como fotografía histórica de la marea.</div>
                    <div class="alert alert-warning d-none" id="voyageDuplicateWarning"></div>
                    <div class="row g-3">
                        <input type="hidden" name="company_id" value="<?= (int) $scope['company_id'] ?>">
                        <input type="hidden" name="site_id" value="<?= (int) $scope['site_id'] ?>">
                        <div class="col-md-6">
                            <label class="form-label">Embarcación</label>
                            <select class="form-select" name="vessel_id" id="voyageVessel" required>
                                <option value="">Seleccione…</option>
                                <?php foreach ($vessels as $vessel): ?><option value="<?= (int) $vessel['id'] ?>"><?= esc($vessel['code'] . ' · ' . $vessel['name']) ?></option><?php endforeach ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Número anual</label>
                            <input class="form-control" type="number" name="annual_number" id="voyageAnnualNumber" min="1" required>
                            <div class="form-text" id="voyageNumberHelp">Selecciona embarcación y año.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Año</label>
                            <input class="form-control" type="number" name="year" id="voyageYear" min="2020" max="2100" value="<?= (int) date('Y') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Frigorífico receptor</label>
                            <select class="form-select" name="receiving_warehouse_id">
                                <option value="">Sin predeterminar</option>
                                <?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>"><?= esc($warehouse['code'] . ' · ' . $warehouse['name']) ?></option><?php endforeach ?>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label">Puerto</label><input class="form-control" name="port_name" maxlength="120" value="Manzanillo"></div>
                        <div class="col-md-6"><label class="form-label">Arribo previsto</label><input class="form-control" type="datetime-local" name="arrival_at"></div>
                        <div class="col-md-6"><label class="form-label">Notas</label><input class="form-control" name="notes" maxlength="1000"></div>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary no-offline-confirm" type="submit">Crear marea</button></div>
            </form>
        </div>
    </div>
</div>

<!-- RSA -->
<div class="modal fade" id="rsaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form data-rsa-form>
                <div class="modal-header"><h2 class="modal-title fs-5">Registrar RSA</h2><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
                <div class="modal-body industrial-form">
                    <div class="mb-3"><label class="form-label">Marea</label><select class="form-select" name="voyage_id" required><option value="">Seleccione…</option><?php foreach ($voyages as $voyage): ?><option value="<?= (int) $voyage['id'] ?>"><?= esc($voyage['vessel_code'] . ' · ' . $voyage['annual_number'] . '/' . $voyage['year']) ?></option><?php endforeach ?></select></div>
                    <div class="mb-3"><label class="form-label">Código RSA</label><input class="form-control" name="code" maxlength="80" required></div>
                    <div class="row g-3"><div class="col-6"><label class="form-label">Emisión</label><input class="form-control" type="date" name="issued_on"></div><div class="col-6"><label class="form-label">Vencimiento</label><input class="form-control" type="date" name="expires_on"></div></div>
                </div>
                <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary no-offline-confirm" type="submit">Registrar RSA</button></div>
            </form>
        </div>
    </div>
</div>

<!-- CREAR / EDITAR PLAN -->
<div class="modal fade" id="planModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable planning-modal-dialog">
        <div class="modal-content planning-modal-content">
            <form data-plan-form data-plan-id="" class="planning-plan-form">
                <div class="modal-header planning-modal-header">
                    <div class="d-flex align-items-center gap-3">
                        <div class="planning-modal-icon"><i class="bi bi-calendar2-check"></i></div>
                        <div>
                            <h2 class="modal-title fs-5 mb-1" id="planModalTitle">Nuevo plan de descarga</h2>
                            <small class="text-secondary">Define la ventana y composición. El plan permanece editable mientras esté en borrador.</small>
                        </div>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body industrial-form planning-plan-body">
                    <section class="planning-section planning-section-general mb-3">
                        <div class="planning-section-heading">
                            <div>
                                <span class="planning-section-kicker">01 · Datos generales</span>
                                <h3 class="h6 mb-0">Marea, RSA y ventana de operación</h3>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-lg-5">
                                <label class="form-label">Marea</label>
                                <select class="form-select" name="voyage_id" id="planVoyage" required>
                                    <option value="">Seleccione…</option>
                                    <?php foreach ($voyages as $voyage): ?><option value="<?= (int) $voyage['id'] ?>"><?= esc($voyage['vessel_code'] . ' · ' . $voyage['annual_number'] . '/' . $voyage['year']) ?></option><?php endforeach ?>
                                </select>
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label">RSA</label>
                                <select class="form-select" name="rsa_id" id="planRsa">
                                    <option value="">Sin asociar</option>
                                    <?php foreach ($rsas as $rsa): ?><option value="<?= (int) $rsa['id'] ?>" data-voyage="<?= (int) $rsa['voyage_id'] ?>" data-issued="<?= esc((string) ($rsa['issued_on'] ?? '')) ?>" data-expires="<?= esc((string) ($rsa['expires_on'] ?? '')) ?>"><?= esc($rsa['code'] . ($rsa['expires_on'] !== null ? ' · vence ' . $rsa['expires_on'] : ' · sin vencimiento')) ?></option><?php endforeach ?>
                                </select>
                                <div class="form-text" id="rsaPlanHint">Se valida contra la fecha de inicio planeada.</div>
                            </div>
                            <div class="col-sm-6 col-lg-2">
                                <label class="form-label">Inicio</label>
                                <input class="form-control" type="datetime-local" name="planned_start_at" id="planStart">
                            </div>
                            <div class="col-sm-6 col-lg-2">
                                <label class="form-label">Fin</label>
                                <input class="form-control" type="datetime-local" name="planned_end_at" id="planEnd">
                            </div>
                        </div>
                    </section>

                    <section class="planning-section planning-composition-section">
                        <div class="planning-section-heading planning-composition-heading">
                            <div>
                                <span class="planning-section-kicker">02 · Composición</span>
                                <div class="d-flex align-items-center flex-wrap gap-2">
                                    <h3 class="h6 mb-0">Composición planeada</h3>
                                    <span class="badge rounded-pill text-bg-light" id="planLineCount">0 líneas</span>
                                    <span class="badge rounded-pill planning-total-badge" id="planWeightTotal">0.000 kg</span>
                                </div>
                            </div>
                            <button class="btn btn-sm btn-primary" type="button" data-add-plan-line>
                                <i class="bi bi-plus-lg"></i> Agregar línea
                            </button>
                        </div>

                        <div class="planning-lines-scroll table-responsive">
                            <table class="table table-hover align-middle mb-0" id="planLines">
                                <thead>
                                <tr><th>Tanque</th><th>Especie</th><th>Talla</th><th class="text-end">kg</th><th>MSC</th><th>Dolphin</th><th>Cong. °C</th><th class="planning-action-col"></th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        <div class="planning-notes-wrap">
                            <label class="form-label mb-1">Notas del plan <span class="text-secondary fw-normal">(opcional)</span></label>
                            <textarea class="form-control" name="notes" rows="2" maxlength="1000" placeholder="Observaciones relevantes para la operación de descarga…"></textarea>
                        </div>
                    </section>
                </div>

                <div class="modal-footer planning-sticky-footer">
                    <div class="planning-footer-summary me-auto d-none d-md-flex">
                        <span><i class="bi bi-list-check"></i> <strong id="planFooterLineCount">0</strong> líneas</span>
                        <span><i class="bi bi-box-seam"></i> <strong id="planFooterWeight">0.000 kg</strong> planeados</span>
                    </div>
                    <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary btn-operational no-offline-confirm" type="submit" id="planSubmitButton">
                        <i class="bi bi-floppy"></i> Guardar plan en borrador
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DETALLE / REVISIÓN -->
<div class="modal fade" id="planDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable planning-detail-dialog">
        <div class="modal-content planning-detail-content">
            <div class="modal-header planning-modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="planning-modal-icon planning-modal-icon-detail"><i class="bi bi-clipboard2-data"></i></div>
                    <div>
                        <h2 class="modal-title fs-5 mb-1" id="planDetailTitle">Detalle del plan</h2>
                        <small class="text-secondary" id="planDetailSubtitle"></small>
                    </div>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body planning-detail-body">
                <section class="planning-detail-summary mb-3">
                    <div id="planDetailSummary"></div>
                </section>

                <section class="planning-section planning-detail-composition mb-3">
                    <div class="planning-section-heading">
                        <div>
                            <span class="planning-section-kicker">Composición</span>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h3 class="h6 mb-0">Producto planeado</h3>
                                <span class="badge rounded-pill text-bg-light" id="planDetailLineCount">0 líneas</span>
                            </div>
                        </div>
                        <div class="planning-detail-search">
                            <i class="bi bi-search"></i>
                            <input type="search" class="form-control form-control-sm" id="planDetailSearch" placeholder="Buscar tanque, especie o talla…" autocomplete="off">
                        </div>
                    </div>
                    <div class="planning-detail-lines-scroll table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 planning-detail-table">
                            <thead><tr><th>#</th><th>Tanque</th><th>Especie</th><th>Talla</th><th class="text-end">kg</th><th>MSC</th><th>Dolphin</th><th>Cong. °C</th></tr></thead>
                            <tbody id="planDetailLines"></tbody>
                            <tfoot><tr><th colspan="4">Total planeado</th><th class="text-end" id="planDetailTotal">0.000 kg</th><th colspan="3"></th></tr></tfoot>
                        </table>
                    </div>
                </section>

                <section class="planning-validation-panel">
                    <div class="planning-validation-header">
                        <div class="d-flex align-items-center gap-2">
                            <span class="planning-validation-icon"><i class="bi bi-shield-check"></i></span>
                            <div>
                                <span class="planning-section-kicker">Control previo</span>
                                <h3 class="h6 mb-0">Validaciones para aprobación</h3>
                            </div>
                        </div>
                        <span class="planning-validation-summary" id="planValidationSummary">Validando…</span>
                    </div>
                    <details class="planning-validation-details" id="planValidationDetails">
                        <summary><span>Ver detalle de validaciones</span><i class="bi bi-chevron-down"></i></summary>
                        <div class="row g-2 mt-1" id="planDetailValidations"></div>
                    </details>
                </section>
            </div>
            <div class="modal-footer planning-sticky-footer" id="planDetailActions">
                <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<template id="planLineTemplate">
    <tr>
        <td><select class="form-select form-select-sm" data-line="voyage_tank_id" required><option value="">Tanque…</option><?php foreach ($tanks as $tank): ?><option value="<?= (int) $tank['id'] ?>" data-voyage="<?= (int) $tank['voyage_id'] ?>"><?= esc($tank['tank_number']) ?></option><?php endforeach ?></select></td>
        <td><select class="form-select form-select-sm" data-line="species_id" required><option value="">Especie…</option><?php foreach ($species as $item): ?><option value="<?= (int) $item['id'] ?>"><?= esc($item['code'] . ' · ' . $item['name']) ?></option><?php endforeach ?></select></td>
        <td><select class="form-select form-select-sm" data-line="size_grade_id"><option value="">Sin talla</option><?php foreach ($sizes as $item): ?><option value="<?= (int) $item['id'] ?>" data-species="<?= (int) ($item['species_id'] ?? 0) ?>"><?= esc($item['name']) ?></option><?php endforeach ?></select></td>
        <td><input class="form-control form-control-sm" data-line="planned_weight_kg" type="number" min="0.001" step="0.001" required></td>
        <td><select class="form-select form-select-sm" data-line="msc_code" required><option value="">MSC…</option><?php foreach ($mscValues as $value): ?><option value="<?= esc((string) $value) ?>"><?= esc((string) $value) ?></option><?php endforeach ?></select></td>
        <td><select class="form-select form-select-sm" data-line="dolphin_safe_code" required><option value="">Dolphin…</option><?php foreach ($dolphinValues as $value): ?><option value="<?= esc((string) $value) ?>"><?= esc((string) $value) ?></option><?php endforeach ?></select></td>
        <td><input class="form-control form-control-sm" data-line="freezing_temperature_c" type="number" step="0.01" required></td>
        <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-plan-line title="Eliminar renglón"><i class="bi bi-trash"></i></button></td>
    </tr>
</template>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/planning.js"></script>
<script src="/assets/js/planning-ui.js"></script>
<?= $this->endSection() ?>
