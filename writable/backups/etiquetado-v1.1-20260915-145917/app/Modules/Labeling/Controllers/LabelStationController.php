<?php

namespace App\Modules\Labeling\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;

final class LabelStationController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();

        $weighings = $db->table('weighings w')
            ->select("w.id, w.folio, w.accepted_net_kg, w.confirmed_at, w.status, t.code AS tub_code, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name, l.id AS label_id, l.code AS label_code, l.template_id AS label_template_id, l.status AS label_status, (SELECT COUNT(*) FROM print_jobs ipj WHERE ipj.label_id = l.id AND ipj.is_reprint = 0 AND ipj.result IN ('sent', 'generated_pdf', 'awaiting_browser')) AS initial_print_count, (SELECT pj.id FROM print_jobs pj WHERE pj.label_id = l.id ORDER BY pj.print_number DESC LIMIT 1) AS last_job_id, (SELECT pj.result FROM print_jobs pj WHERE pj.label_id = l.id ORDER BY pj.print_number DESC LIMIT 1) AS last_print_result, (SELECT pj.output_path FROM print_jobs pj WHERE pj.label_id = l.id ORDER BY pj.print_number DESC LIMIT 1) AS last_output_path, (SELECT pj.print_number FROM print_jobs pj WHERE pj.label_id = l.id ORDER BY pj.print_number DESC LIMIT 1) AS last_print_number", false)
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
            ->orderBy('w.confirmed_at', 'DESC')
            ->limit(500)
            ->get()->getResultArray();

        $templates = $db->table('label_templates')->where('active', 1)->orderBy('code')->orderBy('version', 'DESC')->get()->getResultArray();
        $printers = $db->table('printers')->where(['site_id' => $scope['site_id'], 'active' => 1])->orderBy('name')->get()->getResultArray();
        try {
            $printerStatus = service('labelPrinter')->getPrinterStatus();
        } catch (\Throwable) {
            $printerStatus = ['connected' => false, 'status' => 'unavailable', 'physical_printer' => null];
        }

        return view('App\\Modules\\Labeling\\Views\\station', [
            'title' => 'Estación de etiquetado',
            'subtitle' => 'Impresión inicial y reimpresión trazable por marbete, plantilla, impresora, usuario y motivo.',
            'weighings' => $weighings,
            'templates' => $templates,
            'printers' => $printers,
            'printerStatus' => $printerStatus,
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
}
