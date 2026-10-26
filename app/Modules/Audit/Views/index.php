<?= $this->extend('layouts/app') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="/assets/css/audit-center.css?v=202">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$filterQuery = static function (array $override = []) use ($filters): string {
    $params = array_merge($filters, $override);
    $params = array_filter($params, static fn ($value): bool => $value !== '' && $value !== null);
    return http_build_query($params);
};

$securityCount = count($securityEvents ?? []);
?>

<div class="gm-audit">
  <section class="audit-hero">
    <div>
      <span class="audit-eyebrow">CONTROL Y TRAZABILIDAD</span>
      <h2>Auditoría del sistema</h2>
      <p>Consulta quién realizó una operación, cuándo ocurrió, qué registro afectó y qué cambió.</p>
    </div>
    <div class="audit-live-badge"><span></span> Datos actuales</div>
  </section>

  <section class="audit-filter-card">
    <form method="get" action="/auditoria" class="audit-filter-grid">
      <div>
        <label for="auditFrom">Desde</label>
        <input class="form-control" id="auditFrom" name="from" type="date" value="<?= esc($filters['from']) ?>">
      </div>

      <div>
        <label for="auditTo">Hasta</label>
        <input class="form-control" id="auditTo" name="to" type="date" value="<?= esc($filters['to']) ?>">
      </div>

      <div>
        <label for="auditActor">Usuario / actor</label>
        <select class="form-select" id="auditActor" name="actor">
          <option value="">Todos</option>
          <?php foreach (($facets['actors'] ?? []) as $option): ?>
            <option value="<?= esc($option['value']) ?>" <?= $filters['actor'] === $option['value'] ? 'selected' : '' ?>>
              <?= esc($option['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="auditAggregate">Módulo / entidad</label>
        <select class="form-select" id="auditAggregate" name="aggregate">
          <option value="">Todos</option>
          <?php foreach (($facets['aggregates'] ?? []) as $option): ?>
            <option value="<?= esc($option['value']) ?>" <?= $filters['aggregate'] === $option['value'] ? 'selected' : '' ?>>
              <?= esc($option['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="auditAction">Acción</label>
        <select class="form-select" id="auditAction" name="action">
          <option value="">Todas</option>
          <?php foreach (($facets['actions'] ?? []) as $option): ?>
            <option value="<?= esc($option['value']) ?>" <?= $filters['action'] === $option['value'] ? 'selected' : '' ?>>
              <?= esc($option['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="audit-search">
        <label for="auditQ">Buscar referencia</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input
            class="form-control"
            id="auditQ"
            name="q"
            maxlength="120"
            placeholder="Folio, tina, ID, correlación, IP..."
            value="<?= esc($filters['q']) ?>"
          >
        </div>
      </div>

      <div class="audit-filter-actions">
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Aplicar</button>
        <a class="btn btn-light" href="/auditoria"><i class="bi bi-x-circle"></i> Limpiar</a>
      </div>
    </form>
  </section>

  <section class="audit-kpis">
    <?php foreach ($kpis as $kpi): ?>
      <article class="audit-kpi">
        <div class="audit-kpi-icon"><i class="bi <?= esc($kpi['icon']) ?>"></i></div>
        <div>
          <span><?= esc($kpi['label']) ?></span>
          <strong><?= number_format((int) $kpi['value']) ?></strong>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="audit-main-card">
    <header class="audit-card-header">
      <div>
        <span class="audit-eyebrow">EVENTOS OPERATIVOS</span>
        <h3>Bitácora de auditoría</h3>
        <p>Los valores técnicos se presentan en lenguaje operativo; el registro original permanece intacto.</p>
      </div>
      <span class="audit-limit">máximo <?= number_format((int) $maxRows) ?> en pantalla</span>
    </header>

    <?php if ($logs): ?>
      <div class="table-responsive audit-table-wrap">
        <table class="table align-middle audit-table" id="auditEventsTable">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Usuario / actor</th>
              <th>Módulo</th>
              <th>Acción</th>
              <th>Referencia</th>
              <th>Cambio</th>
              <th>Origen</th>
              <th>Detalle</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td class="audit-date"><?= esc($log['date_label']) ?></td>
                <td>
                  <strong class="audit-actor"><?= esc($log['actor_label']) ?></strong>
                  <small><?= esc(($log['actor_type'] ?? 'user') === 'user' ? 'Usuario del sistema' : ($log['actor_type'] ?? 'Servicio')) ?></small>
                </td>
                <td><span class="audit-module-badge"><?= esc($log['aggregate_label']) ?></span></td>
                <td class="audit-action"><?= esc($log['action_label']) ?></td>
                <td class="audit-reference"><?= esc($log['reference_label']) ?></td>
                <td class="audit-change-summary"><?= esc($log['change_summary']) ?></td>
                <td class="audit-origin">
                  <?= esc($log['ip_address'] ?? '—') ?>
                  <?php if (! empty($log['correlation_id'])): ?>
                    <small>Correlación: <?= esc($log['correlation_id']) ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <button
                    class="btn btn-sm btn-outline-primary"
                    type="button"
                    data-audit-open="<?= (int) $log['id'] ?>"
                  >
                    <i class="bi bi-search"></i> Ver
                  </button>

                  <template id="audit-detail-<?= (int) $log['id'] ?>">
                    <div class="audit-detail audit-detail-compact">
                      <div class="audit-detail-compact-head">
                        <div>
                          <span class="audit-eyebrow"><?= esc($log['aggregate_label']) ?></span>
                          <h4><?= esc($log['action_label']) ?></h4>
                          <p><?= esc($log['reference_label']) ?></p>
                        </div>
                        <span class="audit-detail-date"><?= esc($log['date_label']) ?></span>
                      </div>

                      <div class="audit-event-summary">
                        <i class="bi bi-info-circle"></i>
                        <span><?= esc($log['change_summary']) ?></span>
                      </div>

                      <div class="audit-meta-inline">
                        <span><i class="bi bi-person"></i> <?= esc($log['actor_label']) ?></span>
                        <span><i class="bi bi-clock"></i> <?= esc($log['date_label']) ?></span>
                        <span><i class="bi bi-pc-display"></i> <?= esc($log['ip_address'] ?? '—') ?></span>
                        <?php if (! empty($log['reason'])): ?>
                          <span class="audit-meta-reason"><i class="bi bi-chat-left-text"></i> <?= esc($log['reason']) ?></span>
                        <?php endif; ?>
                      </div>

                      <?php
                        $visibleChanges = array_values(array_filter(
                            $log['changes'],
                            static function (array $change): bool {
                                $field = (string) ($change['field'] ?? '');
                                return ! in_array($field, ['changed', 'legacy_reception'], true)
                                    && ! str_ends_with($field, '.changed')
                                    && ! str_ends_with($field, '.legacy_reception');
                            }
                        ));
                      ?>

                      <?php if ($visibleChanges): ?>
                        <div class="audit-compact-table-wrap">
                          <table class="table audit-compact-diff">
                            <thead>
                              <tr>
                                <th>Campo</th>
                                <th>Antes</th>
                                <th>Después</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($visibleChanges as $change): ?>
                                <tr>
                                  <td><?= esc($change['label']) ?></td>
                                  <td><?= esc($change['before']) ?></td>
                                  <td><?= esc($change['after']) ?></td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      <?php else: ?>
                        <div class="audit-empty-inline audit-empty-inline-compact">
                          Sin cambios operativos relevantes para mostrar.
                        </div>
                      <?php endif; ?>

                      <details class="audit-technical-details audit-technical-details-compact">
                        <summary>Información técnica</summary>
                        <div class="audit-technical-grid audit-technical-grid-compact">
                          <div><span>ID evento</span><strong>#<?= (int) $log['id'] ?></strong></div>
                          <div><span>Actor interno</span><strong><?= esc($log['actor_type'] ?? '—') ?></strong></div>
                          <div><span>Acción interna</span><strong><?= esc($log['action'] ?? '—') ?></strong></div>
                          <div><span>Entidad interna</span><strong><?= esc($log['aggregate_type'] ?? '—') ?></strong></div>
                          <div><span>ID entidad</span><strong><?= esc($log['aggregate_id'] ?? '—') ?></strong></div>
                          <div><span>Correlación</span><strong><?= esc($log['correlation_id'] ?? '—') ?></strong></div>
                        </div>

                        <?php
                          $technicalChanges = array_values(array_filter(
                              $log['changes'],
                              static function (array $change): bool {
                                  $field = (string) ($change['field'] ?? '');
                                  return in_array($field, ['changed', 'legacy_reception'], true)
                                      || str_ends_with($field, '.changed')
                                      || str_ends_with($field, '.legacy_reception');
                              }
                          ));
                        ?>

                        <?php if ($technicalChanges): ?>
                          <div class="audit-tech-changes">
                            <?php foreach ($technicalChanges as $change): ?>
                              <span>
                                <strong><?= esc($change['label']) ?>:</strong>
                                <?= esc($change['before']) ?> → <?= esc($change['after']) ?>
                              </span>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </details>

                      <?php if (! empty($log['correlation_id'])): ?>
                        <div class="audit-related audit-related-compact">
                          <div>
                            <i class="bi bi-link-45deg"></i>
                            <span>Hay eventos relacionados con esta operación.</span>
                          </div>
                          <a class="btn btn-sm btn-outline-secondary" href="/auditoria?<?= esc(http_build_query(['q' => $log['correlation_id']])) ?>">
                            Ver relacionados
                          </a>
                        </div>
                      <?php endif; ?>
                    </div>
                  </template>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="audit-empty">
        <i class="bi bi-journal-x"></i>
        <strong>No encontramos eventos</strong>
        <span>Ajusta los filtros para ampliar la consulta.</span>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($canSeeSecurity): ?>
    <section class="audit-main-card audit-security-card">
      <header class="audit-card-header">
        <div>
          <span class="audit-eyebrow">SEGURIDAD</span>
          <h3>Eventos de seguridad</h3>
          <p>Visible únicamente para perfiles autorizados.</p>
        </div>
        <span class="audit-limit"><?= number_format($securityCount) ?> evento(s)</span>
      </header>

      <?php if ($securityEvents): ?>
        <div class="table-responsive">
          <table class="table align-middle audit-table" id="auditSecurityTable">
            <thead>
              <tr><th>Fecha</th><th>Tipo</th><th>Severidad</th><th>Actor</th><th>Origen</th><th>Resultado</th></tr>
            </thead>
            <tbody>
              <?php foreach ($securityEvents as $event): ?>
                <?php
                  $severity = strtolower((string) ($event['severity'] ?? ''));
                  $severityClass = $severity === 'critical' ? 'is-critical' : ($severity === 'warning' ? 'is-warning' : 'is-info');
                ?>
                <tr>
                  <td class="audit-date"><?= esc($event['occurred_at'] ?? '—') ?></td>
                  <td><?= esc($event['event_label']) ?></td>
                  <td><span class="audit-severity <?= esc($severityClass) ?>"><?= esc($event['severity_label']) ?></span></td>
                  <td><?= esc((string) ($event['actor_user_id'] ?? 'Anónimo')) ?></td>
                  <td><?= esc($event['ip_address'] ?? '—') ?></td>
                  <td><?= esc($event['outcome_label']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="audit-empty audit-empty-security">
          <i class="bi bi-shield-check"></i>
          <strong>Sin eventos de seguridad registrados</strong>
          <span>No existen eventos en <code>security_events</code> en este momento.</span>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>

<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-labelledby="auditDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <span class="audit-eyebrow">DETALLE DE AUDITORÍA</span>
          <h5 class="modal-title" id="auditDetailModalLabel">Evento</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body" data-audit-modal-body></div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/js/audit-center.js?v=202"></script>
<?= $this->endSection() ?>
