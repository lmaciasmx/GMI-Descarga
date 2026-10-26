<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 22px 24px 28px; }
  body { font-family: "DejaVu Sans", sans-serif; color: #17324a; font-size: 9px; }
  .header { border-bottom: 2px solid #0b6fae; padding-bottom: 8px; margin-bottom: 10px; }
  .brand { font-size: 11px; font-weight: bold; letter-spacing: .8px; color: #0b6fae; }
  h1 { font-size: 18px; margin: 3px 0 2px; color: #14324a; }
  .desc { color: #627487; font-size: 9px; }
  .meta { width: 100%; margin: 8px 0 10px; border-collapse: collapse; }
  .meta td { width: 25%; border: 1px solid #dce5ec; padding: 5px 6px; vertical-align: top; }
  .meta .label { display: block; font-size: 7px; color: #718096; text-transform: uppercase; }
  .meta .value { display: block; margin-top: 2px; font-weight: bold; }
  .kpis { width: 100%; border-collapse: separate; border-spacing: 5px; margin: 0 0 9px; }
  .kpis td { background: #f4f8fb; border: 1px solid #dce5ec; padding: 6px; }
  .kpis .label { color: #718096; font-size: 7px; text-transform: uppercase; }
  .kpis .value { font-size: 11px; font-weight: bold; margin-top: 2px; }
  table.detail { width: 100%; border-collapse: collapse; }
  .detail th { background: #0b6fae; color: #fff; font-size: 7px; padding: 5px 4px; text-align: left; }
  .detail td { border: 1px solid #dce5ec; padding: 4px; font-size: 7.4px; vertical-align: top; }
  .detail tr:nth-child(even) td { background: #f8fafc; }
  .note { margin-top: 8px; color: #718096; font-size: 7.5px; }
  .footer { position: fixed; bottom: -16px; left: 0; right: 0; font-size: 7px; color: #8795a5; text-align: right; }
</style>
</head>
<body>
<?php
$scope = $export['scope'] ?? [];
$filters = $export['filters'] ?? [];
$period = 'Sin filtro de fecha';
if (! empty($filters['from']) || ! empty($filters['to'])) {
    $period = ($filters['from'] ?? 'Inicio') . ' a ' . ($filters['to'] ?? 'Actual');
}
?>
<div class="header">
  <div class="brand">GRUPOMAR · CENTRO DE REPORTES</div>
  <h1><?= esc($export['title'] ?? 'Reporte') ?></h1>
  <div class="desc"><?= esc($export['description'] ?? '') ?></div>
</div>

<table class="meta">
  <tr>
    <td><span class="label">Periodo</span><span class="value"><?= esc($period) ?></span></td>
    <td><span class="label">Compañía</span><span class="value"><?= esc($scope['company'] ?? 'Actual') ?></span></td>
    <td><span class="label">Sitio</span><span class="value"><?= esc($scope['site'] ?? 'Actual') ?></span></td>
    <td><span class="label">Almacén</span><span class="value"><?= esc($scope['warehouse'] ?? 'Actual') ?></span></td>
  </tr>
</table>

<?php if (! empty($export['kpis'])): ?>
<table class="kpis">
  <tr>
    <?php foreach ($export['kpis'] as $kpi): ?>
      <td>
        <div class="label"><?= esc($kpi['label'] ?? '') ?></div>
        <div class="value"><?= esc($kpi['value'] ?? '') ?></div>
      </td>
    <?php endforeach; ?>
  </tr>
</table>
<?php endif; ?>

<?php if (! empty($export['pdfRows'])): ?>
<table class="detail">
  <thead>
    <tr>
      <?php foreach (($export['columns'] ?? []) as $label): ?>
        <th><?= esc($label) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($export['pdfRows'] as $row): ?>
      <tr>
        <?php foreach (($export['columns'] ?? []) as $key => $label): ?>
          <td><?= esc($row[$key] ?? '—') ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<p>Sin registros para los filtros seleccionados.</p>
<?php endif; ?>

<?php if (($export['rowCount'] ?? 0) > count($export['pdfRows'] ?? [])): ?>
<div class="note">
  El PDF muestra <?= number_format(count($export['pdfRows'])) ?> de <?= number_format((int)$export['rowCount']) ?> registros.
  Use Excel para consultar el detalle completo.
</div>
<?php endif; ?>

<div class="note">
  Generado por <?= esc($export['generatedBy'] ?? 'Usuario') ?> · <?= esc($export['generatedAt'] ?? '') ?>
</div>
<div class="footer">GRUPOMAR · Documento generado desde Centro de Reportes</div>
</body>
</html>
