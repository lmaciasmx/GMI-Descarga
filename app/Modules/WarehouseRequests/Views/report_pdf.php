<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
@page { margin: 26px 28px; }

body {
    font-family: "DejaVu Sans", sans-serif;
    color: #18384f;
    font-size: 9px;
    line-height: 1.35;
    margin: 0;
    padding: 0;
}

table {
    width: 100%;
    border-collapse: collapse;
}

.header {
    margin-bottom: 14px;
}

.header td {
    border: 0;
    vertical-align: top;
    padding: 0;
}

.brand {
    color: #0b5790;
    font-size: 18px;
    font-weight: bold;
}

.kicker {
    color: #718695;
    font-size: 7px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-top: 2px;
}

.folio {
    color: #173751;
    font-size: 14px;
    font-weight: bold;
    margin-top: 2px;
}

.status {
    text-align: right;
    color: #0b6fae;
    font-size: 9px;
    font-weight: bold;
}

.type {
    text-align: right;
    color: #718695;
    font-size: 7px;
    margin-top: 4px;
}

.rule {
    border-top: 3px solid #0b6fae;
    margin: 8px 0 12px 0;
}

.section {
    margin-top: 13px;
}

.section-title {
    color: #173751;
    font-size: 10px;
    font-weight: bold;
    padding-bottom: 4px;
    border-bottom: 1px solid #d9e3ea;
    margin-bottom: 6px;
}

.kpis {
    border-collapse: separate;
    border-spacing: 5px 0;
}

.kpis td {
    width: 25%;
    border: 1px solid #d9e3ea;
    background: #f6f9fb;
    padding: 8px;
    vertical-align: top;
}

.kpi-label {
    color: #778b99;
    font-size: 6.5px;
    text-transform: uppercase;
}

.kpi-value {
    color: #163952;
    font-size: 13px;
    font-weight: bold;
    margin-top: 2px;
}

.kpi-note {
    color: #8798a4;
    font-size: 6.5px;
    margin-top: 2px;
}

.info td {
    border: 1px solid #dfe7ec;
    padding: 6px 7px;
    vertical-align: top;
}

.info-key {
    width: 16%;
    background: #f3f6f8;
    color: #6f8391;
    font-size: 6.5px;
    font-weight: bold;
    text-transform: uppercase;
}

.info-value {
    color: #203f56;
    font-weight: bold;
}

.muted {
    color: #7b909f;
    font-size: 7px;
    font-weight: normal;
}

.production td {
    border: 1px solid #cfe3d7;
    background: #f2f8f4;
    padding: 7px;
    vertical-align: top;
}

.prod-label {
    color: #648073;
    font-size: 6.5px;
    text-transform: uppercase;
}

.prod-value {
    color: #1d5c43;
    font-size: 9px;
    font-weight: bold;
    margin-top: 2px;
}

.data th {
    background: #0b6fae;
    color: #ffffff;
    border: 1px solid #0a6198;
    padding: 5px 4px;
    font-size: 6.5px;
    text-align: left;
}

.data td {
    border: 1px solid #dce4ea;
    padding: 4px;
    font-size: 7px;
    vertical-align: top;
}

.right { text-align: right; }
.center { text-align: center; }

.note {
    border: 1px solid #dbe5eb;
    background: #f7f9fb;
    padding: 8px;
    color: #405e72;
}

.appendix {
    margin-top: 15px;
    background: #edf3f7;
    color: #244d68;
    font-size: 8px;
    font-weight: bold;
    padding: 6px 7px;
}

.caption {
    color: #7b8f9e;
    font-size: 6.5px;
    margin: 4px 0 6px 0;
}

.footer {
    margin-top: 14px;
    border-top: 1px solid #d9e3ea;
    padding-top: 6px;
    color: #8395a2;
    font-size: 6.5px;
}
</style>
</head>
<body>

<table class="header">
    <tr>
        <td>
            <div class="brand">GRUPOMAR</div>
            <div class="kicker">Reporte ejecutivo - Solicitud entre almacenes</div>
            <div class="folio"><?= esc((string) $request['folio']) ?></div>
        </td>
        <td>
            <div class="status"><?= esc($requestStatusLabel) ?></div>
            <div class="type"><?= esc($requestTypeLabel) ?></div>
        </td>
    </tr>
</table>

<div class="rule"></div>

