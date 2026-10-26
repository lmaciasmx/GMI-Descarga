<?php
$dashboardUser = function_exists('auth') && auth()->loggedIn() ? auth()->user() : null;
$can = static fn (string $permission): bool => $dashboardUser !== null && $dashboardUser->can($permission);

$canDischarge = $can('discharge.operate');
$canEntry = $can('weighings.create');
$canInventory = $can('inventory.view');
$canRequests = $can('requests.create')
    || $can('requests.approve')
    || $can('reservations.manage');
$canReports = $can('reports.view');
$canAudit = $can('audit.view');
$canQuality = $can('quality.inspect');
$canTransfers = $can('transfers.manage');

$alertHref = static function (string $type) use (
    $canInventory,
    $canQuality,
    $canRequests,
    $canTransfers
): ?string {
    return match ($type) {
        'inventory_hold' => $canInventory ? site_url('inventario') : null,
        'quality_pending' => $canQuality ? site_url('operacion/calidad') : null,
        'request_overdue' => $canRequests ? site_url('solicitudes/almacenes') : null,
        'transfer_discrepancy' => $canTransfers ? site_url('solicitudes/almacenes') : null,
        default => null,
    };
};

$statusHref = static function (string $key) use (
    $canDischarge,
    $canQuality,
    $canEntry,
    $canInventory
): ?string {
    return match ($key) {
        'discharge' => $canDischarge ? site_url('operacion/planes') : null,
        'quality' => $canQuality ? site_url('operacion/calidad') : null,
        'cold' => $canEntry ? site_url('operacion/entrada-frigorifico') : null,
        'inventory' => $canInventory ? site_url('inventario') : null,
        default => null,
    };
};

$quickLinks = [];

if ($canDischarge) {
    $quickLinks[] = [
        'href' => site_url('operacion/planes'),
        'icon' => 'bi-water',
        'label' => 'Registrar descarga',
        'tone' => 'blue',
    ];
}

if ($canEntry) {
    $quickLinks[] = [
        'href' => site_url('operacion/entrada-frigorifico'),
        'icon' => 'bi-snow',
        'label' => 'Nueva entrada a frigorífico',
        'tone' => 'ice',
    ];
}

if ($canInventory) {
    $quickLinks[] = [
        'href' => site_url('inventario'),
        'icon' => 'bi-database',
        'label' => 'Consultar existencias',
        'tone' => 'green',
    ];
}

if ($canRequests) {
    $quickLinks[] = [
        'href' => site_url('solicitudes/almacenes'),
        'icon' => 'bi-file-earmark-text',
        'label' => 'Solicitudes entre almacenes',
        'tone' => 'violet',
    ];
}

if ($canReports) {
    $quickLinks[] = [
        'href' => site_url('reportes'),
        'icon' => 'bi-bar-chart-line',
        'label' => 'Reportes de operación',
        'tone' => 'navy',
    ];
}

if ($canInventory) {
    $quickLinks[] = [
        'href' => site_url('inventario'),
        'icon' => 'bi-search',
        'label' => 'Trazabilidad de lote',
        'tone' => 'blue',
    ];
}

