<?php

namespace App\Modules\Reports\Services;

use ZipArchive;

final class ReportExportService
{
    private const SUPPORTED = [
        'general.summary',
        'inventory.current',
        'inventory.movements',
        'requests.warehouse',
        'transfers.summary',
        'production.raw-material',
        'sales.dispatches',
        'traceability.unit',
        'discharge.summary',
        'warehouse.entries',
    ];

    public function supportedKeys(): array
    {
        return self::SUPPORTED;
    }

    public function supports(string $reportKey): bool
    {
        return in_array($reportKey, self::SUPPORTED, true);
    }

    public function prepare(
        string $reportKey,
        array $catalogItem,
        array $report,
        array $scope,
        array $filters,
        string $generatedBy
    ): array {
        $columns = $report['columns'] ?? [];
        $rows = $report['rows'] ?? [];

        $formattedRows = [];
        $typedRows = [];

        foreach ($rows as $row) {
            $formatted = [];
            $typed = [];

            foreach ($columns as $key => $label) {
                $value = $row[$key] ?? null;
                $formatted[$key] = $this->formatCell((string) $key, $value);
                $typed[$key] = $this->typedCell((string) $key, $value);
            }

            $formattedRows[] = $formatted;
            $typedRows[] = $typed;
        }

        return [
            'reportKey' => $reportKey,
            'title' => (string) ($catalogItem['title'] ?? 'Reporte'),
            'description' => (string) ($catalogItem['description'] ?? ''),
            'scope' => [
                'company' => (string) ($scope['company_name'] ?? $scope['company'] ?? 'Actual'),
                'site' => (string) ($scope['site_name'] ?? $scope['site'] ?? 'Actual'),
                'warehouse' => (string) ($scope['warehouse_name'] ?? $scope['warehouse'] ?? 'Actual'),
            ],
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'kpis' => $report['kpis'] ?? [],
            'columns' => $columns,
            'rows' => $formattedRows,
            'typedRows' => $typedRows,
            'rowCount' => count($rows),
            'generatedAt' => date('d/m/Y H:i'),
            'generatedBy' => $generatedBy !== '' ? $generatedBy : 'Usuario',
        ];
    }

