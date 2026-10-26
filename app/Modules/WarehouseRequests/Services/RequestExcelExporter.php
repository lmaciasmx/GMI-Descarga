<?php

namespace App\Modules\WarehouseRequests\Services;

use ZipArchive;

final class RequestExcelExporter
{
    public function build(array $data): string
    {
        $sheets = $this->sheets($data);
        $tmp = tempnam(sys_get_temp_dir(), 'gm_req_xlsx_');

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

    private function sheets(array $data): array
    {
        return [
            'Resumen' => $this->summarySheet($data),
            'Líneas' => $this->linesSheet($data),
            'Tinas' => $this->unitsSheet($data),
            'Producción' => $this->productionSheet($data),
            'Actividad' => $this->activitySheet($data),
        ];
    }

    private function summarySheet(array $data): array
    {
        $r = $data['request'];
        $source = $data['source'];
        $destination = $data['destination'];
        $t = $data['totals'];

        $rows = [
            [['Solicitud entre almacenes', 'title']],
            [],
            [['Folio', 'label'], [(string) $r['folio'], 'value'], ['Tipo', 'label'], [$data['requestTypeLabel'], 'value']],
            [['Estado', 'label'], [$data['requestStatusLabel'], 'value'], ['Fecha requerida', 'label'], [(string) ($r['required_at'] ?? '—'), 'value']],
            [['Origen', 'label'], [(string) ($source['name'] ?? '—'), 'value'], ['Destino', 'label'], [(string) ($destination['name'] ?? '—'), 'value']],
            [['Compañía origen', 'label'], [(string) ($source['company_name'] ?? '—'), 'value'], ['Compañía destino', 'label'], [(string) ($destination['company_name'] ?? '—'), 'value']],
            [],
            [['Indicadores ejecutivos', 'section']],
            [['Tinas', 'header'], ['Despachadas', 'header'], ['Recibidas', 'header'], ['Dev. pendientes', 'header'], ['Reingresadas', 'header'], ['Kg salida', 'header'], ['Kg entrada', 'header'], ['Diferencia kg', 'header']],
            [
                [(int) $t['units'], 'integer'],
                [(int) $t['dispatched_units'], 'integer'],
                [(int) $t['received_units'], 'integer'],
                [(int) $t['returning_units'], 'integer'],
                [(int) $t['returned_units'], 'integer'],
                [(float) $t['outbound_kg'], 'decimal'],
                [(float) $t['inbound_kg'], 'decimal'],
                [(float) $t['difference_kg'], 'decimal'],
            ],
            [],
            [['Observaciones', 'section']],
            [[(string) ($r['observations'] ?? 'Sin observaciones'), 'wrap']],
            [],
            [['Generado por', 'label'], [$data['generatedBy'], 'value'], ['Fecha de generación', 'label'], [$data['generatedAt'], 'value']],
        ];

        if (($r['request_type'] ?? '') === 'production' && is_array($data['production'])) {
            $p = $data['production'];
            $rows[] = [];
            $rows[] = [['Salida a Producción', 'section']];
            $rows[] = [
                ['Área', 'label'],
                [(string) ($p['area'] ?? '—'), 'value'],
                ['Estado', 'label'],
                [(string) ($p['state'] ?? '—'), 'value'],
                ['Tinas completadas', 'label'],
                [(int) ($p['completed_units'] ?? 0), 'integer'],
                ['Peso enviado kg', 'label'],
                [(float) ($p['completed_weight_kg'] ?? 0), 'decimal'],
            ];
        }

        return [
            'rows' => $rows,
            'widths' => [18, 28, 18, 28, 18, 18, 18, 18],
            'freeze' => 0,
            'mergeTitle' => 'A1:H1',
            'autoFilter' => null,
        ];
    }

    private function linesSheet(array $data): array
    {
        $rows = [[
            ['#', 'header'], ['Especie', 'header'], ['Talla', 'header'],
            ['Lote', 'header'], ['Propietario', 'header'], ['Kg solicitados', 'header'],
            ['Tinas despachadas', 'header'], ['Tinas recibidas', 'header'],
            ['Kg salida', 'header'], ['Kg entrada', 'header'], ['Diferencia kg', 'header'],
        ]];

        foreach ($data['lines'] as $line) {
            $rows[] = [
                [(int) $line['line_number'], 'integer'],
                [(string) $line['species_name'], 'text'],
                [(string) $line['size_name'], 'text'],
                [(string) ($line['lot_code'] ?? 'Cualquiera'), 'text'],
                [(string) ($line['owner_name'] ?? 'Cualquiera'), 'text'],
                [(float) $line['requested_weight_kg'], 'decimal'],
                [(int) ($line['report']['dispatched_units'] ?? 0), 'integer'],
                [(int) ($line['report']['received_units'] ?? 0), 'integer'],
                [(float) ($line['report']['outbound_kg'] ?? 0), 'decimal'],
                [(float) ($line['report']['inbound_kg'] ?? 0), 'decimal'],
                [(float) ($line['report']['difference_kg'] ?? 0), 'decimal'],
            ];
        }

        return [
            'rows' => $rows,
            'widths' => [7, 24, 16, 18, 24, 16, 17, 16, 14, 14, 15],
            'freeze' => 1,
            'mergeTitle' => null,
            'autoFilter' => 'A1:K' . max(1, count($rows)),
        ];
    }

    private function unitsSheet(array $data): array
    {
        $rows = [[
            ['Tina', 'header'], ['Etiqueta', 'header'], ['Línea', 'header'],
            ['Lote', 'header'], ['Propietario', 'header'], ['Estado', 'header'],
            ['Salida kg', 'header'], ['Entrada kg', 'header'], ['Diferencia kg', 'header'],
            ['Ubicación', 'header'],
        ]];

        foreach ($data['units'] as $unit) {
            $status = (string) ($unit['status'] ?? '');
            $rows[] = [
                [(string) ($unit['tub_code'] ?? '—'), 'text'],
                [(string) ($unit['unit_code'] ?? '—'), 'text'],
                [(int) ($unit['request_line_id'] ?? 0), 'integer'],
                [(string) ($unit['lot_code'] ?? '—'), 'text'],
                [(string) ($unit['owner_name'] ?? '—'), 'text'],
                [(string) ($data['unitStatusLabels'][$status] ?? $status ?: '—'), 'text'],
                [$this->floatOrBlank($unit['outbound_weight_kg'] ?? null), 'decimal'],
                [$this->floatOrBlank($unit['inbound_weight_kg'] ?? null), 'decimal'],
                [$this->floatOrBlank($unit['difference_kg'] ?? null), 'decimal'],
                [(string) ($unit['location_path'] ?? '—'), 'text'],
            ];
        }

        return [
            'rows' => $rows,
            'widths' => [14, 22, 10, 18, 24, 20, 14, 14, 15, 28],
            'freeze' => 1,
            'mergeTitle' => null,
            'autoFilter' => 'A1:J' . max(1, count($rows)),
        ];
    }

    private function productionSheet(array $data): array
    {
        $production = $data['production'];

        if (($data['request']['request_type'] ?? '') !== 'production' || ! is_array($production)) {
            return [
                'rows' => [
                    [['Salida a Producción', 'title']],
                    [],
                    [['Esta solicitud no corresponde a Materia Prima para Producción.', 'wrap']],
                ],
                'widths' => [42, 22, 22, 22, 20, 20, 22],
                'freeze' => 0,
                'mergeTitle' => 'A1:G1',
                'autoFilter' => null,
            ];
        }

        $rows = [
            [['Salida a Producción', 'title']],
            [],
            [
                ['Área', 'label'], [(string) ($production['area'] ?? '—'), 'value'],
                ['Estado', 'label'], [(string) ($production['state'] ?? '—'), 'value'],
                ['Tinas completadas', 'label'], [(int) ($production['completed_units'] ?? 0), 'integer'],
                ['Peso enviado kg', 'label'], [(float) ($production['completed_weight_kg'] ?? 0), 'decimal'],
            ],
            [],
            [
                ['Tina', 'header'], ['Etiqueta', 'header'], ['Recepción', 'header'],
                ['Estado producción', 'header'], ['Folio producción', 'header'],
                ['Kg', 'header'], ['Fecha', 'header'],
            ],
        ];

        foreach (($production['units'] ?? []) as $unit) {
            $receiptStatus = (string) ($unit['receipt_status'] ?? '');
            $rows[] = [
                [(string) ($unit['tub_code'] ?? '—'), 'text'],
                [(string) ($unit['unit_code'] ?? '—'), 'text'],
                [(string) ($data['unitStatusLabels'][$receiptStatus] ?? $receiptStatus ?: '—'), 'text'],
                [(string) ($unit['production_state'] ?? '—'), 'text'],
                [(string) ($unit['production_folio'] ?? '—'), 'text'],
                [$this->floatOrBlank($unit['production_weight_kg'] ?? null), 'decimal'],
                [(string) ($unit['production_at'] ?? $unit['production_reversed_at'] ?? $unit['received_at'] ?? '—'), 'text'],
            ];
        }

        return [
            'rows' => $rows,
            'widths' => [14, 22, 20, 22, 22, 14, 22],
            'freeze' => 5,
            'mergeTitle' => 'A1:G1',
            'autoFilter' => count($rows) >= 5 ? 'A5:G' . count($rows) : null,
        ];
    }

    private function activitySheet(array $data): array
    {
        $rows = [[
            ['Fecha', 'header'], ['Evento', 'header'], ['Usuario', 'header'],
        ]];

        foreach ($data['events'] as $event) {
            $action = (string) ($event['action'] ?? '');
            $rows[] = [
                [(string) ($event['created_at'] ?? '—'), 'text'],
                [(string) ($data['eventLabels'][$action] ?? $action ?: '—'), 'text'],
                [(string) ($event['actor_user_id'] ?? '—'), 'text'],
            ];
        }

        return [
            'rows' => $rows,
            'widths' => [22, 42, 18],
            'freeze' => 1,
            'mergeTitle' => null,
            'autoFilter' => 'A1:C' . max(1, count($rows)),
        ];
    }

    private function floatOrBlank(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (float) $value;
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

        $freezeXml = '';
        if (($sheet['freeze'] ?? 0) > 0) {
            $row = ((int) $sheet['freeze']) + 1;
            $freezeXml = '<sheetViews><sheetView workbookViewId="0">'
                . '<pane ySplit="' . (int) $sheet['freeze'] . '" topLeftCell="A' . $row . '" activePane="bottomLeft" state="frozen"/>'
                . '</sheetView></sheetViews>';
        } else {
            $freezeXml = '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
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
            $xml .= '<Relationship Id="rId' . $i . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . $i . '.xml"/>';
        }

        $xml .= '<Relationship Id="rId' . ($count + 1) . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
            . 'Target="styles.xml"/>';

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
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
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
        $names = '';
        foreach (array_keys($sheets) as $name) {
            $names .= '<vt:lpstr>' . $this->xml($name) . '</vt:lpstr>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>GRUPOMAR</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Hojas de cálculo</vt:lpstr></vt:variant>'
            . '<vt:variant><vt:i4>' . count($sheets) . '</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . count($sheets) . '" baseType="lpstr">' . $names . '</vt:vector></TitlesOfParts>'
            . '<Company>GRUPOMAR</Company><AppVersion>1.1</AppVersion></Properties>';
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
            . '<dc:creator>GRUPOMAR</dc:creator><cp:lastModifiedBy>GRUPOMAR</cp:lastModifiedBy>'
            . '<dc:title>Solicitud entre almacenes</dc:title>'
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
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