<div class="section">
    <div class="section-title">Resumen ejecutivo</div>
    <table class="kpis">
        <tr>
            <td>
                <div class="kpi-label">Tinas</div>
                <div class="kpi-value"><?= number_format((int) $totals['units']) ?></div>
                <div class="kpi-note">asociadas al folio</div>
            </td>
            <td>
                <div class="kpi-label">Despachadas</div>
                <div class="kpi-value"><?= number_format((int) $totals['dispatched_units']) ?></div>
                <div class="kpi-note"><?= number_format((float) $totals['outbound_kg'], 3) ?> kg salida</div>
            </td>
            <td>
                <div class="kpi-label">Recibidas</div>
                <div class="kpi-value"><?= number_format((int) $totals['received_units']) ?></div>
                <div class="kpi-note"><?= number_format((float) $totals['inbound_kg'], 3) ?> kg entrada</div>
            </td>
            <td>
                <div class="kpi-label">Diferencia</div>
                <div class="kpi-value"><?= number_format((float) $totals['difference_kg'], 3) ?> kg</div>
                <div class="kpi-note">acumulada registrada</div>
            </td>
        </tr>
    </table>
</div>

<div class="section">
    <div class="section-title">Datos de la solicitud</div>
    <table class="info">
        <tr>
            <td class="info-key">Tipo</td>
            <td class="info-value"><?= esc($requestTypeLabel) ?></td>
            <td class="info-key">Estado</td>
            <td class="info-value"><?= esc($requestStatusLabel) ?></td>
        </tr>
        <tr>
            <td class="info-key">Origen</td>
            <td class="info-value">
                <?= esc((string) ($source['name'] ?? '—')) ?>
                <div class="muted"><?= esc((string) ($source['company_name'] ?? '')) ?></div>
            </td>
            <td class="info-key">Destino</td>
            <td class="info-value">
                <?= esc((string) ($destination['name'] ?? '—')) ?>
                <div class="muted"><?= esc((string) ($destination['company_name'] ?? '')) ?></div>
            </td>
        </tr>
        <tr>
            <td class="info-key">Creada</td>
            <td><?= esc((string) ($request['created_at'] ?? '—')) ?></td>
            <td class="info-key">Requerida</td>
            <td><?= esc((string) ($request['required_at'] ?? '—')) ?></td>
        </tr>
    </table>
</div>

<?php if (($request['request_type'] ?? '') === 'production' && is_array($production)): ?>
<div class="section">
    <div class="section-title">Salida a Producción</div>
    <table class="production">
        <tr>
            <td>
                <div class="prod-label">Área</div>
                <div class="prod-value"><?= esc((string) ($production['area'] ?? '—')) ?></div>
            </td>
            <td>
                <div class="prod-label">Estado</div>
                <div class="prod-value"><?= esc((string) ($production['state'] ?? '—')) ?></div>
            </td>
            <td>
                <div class="prod-label">Completadas</div>
                <div class="prod-value">
                    <?= number_format((int) ($production['completed_units'] ?? 0)) ?>
                    /
                    <?= number_format((int) ($production['total_units'] ?? 0)) ?>
                </div>
            </td>
            <td>
                <div class="prod-label">Peso enviado</div>
                <div class="prod-value"><?= number_format((float) ($production['completed_weight_kg'] ?? 0), 3) ?> kg</div>
            </td>
        </tr>
    </table>
</div>
<?php endif ?>

<div class="section">
    <div class="section-title">Detalle solicitado</div>
    <table class="data">
        <thead>
        <tr>
            <th>#</th>
            <th>Especie</th>
            <th>Talla</th>
            <th>Lote</th>
            <th>Propietario</th>
            <th class="right">Kg solicitados</th>
            <th class="center">Desp.</th>
            <th class="center">Rec.</th>
            <th class="right">Kg salida</th>
            <th class="right">Kg entrada</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($lines as $line): ?>
        <tr>
            <td><?= number_format((int) $line['line_number']) ?></td>
            <td><?= esc((string) $line['species_name']) ?></td>
            <td><?= esc((string) $line['size_name']) ?></td>
            <td><?= esc((string) ($line['lot_code'] ?? 'Cualquiera')) ?></td>
            <td><?= esc((string) ($line['owner_name'] ?? 'Cualquiera')) ?></td>
            <td class="right"><?= number_format((float) $line['requested_weight_kg'], 3) ?></td>
            <td class="center"><?= number_format((int) ($line['report']['dispatched_units'] ?? 0)) ?></td>
            <td class="center"><?= number_format((int) ($line['report']['received_units'] ?? 0)) ?></td>
            <td class="right"><?= number_format((float) ($line['report']['outbound_kg'] ?? 0), 3) ?></td>
            <td class="right"><?= number_format((float) ($line['report']['inbound_kg'] ?? 0), 3) ?></td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

