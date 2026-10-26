<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="/assets/css/sales-request-flow.css?v=1.0.7">
<?= $this->endSection() ?>

<?= $this->section('pageActions') ?>
<button class="btn btn-primary btn-operational" type="button" data-bs-toggle="modal" data-bs-target="#saleModal">
  <i class="bi bi-cart-plus"></i> Nueva orden
</button>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div hidden id="salesContext"><?= json_encode([
    'companyId' => (int) $scope['company_id'],
    'siteId' => (int) $scope['site_id'],
    'warehouseId' => (int) ($scope['warehouse_id'] ?? 0),
], JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?></div>

<div class="alert alert-info border-0">
  <strong>Separación contable:</strong> despachar una venta genera movimiento físico.
  Emitir o cancelar el CFDI en INVoiice sólo actualiza la referencia fiscal y nunca altera inventario.
</div>

<div class="card">
  <div class="card-header">Órdenes de venta y despacho</div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover" data-datatable data-export-title="Ventas-despachos">
        <thead>
          <tr>
            <th>Folio</th>
            <th>Cliente</th>
            <th>Unidades</th>
            <th>Peso kg</th>
            <th>Estado físico</th>
            <th>Despachado</th>
            <th>CFDI</th>
            <th>UUID fiscal</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
          <?php
            $integrated = (int) ($order['request_line_count'] ?? 0) > 0;
            $statusLabel = $integrated && $order['status'] === 'draft'
                ? 'Pendiente de surtido'
                : ($integrated && $order['status'] === 'allocated' ? 'Preparada' : $order['status']);
          ?>
          <tr>
            <td class="fw-bold"><?= esc($order['folio']) ?></td>
            <td><?= esc($order['customer_name']) ?></td>
            <td>
              <?= (int) $order['unit_count'] ?>
              <?php if ($integrated && (int) ($order['proposed_unit_count'] ?? 0) > 0): ?>
                <small class="d-block text-secondary"><?= (int) $order['proposed_unit_count'] ?> propuesta(s)</small>
              <?php endif ?>
            </td>
            <td>
              <?php if ($integrated): ?>
                <strong><?= number_format((float) ($order['requested_kg'] ?? 0), 3) ?></strong>
                <small class="d-block text-secondary">solicitados</small>
                <?php if ((float) ($order['total_kg'] ?? 0) > 0): ?>
                  <small class="d-block">Surtido: <?= number_format((float) $order['total_kg'], 3) ?></small>
                <?php endif ?>
              <?php else: ?>
                <?= number_format((float) $order['total_kg'], 3) ?>
              <?php endif ?>
            </td>
            <td><span class="badge-state <?= esc($order['status']) ?>"><?= esc($statusLabel) ?></span></td>
            <td><?= esc($order['dispatched_at'] ?? '—') ?></td>
            <td><?= esc($order['fiscal_status'] ?? 'Sin referencia') ?></td>
            <td><?= esc($order['fiscal_uuid'] ?? '—') ?></td>
            <td class="text-nowrap">
              <?php if ($integrated && $order['status'] === 'draft'): ?>
                <button class="btn btn-sm btn-primary" type="button" data-sale-prepare="<?= (int) $order['id'] ?>">
                  <i class="bi bi-box-seam me-1"></i>Preparar / surtir
                </button>
              <?php elseif (in_array($order['status'], ['draft', 'allocated'], true)): ?>
                <button class="btn btn-sm btn-success" type="button" data-sale-dispatch="<?= (int) $order['id'] ?>">Despachar</button>
              <?php endif ?>

              <?php if ($order['status'] === 'allocated' && $integrated): ?>
                <button class="btn btn-sm btn-outline-danger" type="button" data-sale-release="<?= (int) $order['id'] ?>">Liberar</button>
              <?php endif ?>

              <?php if ($canBill): ?>
                <button class="btn btn-sm btn-outline-primary" type="button" data-sale-billing="<?= (int) $order['id'] ?>">Referencia fiscal</button>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="salePrepareModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header sale-prepare-header">
        <div>
          <div class="sale-prepare-eyebrow">Surtido definitivo</div>
          <h2 class="modal-title fs-5 mb-1" id="salePrepareTitle">Preparar solicitud</h2>
          <div class="small text-secondary" id="salePrepareSubtitle">Las tinas propuestas son sólo referencia.</div>
        </div>
        <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="salePrepareSummary" class="sale-prepare-summary"></div>
        <div id="salePrepareTabs" class="sale-prepare-tabs"></div>

        <div class="sale-prepare-work">
          <div class="sale-prepare-line-head">
            <div id="salePrepareLineTitle"></div>
            <div id="salePrepareLineTotals"></div>
          </div>

          <div id="salePrepareProposal" class="sale-prepare-proposal"></div>

          <div class="sale-prepare-search">
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input class="form-control" id="salePrepareSearch" placeholder="Buscar tina, lote o propietario">
              <button class="btn btn-outline-secondary" type="button" id="salePrepareSearchBtn">Buscar</button>
            </div>
            <small class="text-secondary" id="salePrepareAvailabilityTotal"></small>
          </div>

          <div class="table-responsive sale-prepare-table-wrap">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th></th>
                  <th>Tina</th>
                  <th>Lote</th>
                  <th>Propietario</th>
                  <th>Ubicación</th>
                  <th class="text-end">Kg netos</th>
                  <th>Referencia</th>
                </tr>
              </thead>
              <tbody id="salePrepareRows"></tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <div class="me-auto small text-secondary">
          Al confirmar, las tinas elegidas por almacén sí quedarán reservadas.
        </div>
        <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="button" id="salePrepareConfirm">
          <i class="bi bi-check2-circle me-1"></i>Confirmar surtido y reservar
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="saleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form data-sale-form>
        <div class="modal-header">
          <div>
            <h2 class="modal-title fs-5">Nueva orden física de venta</h2>
            <small>La selección no reserva; la disponibilidad se valida otra vez al despachar.</small>
          </div>
          <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body industrial-form">
          <div class="row g-3 mb-3">
            <div class="col-md-5">
              <label class="form-label">Cliente</label>
              <select class="form-select" name="customer_company_id" required>
                <option value="">Seleccione…</option>
                <?php foreach ($customers as $customer): ?>
                  <option value="<?= (int) $customer['id'] ?>"><?= esc($customer['code'] . ' · ' . $customer['trade_name']) ?></option>
                <?php endforeach ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Requerido</label>
              <input class="form-control" name="required_at" type="datetime-local">
            </div>
            <div class="col-md-4">
              <label class="form-label">Observaciones</label>
              <input class="form-control" name="observations" maxlength="1000">
            </div>
          </div>
          <div class="table-responsive transfer-unit-list">
            <table class="table table-sm">
              <thead>
                <tr><th></th><th>Unidad</th><th>Lote / especie</th><th>Ubicación</th><th>Disponible</th><th>Vender kg</th><th>Precio/kg</th><th>Moneda</th></tr>
              </thead>
              <tbody>
              <?php foreach ($units as $unit): ?>
                <tr>
                  <td><input class="form-check-input" type="checkbox" data-sale-check></td>
                  <td class="fw-bold"><?= esc($unit['unit_code']) ?></td>
                  <td><?= esc($unit['lot_code']) ?><br><small><?= esc($unit['species_name']) ?></small></td>
                  <td><?= esc($unit['location_path']) ?></td>
                  <td><?= number_format((float) $unit['available_weight_kg'], 3) ?></td>
                  <td><input class="form-control form-control-sm" data-sale-quantity type="number" min="0.001" max="<?= esc((string) $unit['available_weight_kg']) ?>" step="0.001" value="<?= esc((string) $unit['available_weight_kg']) ?>" data-stock-id="<?= (int) $unit['id'] ?>"></td>
                  <td><input class="form-control form-control-sm" data-sale-price type="number" min="0" step="0.0001"></td>
                  <td><select class="form-select form-select-sm" data-sale-currency><option value="MXN">MXN</option><option value="USD">USD</option></select></td>
                </tr>
              <?php endforeach ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary btn-operational no-offline-confirm" type="submit">Crear orden</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/sales.js?v=1.2.0"></script>
<?= $this->endSection() ?>
