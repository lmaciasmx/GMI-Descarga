<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="/assets/css/warehouse-requests.css?v=4.6.2">
<?php if (($mode ?? 'requests') !== 'requests'): ?>
<link rel="stylesheet" href="/assets/css/warehouse-station.css">
<?php endif ?>
<?php if (($mode ?? 'requests') === 'outgoing'): ?>
<link rel="stylesheet" href="/assets/css/warehouse-outgoing-station-v2.css?v=1.0.0">
<?php endif ?>
<link rel="stylesheet" href="/assets/css/warehouse-requests-ui.css?v=1.0.0">
<link rel="stylesheet" href="/assets/css/warehouse-requests-selector.css?v=1.0.8">
<link rel="stylesheet" href="/assets/css/warehouse-requests-history.css?v=1.0.0">
<?= $this->endSection() ?>
<?= $this->section('pageActions') ?>
<?php if (($mode ?? 'requests') === 'requests' && $catalogs !== null): ?>
<div class="wr-layout-actions">
  <button type="button" class="btn btn-outline-secondary wr-refresh-btn" id="wr-refresh"><i class="bi bi-arrow-clockwise me-1"></i>Actualizar</button>
  <?php if ($canOperate): ?>
  <div class="btn-group" role="group" aria-label="Operaciones de almacén">
    <a class="btn btn-sm btn-primary" href="/solicitudes/almacenes">Solicitudes</a>
    <a class="btn btn-sm btn-outline-primary" href="/solicitudes/almacenes/salidas">Salidas</a>
    <a class="btn btn-sm btn-outline-primary" href="/solicitudes/almacenes/entradas">Entradas</a>
  </div>
  <?php endif ?>
</div>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if ($catalogs === null): ?>
<div class="alert alert-info">Selecciona tu almacén en Alcance para solicitar o atender mercancía.</div>
<?php else: ?>
<?php
  $wrTypeConfig = config(\Config\WarehouseRequestTypes::class);
  $wrAllowedRequestTypes = $wrTypeConfig->allowedForWarehouse($catalogs['scope'] ?? []);
  $wrCanTransfer = $canCreate && in_array('transfer', $wrAllowedRequestTypes, true);
  $wrCanProduction = $canCreate && in_array('production', $wrAllowedRequestTypes, true);
  $wrCanSale = $canSale && in_array('sale', $wrAllowedRequestTypes, true);
  $canCapture = $wrCanTransfer || $wrCanProduction || $wrCanSale;
