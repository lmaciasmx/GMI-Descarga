<?php
$path = service('uri')->getPath();
$scope = session('operational_scope') ?? [];
$user = function_exists('auth') && auth()->loggedIn() ? auth()->user() : null;
$can = static fn (string $permission): bool => $user !== null && $user->can($permission);
$active = static fn (string $prefix): string => str_starts_with($path, $prefix) ? 'active' : '';
$flashType = session()->getFlashdata('error') ? 'error' : (session()->getFlashdata('warning') ? 'warning' : (session()->getFlashdata('success') ? 'success' : null));
$flashMessage = session()->getFlashdata('error') ?? session()->getFlashdata('warning') ?? session()->getFlashdata('success');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0b2942">
  <meta name="csrf-token" content="<?= esc(csrf_hash()) ?>">
  <meta name="csrf-header" content="X-CSRF-TOKEN">
  <title><?= esc($title ?? 'Operaciones') ?> · Grupomar</title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="stylesheet" href="/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/vendor/datatables/dataTables.bootstrap5.min.css">
  <link rel="stylesheet" href="/vendor/datatables/buttons.bootstrap5.min.css">
  <link rel="stylesheet" href="/vendor/select2/select2.min.css">
  <link rel="stylesheet" href="/vendor/sweetalert2/sweetalert2.min.css">
  <link rel="stylesheet" href="/assets/fonts/manrope/manrope.css">
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/grupomar-ui.css">
  <?= $this->renderSection('styles') ?>
