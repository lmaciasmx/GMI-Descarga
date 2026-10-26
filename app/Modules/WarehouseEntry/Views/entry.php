<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<link rel="stylesheet" href="/assets/css/warehouse-station.css">
<link rel="stylesheet" href="/assets/css/warehouse-entry-redesign.css">

<div hidden id="warehouseEntryData"><?= json_encode([
    'plans' => $plans,
    'lines' => $lines,
    'tanks' => $tanks,
    'species' => $species,
    'sizes' => $sizes,
    'warehouse' => $warehouse,
    'chambers' => $chambers,
    'template' => $template,
    'printer' => $printer,
    'printers' => $printers,
    'printerDriver' => $printerDriver,
    'scale' => $scale,
    'scales' => $scales,
    'scaleDriver' => $scaleDriver,
    'mscValues' => $mscValues,
    'dolphinValues' => $dolphinValues,
    'currentUserId' => $currentUserId,
    'currentUsername' => $currentUsername,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></div>

<div class="warehouse-station-shell" data-warehouse-station>
    <?= view('App\\Modules\\WarehouseEntry\\Views\\_station_nav', [
        'warehouse' => $warehouse,
        'activeStation' => 'entry',
        'stationTitle' => 'Entrada a Frigorífico',
        'scale' => $scale,
        'printer' => $printer,
    ]) ?>

    <main class="warehouse-station-content">
        <div class="warehouse-station-container">
            <?php if ($plans === []): ?>
                <div class="alert alert-warning mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    No hay un plan <strong>en operación</strong> para este frigorífico. Inicia el plan desde Planeación antes de registrar tinas.
                </div>
            <?php endif ?>

            <section class="ws-card ws-plan-card ws-plan-card-redesign">
                <div class="ws-card-body">
                    <div class="ws-plan-head-redesign">
                        <div class="ws-plan-title-redesign">
                            <span class="ws-plan-title-icon"><i class="bi bi-file-earmark-text"></i></span>
                            <div>
                                <h2>Plan de descarga</h2>
                                <div class="ws-plan-identity" id="planIdentity">Selecciona un plan para comenzar.</div>
                            </div>
                        </div>

                        <div class="ws-plan-controls-redesign">
                            <div class="ws-plan-select-redesign">
                                <label class="visually-hidden" for="entryPlanId">Plan de descarga</label>
                                <select id="entryPlanId" class="form-select" <?= $plans === [] ? 'disabled' : '' ?>>
                                    <option value="">Selecciona el plan de descarga...</option>
                                    <?php foreach ($plans as $plan): ?>
                                        <option value="<?= (int) $plan['id'] ?>">
                                            <?= esc($plan['folio'] . ' · ' . $plan['vessel_code'] . ' · marea ' . $plan['annual_number'] . '/' . $plan['year']) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </div>

                            <button
                                type="button"
                                class="btn btn-outline-primary ws-plan-detail-btn"
                                id="entryPlanDetail"
                                disabled
                                aria-disabled="true"
                                title="El detalle del plan se implementará en el siguiente paso"
                            >
                                <i class="bi bi-eye me-1"></i> Ver detalle del plan
                            </button>
                        </div>
                    </div>

                    <div class="ws-metrics ws-plan-metrics-redesign ws-plan-metrics-functional" aria-hidden="true">
                        <div class="ws-metric ws-plan-metric-redesign ws-plan-metric-planned">
                            <div class="ws-plan-metric-icon"><i class="bi bi-file-earmark-text"></i></div>
                            <div class="ws-plan-metric-copy"><span>Planeado</span><strong id="planPlanned">0.000 kg</strong></div>
                        </div>
                        <div class="ws-metric ws-plan-metric-redesign ws-plan-metric-processed">
                            <div class="ws-plan-metric-icon"><i class="bi bi-gear"></i></div>
                            <div class="ws-plan-metric-copy"><span>Procesado</span><strong id="planProcessed">0.000 kg</strong></div>
                        </div>
                        <div class="ws-metric ws-plan-metric-redesign ws-plan-metric-pending">
                            <div class="ws-plan-metric-icon"><i class="bi bi-clock"></i></div>
                            <div class="ws-plan-metric-copy"><span>Pendiente</span><strong id="planPending">0.000 kg</strong></div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="ws-main-grid">
                <div>
                    <section class="ws-card">
                        <div class="ws-card-header">
                            <h2><i class="bi bi-box-seam me-1"></i> Tina y producto</h2>
                            <div class="ws-status-row">
                                <span class="ws-status-pill" id="entryPlanProductStatus"><span class="status-dot"></span><span>Sin producto</span></span>
                            </div>
                        </div>
                        <div class="ws-card-body">
                            <div class="ws-form-grid">
                                <div class="ws-col-4 ws-autocomplete">
                                    <label class="form-label" for="entryTubSearch">Tina</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input id="entryTubSearch" class="form-control" type="search" autocomplete="off" placeholder="Escribe o escanea...">
                                        <input id="entryTubId" type="hidden" value="">
                                    </div>
                                    <div id="entryTubResults" class="ws-search-menu d-none"></div>
                                    <div class="ws-selected-chip d-none" id="entryTubSelected" aria-hidden="true"><i class="bi bi-upc-scan"></i><span></span></div>
                                </div>

                                <div class="ws-col-4">
                                    <label class="form-label" for="entrySpeciesId">Especie</label>
                                    <select id="entrySpeciesId" class="form-select"><option value="">Selecciona plan...</option></select>
                                </div>

                                <div class="ws-col-4">
                                    <label class="form-label" for="entrySizeId">Talla</label>
                                    <select id="entrySizeId" class="form-select"><option value="">Selecciona especie...</option></select>
                                </div>

                                <div class="ws-col-4">
                                    <label class="form-label" for="entryTareInput">Tara (kg)</label>
                                    <div class="input-group ws-tare-input-group">
                                        <span class="input-group-text"><i class="bi bi-bag"></i></span>
                                        <input
                                            id="entryTareInput"
                                            class="form-control"
                                            type="number"
                                            min="0.001"
                                            step="0.001"
                                            inputmode="decimal"
                                            value="0.000"
                                            disabled
                                            aria-describedby="entryTareEditState"
                                        >
                                    </div>
                                    <strong id="entryTareDisplay" class="d-none" aria-hidden="true">0.000 kg</strong>
                                    <small id="entryTareEditState" class="ws-tare-edit-state"></small>
                                </div>

                                <div class="ws-col-4">
                                    <label class="form-label" for="entryActualTankId">Tanque del plan</label>
                                    <select id="entryActualTankId" class="form-select"><option value="">Selecciona especie y talla...</option></select>
                                </div>

                                <div class="ws-col-4">
                                    <label class="form-label" for="entrySourceTankIds">Tanques del producto</label>
                                    <select id="entrySourceTankIds" class="form-select" multiple></select>
                                </div>

                                <div class="ws-col-6 ws-autocomplete" data-participant-field="sorter">
                                    <label class="form-label" for="entrySorterSearch">Sorteador</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                        <input id="entrySorterSearch" class="form-control" type="search" autocomplete="off" placeholder="Buscar o registrar nombre...">
                                        <input id="entrySorterId" type="hidden" value="">
                                    </div>
                                    <div id="entrySorterResults" class="ws-autocomplete-menu d-none"></div>
                                    <div class="ws-selected-chip d-none" id="entrySorterSelected"></div>
                                </div>

                                <div class="ws-col-6 ws-autocomplete" data-participant-field="labeler">
                                    <label class="form-label" for="entryLabelerSearch">Etiquetador</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-tag"></i></span>
                                        <input id="entryLabelerSearch" class="form-control" type="search" autocomplete="off" placeholder="Buscar o registrar nombre...">
                                        <input id="entryLabelerId" type="hidden" value="">
                                    </div>
                                    <div id="entryLabelerResults" class="ws-autocomplete-menu d-none"></div>
                                    <div class="ws-selected-chip d-none" id="entryLabelerSelected"></div>
                                </div>

                                <div class="ws-col-12 d-none" id="entryOutOfPlanBox">
                                    <div class="ws-out-of-plan">
                                        <div class="fw-semibold small mb-2"><i class="bi bi-exclamation-triangle me-1"></i> Producto fuera de la composición del plan</div>
                                        <div class="ws-form-grid">
                                            <div class="ws-col-4">
                                                <label class="form-label" for="entryMscCode">MSC</label>
                                                <select id="entryMscCode" class="form-select">
                                                    <option value="">Selecciona...</option>
                                                    <?php foreach ($mscValues as $value): ?><option value="<?= esc((string) $value, 'attr') ?>"><?= esc((string) $value) ?></option><?php endforeach ?>
                                                </select>
                                            </div>
                                            <div class="ws-col-4">
                                                <label class="form-label" for="entryDolphinCode">Dolphin Safe</label>
                                                <select id="entryDolphinCode" class="form-select">
                                                    <option value="">Selecciona...</option>
                                                    <?php foreach ($dolphinValues as $value): ?><option value="<?= esc((string) $value, 'attr') ?>"><?= esc((string) $value) ?></option><?php endforeach ?>
                                                </select>
                                            </div>
                                            <div class="ws-col-4">
                                                <label class="form-label" for="entryTemperature">Temperatura °C</label>
                                                <input id="entryTemperature" class="form-control" type="number" step="0.01" placeholder="Ej. -18.00">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="ws-card">
                        <div class="ws-card-header ws-card-header-single">
                            <h2><i class="bi bi-geo-alt me-1"></i> Ubicación</h2>
                        </div>
                        <div class="ws-card-body">
                            <input id="entryWarehouseId" type="hidden" value="<?= (int) ($warehouse['id'] ?? 0) ?>">
                            <div class="ws-destination-grid">
                                <div>
                                    <label class="form-label">Frigorífico</label>
                                    <div class="ws-fixed-scope"><i class="bi bi-building"></i><span><?= esc(($warehouse['code'] ?? '') . ' · ' . ($warehouse['name'] ?? '')) ?></span></div>
                                </div>
                                <div>
                                    <label class="form-label" for="entryChamberId">Cámara</label>
                                    <select id="entryChamberId" class="form-select"><option value="">Selecciona...</option></select>
                                </div>
                                <div>
                                    <label class="form-label" for="entrySectorId">Fila / pasillo</label>
                                    <select id="entrySectorId" class="form-select" disabled><option value="">Selecciona cámara...</option></select>
                                </div>
                            </div>
                            <div class="mt-2 d-none" id="entryDestinationMessage"></div>
                            <div class="mt-3">
                                <label class="form-label" for="entryObservations">Observaciones (opcional)</label>
                                <textarea id="entryObservations" class="form-control" rows="2" maxlength="1000" placeholder="Sólo cuando sea necesario registrar una incidencia u observación."></textarea>
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="ws-card ws-scale-card">
                    <div class="ws-card-header ws-card-header-single">
                        <h2><i class="bi bi-speedometer2 me-1"></i> Báscula</h2>
                        <div class="ws-status-row ws-status-row-functional" aria-hidden="true">
                            <span class="ws-status-pill warning" id="warehouseScaleStatus"><span class="status-dot"></span><span>Verificando</span></span>
                            <span class="ws-status-pill <?= in_array(($printer['adapter_key'] ?? ''), ['middleware', 'zpl'], true) ? 'online' : 'warning' ?>" id="warehousePrinterStatus"><span class="status-dot"></span><span><?= esc($printer['name'] ?? 'Sin impresora') ?></span></span>
                        </div>
                    </div>

                    <div class="ws-scale-top">
                        <div class="ws-scale-reading"><strong id="entryGrossDisplay">0.000</strong><span>kg</span></div>
                        <div class="ws-scale-state" id="entryReadingStatus">Sin lectura</div>
                        <button type="button" class="btn btn-primary ws-read-scale no-offline-confirm" id="entryReadScale" <?= $scale ? '' : 'disabled' ?>>
                            <i class="bi bi-arrow-repeat me-1"></i> LEER BÁSCULA
                        </button>
                    </div>

                    <div class="ws-weight-grid">
                        <div class="ws-weight-item"><span>Tara</span><strong id="entryTareScaleDisplay">0.000 kg</strong></div>
                        <div class="ws-weight-item"><span>Neto físico</span><strong id="entryNetDisplay">0.000 kg</strong></div>
                        <div class="ws-weight-item"><span>Castigo</span><strong id="entryPenaltyKgDisplay">0.000 kg</strong></div>
                        <div class="ws-weight-item accepted"><span>Neto aceptado</span><strong id="entryAcceptedDisplay">0.000 kg</strong></div>
                    </div>

                    <div class="ws-scale-actions">
                        <div class="row g-2 mb-2">
                            <div class="col-5">
                                <label class="form-label" for="entryPenaltyPercent">Castigo %</label>
                                <input id="entryPenaltyPercent" class="form-control" type="number" min="0" max="100" step="0.001" value="0">
                            </div>
                            <div class="col-7">
                                <label class="form-label" for="entryReasonCode">Motivo</label>
                                <input id="entryReasonCode" class="form-control" maxlength="50" placeholder="Obligatorio si hay castigo">
                            </div>
                        </div>

                    </div>
                </aside>
            </div>

            <div class="ws-page-actions" aria-label="Acciones de la estación">
                <button type="button" class="btn btn-light ws-clear-btn" id="entryClearSticky">
                    <i class="bi bi-eraser me-2"></i> LIMPIAR
                </button>

                <button type="button" class="btn btn-success ws-confirm-btn no-offline-confirm" id="entryConfirm" <?= $plans === [] ? 'disabled' : '' ?>>
                    <i class="bi bi-printer me-2"></i> CONFIRMAR E IMPRIMIR MARBETE
                </button>
            </div>
        </div>
    </main>
</div>


<div class="modal fade ws-plan-detail-modal" id="entryPlanDetailModal" tabindex="-1" aria-labelledby="entryPlanDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header ws-plan-detail-modal-header">
                <div class="ws-plan-detail-heading">
                    <span class="ws-plan-detail-heading-icon"><i class="bi bi-file-earmark-text"></i></span>
                    <div>
                        <h2 class="modal-title" id="entryPlanDetailTitle">Detalle del plan de descarga</h2>
                        <div class="ws-plan-detail-subtitle" id="entryPlanDetailSubtitle">Selecciona un plan para consultar su detalle.</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body ws-plan-detail-modal-body">
                <div class="ws-plan-detail-loading" id="entryPlanDetailLoading">
                    <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                    <span>Cargando detalle del plan...</span>
                </div>

                <div class="d-none" id="entryPlanDetailContent">
                    <section class="ws-plan-modal-section">
                        <div class="ws-plan-modal-section-title">
                            <i class="bi bi-info-circle"></i>
                            <span>Información general</span>
                        </div>
                        <div class="ws-plan-general-grid">
                            <div class="ws-plan-general-item">
                                <i class="bi bi-ship"></i>
                                <div><span>Barco</span><strong id="entryPlanModalVessel">—</strong></div>
                            </div>
                            <div class="ws-plan-general-item">
                                <i class="bi bi-water"></i>
                                <div><span>Marea</span><strong id="entryPlanModalVoyage">—</strong></div>
                            </div>
                            <div class="ws-plan-general-item">
                                <i class="bi bi-file-earmark-text"></i>
                                <div><span>RSA</span><strong id="entryPlanModalRsa">—</strong></div>
                            </div>
                            <div class="ws-plan-general-item ws-plan-general-wide">
                                <i class="bi bi-building"></i>
                                <div><span>Frigorífico</span><strong id="entryPlanModalWarehouse">—</strong></div>
                            </div>
                            <div class="ws-plan-general-item">
                                <i class="bi bi-clock"></i>
                                <div>
                                    <span>Estado</span>
                                    <strong><span class="ws-plan-state-pill" id="entryPlanModalStatus">—</span></strong>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="ws-plan-modal-section">
                        <div class="ws-plan-modal-section-title">
                            <i class="bi bi-bar-chart"></i>
                            <span>Avance del plan</span>
                        </div>
                        <div class="ws-plan-progress-row">
                            <div class="progress ws-plan-modal-progress" role="progressbar" aria-label="Avance del plan" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar" id="entryPlanModalProgressBar" style="width: 0%"></div>
                            </div>
                            <strong id="entryPlanModalProgressText">0.0%</strong>
                        </div>
                        <div class="ws-plan-modal-metrics">
                            <div class="ws-plan-modal-metric planned">
                                <i class="bi bi-file-earmark-text"></i>
                                <div><span>Planeado</span><strong id="entryPlanModalPlanned">0.000 kg</strong></div>
                            </div>
                            <div class="ws-plan-modal-metric processed">
                                <i class="bi bi-gear"></i>
                                <div><span>Procesado</span><strong id="entryPlanModalProcessed">0.000 kg</strong></div>
                            </div>
                            <div class="ws-plan-modal-metric pending">
                                <i class="bi bi-clock"></i>
                                <div><span>Pendiente</span><strong id="entryPlanModalPending">0.000 kg</strong></div>
                            </div>
                            <div class="ws-plan-modal-metric progress-metric">
                                <i class="bi bi-pie-chart"></i>
                                <div><span>Avance</span><strong id="entryPlanModalProgressMetric">0.0%</strong></div>
                            </div>
                        </div>
                    </section>

                    <div class="ws-plan-modal-tabs" role="tablist" aria-label="Detalle del plan">
                        <button class="ws-plan-modal-tab active" type="button" role="tab" data-plan-tab="summary" aria-selected="true">
                            <i class="bi bi-box-seam"></i> Resumen por producto
                        </button>
                        <button class="ws-plan-modal-tab" type="button" role="tab" data-plan-tab="tubs" aria-selected="false">
                            <i class="bi bi-list-ul"></i> Tinas registradas
                        </button>
                    </div>

                    <div class="ws-plan-modal-tab-panel" data-plan-panel="summary">
                        <div class="ws-plan-detail-table-wrap">
                            <table class="table ws-plan-detail-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Especie</th>
                                        <th>Talla</th>
                                        <th>Tanque plan</th>
                                        <th>Tanques producto</th>
                                        <th class="text-end">Planeado (kg)</th>
                                        <th class="text-end">Procesado (kg)</th>
                                        <th class="text-end">Pendiente (kg)</th>
                                    </tr>
                                </thead>
                                <tbody id="entryPlanModalProductBody">
                                    <tr><td colspan="7" class="text-center text-secondary py-4">Sin información.</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="ws-plan-modal-summary-footer">
                            <div><i class="bi bi-clock"></i> <span id="entryPlanModalLastRecord">Sin registros procesados.</span></div>
                            <div><i class="bi bi-inboxes"></i> <strong id="entryPlanModalTubCountSummary">0</strong> tinas procesadas</div>
                        </div>
                    </div>

                    <div class="ws-plan-modal-tab-panel d-none" data-plan-panel="tubs">
                        <div class="ws-plan-cancel-note">
                            <i class="bi bi-info-circle"></i>
                            <span>Solo se puede cancelar una tina si aún no ha sido ingresada en el destino.</span>
                        </div>

                        <div class="ws-plan-tubs-scroll">
                            <table class="table ws-plan-tubs-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Tina</th>
                                        <th>Especie</th>
                                        <th>Talla</th>
                                        <th class="text-end">Peso neto (kg)</th>
                                        <th>Fecha / Hora</th>
                                        <th>Registró</th>
                                        <th>Estado destino</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody id="entryPlanModalTubsBody">
                                    <tr><td colspan="8" class="text-center text-secondary py-4">Sin tinas registradas.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer ws-plan-detail-modal-footer">
                <div class="ws-plan-modal-footer-count">
                    <i class="bi bi-list-ul"></i>
                    <strong id="entryPlanModalTubCount">0</strong> tinas procesadas
                </div>

                <div class="ws-plan-modal-footer-actions">
                    <button type="button" class="btn btn-outline-primary d-none" id="entryPlanModalCancelableOnly" aria-pressed="false">
                        <i class="bi bi-funnel me-1"></i> Ver solo cancelables
                    </button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="entryStationSettingsModal" tabindex="-1" aria-labelledby="entryStationSettingsTitle" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content ws-settings-modal">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="entryStationSettingsTitle"><i class="bi bi-gear me-1"></i> Seleccionar equipos</h2>
                    <small class="text-secondary">Selecciona la báscula y la impresora que usarás en esta estación.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="entryStationScale">Báscula</label>
                        <select id="entryStationScale" class="form-select form-select-lg">
                            <?php if ($scales === []): ?><option value="">Sin básculas activas</option><?php endif ?>
                            <?php foreach ($scales as $item): ?>
                                <option value="<?= (int) $item['id'] ?>"
                                        data-adapter="<?= esc((string) $item['adapter_key'], 'attr') ?>"
                                        data-connection="<?= esc((string) $item['connection_type'], 'attr') ?>"
                                        <?= (int) ($scale['id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>>
                                    <?= esc($item['code'] . ' · ' . $item['name']) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold" for="entryStationPrinter">Impresora</label>
                        <select id="entryStationPrinter" class="form-select form-select-lg">
                            <?php if ($printers === []): ?><option value="">Sin impresoras activas</option><?php endif ?>
                            <?php foreach ($printers as $item): ?>
                                <option value="<?= (int) $item['id'] ?>"
                                        data-adapter="<?= esc((string) $item['adapter_key'], 'attr') ?>"
                                        <?= (int) ($printer['id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>>
                                    <?= esc($item['code'] . ' · ' . $item['name']) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="entrySaveStationSettings"><i class="bi bi-check2 me-1"></i> Guardar</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/warehouse-station.js"></script>
<script src="/assets/js/warehouse-entry.js"></script>
<script src="/assets/js/warehouse-entry-redesign.js"></script>
<script src="/assets/js/warehouse-entry-plan-modal.js"></script>
<?= $this->endSection() ?>