?>
<div hidden id="wr-context"><?= json_encode(['mode'=>$mode,'catalogs'=>$catalogs,'canCreate'=>$canCreate,'canSale'=>$canSale,'canCapture'=>$canCapture,'canHistory'=>$canHistory,'canAccept'=>$canAccept,'canSelect'=>$canSelect,'canOperate'=>$canOperate,'allowedRequestTypes'=>$wrAllowedRequestTypes], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></div>
<div id="wr-app" class="wr-shell <?= $mode!=='requests'?'wr-operation-mode':'' ?>">
  <div id="wr-message" class="alert d-none" role="status" aria-live="polite"></div>

  <?php if ($mode === 'requests'): ?>
  <section class="wr-switchboard wr-switchboard-clean" aria-label="Tipo de solicitud">
    <?php if ($canCapture): ?>
    <div class="wr-segment wr-request-types" aria-label="Tipo de solicitud">
      <?php if ($wrCanTransfer): ?>
      <button type="button" class="wr-segment-btn active" id="wr-type-transfer" data-request-type="transfer">
        <i class="bi bi-arrow-left-right"></i><span>Traspaso interno</span>
      </button>
      <?php endif ?>
      <?php if ($wrCanProduction): ?>
      <button type="button" class="wr-segment-btn <?= !$wrCanTransfer?'active':'' ?>" id="wr-type-production" data-request-type="production">
        <i class="bi bi-box-seam"></i><span>Materia prima</span>
      </button>
      <?php endif ?>
      <?php if ($wrCanSale): ?>
      <button type="button" class="wr-segment-btn <?= (!$wrCanTransfer && !$wrCanProduction)?'active':'' ?>" id="wr-type-sale" data-request-type="sale">
        <i class="bi bi-cart-check"></i><span>Venta a cliente</span>
      </button>
      <?php endif ?>
    </div>
    <?php endif ?>

    <?php if ($canHistory): ?>
    <button type="button" class="btn btn-outline-primary wr-registered-action <?= $canCapture?'':'active' ?>" id="wr-view-list" data-work-view="list">
      <i class="bi bi-list-ul me-1"></i><span>Solicitudes registradas</span>
    </button>
    <?php endif ?>
  </section>

  <?php if ($canCapture): ?>
  <section id="wr-capture-view" class="wr-work-view">
    <div class="wr-layout-grid">
      <div class="wr-main-column">
        <section id="wr-create" class="wr-card">
          <form id="wr-form">
            <input type="hidden" id="wr-type" value="<?= esc($wrAllowedRequestTypes[0] ?? 'transfer') ?>">
            <div class="wr-card-title"><span class="wr-step-badge">1</span><div><strong>Datos generales de la solicitud</strong><small>Define quién surtirá y cuándo se requiere el producto.</small></div></div>
            <div class="wr-card-body">
              <div class="wr-general-grid">
                <div class="wr-general-provider" id="wr-provider-group">
                  <label for="wr-source" class="form-label">Almacén proveedor <span class="text-danger">*</span></label>
                  <select id="wr-source" class="form-select"></select>
                </div>
                <div class="wr-general-provider d-none" id="wr-sale-origin-group">
                  <label class="form-label">Almacén que surte</label>
                  <div class="form-control bg-light" id="wr-sale-origin" aria-readonly="true"><?= esc($catalogs['scope']['company_name'].' · '.$catalogs['scope']['site_name'].' · '.$catalogs['scope']['name']) ?></div>
                </div>
                <div class="wr-general-provider d-none" id="wr-production-destination-group">
                  <label for="wr-production-destination" class="form-label">Área destino de producción <span class="text-danger">*</span></label>
                  <input id="wr-production-destination" class="form-control" type="text" maxlength="120"
                         list="wr-production-destination-options" autocomplete="off"
                         placeholder="Ej. Clasificado, Medallones, Lomos">
                  <datalist id="wr-production-destination-options">
                    <option value="Clasificado">
                    <option value="Medallones">
                    <option value="Lomos">
                    <option value="Filete">
                    <option value="Empaque">
                  </datalist>
                </div>
                <div class="wr-general-provider d-none" id="wr-customer-group">
                  <label for="wr-customer-search" class="form-label">Cliente <span class="text-danger">*</span></label>
                  <div class="wr-customer-picker">
                    <div class="wr-customer-search-wrap">
                      <i class="bi bi-search" aria-hidden="true"></i>
                      <input id="wr-customer-search" class="form-control" type="search" autocomplete="off"
                             placeholder="Buscar por código, nombre o razón social" aria-autocomplete="list"
                             aria-controls="wr-customer-results">
                      <input id="wr-customer" type="hidden" value="">
                      <div id="wr-customer-results" class="wr-customer-results d-none" role="listbox" aria-label="Clientes encontrados"></div>
                    </div>
                    <button type="button" class="btn btn-outline-primary wr-customer-new-btn" id="wr-customer-new">
                      <i class="bi bi-plus-lg me-1"></i>Nuevo cliente
                    </button>
                  </div>
                </div>
                <div class="wr-general-required">
                  <label for="wr-required" class="form-label">Fecha y hora requeridas <span class="text-danger">*</span></label>
                  <input id="wr-required" class="form-control" type="datetime-local" required>
                </div>
                <div class="wr-general-observations">
                  <label for="wr-observations" class="form-label">Observaciones</label>
                  <textarea id="wr-observations" class="form-control" rows="2" maxlength="1000" placeholder="Escribe observaciones operativas (opcional)"></textarea>
                </div>
              </div>
            </div>
          </form>
        </section>

        <section class="wr-card mt-3">
          <div class="wr-card-title wr-card-title-actions">
            <div class="d-flex align-items-center gap-2"><span class="wr-step-badge">2</span><div><strong>Productos solicitados</strong><small>Cada línea conserva sus criterios de especie, talla, lote y propietario.</small></div></div>
            <button type="button" class="btn btn-primary btn-sm" id="wr-add-line"><i class="bi bi-plus-lg me-1"></i>Agregar línea</button>
          </div>
          <div class="wr-card-body p-0">
            <div class="table-responsive wr-products-scroll">
              <table class="table align-middle mb-0 wr-products" id="wr-products-table">
                <thead><tr><th>#</th><th>Especie</th><th>Talla</th><th>Lote</th><th>Propietario</th><th class="text-end">Kg requeridos</th><th id="wr-heading-coverage">Cobertura</th><th class="text-center" id="wr-heading-units">Tinas</th><th id="wr-heading-state">Estado</th><th class="text-end">Acciones</th></tr></thead>
                <tbody id="wr-lines"></tbody>
              </table>
            </div>
          </div>
        </section>
      </div>

      <aside class="wr-side-column" id="wr-capture-aside">
        <section class="wr-card wr-sticky-card">
          <div class="wr-card-title"><span class="wr-step-badge">3</span><div><strong>Resumen de la solicitud</strong><small>Se actualiza conforme capturas y seleccionas existencias.</small></div></div>
          <div class="wr-card-body">
            <div class="wr-summary-context">
              <div><span>Tipo de solicitud</span><strong id="wr-summary-type">Traspaso interno</strong></div>
              <div><span id="wr-summary-source-label">Almacén proveedor</span><strong id="wr-summary-source">Sin seleccionar</strong></div>
              <div><span id="wr-summary-destination-label">Almacén destino</span><strong id="wr-summary-destination"><?= esc($catalogs['scope']['name']) ?></strong></div>
              <div><span>Fecha requerida</span><strong id="wr-summary-required">Sin definir</strong></div>
            </div>
            <div class="wr-summary-metrics">
              <div><span>Total de líneas</span><strong id="wr-summary-lines">1</strong></div>
              <div><span>Kg solicitados</span><strong id="wr-summary-requested">0.000</strong></div>
              <div><span id="wr-summary-covered-label">Kg cubiertos</span><strong id="wr-summary-covered">0.000</strong></div>
              <div><span id="wr-summary-pending-label">Kg pendientes</span><strong id="wr-summary-pending">0.000</strong></div>
              <div><span id="wr-summary-units-label">Tinas seleccionadas</span><strong id="wr-summary-units">0</strong></div>
            </div>
          </div>
        </section>

        <section class="wr-card mt-3">
          <div class="wr-card-title"><span class="wr-step-badge">4</span><div><strong>Validaciones</strong><small>Controles previos al envío.</small></div></div>
          <div class="wr-card-body">
            <div class="wr-validation-list" id="wr-validation-list">
              <div data-validation="general"><i class="bi bi-circle"></i><span>Datos generales completos</span></div>
              <div data-validation="lines"><i class="bi bi-circle"></i><span>Líneas válidas</span></div>
              <div data-validation="duplicates"><i class="bi bi-circle"></i><span>Sin líneas duplicadas</span></div>
              <div data-validation="units"><i class="bi bi-circle"></i><span id="wr-validation-units-label">Sin tinas repetidas</span></div>
            </div>
          </div>
        </section>

        <section class="wr-card mt-3">
          <div class="wr-card-title"><span class="wr-step-badge">5</span><div><strong>Mensajes</strong><small>Alertas y estado actual de la captura.</small></div></div>
          <div class="wr-card-body">
            <div id="wr-live-messages" class="wr-live-messages" aria-live="polite"></div>
          </div>
        </section>
      </aside>
    </div>

    <div class="wr-capture-footer" aria-label="Acciones de la solicitud">
      <div class="wr-capture-footer-status">
        <i class="bi bi-info-circle"></i>
        <span id="wr-capture-footer-text">Completa la solicitud; los controles se actualizan automáticamente.</span>
      </div>
      <div class="wr-capture-actions">
        <button type="button" class="btn btn-outline-secondary" id="wr-reset-draft">Limpiar</button>
        <button type="submit" class="btn btn-primary" id="wr-submit-main" form="wr-form"><i class="bi bi-send me-1"></i>Enviar solicitud</button>
      </div>
    </div>
  </section>
  <?php endif ?>

  <?php if ($canHistory): ?>
  <section id="wr-list-view" class="wr-work-view <?= $canCapture?'d-none':'' ?>">
    <section class="wr-card wr-history-card">
      <div class="wr-card-title wr-history-heading">
        <div>
          <strong>Solicitudes registradas</strong>
          <small>Consulta y filtra traspasos, materia prima y ventas a cliente en un solo lugar.</small>
        </div>
        <?php if ($canCapture): ?>
        <button type="button" class="btn btn-primary btn-sm wr-history-new" id="wr-new"><i class="bi bi-plus-lg me-1"></i>Nueva solicitud</button>
        <?php endif ?>
      </div>

      <div class="wr-card-body wr-history-body">
        <form id="wr-list-filter" class="wr-history-filters">
          <div class="wr-history-filter wr-history-search">
            <label class="form-label" for="wr-search">Buscar folio o concepto</label>
            <div class="wr-history-input-icon">
              <i class="bi bi-search"></i>
              <input id="wr-search" class="form-control" placeholder="Ej. SAL-20260925, cliente, producto…" maxlength="100">
            </div>
          </div>

          <div class="wr-history-filter">
            <label class="form-label" for="wr-status">Estado</label>
            <select id="wr-status" class="form-select"></select>
          </div>

          <div class="wr-history-filter">
            <label class="form-label" for="wr-role">Relación</label>
            <select id="wr-role" class="form-select">
              <option value="">Todas las relaciones</option>
              <option value="provider">Me solicitan / debo surtir</option>
              <option value="receiver">Solicité producto</option>
            </select>
          </div>

          <div class="wr-history-filter">
            <label class="form-label" for="wr-history-type">Tipo de solicitud</label>
            <select id="wr-history-type" class="form-select">
              <option value="">Todos los tipos</option>
              <option value="transfer">Traspaso interno</option>
              <option value="production">Materia prima</option>
              <option value="sale">Venta a cliente</option>
            </select>
          </div>

          <div class="wr-history-filter wr-history-date-filter">
            <label class="form-label">Rango de fecha requerida</label>
            <div class="wr-history-date-range">
              <i class="bi bi-calendar3"></i>
              <input type="date" class="form-control" id="wr-history-from" aria-label="Fecha inicial">
              <span>—</span>
              <input type="date" class="form-control" id="wr-history-to" aria-label="Fecha final">
            </div>
          </div>

          <div class="wr-history-filter-action">
            <button class="btn btn-primary w-100" type="submit" id="wr-history-filter-submit"><i class="bi bi-funnel me-1"></i>Filtrar</button>
          </div>
        </form>

        <div class="wr-history-kpis" aria-label="Resumen de solicitudes">
          <div class="wr-history-kpi total">
            <span class="wr-history-kpi-icon"><i class="bi bi-file-earmark-text"></i></span>
            <div><span>Total de solicitudes</span><strong id="wr-history-total">0</strong><small>En todos los tipos</small></div>
          </div>
          <div class="wr-history-kpi pending">
            <span class="wr-history-kpi-icon"><i class="bi bi-clock"></i></span>
            <div><span>Pendientes</span><strong id="wr-history-pending">0</strong><small id="wr-history-pending-pct">0% del total</small></div>
          </div>
          <div class="wr-history-kpi accepted">
            <span class="wr-history-kpi-icon"><i class="bi bi-check-circle"></i></span>
            <div><span>Aceptadas</span><strong id="wr-history-accepted">0</strong><small id="wr-history-accepted-pct">0% del total</small></div>
          </div>
          <div class="wr-history-kpi supply">
            <span class="wr-history-kpi-icon"><i class="bi bi-box-seam"></i></span>
            <div><span>En surtido</span><strong id="wr-history-supply">0</strong><small id="wr-history-supply-pct">0% del total</small></div>
          </div>
          <div class="wr-history-kpi closed">
            <span class="wr-history-kpi-icon"><i class="bi bi-flag"></i></span>
            <div><span>Cerradas</span><strong id="wr-history-closed">0</strong><small id="wr-history-closed-pct">0% del total</small></div>
          </div>
        </div>
      </div>
    </section>

    <section class="wr-card mt-3 wr-history-table-card">
      <div class="wr-history-table-toolbar">
        <span id="wr-history-count">Mostrando 0 solicitudes</span>
        <select id="wr-history-per-page" class="form-select form-select-sm" aria-label="Solicitudes por página">
          <option value="10">10 por página</option>
          <option value="25">25 por página</option>
          <option value="50">50 por página</option>
          <option value="100">100 por página</option>
        </select>
      </div>

      <div class="table-responsive wr-history-table-wrap">
        <table class="table align-middle mb-0 wr-history-table">
          <thead>
            <tr>
              <th>Folio / tipo</th>
              <th>Relación (origen → destino)</th>
              <th>Requerido</th>
              <th class="text-end">Peso solicitado</th>
              <th>Cobertura</th>
              <th class="text-center">Tinas</th>
              <th>Estado</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody id="wr-list"></tbody>
        </table>
      </div>

      <div class="wr-history-pagination" id="wr-history-pagination" aria-label="Paginación de solicitudes"></div>
    </section>
  </section>
  <?php endif ?>

  <section id="wr-detail-view" class="wr-work-view d-none">
    <div id="wr-detail"></div>
  </section>
  <?php else: ?>
  <?php
    $wrStationUser = auth()->user();
    $wrStationScope = $catalogs['scope'] ?? [];
    $wrWarehouseLabel = trim((string) ($wrStationScope['name'] ?? 'Almacén actual'));
    $wrSiteLabel = trim((string) ($wrStationScope['company_name'] ?? '') . ' · ' . (string) ($wrStationScope['site_name'] ?? ''));
    $wrStationTitle = $mode === 'outgoing' ? 'Estación de salida' : 'Estación de entrada';
  ?>
  <div class="warehouse-station-shell wr-transfer-station <?= in_array($mode,['outgoing','incoming'],true)?'wr-outgoing-mode':'' ?> <?= $mode==='outgoing'?'wr-v2-outgoing':'' ?>" data-warehouse-station>
    <div class="warehouse-station-backdrop" data-station-menu-close></div>

    <aside class="warehouse-station-drawer" aria-label="Navegación de estación">
      <div class="warehouse-station-drawer-head">
        <div class="warehouse-station-brand-mark">GM</div>
        <div><strong>Grupo Mar</strong><span>Inventarios y almacenes</span></div>
        <button type="button" class="warehouse-station-icon-btn ms-auto" data-station-menu-close aria-label="Cerrar menú"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="warehouse-station-context">
        <small>ALCANCE ACTUAL</small>
        <strong><?= esc($wrWarehouseLabel) ?></strong>
        <span><?= esc($wrSiteLabel) ?></span>
      </div>
      <nav class="warehouse-station-nav">
        <a href="/solicitudes/almacenes"><i class="bi bi-clipboard-check"></i><span>Solicitudes</span></a>
        <a href="/solicitudes/almacenes/salidas" class="<?= $mode==='outgoing'?'active':'' ?>"><i class="bi bi-box-arrow-right"></i><span>Salidas</span></a>
        <a href="/solicitudes/almacenes/entradas" class="<?= $mode==='incoming'?'active':'' ?>"><i class="bi bi-box-arrow-in-down"></i><span>Entradas</span></a>
        <?php if ($wrStationUser->can('discharge.operate') && $wrStationUser->can('weighings.create') && $wrStationUser->can('labels.print')): ?>
        <a href="/operacion/entrada-frigorifico"><i class="bi bi-speedometer2"></i><span>Entrada a Frigorífico</span></a>
        <?php endif ?>
        <?php if ($wrStationUser->can('inventory.view')): ?>
        <a href="/inventario"><i class="bi bi-boxes"></i><span>Existencias</span></a>
        <?php endif ?>
      </nav>
      <div class="warehouse-station-drawer-foot">
        <span><i class="bi bi-person-circle"></i> <?= esc((string) $wrStationUser->username) ?></span>
        <a href="/solicitudes/almacenes"><i class="bi bi-arrow-left-circle"></i> Salir de estación</a>
      </div>
    </aside>

    <header class="warehouse-station-topbar">
      <div class="warehouse-station-topbar-left">
        <button type="button" class="warehouse-station-menu-btn" data-station-menu-open aria-label="Abrir menú"><i class="bi bi-list"></i></button>
        <?php if ($mode === 'outgoing'): ?>
        <div class="wr-v2-brand" aria-label="GRUPOMAR">
          <span class="wr-v2-brand-mark"><i class="bi bi-water"></i></span>
          <strong>GRUPOMAR</strong>
        </div>
        <span class="wr-v2-topbar-separator" aria-hidden="true"></span>
        <?php endif ?>
        <div class="wr-v2-station-heading">
          <h1><?php if ($mode === 'outgoing'): ?><i class="bi bi-box-seam wr-v2-title-icon"></i><?php endif ?><?= esc($wrStationTitle) ?></h1>
          <div class="warehouse-station-breadcrumb">
            <span><?= esc($wrWarehouseLabel) ?></span>
            <span class="warehouse-station-divider">/</span>
            <span id="wr-station-context-title"><?= $mode==='outgoing'?'Lee una tina para identificar la solicitud':'Lee una tina para identificar la solicitud en tránsito' ?></span>
          </div>
        </div>
      </div>
      <div class="warehouse-station-topbar-right">
        <?php if (in_array($mode, ['outgoing','incoming'], true)): ?>
        <div class="wr-topbar-scale" id="wr-scale-top-status"><span class="wr-device-dot"></span><span><strong>Báscula</strong><small>Verificando…</small></span></div>
        <?php endif ?>
        <?php if ($mode === 'outgoing'): ?>
        <div class="wr-v2-communication online" id="wr-v2-communication">
          <i class="bi bi-wifi"></i>
          <span><strong>Comunicación</strong><small id="wr-v2-communication-label">En línea</small></span>
        </div>
        <?php endif ?>
        <button type="button" class="warehouse-station-icon-btn wr-refresh-btn" id="wr-refresh" aria-label="Actualizar" title="Actualizar"><i class="bi bi-arrow-clockwise"></i></button>
        <div class="warehouse-station-user wr-v2-station-user">
          <i class="bi bi-person-circle"></i>
          <span><strong><?= esc((string) $wrStationUser->username) ?></strong><?php if ($mode === 'outgoing'): ?><small>Operador</small><?php endif ?></span>
        </div>
      </div>
    </header>

    <main class="warehouse-station-content">
      <div class="warehouse-station-container">



        <?php if ($mode === 'outgoing'): ?>
        <section id="wr-station" class="wr-outgoing-station" aria-label="Estación de salida por tina">
          <div class="wr-outgoing-grid">
            <div class="wr-outgoing-left">
              <section class="ws-card wr-outgoing-scan-card">
                <div class="ws-card-header wr-outgoing-card-head">
                  <div><h2><i class="bi bi-qr-code-scan me-1"></i> Leer tina</h2><small>El sistema identifica automáticamente la solicitud.</small></div>
                  <span class="ws-status-pill warning" id="wr-station-ready"><span class="status-dot"></span><span>Esperando tina</span></span>
                </div>
                <div class="ws-card-body">
                  <form id="wr-scan" class="wr-station-scan wr-outgoing-scan">
                    <label for="wr-qr" class="form-label visually-hidden">QR / identificación de tina</label>
                    <div class="input-group input-group-lg">
                      <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                      <input id="wr-qr" class="form-control" placeholder="Escanear o ingresar código de tina…" maxlength="200" autocomplete="off" required autofocus>
                      <button type="submit" class="btn btn-primary wr-identify-btn" aria-label="Identificar tina" title="Identificar tina"><i class="bi bi-search"></i></button>
                    </div>
                    <div class="wr-station-scan-help"><i class="bi bi-info-circle me-1"></i><span>El cursor queda listo para la siguiente lectura.<small>Puedes escanear con lector de código de barras.</small></span></div>
                  </form>
                </div>
              </section>

              <section class="ws-card wr-outgoing-unit-card">
                <div class="ws-card-header"><h2><i class="bi bi-box-seam me-1"></i> Tina actual</h2><span id="wr-unit-state" class="wr-mini-state neutral">Sin lectura</span></div>
                <div class="ws-card-body" id="wr-unit-info">
                  <div class="wr-empty-unit"><i class="bi bi-qr-code"></i><strong>Escanea una tina</strong><span>Aquí aparecerán especie, talla, lote, propietario y ubicación registrada.</span></div>
                </div>
              </section>
            </div>

            <div class="wr-outgoing-center">
              <section class="ws-card wr-outgoing-request-card">
                <div class="ws-card-header wr-v2-request-head">
                  <div><h2><i class="bi bi-file-earmark-text me-1"></i> Solicitud de salida</h2></div>
                  <span class="wr-manifest-count" id="wr-v2-request-count">0 de 0</span>
                </div>
                <div id="wr-outgoing-request" class="wr-outgoing-request-empty wr-v2-functional-request" aria-hidden="true">
                  <div><i class="bi bi-check-circle"></i><strong>Solicitud detectada</strong><span>Se mostrará al leer la tina.</span></div>
                </div>
                <div id="wr-outgoing-request-v2" class="wr-v2-request-visual">
                  <div class="wr-v2-request-empty"><i class="bi bi-file-earmark-text"></i><span>Escanea una tina para identificar la solicitud.</span></div>
                </div>
              </section>

              <section class="ws-card wr-outgoing-register-card">
                <div class="ws-card-header"><div><h2><i class="bi bi-truck me-1"></i> Registrar salida</h2><small id="wr-operation-hint">Lee la tina para habilitar la operación.</small></div></div>
                <div class="ws-card-body">
                  <form id="wr-unit-form" class="d-none">
                    <input type="hidden" id="wr-match-line" value="">
                    <div id="wr-unit-fields" class="d-none"></div>
                    <div class="wr-outgoing-operation-grid" id="wr-weighing-section">
                      <div class="wr-outgoing-scale-select">
                        <label for="wr-scale" class="form-label"><i class="bi bi-speedometer2 me-1"></i>Báscula configurada</label>
                        <select id="wr-scale" class="form-select" required></select>
                      </div>
                      <div class="wr-outgoing-weight-field" id="wr-weight-box">
                        <div class="wr-weight-label-row">
                          <label class="form-label mb-0">Peso bruto leído (kg)</label>
                          <?php if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production'): ?>
                          <label class="wr-test-mode-toggle" for="wr-test-mode" title="Permite registrar una lectura simulada sólo para pruebas locales">
                            <input class="form-check-input" type="checkbox" role="switch" id="wr-test-mode">
                            <span>Modo prueba</span>
                          </label>
                          <?php endif ?>
                        </div>
                        <div class="wr-outgoing-weight-row">
                          <div class="wr-outgoing-weight-display" id="wr-weight-display"><strong id="wr-weight">—</strong><span>kg</span></div>
                          <?php if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production'): ?>
                          <div class="wr-test-weight-input d-none" id="wr-test-weight-panel">
                            <input id="wr-test-weight" class="form-control" type="number" min="0.001" max="9999.999" step="0.001" inputmode="decimal" placeholder="Peso bruto ficticio">
                            <span>kg</span>
                          </div>
                          <?php endif ?>
                          <button type="button" class="btn btn-outline-primary" id="wr-read"><i class="bi bi-speedometer2 me-1"></i><span id="wr-read-label">Leer báscula</span></button>
                        </div>
                        <small id="wr-weight-raw" class="wr-weight-raw">Sin lectura</small>
                      </div>
                    </div>
                    <div class="wr-outgoing-submit-row">
                      <button type="submit" class="btn btn-primary btn-lg w-100" id="wr-unit-submit"><i class="bi bi-send-fill me-1"></i>Registrar salida</button>
                    </div>
                  </form>
                  <div id="wr-outgoing-register-empty" class="wr-register-empty"><i class="bi bi-arrow-left-circle"></i><span>Escanea una tina para cargar la báscula y registrar el peso.</span></div>
                </div>
              </section>
            </div>

            <aside class="ws-card wr-outgoing-manifest-card">
              <div class="ws-card-header wr-outgoing-manifest-title">
                <div><h2><i class="bi bi-box-seam me-1"></i> Tinas de la solicitud</h2><small>Listado de tinas correspondientes al folio actual.</small></div>
                <span class="wr-manifest-count" id="wr-manifest-count">0 tinas</span>
              </div>
              <div class="ws-card-body" id="wr-outgoing-manifest">
                <div class="wr-manifest-table-head" aria-hidden="true"><span>#</span><span>Código de tina</span><span>Peso (kg)</span><span>Estatus</span></div>
                <div class="wr-manifest-empty"><i class="bi bi-clipboard"></i><span>Escanea una tina para cargar el folio y su avance.</span></div>
              </div>
            </aside>
          </div>
        </section>

        <footer class="wr-v2-station-footer" id="wr-v2-station-footer">
          <div class="wr-v2-footer-left">
            <span><i class="bi bi-file-earmark-text"></i><strong id="wr-v2-footer-folio">Sin folio</strong></span>
            <span class="wr-v2-footer-divider"></span>
            <span><i class="bi bi-box-seam"></i><?= esc($wrWarehouseLabel) ?></span>
            <span class="wr-v2-footer-divider"></span>
            <span><i class="bi bi-person"></i><span id="wr-v2-footer-destination-label">Destino:</span> <strong id="wr-v2-footer-destination">—</strong></span>
          </div>
          <div class="wr-v2-footer-right">
            <span><i class="bi bi-calendar3"></i><span id="wr-v2-footer-date">—</span> <strong id="wr-v2-footer-time">—</strong></span>
            <span class="wr-v2-footer-divider"></span>
            <span>Estación de salida</span>
            <span class="wr-v2-footer-divider"></span>
            <strong>GRUPOMAR</strong>
          </div>
        </footer>

        <section id="wr-detail" class="d-none"></section>
        <?php else: ?>
        <section id="wr-detail" class="d-none wr-station-detail-host"></section>
        <section id="wr-station" class="wr-outgoing-station wr-incoming-station" aria-label="Estación de recepción por tina">
          <div class="wr-outgoing-grid">
            <div class="wr-outgoing-left">
              <section class="ws-card wr-outgoing-scan-card">
                <div class="ws-card-header wr-outgoing-card-head">
                  <div><h2><i class="bi bi-qr-code-scan me-1"></i> Leer tina</h2><small>El sistema identifica automáticamente la solicitud en tránsito.</small></div>
                  <span class="ws-status-pill warning" id="wr-station-ready"><span class="status-dot"></span><span>Esperando tina</span></span>
                </div>
                <div class="ws-card-body">
                  <form id="wr-scan" class="wr-station-scan wr-outgoing-scan">
                    <label for="wr-qr" class="form-label visually-hidden">QR / identificación de tina</label>
                    <div class="input-group input-group-lg">
                      <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                      <input id="wr-qr" class="form-control" placeholder="Escanear o ingresar código de tina…" maxlength="200" autocomplete="off" required autofocus>
                      <button type="submit" class="btn btn-primary wr-identify-btn" aria-label="Identificar tina" title="Identificar tina"><i class="bi bi-search"></i></button>
                    </div>
                    <div class="wr-station-scan-help"><i class="bi bi-keyboard me-1"></i> El cursor queda listo para la siguiente recepción.</div>
                  </form>
                </div>
              </section>

              <section class="ws-card wr-outgoing-unit-card">
                <div class="ws-card-header"><h2><i class="bi bi-box-seam me-1"></i> Tina actual</h2><span id="wr-unit-state" class="wr-mini-state neutral">Sin lectura</span></div>
                <div class="ws-card-body" id="wr-unit-info">
                  <div class="wr-empty-unit"><i class="bi bi-qr-code"></i><strong>Escanea una tina</strong><span>Aquí aparecerán producto, tara y pesos de referencia.</span></div>
                </div>
              </section>
            </div>

            <div class="wr-outgoing-center">
              <section class="ws-card wr-outgoing-request-card">
                <div id="wr-incoming-request" class="wr-outgoing-request-empty">
                  <div><i class="bi bi-check-circle"></i><strong>Solicitud detectada</strong><span>Se mostrará al leer la tina.</span></div>
                </div>
              </section>

              <section class="ws-card wr-outgoing-register-card">
                <div class="ws-card-header"><div><h2 id="wr-operation-title"><i class="bi bi-box-arrow-in-down me-1"></i> Registrar recepción</h2><small id="wr-operation-hint">Lee la tina para habilitar la recepción.</small></div></div>
                <div class="ws-card-body">
                  <form id="wr-unit-form" class="d-none">
                    <div id="wr-unit-fields" class="mb-2"></div>
                    <div id="wr-placement-box" class="row g-2 mb-2">
                      <div class="col-6">
                        <label for="wr-chamber" class="form-label">Cámara</label>
                        <select id="wr-chamber" class="form-select"><option value="">Selecciona cámara</option></select>
                      </div>
                      <div class="col-6">
                        <label for="wr-row" class="form-label">Fila</label>
                        <select id="wr-row" class="form-select"><option value="">Selecciona fila</option></select>
                      </div>
                    </div>
                    <div class="wr-outgoing-operation-grid" id="wr-weighing-section">
                      <div class="wr-outgoing-scale-select">
                        <label for="wr-scale" class="form-label"><i class="bi bi-speedometer2 me-1"></i>Báscula configurada</label>
                        <select id="wr-scale" class="form-select" required></select>
                      </div>
                      <div class="wr-outgoing-weight-field" id="wr-weight-box">
                        <div class="wr-weight-label-row">
                          <label class="form-label mb-0">Peso bruto leído (kg)</label>
                          <?php if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production'): ?>
                          <label class="wr-test-mode-toggle" for="wr-test-mode" title="Permite registrar una lectura simulada sólo para pruebas locales">
                            <input class="form-check-input" type="checkbox" role="switch" id="wr-test-mode">
                            <span>Modo prueba</span>
                          </label>
                          <?php endif ?>
                        </div>
                        <div class="wr-outgoing-weight-row">
                          <div class="wr-outgoing-weight-display" id="wr-weight-display"><strong id="wr-weight">—</strong><span>kg</span></div>
                          <?php if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production'): ?>
                          <div class="wr-test-weight-input d-none" id="wr-test-weight-panel">
                            <input id="wr-test-weight" class="form-control" type="number" min="0.001" max="9999.999" step="0.001" inputmode="decimal" placeholder="Peso ficticio">
                            <span>kg</span>
                          </div>
                          <?php endif ?>
                          <button type="button" class="btn btn-outline-primary" id="wr-read"><i class="bi bi-speedometer2 me-1"></i><span id="wr-read-label">Leer báscula</span></button>
                        </div>
                        <small id="wr-weight-raw" class="wr-weight-raw">Sin lectura</small>
                      </div>
                    </div>
                    <div class="wr-outgoing-submit-row mt-2">
                      <button type="submit" class="btn btn-success btn-lg w-100" id="wr-unit-submit"><i class="bi bi-check2-circle me-1"></i>Registrar recepción</button>
                    </div>
                  </form>
                  <div id="wr-incoming-register-empty" class="wr-register-empty"><i class="bi bi-arrow-left-circle"></i><span>Escanea una tina para seleccionar Cámara, Fila y registrar la recepción.</span></div>
                </div>
              </section>
            </div>

            <aside class="ws-card wr-outgoing-manifest-card">
              <div class="ws-card-header wr-outgoing-manifest-title">
                <div><h2><i class="bi bi-list-check me-1"></i> Detalle de la solicitud</h2><small>Tinas recibidas y pendientes del folio actual.</small></div>
                <span class="wr-manifest-count" id="wr-manifest-count">0 tinas</span>
              </div>
              <div class="ws-card-body" id="wr-incoming-manifest">
                <div class="wr-manifest-table-head" aria-hidden="true"><span>#</span><span>Código de tina</span><span>Peso (kg)</span><span>Estatus</span></div>
                <div class="wr-manifest-empty"><i class="bi bi-clipboard"></i><span>Escanea una tina para cargar el folio y su avance de recepción.</span></div>
              </div>
            </aside>
          </div>
        </section>
        <?php endif ?>
      </div>
    </main>
  </div>
  <?php endif ?>

  <?php if ($mode !== 'requests'): ?>
  <section id="wr-create" class="d-none"><form id="wr-form"><input id="wr-type" value="transfer"><select id="wr-source"></select><input id="wr-required"><textarea id="wr-observations"></textarea><table><tbody id="wr-lines"></tbody></table><button type="button" id="wr-add-line"></button><button type="button" id="wr-cancel-create"></button></form></section>
  <?php endif ?>


  <dialog id="wr-customer-dialog" class="wr-dialog wr-customer-dialog" aria-labelledby="wr-customer-dialog-title">
    <div class="wr-customer-dialog-head">
      <div>
        <div class="wr-eyebrow">Catálogo de Empresas</div>
        <h2 id="wr-customer-dialog-title" class="h5 mb-1">Nuevo cliente</h2>
        <p class="mb-0 text-secondary small">El registro se guardará como empresa de tipo Cliente.</p>
      </div>
      <button type="button" class="btn-close" id="wr-customer-dialog-close" aria-label="Cerrar"></button>
    </div>
    <form id="wr-customer-form">
      <div class="wr-customer-form-grid">
        <div>
          <label for="wr-customer-code" class="form-label">Código <span class="text-danger">*</span></label>
          <input id="wr-customer-code" class="form-control" maxlength="20" required placeholder="Ej. CLI001">
        </div>
        <div>
          <label for="wr-customer-tax-id" class="form-label">RFC / identificación fiscal</label>
          <input id="wr-customer-tax-id" class="form-control" maxlength="20" placeholder="Opcional">
        </div>
        <div class="wr-customer-form-wide">
          <label for="wr-customer-trade-name" class="form-label">Nombre comercial <span class="text-danger">*</span></label>
          <input id="wr-customer-trade-name" class="form-control" maxlength="120" required>
        </div>
        <div class="wr-customer-form-wide">
          <label for="wr-customer-legal-name" class="form-label">Razón social <span class="text-danger">*</span></label>
          <input id="wr-customer-legal-name" class="form-control" maxlength="180" required>
        </div>
      </div>
      <div class="wr-customer-dialog-actions">
        <button type="button" class="btn btn-outline-secondary" id="wr-customer-dialog-cancel">Cancelar</button>
        <button type="submit" class="btn btn-primary" id="wr-customer-save">
          <i class="bi bi-check2-circle me-1"></i>Guardar cliente
        </button>
      </div>
    </form>
  </dialog>

  <dialog id="wr-stock-dialog" class="wr-dialog wr-stock-dialog wr-stock-dialog-redesign" aria-labelledby="wr-stock-title">
    <div class="wr-stock-head">
      <div>
        <div class="wr-eyebrow">Selección de existencias</div>
        <h2 id="wr-stock-title" class="h5 mb-1">Seleccionar tinas</h2>
        <p class="mb-0 text-secondary small">Los filtros de esta modal no cambian la condición de la línea.</p>
      </div>
      <button type="button" class="btn-close" id="wr-stock-close" aria-label="Cerrar existencias"></button>
    </div>

    <div class="wr-stock-kpis" id="wr-stock-kpis">
      <div class="wr-stock-kpi">
        <span class="wr-stock-kpi-icon"><i class="bi bi-box-seam"></i></span>
        <span class="wr-stock-kpi-copy"><span>Producto</span><strong id="wr-stock-product">—</strong></span>
      </div>
      <div class="wr-stock-kpi">
        <span class="wr-stock-kpi-icon"><i class="bi bi-clipboard2-data"></i></span>
        <span class="wr-stock-kpi-copy"><span>Kg requeridos</span><strong id="wr-stock-required">0.000</strong></span>
      </div>
      <div class="wr-stock-kpi">
        <span class="wr-stock-kpi-icon"><i class="bi bi-check2-circle"></i></span>
        <span class="wr-stock-kpi-copy"><span>Kg cubiertos</span><strong id="wr-stock-covered">0.000</strong></span>
      </div>
      <div class="wr-stock-kpi">
        <span class="wr-stock-kpi-icon"><i class="bi bi-layers"></i></span>
        <span class="wr-stock-kpi-copy"><span>Tinas seleccionadas</span><strong id="wr-stock-selected-count">0</strong></span>
      </div>
    </div>

    <div class="wr-stock-scope-note" id="wr-stock-scope-note">
      <i class="bi bi-info-circle"></i>
      <span>La línea permite seleccionar producto compatible según sus criterios actuales.</span>
    </div>

    <div id="wr-stock-filter" class="wr-stock-filter-grid">
      <div class="wr-stock-filter-field"><label for="wr-stock-lot" class="form-label">Lote</label><select id="wr-stock-lot" class="form-select"><option value="">Todos los lotes</option></select></div>
      <div class="wr-stock-filter-field"><label for="wr-stock-owner" class="form-label">Propietario</label><select id="wr-stock-owner" class="form-select"></select></div>
      <div class="wr-stock-filter-field wr-stock-filter-search"><label for="wr-stock-search" class="form-label">Buscar tina</label><input id="wr-stock-search" class="form-control" maxlength="100" placeholder="Ej. 00125"></div>
      <div class="wr-stock-filter-field wr-stock-filter-state"><label for="wr-stock-state" class="form-label">Estado</label><select class="form-select" id="wr-stock-state" disabled><option>Disponibles</option></select></div>
      <div class="wr-stock-filter-action"><button type="button" class="btn btn-primary w-100" id="wr-stock-apply"><i class="bi bi-search"></i><span>Buscar</span></button></div>
      <select id="wr-stock-species" class="d-none" disabled></select><select id="wr-stock-size" class="d-none" disabled></select>
      <div id="wr-stock-capture-options" class="d-none"><input type="checkbox" id="wr-fix-lot"><input type="checkbox" id="wr-fix-owner"></div>
    </div>

    <div id="wr-stock-message" class="alert alert-danger d-none" role="alert"></div>

    <div class="wr-stock-summary">
      <p id="wr-stock-total" class="text-secondary mb-0"></p>
      <p id="wr-stock-selected" class="mb-0 fw-semibold"></p>
    </div>

    <div class="table-responsive wr-stock-table-wrap">
      <table class="table table-sm align-middle w-100" id="wr-stock-table">
        <thead><tr><th class="text-center">Sel.</th><th>Tina / etiqueta</th><th>Lote</th><th>Propietario</th><th class="text-end">Peso neto</th><th>Fecha ingreso</th><th>Ubicación</th><th>Estado</th></tr></thead>
        <tbody id="wr-stock-rows"></tbody>
      </table>
    </div>

    <div class="wr-stock-footer">
      <span id="wr-stock-footer-summary">Sin tinas seleccionadas.</span>
      <button type="button" class="btn btn-outline-secondary" id="wr-stock-done">Cerrar selección</button>
    </div>
  </dialog>

  <dialog id="wr-dialog" class="wr-dialog" aria-labelledby="wr-dialog-title"><form id="wr-dialog-form">
    <div class="d-flex justify-content-between mb-3"><h2 class="h5 mb-0" id="wr-dialog-title"></h2><button type="button" class="btn-close" id="wr-dialog-close" aria-label="Cerrar"></button></div>
    <div id="wr-dialog-message" class="alert alert-danger d-none" role="alert"></div><div id="wr-dialog-body"></div>
    <div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-outline-secondary" id="wr-dialog-cancel">Cancelar</button><button type="submit" class="btn btn-primary" id="wr-dialog-submit">Confirmar</button></div>
  </form></dialog>
</div>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<?php if (($mode ?? 'requests') !== 'requests'): ?><script src="/assets/js/warehouse-station.js"></script><?php endif ?>
<script src="/assets/js/warehouse-requests.js?v=4.7.0-sales-1.0.0"></script>
<?php if (($mode ?? 'requests') === 'outgoing'): ?>
<script src="/assets/js/warehouse-outgoing-station-v2.js?v=1.0.0"></script>
<script src="/assets/js/warehouse-sale-finalize-bridge.js?v=1.0.0"></script>
<?php endif ?>
<?= $this->endSection() ?>