<div class="section">
    <div class="section-title">Observaciones</div>
    <div class="note"><?= nl2br(esc((string) ($request['observations'] ?? 'Sin observaciones'))) ?></div>
</div>

<div class="appendix">Anexo operativo - Tinas asociadas</div>
<div class="caption">
    Detalle de trazabilidad física del folio. La actividad del sistema se conserva exclusivamente en el archivo Excel.
</div>

<table class="data">
    <thead>
    <tr>
        <th>Tina</th>
        <th>Etiqueta</th>
        <th>Lote</th>
        <th>Propietario</th>
        <th>Estado</th>
        <th class="right">Salida kg</th>
        <th class="right">Entrada kg</th>
        <th class="right">Dif. kg</th>
        <th>Ubicación</th>
    </tr>
    </thead>
    <tbody>
    <?php if ($units === []): ?>
    <tr>
        <td colspan="9" class="center">Sin tinas asociadas.</td>
    </tr>
    <?php else: ?>
    <?php foreach ($units as $unit): ?>
    <?php $status = (string) ($unit['status'] ?? ''); ?>
    <tr>
        <td><?= esc((string) ($unit['tub_code'] ?? '—')) ?></td>
        <td><?= esc((string) ($unit['unit_code'] ?? '—')) ?></td>
        <td><?= esc((string) ($unit['lot_code'] ?? '—')) ?></td>
        <td><?= esc((string) ($unit['owner_name'] ?? '—')) ?></td>
        <td><?= esc((string) ($unitStatusLabels[$status] ?? $status ?: '—')) ?></td>
        <td class="right"><?= isset($unit['outbound_weight_kg']) && $unit['outbound_weight_kg'] !== null ? number_format((float) $unit['outbound_weight_kg'], 3) : '—' ?></td>
        <td class="right"><?= isset($unit['inbound_weight_kg']) && $unit['inbound_weight_kg'] !== null ? number_format((float) $unit['inbound_weight_kg'], 3) : '—' ?></td>
        <td class="right"><?= isset($unit['difference_kg']) && $unit['difference_kg'] !== null ? number_format((float) $unit['difference_kg'], 3) : '—' ?></td>
        <td><?= esc((string) ($unit['location_path'] ?? '—')) ?></td>
    </tr>
    <?php endforeach ?>
    <?php endif ?>
    </tbody>
</table>

<?php if (($request['request_type'] ?? '') === 'production' && ! empty($production['units'])): ?>
<div class="section">
    <div class="section-title">Anexo - Salida a Producción</div>
    <table class="data">
        <thead>
        <tr>
            <th>Tina</th>
            <th>Recepción</th>
            <th>Producción</th>
            <th>Folio</th>
            <th class="right">Kg</th>
            <th>Fecha</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($production['units'] as $unit): ?>
        <?php $receiptStatus = (string) ($unit['receipt_status'] ?? ''); ?>
        <tr>
            <td><?= esc((string) ($unit['tub_code'] ?? $unit['unit_code'] ?? '—')) ?></td>
            <td><?= esc((string) ($unitStatusLabels[$receiptStatus] ?? $receiptStatus ?: '—')) ?></td>
            <td><?= esc((string) ($unit['production_state'] ?? '—')) ?></td>
            <td><?= esc((string) ($unit['production_folio'] ?? '—')) ?></td>
            <td class="right"><?= $unit['production_weight_kg'] !== null ? number_format((float) $unit['production_weight_kg'], 3) : '—' ?></td>
            <td><?= esc((string) ($unit['production_at'] ?? $unit['production_reversed_at'] ?? $unit['received_at'] ?? '—')) ?></td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>

<div class="footer">
    Documento generado por <?= esc($generatedBy) ?> el <?= esc($generatedAt) ?>.
</div>

</body>
</html>
