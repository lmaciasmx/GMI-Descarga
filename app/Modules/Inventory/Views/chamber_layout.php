<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<style>
  .chamber-layout-page .summary-card {
    border: 0;
    box-shadow: 0 0.125rem 0.45rem rgba(0, 0, 0, .06);
  }

  .chamber-layout-page .layout-scroll {
    overflow: auto;
    max-height: calc(100vh - 330px);
    min-height: 420px;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: var(--bs-body-bg);
  }

  .chamber-layout-page .layout-board {
    min-width: 1050px;
    padding: 1rem;
  }

  .chamber-layout-page .sector-block {
    border: 1px solid var(--bs-border-color);
    border-radius: .65rem;
    overflow: hidden;
    background: var(--bs-body-bg);
    min-width: 235px;
  }

  .chamber-layout-page .sector-title {
    padding: .55rem .65rem;
    font-weight: 700;
    border-bottom: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
    position: sticky;
    top: 0;
    z-index: 2;
  }

  .chamber-layout-page .position-row {
    display: grid;
    grid-template-columns: 50px repeat(6, 1fr);
    gap: .3rem;
    align-items: center;
    padding: .35rem .45rem;
    border-bottom: 1px solid var(--bs-border-color-translucent);
  }

  .chamber-layout-page .position-row:last-child {
    border-bottom: 0;
  }

  .chamber-layout-page .position-number {
    font-size: .8rem;
    font-weight: 700;
    text-align: center;
  }

  .chamber-layout-page .stack-cell {
    min-width: 27px;
    height: 28px;
    border-radius: .35rem;
    border: 1px solid transparent;
    cursor: pointer;
    font-size: .7rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform .12s ease, box-shadow .12s ease;
  }

  .chamber-layout-page .stack-cell:hover {
    transform: translateY(-1px);
    box-shadow: 0 .2rem .5rem rgba(0, 0, 0, .15);
  }

  .chamber-layout-page .stack-free {
    background: rgba(25, 135, 84, .16);
    border-color: rgba(25, 135, 84, .55);
    color: var(--bs-success-text-emphasis);
  }

  .chamber-layout-page .stack-occupied {
    background: rgba(13, 110, 253, .18);
    border-color: rgba(13, 110, 253, .6);
    color: var(--bs-primary-text-emphasis);
  }

  .chamber-layout-page .stack-blocked {
    background: rgba(220, 53, 69, .18);
    border-color: rgba(220, 53, 69, .65);
    color: var(--bs-danger-text-emphasis);
  }

  .chamber-layout-page .legend-dot {
    width: 12px;
    height: 12px;
    border-radius: 3px;
    display: inline-block;
  }

  .chamber-layout-page .sector-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(235px, 1fr));
    gap: .75rem;
  }

  @media (max-width: 1400px) {
    .chamber-layout-page .sector-grid {
      grid-template-columns: repeat(3, minmax(235px, 1fr));
    }
  }

  .chamber-layout-page .empty-layout {
    min-height: 350px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
  }

  .chamber-layout-page .detail-list dt {
    color: var(--bs-secondary-color);
    font-weight: 600;
  }
</style>

<div class="chamber-layout-page">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
    <div>
      <h1 class="h4 mb-1">Mapa de cámaras</h1>
      <div class="text-secondary">
        Disponibilidad física por posición y estiba.
      </div>
    </div>

    <div style="min-width: 280px;">
      <label class="form-label fw-semibold mb-1" for="chamberSelector">Cámara</label>
      <select class="form-select" id="chamberSelector">
        <?php foreach ($chambers as $chamber): ?>
          <option
            value="<?= (int) $chamber['id'] ?>"
            <?= (int) $selectedChamberId === (int) $chamber['id'] ? 'selected' : '' ?>
          >
            <?= esc($chamber['code'] . ' · ' . $chamber['name']) ?>
          </option>
        <?php endforeach ?>
      </select>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
      <div class="card summary-card h-100">
        <div class="card-body py-3">
          <div class="text-secondary small">Total de estibas</div>
          <div class="fs-4 fw-bold" data-summary="total">0</div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card summary-card h-100">
        <div class="card-body py-3">
          <div class="text-secondary small">Libres</div>
          <div class="fs-4 fw-bold text-success" data-summary="free">0</div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card summary-card h-100">
        <div class="card-body py-3">
          <div class="text-secondary small">Ocupadas</div>
          <div class="fs-4 fw-bold text-primary" data-summary="occupied">0</div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="card summary-card h-100">
        <div class="card-body py-3">
          <div class="text-secondary small">Inhabilitadas</div>
          <div class="fs-4 fw-bold text-danger" data-summary="blocked">0</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div>
        <div class="fw-bold" id="layoutTitle">Cargando cámara...</div>
        <small class="text-secondary" id="layoutSubtitle"></small>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-3 small">
        <span class="d-flex align-items-center gap-1">
          <span class="legend-dot stack-free"></span> Libre
        </span>
        <span class="d-flex align-items-center gap-1">
          <span class="legend-dot stack-occupied"></span> Ocupada
        </span>
        <span class="d-flex align-items-center gap-1">
          <span class="legend-dot stack-blocked"></span> Inhabilitada
        </span>
      </div>
    </div>

    <div class="card-body p-2">
      <div class="layout-scroll">
        <div class="layout-board" id="layoutBoard">
          <div class="empty-layout text-secondary">
            <div>
              <div class="spinner-border spinner-border-sm mb-2" role="status"></div>
              <div>Cargando distribución...</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="stackDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h2 class="modal-title fs-5" id="stackModalTitle">Detalle de estiba</h2>
          <small class="text-secondary" id="stackModalPath"></small>
        </div>
        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <div id="stackDetailContent"></div>

        <div class="mt-4 d-none" id="restrictionFormWrap">
          <hr>
          <h3 class="h6">Inhabilitar ubicación</h3>

          <form id="restrictionForm" class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="restrictionType">Tipo</label>
              <select class="form-select" id="restrictionType" required>
                <option value="">Seleccione...</option>
                <option value="humidifier">Humidificador</option>
                <option value="maintenance">Mantenimiento</option>
                <option value="obstacle">Obstáculo físico</option>
                <option value="reserved">Reserva operativa</option>
                <option value="other">Otro</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="restrictionReason">Motivo</label>
              <input
                class="form-control"
                id="restrictionReason"
                maxlength="150"
                required
              >
            </div>

            <div class="col-12">
              <label class="form-label" for="restrictionNotes">Observaciones</label>
              <textarea
                class="form-control"
                id="restrictionNotes"
                rows="3"
              ></textarea>
            </div>

            <div class="col-12 text-end">
              <button class="btn btn-danger" type="submit">
                <i class="bi bi-slash-circle"></i> Inhabilitar
              </button>
            </div>
          </form>
        </div>
      </div>

      <div class="modal-footer">
        <button
          class="btn btn-outline-success d-none"
          type="button"
          id="releaseRestrictionButton"
        >
          <i class="bi bi-unlock"></i> Liberar ubicación
        </button>

        <button class="btn btn-light" type="button" data-bs-dismiss="modal">
          Cerrar
        </button>
      </div>
    </div>
  </div>
</div>

<div hidden id="chamberLayoutContext"><?= json_encode([
    'selected_chamber_id' => (int) $selectedChamberId,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/chamber-layout.js"></script>
<?= $this->endSection() ?>
