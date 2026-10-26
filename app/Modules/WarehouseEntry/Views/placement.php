<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
<link rel="stylesheet" href="/assets/css/warehouse-station.css">

<div hidden id="warehousePlacementData"><?= json_encode([
    'pending' => $pending,
    'warehouse' => $warehouse,
    'warehouses' => $warehouses,
    'chambers' => $chambers,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></div>

<div class="warehouse-station-shell" data-warehouse-station>
    <?= view('App\\Modules\\WarehouseEntry\\Views\\_station_nav', [
        'warehouse' => $warehouse,
        'activeStation' => 'placement',
        'stationTitle' => 'Acomodo de tinas',
    ]) ?>

    <main class="warehouse-station-content">
        <div class="warehouse-station-container">
            <section class="ws-card mb-3">
                <div class="ws-card-body">
                    <div class="ws-placement-toolbar">
                        <div>
                            <label class="form-label" for="placementSearch">Buscar tina, marbete, lote o producto</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input id="placementSearch" class="form-control" type="search" autocomplete="off" placeholder="Escanea o escribe tina / marbete / lote...">
                            </div>
                        </div>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <?php if ($warehouse !== null): ?>
                                <div class="ws-fixed-scope"><i class="bi bi-building"></i><span><?= esc(($warehouse['code'] ?? '') . ' · ' . ($warehouse['name'] ?? '')) ?></span></div>
                            <?php endif ?>
                            <span class="badge text-bg-warning fs-6"><?= count($pending) ?> pendiente<?= count($pending) === 1 ? '' : 's' ?></span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ws-card">
                <div class="ws-card-header">
                    <div>
                        <h2><i class="bi bi-box-seam me-1"></i> Tinas pendientes de acomodo</h2>
                        <small>La Cámara y Fila/Pasillo capturadas en Entrada a Frigorífico son la ubicación inicial. El montacarguista confirma o corrige dónde quedó realmente la tina.</small>
                    </div>
                </div>
                <div class="ws-card-body">
                    <?php if ($pending === []): ?>
                        <div class="ws-empty-state">
                            <i class="bi bi-check2-circle"></i>
                            <strong>No hay tinas pendientes de acomodo.</strong>
                            <div class="small mt-1">Las nuevas tinas aparecerán aquí después de confirmar Entrada a Frigorífico.</div>
                        </div>
                    <?php else: ?>
                        <div class="ws-placement-list" id="placementList">
                            <?php foreach ($pending as $row): ?>
                                <?php $shortSector = preg_replace('/^.*-([FP]\d+)$/', '$1', (string) ($row['sector_code'] ?? '')); ?>
                                <article class="ws-placement-item ws-placement-item-v221"
                                    data-placement-row
                                    data-search="<?= esc(strtolower(implode(' ', [
                                        $row['tub_code'] ?? '',
                                        $row['label_code'] ?? '',
                                        $row['weighing_folio'] ?? '',
                                        $row['species_name'] ?? '',
                                        $row['size_name'] ?? '',
                                        $row['lot_code'] ?? '',
                                    ])), 'attr') ?>">
                                    <div class="ws-placement-cell">
                                        <small>Marbete</small>
                                        <span class="primary"><?= esc($row['label_code']) ?></span>
                                        <span class="text-secondary"><?= esc($row['weighing_folio']) ?></span>
                                    </div>
                                    <div class="ws-placement-cell">
                                        <small>Tina</small>
                                        <span class="primary"><?= esc($row['tub_code']) ?></span>
                                    </div>
                                    <div class="ws-placement-cell">
                                        <small>Especie / talla</small>
                                        <strong><?= esc($row['species_name'] ?? '—') ?></strong>
                                        <span class="text-secondary"><?= esc($row['size_name'] ?? '—') ?></span>
                                    </div>
                                    <div class="ws-placement-cell">
                                        <small>Lote</small>
                                        <strong><?= esc($row['lot_code'] ?? '—') ?></strong>
                                    </div>
                                    <div class="ws-placement-cell">
                                        <small>Plan / marea</small>
                                        <strong><?= esc($row['plan_folio']) ?></strong>
                                        <span class="text-secondary"><?= esc($row['vessel_code'] . ' · ' . $row['annual_number'] . '/' . $row['year']) ?></span>
                                    </div>
                                    <div class="ws-placement-cell">
                                        <small>Peso aceptado</small>
                                        <strong><?= number_format((float) $row['accepted_net_kg'], 3) ?> kg</strong>
                                    </div>
                                    <div class="ws-placement-cell">
                                        <small>Ubicación inicial</small>
                                        <strong><?= esc($row['chamber_code'] . ' · ' . $shortSector) ?></strong>
                                        <span class="text-secondary"><?= esc($row['sector_name']) ?></span>
                                    </div>
                                    <div class="ws-placement-action">
                                        <button type="button" class="btn btn-primary no-offline-confirm"
                                            data-place-destination="<?= (int) $row['id'] ?>"
                                            data-proposed-chamber="<?= (int) $row['chamber_id'] ?>"
                                            data-proposed-sector="<?= (int) $row['sector_location_id'] ?>"
                                            data-tub-code="<?= esc($row['tub_code'], 'attr') ?>"
                                            data-label-code="<?= esc($row['label_code'], 'attr') ?>"
                                            data-species-name="<?= esc($row['species_name'] ?? '—', 'attr') ?>"
                                            data-size-name="<?= esc($row['size_name'] ?? '—', 'attr') ?>"
                                            data-lot-code="<?= esc($row['lot_code'] ?? '—', 'attr') ?>">
                                            <i class="bi bi-geo-alt-fill me-1"></i> Ubicar
                                        </button>
                                    </div>
                                </article>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                </div>
            </section>
        </div>
    </main>
