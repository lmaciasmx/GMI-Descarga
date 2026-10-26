<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,sans-serif;font-size:8.5px;color:#1f2937}h1{font-size:16px;margin:0 0 4px}p{margin:0 0 8px;color:#6b7280}.meta{margin-bottom:10px}.badge{display:inline-block;padding:2px 5px;border:1px solid #d1d5db;border-radius:8px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dfe3e8;padding:4px;vertical-align:top}th{background:#eef4f7;text-align:left;font-size:8px}tr:nth-child(even){background:#fafafa}.annulled{color:#8a1c1c;font-weight:bold}.small{font-size:7px;color:#6b7280}
</style>
</head>
<body>
<h1>Reporte de Calidad</h1>
<p>Inspecciones y dictámenes según los filtros aplicados en la pantalla de Calidad.</p>
<div class="meta">Generado: <?= esc($generatedAt) ?> · Registros: <?= number_format(count($rows)) ?></div>
<table>
<thead><tr><th>Inspección</th><th>Fecha</th><th>Tina</th><th>Lote</th><th>Producto</th><th>Grado</th><th>Defecto</th><th>Temp.</th><th>Resultado</th><th>Estado</th><th>Inspector</th><th>Observaciones</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td>INSP-<?= str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT) ?></td>
<td><?= esc($row['inspected_at']) ?></td>
<td><?= esc($row['tub_code']) ?><div class="small"><?= esc($row['weighing_folio']) ?></div></td>
<td><?= esc($row['lot_code']) ?></td>
<td><?= esc($row['species_name']) ?><div class="small"><?= esc($row['size_name']) ?></div></td>
<td><?= esc($row['quality_grade'] ?? '—') ?></td>
<td><?= $row['defect_percent'] === null ? '—' : number_format((float) $row['defect_percent'], 3) . '%' ?><div class="small"><?= esc($row['defect_names'] ?? '') ?></div></td>
<td><?= $row['temperature_c'] === null ? '—' : number_format((float) $row['temperature_c'], 2) . ' °C' ?></td>
<td><?= esc([ 'accepted'=>'Sin incidencia','observed'=>'Observación','hold'=>'Retener tina','hold_lot'=>'Retener lote','released'=>'Liberada','released_lot'=>'Lote liberado','rejected'=>'Rechazada' ][$row['disposition']] ?? $row['disposition']) ?></td>
<td class="<?= $row['status'] === 'annulled' ? 'annulled' : '' ?>"><?= esc($row['status'] === 'submitted' ? 'Pendiente' : ($row['status'] === 'annulled' ? 'Anulada' : 'Completada')) ?><?php if ($row['status'] === 'annulled' && ! empty($row['annul_reason'])): ?><div class="small"><?= esc($row['annul_reason']) ?></div><?php endif ?></td>
<td><?= esc($row['inspector_name']) ?></td>
<td><?= esc($row['observations'] ?? '') ?></td>
</tr>
<?php endforeach ?>
<?php if ($rows === []): ?><tr><td colspan="12">No hay registros para los filtros seleccionados.</td></tr><?php endif ?>
</tbody>
</table>
</body>
</html>