    public function buildXlsx(array $data): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException(
                'La extensión ZIP de PHP no está disponible para generar Excel XLSX.'
            );
        }

        $sheets = [
            'Resumen' => $this->summarySheet($data),
            'Detalle' => $this->detailSheet($data),
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'gm_reports_xlsx_');
        if ($tmp === false) {
            throw new \RuntimeException('No fue posible crear el archivo temporal XLSX.');
        }

        $zip = new ZipArchive();

        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('No fue posible crear el archivo XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('docProps/app.xml', $this->appProps($sheets));
        $zip->addFromString('docProps/core.xml', $this->coreProps());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach (array_values($sheets) as $index => $sheet) {
            $zip->addFromString(
                'xl/worksheets/sheet' . ($index + 1) . '.xml',
                $this->sheetXml($sheet)
            );
        }

        $zip->close();

        $binary = file_get_contents($tmp);
        @unlink($tmp);

        if ($binary === false) {
            throw new \RuntimeException('No fue posible leer el archivo XLSX generado.');
        }

        return $binary;
    }

    public function filename(string $title, string $extension): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $this->ascii($title)) ?: 'Reporte';

        return 'GRUPOMAR_' . trim($safe, '_') . '_' . date('Ymd_His') . '.' . $extension;
    }

    public function pdfRows(array $data, int $max = 150): array
    {
        return array_slice($data['rows'] ?? [], 0, $max);
    }

    private function formatCell(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($this->isDateKey($key)) {
            return $this->formatDate($value);
        }

        if ($this->isLocationKey($key)) {
            return $this->compactLocation($value);
        }

        if ($this->isTechnicalKey($key)) {
            return $this->humanizeTechnical((string) $value);
        }

        if (is_numeric($value)) {
            if ($this->isDecimalKey($key)) {
                return number_format((float) $value, 3, '.', ',');
            }

            if ($this->isIntegerKey($key)) {
                return number_format((int) $value);
            }
        }

        return (string) $value;
    }

    private function typedCell(string $key, mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['', 'text'];
        }

        if ($this->isDateKey($key) || $this->isLocationKey($key) || $this->isTechnicalKey($key)) {
            return [$this->formatCell($key, $value), 'text'];
        }

        if (is_numeric($value) && $this->isDecimalKey($key)) {
            return [(float) $value, 'decimal'];
        }

        if (is_numeric($value) && $this->isIntegerKey($key)) {
            return [(int) $value, 'integer'];
        }

        return [(string) $value, 'text'];
    }

    private function humanizeTechnical(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '—';
        }

        $normalized = strtolower($value);

        $translations = [
            'reservation' => 'Reserva',
            'reservation_release' => 'Liberación de reserva',
            'transfer_shipment' => 'Salida por traspaso',
            'transfer_receipt' => 'Recepción de traspaso',
            'sale_dispatch' => 'Salida por venta',
            'receipt' => 'Recepción',
            'adjustment' => 'Ajuste',
            'relocation' => 'Reubicación',
            'warehouse_entry' => 'Entrada a almacén',
            'warehouse_exit' => 'Salida de almacén',
            'production_issue' => 'Salida a producción',
            'transfer' => 'Traspaso',
            'sale' => 'Venta',
            'raw_material' => 'Materia prima',
            'raw material' => 'Materia prima',
            'production' => 'Producción',
            'return' => 'Devolución',
            'warehouse_request' => 'Solicitud de almacén',
            'warehouse request' => 'Solicitud de almacén',
            'warehouse-transfer' => 'Traspaso entre almacenes',
            'warehouse_transfer' => 'Traspaso entre almacenes',
            'warehouse transfer' => 'Traspaso entre almacenes',
            'manual' => 'Manual',
            'automatic' => 'Automático',
            'draft' => 'Borrador',
            'pending' => 'Pendiente',
            'programmed' => 'Programado',
            'assigned' => 'Asignado',
            'accepted' => 'Aceptado',
            'rejected' => 'Rechazado',
            'supplying' => 'En surtido',
            'supplied' => 'Surtido',
            'supply_completed' => 'Surtido finalizado',
            'receiving' => 'En recepción',
            'received' => 'Recibido',
            'confirmed' => 'Confirmada',
            'confirm' => 'Confirmada',
            'validated' => 'Validado',
            'approved' => 'Aprobado',
            'created' => 'Creado',
            'open' => 'Abierto',
            'processing' => 'En proceso',
            'ready' => 'Listo',
            'closed' => 'Cerrado',
            'cancelled' => 'Cancelado',
            'canceled' => 'Cancelado',
            'available' => 'Disponible',
            'reserved' => 'Reservado',
            'in_transit' => 'En tránsito',
            'in transit' => 'En tránsito',
            'in-progress' => 'En proceso',
            'in_progress' => 'En proceso',
            'in progress' => 'En proceso',
            'finished' => 'Finalizado',
            'finish' => 'Finalizado',
            'done' => 'Finalizado',
            'on_hold' => 'En espera',
            'on hold' => 'En espera',
            'paused' => 'En pausa',
            'scheduled' => 'Programado',
            'shipping' => 'En salida',
            'shipped' => 'Enviado',
            'dispatching' => 'En despacho',
            'dispatched' => 'Despachado',
            'partially_received' => 'Recibido parcialmente',
            'partially received' => 'Recibido parcialmente',
            'partially_supplied' => 'Surtido parcialmente',
            'partially supplied' => 'Surtido parcialmente',
            'exhausted' => 'Agotado',
            'completed' => 'Completado',
            'complete' => 'Completado',
            'annulled' => 'Anulado',
            'active' => 'Activo',
            'inactive' => 'Inactivo',
            'urgent' => 'Urgente',
            'high' => 'Alta',
            'normal' => 'Normal',
            'low' => 'Baja',
        ];

        if (isset($translations[$normalized])) {
            return $translations[$normalized];
        }

        $readable = str_replace(['_', '-'], ' ', $normalized);
        $readable = preg_replace('/\s+/', ' ', trim($readable)) ?: $readable;

        return ucfirst($readable);
    }

    private function compactLocation(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '—';
        }

        $text = trim((string) $value);
        $parts = array_values(array_filter(
            array_map('trim', explode('/', trim($text, '/'))),
            static fn (string $part): bool => $part !== ''
        ));

        if ($parts === []) {
            return $text;
        }

        $camera = null;
        $row = null;

        foreach ($parts as $part) {
            $upper = strtoupper($part);

            if ($camera === null && preg_match('/^(CAM|CAMARA|CÁMARA)[-_ ]?\d+/u', $upper)) {
                $camera = $part;
                continue;
            }

            if ($row === null && preg_match('/^(F|FILA)[-_ ]?\d+/u', $upper)) {
                $row = $part;
            }
        }

        if ($camera !== null || $row !== null) {
            return implode(' / ', array_values(array_filter([$camera, $row])));
        }

        if (count($parts) >= 2) {
            return $parts[0] . ' / ' . $parts[1];
        }

        return $parts[0];
    }

    private function formatDate(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '—';
        }

        try {
            $date = new \DateTimeImmutable($text);
        } catch (\Throwable) {
            return $text;
        }

        $hasTime = preg_match('/\d{1,2}:\d{2}/', $text) === 1;

        return $date->format($hasTime ? 'd/m/Y H:i' : 'd/m/Y');
    }

    private function isDateKey(string $key): bool
    {
        return str_ends_with($key, '_at')
            || str_contains($key, 'date')
            || in_array($key, [
                'occurred_at', 'received_at', 'requested_at',
                'shipped_at', 'dispatched_at', 'required_at',
            ], true);
    }

    private function isLocationKey(string $key): bool
    {
        return str_contains($key, 'location')
            || in_array($key, ['from_location', 'to_location', 'location_path'], true);
    }

    private function isTechnicalKey(string $key): bool
    {
        return in_array($key, [
            'movement_type', 'request_type', 'transfer_type',
            'status', 'priority', 'destination',
        ], true);
    }

    private function isDecimalKey(string $key): bool
    {
        return str_contains($key, 'weight')
            || str_contains($key, '_kg')
            || str_contains($key, 'delta');
    }

    private function isIntegerKey(string $key): bool
    {
        return in_array($key, [
            'units', 'line_count', 'plan_lines', 'year', 'annual_number',
        ], true);
    }

    private function summarySheet(array $data): array
    {
        $period = $this->periodText($data['filters'] ?? []);
        $scope = $data['scope'] ?? [];

        $rows = [
            [['GRUPOMAR · Centro de Reportes', 'title']],
            [],
            [['Reporte', 'label'], [$data['title'] ?? 'Reporte', 'value']],
            [['Descripción', 'label'], [$data['description'] ?? '', 'wrap']],
            [['Periodo', 'label'], [$period, 'value']],
            [['Compañía', 'label'], [$scope['company'] ?? 'Actual', 'value']],
            [['Sitio', 'label'], [$scope['site'] ?? 'Actual', 'value']],
            [['Almacén', 'label'], [$scope['warehouse'] ?? 'Actual', 'value']],
            [['Registros', 'label'], [(int) ($data['rowCount'] ?? 0), 'integer']],
            [],
            [['Indicadores', 'section']],
        ];

        foreach (($data['kpis'] ?? []) as $kpi) {
            $rows[] = [
                [(string) ($kpi['label'] ?? ''), 'label'],
                [(string) ($kpi['value'] ?? ''), 'value'],
            ];
        }

        $rows[] = [];
        $rows[] = [
            ['Generado por', 'label'],
            [(string) ($data['generatedBy'] ?? 'Usuario'), 'value'],
        ];
        $rows[] = [
            ['Fecha de generación', 'label'],
            [(string) ($data['generatedAt'] ?? ''), 'value'],
        ];

        return [
            'rows' => $rows,
            'widths' => [24, 58],
            'freeze' => 0,
            'mergeTitle' => 'A1:B1',
            'autoFilter' => null,
        ];
    }

    private function detailSheet(array $data): array
    {
        $columns = $data['columns'] ?? [];
        $rows = [];

        $header = [];
        foreach ($columns as $label) {
            $header[] = [(string) $label, 'header'];
        }
        $rows[] = $header;

        foreach (($data['typedRows'] ?? []) as $row) {
            $cells = [];
            foreach ($columns as $key => $label) {
                $cell = $row[$key] ?? ['', 'text'];
                $cells[] = [$cell[0], $cell[1]];
            }
            $rows[] = $cells;
        }

        $widths = [];
        foreach ($columns as $key => $label) {
            $widths[] = $this->columnWidth((string) $key, (string) $label);
        }

        $lastColumn = $this->columnName(max(1, count($columns)));
        $lastRow = max(1, count($rows));

        return [
            'rows' => $rows,
            'widths' => $widths,
            'freeze' => 1,
            'mergeTitle' => null,
            'autoFilter' => count($columns) > 0 ? 'A1:' . $lastColumn . $lastRow : null,
        ];
    }

    private function columnWidth(string $key, string $label): int
    {
        if ($this->isDateKey($key)) {
            return 20;
        }

        if ($this->isLocationKey($key)) {
            return 18;
        }

        if ($this->isDecimalKey($key)) {
            return 16;
        }

        if ($this->isTechnicalKey($key)) {
            return 22;
        }

        if (
            str_contains($key, 'folio')
            || str_contains($key, 'code')
            || in_array($key, ['unit_code', 'tub_code', 'lot_code'], true)
        ) {
            return 24;
        }

        if (str_contains($key, 'warehouse') || in_array($key, ['owner', 'customer', 'species'], true)) {
            return 26;
        }

        return max(14, min(30, strlen($label) + 5));
    }

    private function periodText(array $filters): string
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if (! $from && ! $to) {
            return 'Sin filtro de fecha';
        }

        $fromText = $from ? $this->formatDate($from) : 'Inicio';
        $toText = $to ? $this->formatDate($to) : 'Actual';

        return $fromText . ' a ' . $toText;
    }

    private function sheetXml(array $sheet): string
    {
        $rowsXml = '';
        $maxCols = 1;

        foreach ($sheet['rows'] as $rIndex => $cells) {
            $rowNumber = $rIndex + 1;
            $maxCols = max($maxCols, count($cells));
            $cellXml = '';

            foreach ($cells as $cIndex => $cell) {
                [$value, $kind] = $cell;
                $ref = $this->columnName($cIndex + 1) . $rowNumber;
                $styleId = $this->styleId($kind);

                if (in_array($kind, ['integer', 'decimal'], true) && $value !== '') {
                    $cellXml .= '<c r="' . $ref . '" s="' . $styleId . '"><v>'
                        . $this->xml((string) $value)
                        . '</v></c>';
                } else {
                    $cellXml .= '<c r="' . $ref . '" s="' . $styleId . '" t="inlineStr"><is><t xml:space="preserve">'
                        . $this->xml((string) $value)
                        . '</t></is></c>';
                }
            }

            $rowsXml .= '<row r="' . $rowNumber . '">' . $cellXml . '</row>';
        }

        $colsXml = '';
        foreach ($sheet['widths'] as $index => $width) {
            $n = $index + 1;
            $colsXml .= '<col min="' . $n . '" max="' . $n . '" width="' . $width . '" customWidth="1"/>';
        }

        $freezeXml = '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
        if (($sheet['freeze'] ?? 0) > 0) {
            $row = ((int) $sheet['freeze']) + 1;
            $freezeXml = '<sheetViews><sheetView workbookViewId="0">'
                . '<pane ySplit="' . (int) $sheet['freeze'] . '" topLeftCell="A' . $row
                . '" activePane="bottomLeft" state="frozen"/>'
                . '</sheetView></sheetViews>';
        }

        $mergeXml = '';
        if (! empty($sheet['mergeTitle'])) {
            $mergeXml = '<mergeCells count="1"><mergeCell ref="' . $sheet['mergeTitle'] . '"/></mergeCells>';
        }

        $filterXml = '';
        if (! empty($sheet['autoFilter'])) {
            $filterXml = '<autoFilter ref="' . $sheet['autoFilter'] . '"/>';
        }

        $dimension = 'A1:' . $this->columnName($maxCols) . max(1, count($sheet['rows']));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="' . $dimension . '"/>'
            . $freezeXml
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>' . $colsXml . '</cols>'
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . $mergeXml
            . $filterXml
            . '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '</worksheet>';
    }

    private function styleId(string $kind): int
    {
        return match ($kind) {
            'title' => 1,
            'header' => 2,
            'label' => 3,
            'decimal' => 4,
            'integer' => 5,
            'section' => 6,
            'wrap' => 7,
            'value' => 8,
            default => 0,
        };
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.000"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="10"/><name val="Aptos"/></font>'
            . '<font><b/><sz val="16"/><color rgb="FF0B5790"/><name val="Aptos Display"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Aptos"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF173751"/><name val="Aptos"/></font>'
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0B6FAE"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF3F9"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF5F8FA"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD9E3EA"/></left><right style="thin"><color rgb="FFD9E3EA"/></right><top style="thin"><color rgb="FFD9E3EA"/></top><bottom style="thin"><color rgb="FFD9E3EA"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="9">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFill="1" applyFont="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFill="1" applyFont="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="4" borderId="0" xfId="0" applyFill="1" applyFont="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="center" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function workbook(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="22000" windowHeight="12000"/></bookViews>'
            . '<sheets>';

        $i = 1;
        foreach (array_keys($sheets) as $name) {
            $xml .= '<sheet name="' . $this->xml($name) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $i++;
        }

        return $xml . '</sheets></workbook>';
    }

    private function workbookRels(int $count): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        for ($i = 1; $i <= $count; $i++) {
            $xml .= '<Relationship Id="rId' . $i
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . $i . '.xml"/>';
        }

        $xml .= '<Relationship Id="rId' . ($count + 1)
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>';

        return $xml . '</Relationships>';
    }

    private function contentTypes(int $count): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

        for ($i = 1; $i <= $count; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $i
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function appProps(array $sheets): string
    {
        $titles = '';
        foreach (array_keys($sheets) as $name) {
            $titles .= '<vt:lpstr>' . $this->xml($name) . '</vt:lpstr>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>GRUPOMAR</Application>'
            . '<TitlesOfParts><vt:vector size="' . count($sheets) . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts>'
            . '</Properties>';
    }

    private function coreProps(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>GRUPOMAR</dc:creator>'
            . '<cp:lastModifiedBy>GRUPOMAR</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function columnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function ascii(string $value): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}