</div>

<div class="modal fade ws-modal" id="placementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Confirmar acomodo físico</h5>
                    <small class="text-secondary" id="placementSubject"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="placementDestinationId">
                <input type="hidden" id="placementProposedChamber">
                <input type="hidden" id="placementProposedSector">

                <div class="border rounded-3 bg-light p-3 mb-3">
                    <div class="row g-2">
                        <div class="col-sm-3"><small class="text-secondary d-block">Tina</small><strong id="placementTubCode">—</strong></div>
                        <div class="col-sm-3"><small class="text-secondary d-block">Especie</small><strong id="placementSpeciesName">—</strong></div>
                        <div class="col-sm-3"><small class="text-secondary d-block">Talla</small><strong id="placementSizeName">—</strong></div>
                        <div class="col-sm-3"><small class="text-secondary d-block">Lote</small><strong id="placementLotCode">—</strong></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label" for="placementChamberId">Cámara real</label>
                        <select id="placementChamberId" class="form-select">
                            <option value="">Selecciona...</option>
                            <?php foreach ($chambers as $chamber): ?>
                                <option value="<?= (int) $chamber['id'] ?>"><?= esc($chamber['code'] . ' · ' . $chamber['name']) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label" for="placementSectorId">Fila / pasillo real</label>
                        <select id="placementSectorId" class="form-select"><option value="">Selecciona cámara...</option></select>
                        <small class="text-secondary">El montacarguista sólo confirma Cámara y Fila/Pasillo. La posición interna se asigna automáticamente.</small>
                    </div>
                    <div class="col-12 d-none" id="placementChangeAlert">
                        <div class="alert alert-warning mb-0 small">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            La Cámara o Fila/Pasillo real es distinta a la registrada en Entrada a Frigorífico. Captura el motivo del cambio.
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="placementReason">Motivo / observación</label>
                        <textarea id="placementReason" class="form-control" rows="2" maxlength="255" placeholder="Observación opcional."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success no-offline-confirm" id="placementConfirm">
                    <i class="bi bi-check2-square me-1"></i> Confirmar acomodo
                </button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/warehouse-station.js"></script>
<script src="/assets/js/warehouse-placement.js"></script>
<?= $this->endSection() ?>
