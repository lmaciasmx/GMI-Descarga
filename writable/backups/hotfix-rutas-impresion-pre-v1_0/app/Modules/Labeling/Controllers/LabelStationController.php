<?php

namespace App\Modules\Labeling\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;
use Dompdf\Dompdf;
use Dompdf\Options;

final class LabelStationController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();
        $workDate = (string) ($this->request->getGet('fecha') ?: date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $workDate = date('Y-m-d');
        }
        $from = $workDate . ' 00:00:00';
        $to = date('Y-m-d H:i:s', strtotime($workDate . ' +1 day'));

        $weighings = $db->table('weighings w')
            ->select("w.id, w.folio, w.accepted_net_kg, w.confirmed_at, w.status, t.code AS tub_code, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name, l.id AS label_id, l.code AS label_code, l.template_id AS label_template_id, l.status AS label_status, (SELECT COUNT(*) FROM print_jobs ipj WHERE ipj.label_id = l.id AND ipj.is_reprint = 0 AND ipj.result IN ('sent', 'generated_pdf', 'awaiting_browser')) AS initial_print_count, (SELECT COUNT(*) FROM print_jobs rpj WHERE rpj.label_id = l.id AND rpj.is_reprint = 1 AND rpj.result IN ('sent', 'generated_pdf', 'awaiting_browser_reprint', 'awaiting_browser')) AS reprint_count, (SELECT pj.id FROM print_jobs pj WHERE pj.label_id = l.id ORDER BY pj.print_number DESC LIMIT 1) AS last_job_id, (SELECT pj.result FROM print_jobs pj WHERE pj.label_id = l.id ORDER BY pj.print_number DESC LIMIT 1) AS last_print_result, (SELECT pj.output_path FROM print_jobs pj WHERE pj.label_id = l.id ORDER BY pj.print_number DESC LIMIT 1) AS last_output_path, (SELECT pj.print_number FROM print_jobs pj WHERE pj.label_id = l.id ORDER BY pj.print_number DESC LIMIT 1) AS last_print_number", false)
            ->join('tub_fillings tf', 'tf.id = w.tub_filling_id')
            ->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('tubs t', 't.id = w.tub_id')
            ->join('lots lo', 'lo.id = w.lot_id')
            ->join('species s', 's.id = tf.species_id')
            ->join('size_grades sg', 'sg.id = tf.size_grade_id')
            ->join('labels l', 'l.weighing_id = w.id', 'left')
            ->where(['v.company_id' => $scope['company_id'], 'v.site_id' => $scope['site_id']])
            ->whereIn('w.status', ['confirmed', 'pending_inspection', 'received'])
            ->where('w.confirmed_at >=', $from)
            ->where('w.confirmed_at <', $to)
            ->orderBy('w.confirmed_at', 'DESC')
            ->limit(1200)
            ->get()->getResultArray();

        $templates = $db->table('label_templates')->where(['active' => 1, 'format' => 'html'])->orderBy('code')->orderBy('version', 'DESC')->get()->getResultArray();
        $printers = $db->table('printers')->where(['site_id' => $scope['site_id'], 'active' => 1])->orderBy('name')->get()->getResultArray();
        $defaultTemplate = $db->table('label_templates')->where(['code' => 'TINA_RECEPCION', 'active' => 1, 'format' => 'html'])->orderBy('version', 'DESC')->get()->getRowArray();
        try {
            $printerStatus = service('labelPrinter')->getPrinterStatus();
        } catch (\Throwable) {
            $printerStatus = ['connected' => false, 'status' => 'unavailable', 'physical_printer' => null];
        }

        $totalToday = count($weighings);
        $labeledToday = 0;
        $pendingToday = 0;
        $reprintsToday = 0;
        foreach ($weighings as $row) {
            if ((int) $row['initial_print_count'] > 0) {
                $labeledToday++;
            } else {
                $pendingToday++;
            }
            $reprintsToday += (int) ($row['reprint_count'] ?? 0);
        }

        $status = (string) ($printerStatus['status'] ?? 'sin_estado');
        $printerStatusLabel = match ($status) {
            'pdf_contingency' => 'PDF 99×50 mm / impresión desde navegador',
            'browser_managed' => 'Impresión desde navegador',
            'configured', 'ready', 'online' => 'Impresora disponible',
            'unavailable' => 'Impresión no disponible',
            default => 'Impresión: ' . str_replace('_', ' ', $status),
        };

        return view('App\\Modules\\Labeling\\Views\\station', [
            'title' => 'Reimpresión de etiquetas',
            'subtitle' => 'Búsqueda rápida, PDF 99×50 mm y reimpresión directa en PC43t con trazabilidad por tina.',
            'weighings' => $weighings,
            'templates' => $templates,
            'printers' => $printers,
            'printerStatus' => $printerStatus,
            'printerStatusLabel' => $printerStatusLabel,
            'defaultTemplateId' => (int) ($defaultTemplate['id'] ?? 0),
            'workDate' => $workDate,
            'stats' => [
                'total_today' => $totalToday,
                'labeled_today' => $labeledToday,
                'reprints_today' => $reprintsToday,
                'pending_today' => $pendingToday,
            ],
            'canPrint' => auth()->user()->can('labels.print'),
            'canReprint' => auth()->user()->can('labels.reprint'),
        ]);
    }

    public function download(int $jobId)
    {
        $job = db_connect()->table('print_jobs pj')
            ->select('pj.id, pj.label_id, pj.print_number, pj.output_path, pj.result, l.code AS label_code')
            ->join('labels l', 'l.id = pj.label_id')
            ->where('pj.id', $jobId)
            ->get()->getRowArray();

        if ($job === null) {
            throw PageNotFoundException::forPageNotFound('Trabajo de impresión no encontrado.');
        }
        service('scopeContext')->assertAggregate('label', (int) $job['label_id']);

        $baseDirectory = realpath(WRITEPATH . 'labels');
        $realPath = $job['output_path'] === null ? false : realpath((string) $job['output_path']);
        if ($baseDirectory === false || $realPath === false || ! str_starts_with($realPath, $baseDirectory . DIRECTORY_SEPARATOR) || ! is_file($realPath)) {
            throw PageNotFoundException::forPageNotFound('El archivo de contingencia no está disponible.');
        }

        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $job['label_code']);

        return $this->response->download($realPath, null)->setFileName($safeCode . '-impresion-' . (int) $job['print_number'] . '.pdf');
    }

    public function pdf(int $labelId)
    {
        service('scopeContext')->assertAggregate('label', $labelId);
        $row = $this->labelDocumentContext($labelId);
        if ($row === null) {
            throw PageNotFoundException::forPageNotFound('Etiqueta no encontrada.');
        }

        $qrPayload = 'GMAR|LABEL=' . (string) $row['label_code'];
        $qrImage = service('codeImageService')->qrDataUri($qrPayload);
        $html = $this->operationalLabelHtml($row, $qrImage);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $pdf = new Dompdf($options);
        $width = 99 * 72 / 25.4;
        $height = 50 * 72 / 25.4;
        $pdf->setPaper([0, 0, $width, $height]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        if ($pdf->getCanvas()->get_page_count() !== 1) {
            throw new \RuntimeException('El marbete excedió una página 99x50 mm.');
        }

        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $row['label_code']);

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $safeCode . '.pdf"')
            ->setBody($pdf->output());
    }

    private function labelDocumentContext(int $labelId): ?array
    {
        return db_connect()->table('labels l')
            ->select('l.id, l.code AS label_code, w.id AS weighing_id, w.gross_weight_kg, w.accepted_net_kg, w.applied_tare_kg, w.penalty_percent, w.confirmed_at, t.code AS tub_code, lo.code AS lot_code, s.name AS species_name, s.code AS species_code, sg.name AS size_name, sg.code AS size_code, v.annual_number, ve.name AS vessel_name, rsa.code AS rsa_code')
            ->join('weighings w', 'w.id = l.weighing_id')
            ->join('tub_fillings tf', 'tf.id = w.tub_filling_id')
            ->join('tubs t', 't.id = w.tub_id')
            ->join('lots lo', 'lo.id = w.lot_id')
            ->join('voyages v', 'v.id = lo.voyage_id')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->join('species s', 's.id = tf.species_id')
            ->join('size_grades sg', 'sg.id = tf.size_grade_id')
            ->join('rsa_records rsa', 'rsa.id = w.rsa_id', 'left')
            ->where('l.id', $labelId)
            ->get()->getRowArray();
    }

    private function operationalLabelHtml(array $row, string $qrImage): string
    {
        $e = static fn (mixed $value): string => esc((string) ($value ?? '—'));
        $date = ! empty($row['confirmed_at']) ? date('d/m/Y', strtotime((string) $row['confirmed_at'])) : '—';
        $gross = number_format((float) ($row['gross_weight_kg'] ?? 0), 3, '.', '');
        $net = number_format((float) ($row['accepted_net_kg'] ?? 0), 3, '.', '');
        $tare = number_format((float) ($row['applied_tare_kg'] ?? 0), 3, '.', '');
        $penalty = number_format((float) ($row['penalty_percent'] ?? 0), 2, '.', '');
        $species = mb_strtoupper((string) ($row['species_name'] ?? ''));
        $speciesCode = mb_strtoupper((string) ($row['species_code'] ?? ''));

        // En el marbete se muestra la talla descriptiva. size_code puede ser una clave técnica.
        $size = mb_strtoupper(trim((string) ($row['size_name'] ?? '')));
        if ($size === '') {
            $size = mb_strtoupper((string) ($row['size_code'] ?? ''));
        }

        /*
         * Mismo prototipo aprobado para la PC43t, adaptado a PDF 99 x 50 mm:
         * - QR a la derecha.
         * - Separador desplazado hacia el QR para ampliar datos.
         * - Barco y marea en la misma línea.
         * - Peso bruto antes de peso neto.
         * - Sin código de barras.
         */
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>'
            . '@page{size:99mm 50mm;margin:0}'
            . 'html,body{margin:0;padding:0;width:99mm;height:50mm;overflow:hidden;font-family:DejaVu Sans,sans-serif;color:#000}'
            . '*{box-sizing:border-box}'
            . '.label{position:absolute;left:.8mm;top:.8mm;width:97.4mm;height:48.4mm;border:.35mm solid #000;overflow:hidden}'
            . '.head{position:absolute;left:1mm;top:1mm;width:95mm;height:10.3mm;text-align:center;border-bottom:.3mm solid #000}'
            . '.title{font-size:13pt;font-weight:800;line-height:1.05;white-space:nowrap}'
            . '.sub{font-size:7.4pt;font-weight:700;line-height:1.05;margin-top:1.1mm;white-space:nowrap}'
            . '.left{position:absolute;left:1.4mm;top:12.2mm;width:54.0mm;height:34.7mm;border-right:.3mm solid #000;padding-right:1.4mm}'
            . '.right{position:absolute;left:56.3mm;top:12.2mm;width:39.3mm;height:34.7mm;text-align:center;overflow:hidden}'
            . '.r{height:3.05mm;font-size:6.35pt;line-height:2.9mm;white-space:nowrap;overflow:hidden}'
            . '.k{display:inline-block;width:17.0mm;font-weight:800}.v{font-weight:700}'
            . '.barco{display:inline-block;width:34.5mm;overflow:hidden;vertical-align:top}'
            . '.marea{display:inline-block;width:16.5mm;vertical-align:top;text-align:left}'
            . '.marea .mk{font-weight:800;margin-right:1.2mm}'
            . '.sep{height:.25mm;border-top:.2mm solid #000;margin:.35mm 0 .45mm}'
            . '.qr{position:absolute;left:3.0mm;top:.6mm;width:33.3mm;height:33.3mm}'
            . '.key{position:absolute;left:.5mm;right:.5mm;bottom:.55mm;font-size:4.4pt;font-weight:700;text-align:center;white-space:nowrap;overflow:hidden}'
            . '</style></head><body><div class="label">'
            . '<div class="head"><div class="title">' . $e($species) . '</div><div class="sub">ESPECIE: ' . $e($speciesCode !== '' ? $speciesCode : $species) . ' / TALLA: ' . $e($size) . '</div></div>'
            . '<div class="left">'
            . '<div class="r"><span class="k">LOTE:</span><span class="v">' . $e($row['lot_code']) . '</span></div>'
            . '<div class="r"><span class="barco"><span class="k">BARCO:</span><span class="v">' . $e(mb_strtoupper((string) $row['vessel_name'])) . '</span></span><span class="marea"><span class="mk">MAREA:</span><span class="v">' . $e($row['annual_number']) . '</span></span></div>'
            . '<div class="r"><span class="k">FECHA:</span><span class="v">' . $e($date) . '</span></div>'
            . '<div class="sep"></div>'
            . '<div class="r"><span class="k">PESO BRUTO:</span><span class="v">' . $gross . ' KG</span></div>'
            . '<div class="r"><span class="k">PESO NETO:</span><span class="v">' . $net . ' KG</span></div>'
            . '<div class="r"><span class="k">TARA:</span><span class="v">' . $tare . ' KG</span></div>'
            . '<div class="r"><span class="k">CASTIGO:</span><span class="v">' . $penalty . ' %</span></div>'
            . '<div class="r"><span class="k">RSA:</span><span class="v">' . $e($row['rsa_code']) . '</span></div>'
            . '<div class="r"><span class="k">TINA:</span><span class="v">' . $e($row['tub_code']) . '</span></div>'
            . '</div>'
            . '<div class="right"><img class="qr" src="' . $qrImage . '"><div class="key">' . $e($row['label_code']) . '</div></div>'
            . '</div></body></html>';
    }

}