$receptionDelta = $kpis['reception_delta'] ?? null;
$receptionDeltaTone = $receptionDelta !== null && $receptionDelta < 0 ? 'down' : 'up';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/dashboard-ui.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('pageActions') ?>
<div class="gm-dashboard-live">
    <span class="gm-dashboard-live__date">
        <i class="bi bi-calendar3"></i>
        <?= esc(date('d/m/Y')) ?>
    </span>
    <span class="gm-dashboard-live__time"><?= esc(date('H:i')) ?></span>
    <span class="gm-dashboard-live__status">
        <i></i>
        En tiempo real
    </span>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<section class="gm-dashboard-v12">

    <div class="gm-kpis">
        <article class="gm-kpi gm-kpi--blue position-relative">
            <span class="gm-kpi__icon"><i class="bi bi-water"></i></span>
            <span class="gm-kpi__copy">
                <span class="gm-kpi__label">Descargas activas</span>
                <strong><?= number_format((int) $kpis['active_voyages']) ?></strong>
                <small>Mareas planeadas o descargando</small>
            </span>
            <?php if ($canDischarge): ?>
                <a href="<?= site_url('operacion/planes') ?>" class="gm-kpi__arrow stretched-link" aria-label="Ir a descarga">
                    <i class="bi bi-chevron-right"></i>
                </a>
            <?php endif ?>
        </article>

        <article class="gm-kpi gm-kpi--green position-relative">
            <span class="gm-kpi__icon"><i class="bi bi-box-seam"></i></span>
            <span class="gm-kpi__copy">
                <span class="gm-kpi__label">Recepciones hoy</span>
                <strong><?= number_format(((float) $kpis['today_reception_kg']) / 1000, 2) ?> <em>t</em></strong>
                <?php if ($receptionDelta !== null): ?>
                    <small class="gm-kpi__delta gm-kpi__delta--<?= esc($receptionDeltaTone) ?>">
                        <i class="bi <?= $receptionDeltaTone === 'down' ? 'bi-caret-down-fill' : 'bi-caret-up-fill' ?>"></i>
                        <?= esc(number_format(abs((float) $receptionDelta), 1)) ?>% vs. ayer
                    </small>
                <?php else: ?>
                    <small>Toneladas confirmadas hoy</small>
                <?php endif ?>
            </span>
            <?php if ($canTransfers): ?>
                <a href="<?= site_url('solicitudes/almacenes/entradas') ?>" class="gm-kpi__arrow stretched-link" aria-label="Ir a recepciones de solicitudes">
                    <i class="bi bi-chevron-right"></i>
                </a>
            <?php endif ?>
        </article>

        <article class="gm-kpi gm-kpi--ice position-relative">
            <span class="gm-kpi__icon"><i class="bi bi-snow"></i></span>
            <span class="gm-kpi__copy">
                <span class="gm-kpi__label">Tinas en frío</span>
                <strong><?= number_format((int) $kpis['cold_units']) ?></strong>
                <small>Con existencia física registrada</small>
            </span>
            <?php if ($canInventory): ?>
                <a href="<?= site_url('inventario') ?>" class="gm-kpi__arrow stretched-link" aria-label="Ir a existencias">
                    <i class="bi bi-chevron-right"></i>
                </a>
            <?php endif ?>
        </article>

        <article class="gm-kpi gm-kpi--red position-relative">
            <span class="gm-kpi__icon"><i class="bi bi-exclamation-triangle"></i></span>
            <span class="gm-kpi__copy">
                <span class="gm-kpi__label">Alertas</span>
                <strong><?= number_format((int) $kpis['alerts_total']) ?></strong>
                <small class="gm-kpi__alert-meta">
                    <?= number_format((int) $kpis['alerts_critical']) ?> críticas
                    ·
                    <?= number_format((int) $kpis['alerts_attention']) ?> de atención
                </small>
            </span>
            <a href="<?= current_url() ?>#gm-pendientes-criticos" class="gm-kpi__arrow stretched-link" aria-label="Ver pendientes críticos">
                <i class="bi bi-chevron-right"></i>
            </a>
        </article>
    </div>

    <div class="gm-dashboard-grid gm-dashboard-grid--top">

        <section class="gm-card gm-card--status">
            <header class="gm-card__header">
                <div class="gm-card__title">
                    <i class="bi bi-bar-chart-line"></i>
                    <div>
                        <h2>Estado operativo</h2>
                        <p>Avance del proceso actual en planta</p>
                    </div>
                </div>
            </header>

            <div class="gm-process">
                <div class="gm-process__rail"></div>

                <?php foreach ($operationalStatus as $index => $status): ?>
                    <?php $href = $statusHref((string) $status['key']); ?>
                    <?php if ($href !== null): ?>
                        <a href="<?= esc($href) ?>" class="gm-process__step gm-process__step--<?= esc($status['tone']) ?> text-decoration-none">
                    <?php else: ?>
                        <article class="gm-process__step gm-process__step--<?= esc($status['tone']) ?>">
                    <?php endif ?>
                        <div class="gm-process__icon">
                            <i class="bi <?= esc($status['icon']) ?>"></i>
                        </div>
                        <h3><?= esc($status['label']) ?></h3>
                        <p><?= esc($status['meta']) ?></p>
                        <strong><?= number_format((int) $status['percent']) ?>%</strong>
                        <div class="gm-process__bar">
                            <span style="width: <?= esc((string) $status['percent']) ?>%"></span>
                        </div>
                    <?php if ($href !== null): ?>
                        </a>
                    <?php else: ?>
                        </article>
                    <?php endif ?>
                <?php endforeach ?>
            </div>
        </section>

        <section class="gm-card gm-card--activity">
            <header class="gm-card__header">
                <div class="gm-card__title">
                    <i class="bi bi-clock"></i>
                    <div>
                        <h2>Actividad reciente</h2>
                        <p>Últimos movimientos en el sistema</p>
                    </div>
                </div>
                <?php if ($canAudit): ?>
                    <a href="<?= site_url('auditoria') ?>" class="gm-card__button">
                        Ver todas <i class="bi bi-arrow-right"></i>
                    </a>
                <?php endif ?>
            </header>

            <?php if ($recentActivity !== []): ?>
                <div class="gm-timeline">
                    <?php foreach ($recentActivity as $activity): ?>
                        <?php
                        $timestamp = strtotime((string) $activity['occurred_at']);
                        $time = $timestamp === false ? '—' : date('H:i', $timestamp);
                        ?>
                        <article class="gm-timeline__item gm-timeline__item--<?= esc($activity['tone']) ?>">
                            <time><?= esc($time) ?></time>
                            <span class="gm-timeline__dot"></span>
                            <div class="gm-timeline__copy">
                                <strong><?= esc($activity['label']) ?></strong>
                                <small>
                                    <?= esc($activity['aggregate']) ?>
                                    <?php if (! empty($activity['reason'])): ?>
                                        · <?= esc($activity['reason']) ?>
                                    <?php endif ?>
                                </small>
                            </div>
                            <span class="gm-timeline__badge"><?= esc($activity['badge']) ?></span>
                        </article>
                    <?php endforeach ?>
                </div>
            <?php else: ?>
                <div class="gm-card-empty">
                    <i class="bi bi-clock-history"></i>
                    <span>Sin actividad reciente</span>
                </div>
            <?php endif ?>
        </section>

        <section class="gm-card gm-card--quick">
            <header class="gm-card__header">
                <div class="gm-card__title">
                    <i class="bi bi-lightning-charge"></i>
                    <div>
                        <h2>Accesos rápidos</h2>
                    </div>
                </div>
            </header>

            <?php if ($quickLinks !== []): ?>
                <div class="gm-quick-grid">
                    <?php foreach ($quickLinks as $link): ?>
                        <a href="<?= esc($link['href']) ?>" class="gm-quick gm-quick--<?= esc($link['tone']) ?>">
                            <span class="gm-quick__icon">
                                <i class="bi <?= esc($link['icon']) ?>"></i>
                            </span>
                            <span><?= esc($link['label']) ?></span>
                            <i class="bi bi-chevron-right gm-quick__arrow"></i>
                        </a>
                    <?php endforeach ?>
                </div>
            <?php else: ?>
                <div class="gm-card-empty">
                    <i class="bi bi-shield-lock"></i>
                    <span>Sin accesos disponibles para este perfil</span>
                </div>
            <?php endif ?>
        </section>

    </div>

    <div class="gm-dashboard-grid gm-dashboard-grid--bottom">

        <section class="gm-card gm-card--capacity">
            <header class="gm-card__header">
                <div class="gm-card__title">
                    <i class="bi bi-database"></i>
                    <div>
                        <h2>Capacidad y ocupación</h2>
                        <p>Ocupación por cámara de frío</p>
                    </div>
                </div>
                <?php if ($canInventory): ?>
                    <a href="<?= site_url('inventario/camaras') ?>" class="gm-card__button">
                        Ver detalle <i class="bi bi-arrow-right"></i>
                    </a>
                <?php endif ?>
            </header>

            <?php if ($chamberOccupancy !== []): ?>
                <div class="gm-capacity-list">
                    <?php foreach ($chamberOccupancy as $chamber): ?>
                        <?php
                        $pct = max(0, min(100, (float) $chamber['percent']));
                        $tone = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'blue' : ($pct >= 45 ? 'cyan' : 'green'));
                        ?>
                        <article class="gm-capacity-row gm-capacity-row--<?= esc($tone) ?>">
                            <div class="gm-capacity-row__name">
                                <strong><?= esc($chamber['name']) ?></strong>
                                <small><?= esc($chamber['warehouse_name']) ?></small>
                            </div>
                            <span class="gm-capacity-row__percent"><?= number_format($pct, 0) ?>%</span>
                            <div class="gm-capacity-row__bar">
                                <span style="width: <?= esc(number_format($pct, 1, '.', '')) ?>%"></span>
                            </div>
                            <span class="gm-capacity-row__value">
                                <?= number_format((int) $chamber['occupied']) ?>
                                /
                                <?= number_format((int) $chamber['usable']) ?>
                                ubic.
                            </span>
                        </article>
                    <?php endforeach ?>
                </div>
            <?php else: ?>
                <div class="gm-card-empty">
                    <i class="bi bi-grid-3x3-gap"></i>
                    <span>Sin cámaras configuradas</span>
                </div>
            <?php endif ?>
        </section>

        <section class="gm-card gm-card--alerts" id="gm-pendientes-criticos">
            <header class="gm-card__header">
                <div class="gm-card__title">
                    <i class="bi bi-exclamation-triangle"></i>
                    <div>
                        <h2>Pendientes críticos</h2>
                        <p>Tareas que requieren atención inmediata</p>
                    </div>
                </div>
            </header>

            <?php if ($criticalAlerts !== []): ?>
                <div class="gm-pending-list">
                    <?php foreach ($criticalAlerts as $alert): ?>
                        <?php $href = $alertHref($alert['type']); ?>
                        <?php if ($href !== null): ?>
                            <a href="<?= esc($href) ?>" class="gm-pending gm-pending--<?= esc($alert['severity']) ?>">
                        <?php else: ?>
                            <div class="gm-pending gm-pending--<?= esc($alert['severity']) ?>">
                        <?php endif ?>

                            <span class="gm-pending__icon">
                                <i class="bi <?= esc($alert['icon']) ?>"></i>
                            </span>
                            <span class="gm-pending__copy">
                                <strong><?= esc($alert['title']) ?></strong>
                                <small><?= esc($alert['detail']) ?></small>
                            </span>
                            <i class="bi bi-chevron-right gm-pending__arrow"></i>

                        <?php if ($href !== null): ?>
                            </a>
                        <?php else: ?>
                            </div>
                        <?php endif ?>
                    <?php endforeach ?>
                </div>
            <?php else: ?>
                <div class="gm-card-empty gm-card-empty--success">
                    <i class="bi bi-check-circle"></i>
                    <span>Sin pendientes críticos</span>
                </div>
            <?php endif ?>
        </section>

        <section class="gm-card gm-card--species">
            <header class="gm-card__header">
                <div class="gm-card__title">
                    <i class="bi bi-water"></i>
                    <div>
                        <h2>Resumen por especie</h2>
                        <p>Existencia disponible registrada</p>
                    </div>
                </div>
                <?php if ($canInventory): ?>
                    <a href="<?= site_url('inventario') ?>" class="gm-card__button">
                        Ver detalle <i class="bi bi-arrow-right"></i>
                    </a>
                <?php endif ?>
            </header>

            <?php if (($speciesSummary['items'] ?? []) !== []): ?>
                <div class="gm-species-layout">
                    <div class="gm-species-chart">
                        <canvas id="speciesChart" aria-label="Resumen por especie"></canvas>
                        <div class="gm-species-chart__center">
                            <strong><?= number_format(((float) $speciesSummary['total_kg']) / 1000, 2) ?> t</strong>
                            <span>disponibles</span>
                        </div>
                    </div>

                    <div class="gm-species-legend">
                        <?php foreach ($speciesSummary['items'] as $index => $species): ?>
                            <div class="gm-species-legend__row">
                                <span class="gm-species-legend__dot gm-species-color-<?= (int) $index ?>"></span>
                                <span class="gm-species-legend__name"><?= esc($species['name']) ?></span>
                                <strong><?= number_format(((float) $species['kg']) / 1000, 2) ?> t</strong>
                                <em><?= number_format((float) $species['percent'], 0) ?>%</em>
                            </div>
                        <?php endforeach ?>
                    </div>
                </div>

                <div hidden id="speciesChartData"><?= json_encode($speciesSummary['items'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></div>
            <?php else: ?>
                <div class="gm-card-empty">
                    <i class="bi bi-pie-chart"></i>
                    <span>Sin existencia disponible</span>
                </div>
            <?php endif ?>
        </section>

    </div>

</section>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/dashboard.js"></script>
<?= $this->endSection() ?>
