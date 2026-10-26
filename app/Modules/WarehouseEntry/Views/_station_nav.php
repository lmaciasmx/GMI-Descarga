<?php
/** @var array|null $warehouse */
$user = auth()->user();
$scope = session('operational_scope') ?? [];
$activeStation = $activeStation ?? '';
$stationTitle = $stationTitle ?? 'Operación';
$warehouseLabel = $warehouse
    ? trim((string) ($warehouse['code'] ?? '') . ' · ' . (string) ($warehouse['name'] ?? ''))
    : 'Alcance sin frigorífico';
?>

<div class="warehouse-station-backdrop" data-station-menu-close></div>

<aside class="warehouse-station-drawer" aria-label="Navegación de estación">
    <div class="warehouse-station-drawer-head">
        <div class="warehouse-station-brand-mark">GM</div>
        <div>
            <strong>Grupo Mar</strong>
            <span>Operación de frigorífico</span>
        </div>
        <button type="button" class="warehouse-station-icon-btn ms-auto" data-station-menu-close aria-label="Cerrar menú">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="warehouse-station-context">
        <small>ALCANCE ACTUAL</small>
        <strong><?= esc($warehouseLabel) ?></strong>
        <span><?= esc((string) ($scope['site_code'] ?? $scope['site_name'] ?? 'Instalación actual')) ?></span>
    </div>

    <nav class="warehouse-station-nav">
        <?php if ($user->can('dashboard.view')): ?>
            <a href="<?= site_url('dashboard') ?>" class="<?= $activeStation === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2"></i><span>Centro de operación</span>
            </a>
        <?php endif ?>

        <?php if ($user->can('discharge.operate') && $user->can('weighings.create') && $user->can('labels.print')): ?>
            <a href="<?= site_url('operacion/entrada-frigorifico') ?>" class="<?= $activeStation === 'entry' ? 'active' : '' ?>">
                <i class="bi bi-box-arrow-in-down"></i><span>Entrada a Frigorífico</span>
            </a>
        <?php endif ?>

        <?php if ($user->can('labels.reprint')): ?>
            <a href="<?= site_url('operacion/etiquetas') ?>" class="<?= $activeStation === 'labels' ? 'active' : '' ?>">
                <i class="bi bi-printer"></i><span>Reimpresión de marbetes</span>
            </a>
        <?php endif ?>

        <?php if ($user->can('receiving.confirm')): ?>
            <a href="<?= site_url('operacion/acomodo-tinas') ?>" class="<?= $activeStation === 'placement' ? 'active' : '' ?>">
                <i class="bi bi-box-seam"></i><span>Acomodo de tinas</span>
            </a>
        <?php endif ?>

        <?php if ($user->can('inventory.view')): ?>
            <a href="<?= site_url('inventario') ?>" class="<?= $activeStation === 'inventory' ? 'active' : '' ?>">
                <i class="bi bi-boxes"></i><span>Existencias</span>
            </a>
        <?php endif ?>
    </nav>

    <div class="warehouse-station-drawer-foot">
        <span><i class="bi bi-person-circle"></i> <?= esc((string) $user->username) ?></span>
        <a href="<?= site_url('dashboard') ?>"><i class="bi bi-arrow-left-circle"></i> Salir de estación</a>
    </div>
</aside>

<header class="warehouse-station-topbar">
    <div class="warehouse-station-topbar-left">
        <button type="button" class="warehouse-station-menu-btn" data-station-menu-open aria-label="Abrir menú">
            <i class="bi bi-list"></i>
        </button>
        <div>
            <h1><?= esc($stationTitle) ?></h1>
            <div class="warehouse-station-breadcrumb">
                <span><?= esc($warehouseLabel) ?></span>
                <span class="warehouse-station-divider">/</span>
                <span id="stationPlanSummary">Operación en piso</span>
            </div>
        </div>
    </div>
    <div class="warehouse-station-topbar-right">
        <?php if ($activeStation === 'entry'): ?>
            <div class="warehouse-entry-device-strip" aria-label="Estado de equipos de la estación">
                <div class="warehouse-entry-device">
                    <i class="bi bi-speedometer2"></i>
                    <span class="warehouse-entry-device-copy">
                        <strong>Báscula</strong>
                        <span class="ws-status-pill warning" id="warehouseScaleStatusMirror">
                            <span class="status-dot"></span>
                            <span>Verificando</span>
                        </span>
                    </span>
                </div>

                <div class="warehouse-entry-device">
                    <i class="bi bi-printer"></i>
                    <span class="warehouse-entry-device-copy">
                        <strong><?= esc($printer['name'] ?? 'Impresora') ?></strong>
                        <span class="ws-status-pill <?= in_array(($printer['adapter_key'] ?? ''), ['middleware', 'zpl'], true) ? 'online' : 'warning' ?>" id="warehousePrinterStatusMirror">
                            <span class="status-dot"></span>
                            <span><?= $printer ? 'Configurada' : 'Sin impresora' ?></span>
                        </span>
                    </span>
                </div>

                <div class="warehouse-entry-device warehouse-entry-device-communication">
                    <i class="bi bi-wifi"></i>
                    <span class="warehouse-entry-device-copy">
                        <strong>Comunicación</strong>
                        <span class="ws-status-pill warning" id="warehouseCommunicationStatusMirror">
                            <span class="status-dot"></span>
                            <span>Verificando</span>
                        </span>
                    </span>
                </div>
            </div>

            <button
                type="button"
                class="warehouse-station-icon-btn"
                data-bs-toggle="modal"
                data-bs-target="#entryStationSettingsModal"
                aria-label="Configurar estación"
                title="Configurar estación"
            >
                <i class="bi bi-gear"></i>
            </button>
        <?php endif ?>
        <div class="warehouse-station-user">
            <i class="bi bi-person-circle"></i>
            <span><?= esc((string) $user->username) ?></span>
        </div>
    </div>
</header>