</head>
<body class="gm-app">
<div class="app-shell">
  <aside class="app-sidebar" aria-label="Navegación principal">
    <div class="brand">
      <div class="brand-mark">GM</div>
      <div><div class="brand-title">Grupomar</div><div class="brand-subtitle">Operaciones y trazabilidad</div></div>
    </div>

    <div class="nav-caption">Inicio</div>
    <?php if ($can('dashboard.view')): ?><a class="sidebar-link <?= $active('dashboard') ?>" href="/dashboard"><i class="bi bi-grid-1x2-fill"></i> Centro de operación</a><?php endif ?>

    <div class="nav-caption">Operación portuaria</div>
    <?php if ($can('plans.manage')): ?><a class="sidebar-link <?= $active('operacion/planes') ?>" href="/operacion/planes"><i class="bi bi-calendar2-check"></i> Planeación</a><?php endif ?>
    <?php if ($can('discharge.operate') && $can('weighings.create') && $can('labels.print')): ?><a class="sidebar-link <?= $active('operacion/entrada-frigorifico') ?>" href="/operacion/entrada-frigorifico"><i class="bi bi-box-arrow-in-down"></i> Entrada frigorífico</a><?php endif ?>
    <?php if ($can('quality.inspect')): ?><a class="sidebar-link <?= $active('operacion/calidad') ?>" href="/operacion/calidad"><i class="bi bi-shield-check"></i> Calidad</a><?php endif ?>
    <?php if ($can('labels.reprint')): ?><a class="sidebar-link <?= $active('operacion/etiquetas') ?>" href="/operacion/etiquetas"><i class="bi bi-printer"></i> Reimpresión</a><?php endif ?>

    <div class="nav-caption">Inventario y almacenes</div>
    <?php if ($can('receiving.confirm')): ?><a class="sidebar-link <?= $active('operacion/acomodo-tinas') ?>" href="/operacion/acomodo-tinas"><i class="bi bi-box-seam"></i> Acomodo</a><?php endif ?>
    <?php if ($can('inventory.view')): ?><a class="sidebar-link <?= $path === 'inventario' ? 'active' : '' ?>" href="/inventario"><i class="bi bi-boxes"></i> Existencias</a><?php endif ?>
    <?php if ($can('inventory.view')): ?><a class="sidebar-link <?= $active('inventario/movimientos') ?>" href="/inventario/movimientos"><i class="bi bi-journal-text"></i> Movimientos</a><?php endif ?>
    <?php if ($can('inventory.adjust')): ?><a class="sidebar-link <?= $active('inventario/conteos') ?>" href="/inventario/conteos"><i class="bi bi-clipboard-data"></i> Conteos</a><?php endif ?>
    <?php if ($can('inventory.view')): ?><a class="sidebar-link <?= $active('inventario/camaras') ?>" href="/inventario/camaras"><i class="bi bi-grid-3x3-gap"></i> Cámaras</a><?php endif ?>

    <?php if ($can('requests.create') || $can('requests.approve') || $can('reservations.manage') || $can('transfers.manage')): ?>
    <div class="nav-caption">Solicitudes entre almacenes</div>
    <a class="sidebar-link <?= $path === 'solicitudes/almacenes' ? 'active' : '' ?>" href="/solicitudes/almacenes"><i class="bi bi-clipboard-check"></i> Solicitudes</a>
    <?php endif ?>
    <?php if ($can('transfers.manage')): ?>
    <a class="sidebar-link <?= $active('solicitudes/almacenes/salidas') ?>" href="/solicitudes/almacenes/salidas"><i class="bi bi-box-arrow-up"></i> Salidas</a>
    <a class="sidebar-link <?= $active('solicitudes/almacenes/entradas') ?>" href="/solicitudes/almacenes/entradas"><i class="bi bi-box-arrow-in-down"></i> Entradas</a>
    <?php endif ?>

    <div class="nav-caption">Producción y venta</div>
    <?php if ($can('billing.reference')): ?><a class="sidebar-link <?= $active('facturacion') ?>" href="/facturacion"><i class="bi bi-file-earmark-text"></i> Facturación</a><?php endif ?>

    <div class="nav-caption">Control</div>
    <?php if ($can('reports.view')): ?><a class="sidebar-link <?= $active('reportes') ?>" href="/reportes"><i class="bi bi-bar-chart-line"></i> Reportes</a><?php endif ?>
    <?php if ($can('audit.view')): ?><a class="sidebar-link <?= $active('auditoria') ?>" href="/auditoria"><i class="bi bi-journal-lock"></i> Auditoría</a><?php endif ?>
    <?php if ($can('catalogs.manage')): ?><a class="sidebar-link <?= $active('catalogos') ?>" href="/catalogos"><i class="bi bi-database-gear"></i> Catálogos</a><?php endif ?>
    <?php if ($can('users.manage')): ?><a class="sidebar-link <?= $active('usuarios') ?>" href="/usuarios"><i class="bi bi-people"></i> Usuarios</a><?php endif ?>
    <?php if ($can('configuration.manage')): ?><a class="sidebar-link <?= $active('configuracion') ?>" href="/configuracion"><i class="bi bi-sliders"></i> Configuración</a><?php endif ?>
    <?php if ($can('integrations.manage')): ?><a class="sidebar-link <?= $active('integraciones') ?>" href="/integraciones"><i class="bi bi-usb-plug"></i> Integraciones</a><?php endif ?>
    <a class="sidebar-link <?= $active('scope/select') ?>" href="/scope/select"><i class="bi bi-building-gear"></i> Alcance</a>
  </aside>

  <main class="app-main">
    <header class="app-header">
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary mobile-menu" type="button" data-sidebar-toggle aria-label="Abrir menú"><i class="bi bi-list"></i></button>
        <span class="scope-chip"><i class="bi bi-geo-alt-fill"></i> <?= esc(($scope['company_name'] ?? 'Sin empresa') . ' · ' . ($scope['site_name'] ?? 'Sin instalación')) ?></span>
      </div>
      <div class="dropdown">
        <button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown" type="button"><i class="bi bi-person-circle"></i> <?= esc($user->username ?? 'Usuario') ?></button>
        <ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item" href="/logout">Cerrar sesión</a></li></ul>
      </div>
    </header>
    <div class="offline-banner">Sin conexión. Los movimientos críticos están bloqueados y no se guardarán offline.</div>
    <div class="content-wrap">
      <div class="page-heading"><div><h1><?= esc($title ?? '') ?></h1><p><?= esc($subtitle ?? 'Operación segura, trazable y en tiempo real.') ?></p></div><?= $this->renderSection('pageActions') ?></div>
      <?= $this->renderSection('content') ?>
    </div>
  </main>
</div>
<?php if ($flashMessage): ?><div hidden data-flash-message="<?= esc($flashMessage) ?>" data-flash-type="<?= esc($flashType) ?>"></div><?php endif ?>
<script src="/vendor/jquery/jquery.min.js"></script>
<script src="/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/vendor/datatables/dataTables.min.js"></script>
<script src="/vendor/datatables/dataTables.bootstrap5.min.js"></script>
<script src="/vendor/jszip/jszip.min.js"></script>
<script src="/vendor/datatables/dataTables.buttons.min.js"></script>
<script src="/vendor/datatables/buttons.bootstrap5.min.js"></script>
<script src="/vendor/datatables/buttons.html5.min.js"></script>
<script src="/vendor/chartjs/chart.umd.js"></script>
<script src="/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="/vendor/select2/select2.full.min.js"></script>
<script src="/assets/js/app.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
