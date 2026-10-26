<?php

namespace App\Modules\Quality\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\Database\BaseBuilder;
use Dompdf\Dompdf;
use Dompdf\Options;

final class QualityController extends BaseController
{
    public function index()
    {
        $scope = (array) session('operational_scope');
        $db = db_connect();

        $filters = [
            'q' => trim((string) $this->request->getGet('q')),
            'date' => trim((string) $this->request->getGet('date')),
            'voyage_id' => max(0, (int) $this->request->getGet('voyage_id')),
            'species_id' => max(0, (int) $this->request->getGet('species_id')),
            'size_grade_id' => max(0, (int) $this->request->getGet('size_grade_id')),
            'lot_id' => max(0, (int) $this->request->getGet('lot_id')),
            'result' => trim((string) $this->request->getGet('result')),
            'incidents_only' => $this->request->getGet('incidents_only') === '1',
            'view' => in_array($this->request->getGet('view'), ['lot', 'tub'], true) ? (string) $this->request->getGet('view') : 'lot',
        ];

        $companyId = (int) ($scope['company_id'] ?? 0);
        $siteId = (int) ($scope['site_id'] ?? 0);
        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);

        $lotPage = max(1, (int) ($this->request->getGet('lot_page') ?? 1));
        $unitPage = max(1, (int) ($this->request->getGet('unit_page') ?? 1));
        $historyPage = max(1, (int) ($this->request->getGet('history_page') ?? 1));
        $pageSize = 25;

        $metricBase = $db->table('stock_units su')
            ->join('lots l', 'l.id = su.lot_id')
            ->join('voyages v', 'v.id = l.voyage_id')
            ->where(['su.company_id' => $companyId, 'su.site_id' => $siteId])
            ->where('su.on_hand_weight_kg >', 0);
        if ($warehouseId > 0) {
            $metricBase->where('su.warehouse_id', $warehouseId);
        }

        $activeLots = (clone $metricBase)->select('COUNT(DISTINCT su.lot_id) AS total', false)->get()->getRowArray();
        $samplesToday = $db->table('quality_inspections qi')
            ->join('weighings w', 'w.id = qi.weighing_id')
            ->join('lots l', 'l.id = w.lot_id')
            ->join('voyages v', 'v.id = l.voyage_id')
            ->where(['v.company_id' => $companyId, 'v.site_id' => $siteId])
            ->where('qi.status !=', 'annulled')
            ->where('DATE(qi.inspected_at)', date('Y-m-d'), false)
            ->countAllResults();
        $observations = $db->table('quality_inspections qi')
            ->join('weighings w', 'w.id = qi.weighing_id')
            ->join('lots l', 'l.id = w.lot_id')
            ->join('voyages v', 'v.id = l.voyage_id')
            ->where(['v.company_id' => $companyId, 'v.site_id' => $siteId, 'qi.disposition' => 'observed'])
            ->where('qi.status !=', 'annulled')
            ->where('DATE(qi.inspected_at)', date('Y-m-d'), false)
            ->countAllResults();
        $retentions = (clone $metricBase)->where('su.status', 'held')->countAllResults();
        $rejections = (clone $metricBase)->where('su.status', 'rejected')->countAllResults();

        $lotBuilder = $this->stockBuilder($db, $companyId, $siteId, $warehouseId)
            ->select("l.id AS lot_id, l.code AS lot_code, s.name AS species_name, s.code AS species_code, GROUP_CONCAT(DISTINCT sg.name ORDER BY sg.name SEPARATOR ', ') AS sizes, COUNT(DISTINCT su.id) AS tub_count, SUM(su.on_hand_weight_kg) AS weight_kg, COUNT(DISTINCT qi.id) AS sample_count, SUM(CASE WHEN su.status = 'held' THEN 1 ELSE 0 END) AS held_count, SUM(CASE WHEN su.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count, SUM(CASE WHEN qi.disposition = 'observed' THEN 1 ELSE 0 END) AS observation_count, v.id AS voyage_id, v.annual_number, v.year, vs.code AS vessel_code, vs.name AS vessel_name", false)
            ;
        $this->applyStockFilters($lotBuilder, $filters);

        $lotCountBuilder = $this->stockBuilder($db, $companyId, $siteId, $warehouseId);
        $this->applyStockFilters($lotCountBuilder, $filters);
        $lotTotalRow = $lotCountBuilder->select('COUNT(DISTINCT l.id) AS total', false)->get()->getRowArray();
        $lotTotal = (int) ($lotTotalRow['total'] ?? 0);

        $lots = $lotBuilder
            ->groupBy('l.id, l.code, s.name, s.code, v.id, v.annual_number, v.year, vs.code, vs.name')
            ->orderBy('MAX(su.received_at)', 'DESC', false)
            ->limit($pageSize, ($lotPage - 1) * $pageSize)
            ->get()->getResultArray();
        foreach ($lots as &$lot) {
            $lot['quality_state'] = (int) $lot['rejected_count'] > 0
                ? 'rejected'
                : ((int) $lot['held_count'] > 0
                    ? 'held'
                    : ((int) $lot['observation_count'] > 0 ? 'observed' : 'clear'));
        }
        unset($lot);

        $unitBuilder = $this->stockBuilder($db, $companyId, $siteId, $warehouseId)
            ->select("su.id AS stock_unit_id, su.unit_code, su.status AS stock_status, su.on_hand_weight_kg, su.received_at, su.weighing_id, l.id AS lot_id, l.code AS lot_code, s.name AS species_name, sg.name AS size_name, t.code AS tub_code, c.name AS chamber_name, c.code AS chamber_code, loc.path AS location_path, MAX(qi.id) AS inspection_id, MAX(qi.disposition) AS disposition, MAX(qi.status) AS inspection_status", false)
            ->join('tubs t', 't.id = su.tub_id')
            ->join('locations loc', 'loc.id = su.location_id')
            ->join('chambers c', 'c.id = loc.chamber_id', 'left')
            ;
        $this->applyStockFilters($unitBuilder, $filters);

        $unitCountBuilder = $this->stockBuilder($db, $companyId, $siteId, $warehouseId);
        $this->applyStockFilters($unitCountBuilder, $filters);
        $unitTotalRow = $unitCountBuilder->select('COUNT(DISTINCT su.id) AS total', false)->get()->getRowArray();
        $unitTotal = (int) ($unitTotalRow['total'] ?? 0);

        $units = $unitBuilder
            ->groupBy('su.id, su.unit_code, su.status, su.on_hand_weight_kg, su.received_at, su.weighing_id, l.id, l.code, s.name, sg.name, t.code, c.name, c.code, loc.path')
            ->orderBy('su.received_at', 'DESC')
            ->limit($pageSize, ($unitPage - 1) * $pageSize)
            ->get()->getResultArray();

        $candidates = $this->stockBuilder($db, $companyId, $siteId, $warehouseId)
            ->select('su.weighing_id, su.unit_code, su.on_hand_weight_kg, l.id AS lot_id, l.code AS lot_code, s.name AS species_name, sg.name AS size_name, t.code AS tub_code, c.name AS chamber_name, c.code AS chamber_code, loc.path AS location_path')
            ->join('tubs t', 't.id = su.tub_id')
            ->join('locations loc', 'loc.id = su.location_id')
            ->join('chambers c', 'c.id = loc.chamber_id', 'left')
            ->whereNotIn('su.status', ['dispatched', 'consumed', 'in_transit'])
            ->where('qi.id', null)
            ->orderBy('su.received_at', 'DESC')
            ->limit(500)
            ->get()->getResultArray();

        $historyBuilder = $db->table('quality_inspections qi')
            ->select("qi.*, w.folio AS weighing_folio, t.code AS tub_code, l.id AS lot_id, l.code AS lot_code, s.name AS species_name, sg.name AS size_name, COALESCE(u.username, CONCAT('Usuario #', qi.inspected_by)) AS inspector_name, GROUP_CONCAT(DISTINCT qd.name ORDER BY qd.name SEPARATOR ', ') AS defect_names", false)
            ->join('weighings w', 'w.id = qi.weighing_id')
            ->join('tub_fillings tf', 'tf.id = w.tub_filling_id')
            ->join('tubs t', 't.id = w.tub_id')
            ->join('lots l', 'l.id = w.lot_id')
            ->join('species s', 's.id = tf.species_id')
            ->join('size_grades sg', 'sg.id = tf.size_grade_id')
            ->join('voyages v', 'v.id = l.voyage_id')
            ->join('quality_inspection_defects qid', 'qid.quality_inspection_id = qi.id', 'left')
            ->join('quality_defects qd', 'qd.id = qid.defect_id', 'left')
            ->join('users u', 'u.id = qi.inspected_by', 'left')
            ->where(['v.company_id' => $companyId, 'v.site_id' => $siteId]);
        $this->applyHistoryFilters($historyBuilder, $filters);

        $historyCountBuilder = $db->table('quality_inspections qi')
            ->join('weighings w', 'w.id = qi.weighing_id')
            ->join('tub_fillings tf', 'tf.id = w.tub_filling_id')
            ->join('lots l', 'l.id = w.lot_id')
            ->join('voyages v', 'v.id = l.voyage_id')
            ->where(['v.company_id' => $companyId, 'v.site_id' => $siteId]);
        $this->applyHistoryFilters($historyCountBuilder, $filters);
        $historyTotal = $historyCountBuilder->countAllResults();

        $inspections = $historyBuilder
            ->groupBy('qi.id, w.folio, t.code, l.id, l.code, s.name, sg.name, u.username')
            ->orderBy('qi.inspected_at', 'DESC')
            ->limit($pageSize, ($historyPage - 1) * $pageSize)
            ->get()->getResultArray();

        $filterOptions = [
            'voyages' => $db->table('voyages v')->select('v.id, v.annual_number, v.year, vs.code AS vessel_code, vs.name AS vessel_name')->join('vessels vs', 'vs.id = v.vessel_id')->where(['v.company_id' => $companyId, 'v.site_id' => $siteId])->orderBy('v.year', 'DESC')->orderBy('v.annual_number', 'DESC')->limit(200)->get()->getResultArray(),
            'species' => $db->table('species')->select('id, code, name')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'sizes' => $db->table('size_grades')->select('id, species_id, code, name')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'lots' => $db->table('lots l')->select('l.id, l.code')->join('voyages v', 'v.id = l.voyage_id')->where(['v.company_id' => $companyId, 'v.site_id' => $siteId])->orderBy('l.created_at', 'DESC')->limit(500)->get()->getResultArray(),
        ];

        $correctFrom = max(0, (int) $this->request->getGet('correct_from'));
        $correctionSource = null;
        if ($correctFrom > 0) {
            $correctionSource = $db->table('quality_inspections qi')
                ->select('qi.id, qi.status, qi.annul_reason, qi.weighing_id, w.folio AS weighing_folio, t.code AS tub_code, l.code AS lot_code')
                ->join('weighings w', 'w.id = qi.weighing_id')
                ->join('tubs t', 't.id = w.tub_id')
                ->join('lots l', 'l.id = w.lot_id')
                ->join('voyages v', 'v.id = l.voyage_id')
                ->where(['qi.id' => $correctFrom, 'qi.status' => 'annulled', 'v.company_id' => $companyId, 'v.site_id' => $siteId])
                ->get()->getRowArray();
        }

        return view('App\\Modules\\Quality\\Views\\index', [
            'title' => 'Calidad y disposición',
            'subtitle' => 'Control por lote, muestreo e incidencias. La disponibilidad de inventario es independiente de la inspección.',
            'metrics' => [
                'active_lots' => (int) ($activeLots['total'] ?? 0),
                'samples_today' => $samplesToday,
                'observations' => $observations,
                'retentions' => $retentions,
                'rejections' => $rejections,
            ],
            'filters' => $filters,
            'filterOptions' => $filterOptions,
            'lots' => $lots,
            'lotTotal' => $lotTotal,
            'lotPage' => $lotPage,
            'units' => $units,
            'unitTotal' => $unitTotal,
            'unitPage' => $unitPage,
            'pageSize' => $pageSize,
            'candidates' => $candidates,
            'inspections' => $inspections,
            'historyTotal' => $historyTotal,
            'historyPage' => $historyPage,
            'defects' => $db->table('quality_defects')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'canApprove' => auth()->user()->can('quality.approve'),
            'currentUserId' => (int) auth()->id(),
            'correctionSource' => $correctionSource,
        ]);
    }

    public function exportCsv()
    {
        $scope = (array) session('operational_scope');
        $rows = $this->reportRows(
            (int) ($scope['company_id'] ?? 0),
            (int) ($scope['site_id'] ?? 0),
            $this->reportFilters(),
        );

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Inspección', 'Fecha', 'Pesaje', 'Tina', 'Lote', 'Especie', 'Talla', 'Grado', 'Defecto %', 'Temperatura °C', 'Resultado', 'Estado', 'Defectos', 'Observaciones', 'Inspector', 'Motivo anulación'], ';');
        foreach ($rows as $row) {
            fputcsv($stream, [
                'INSP-' . str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT),
                $row['inspected_at'],
                $row['weighing_folio'],
                $row['tub_code'],
                $row['lot_code'],
                $row['species_name'],
                $row['size_name'],
                $row['quality_grade'],
                $row['defect_percent'],
                $row['temperature_c'],
                $this->resultLabel((string) $row['disposition']),
                $this->inspectionStatusLabel((string) $row['status']),
                $row['defect_names'],
                $row['observations'],
                $row['inspector_name'],
                $row['annul_reason'],
            ], ';');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="calidad-' . date('Ymd-His') . '.csv"')
            ->setBody($content ?: '');
    }

    public function exportPdf()
    {
        $scope = (array) session('operational_scope');
        $filters = $this->reportFilters();
        $rows = $this->reportRows(
            (int) ($scope['company_id'] ?? 0),
            (int) ($scope['site_id'] ?? 0),
            $filters,
        );

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('letter', 'landscape');
        $pdf->loadHtml(view('App\\Modules\\Quality\\Views\\report_pdf', [
            'rows' => $rows,
            'filters' => $filters,
            'generatedAt' => date('Y-m-d H:i:s'),
        ]));
        $pdf->render();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="calidad-' . date('Ymd-His') . '.pdf"')
            ->setBody($pdf->output());
    }

    private function reportFilters(): array
    {
        return [
            'q' => trim((string) $this->request->getGet('q')),
            'date' => trim((string) $this->request->getGet('date')),
            'voyage_id' => max(0, (int) $this->request->getGet('voyage_id')),
            'species_id' => max(0, (int) $this->request->getGet('species_id')),
            'size_grade_id' => max(0, (int) $this->request->getGet('size_grade_id')),
            'lot_id' => max(0, (int) $this->request->getGet('lot_id')),
            'result' => trim((string) $this->request->getGet('result')),
            'incidents_only' => $this->request->getGet('incidents_only') === '1',
            'view' => 'lot',
        ];
    }

    private function reportRows(int $companyId, int $siteId, array $filters): array
    {
        $builder = db_connect()->table('quality_inspections qi')
            ->select("qi.*, w.folio AS weighing_folio, t.code AS tub_code, l.code AS lot_code, s.name AS species_name, sg.name AS size_name, COALESCE(u.username, CONCAT('Usuario #', qi.inspected_by)) AS inspector_name, GROUP_CONCAT(DISTINCT qd.name ORDER BY qd.name SEPARATOR ', ') AS defect_names", false)
            ->join('weighings w', 'w.id = qi.weighing_id')
            ->join('tub_fillings tf', 'tf.id = w.tub_filling_id')
            ->join('tubs t', 't.id = w.tub_id')
            ->join('lots l', 'l.id = w.lot_id')
            ->join('species s', 's.id = tf.species_id')
            ->join('size_grades sg', 'sg.id = tf.size_grade_id')
            ->join('voyages v', 'v.id = l.voyage_id')
            ->join('quality_inspection_defects qid', 'qid.quality_inspection_id = qi.id', 'left')
            ->join('quality_defects qd', 'qd.id = qid.defect_id', 'left')
            ->join('users u', 'u.id = qi.inspected_by', 'left')
            ->where(['v.company_id' => $companyId, 'v.site_id' => $siteId]);
        $this->applyHistoryFilters($builder, $filters);

        return $builder
            ->groupBy('qi.id, w.folio, t.code, l.code, s.name, sg.name, u.username')
            ->orderBy('qi.inspected_at', 'DESC')
            ->limit(10000)
            ->get()->getResultArray();
    }

    private function resultLabel(string $value): string
    {
        return [
            'accepted' => 'Sin incidencia',
            'observed' => 'Observación',
            'hold' => 'Retener tina',
            'hold_lot' => 'Retener lote',
            'released' => 'Liberada',
            'released_lot' => 'Lote liberado',
            'rejected' => 'Rechazada',
        ][$value] ?? $value;
    }

    private function inspectionStatusLabel(string $value): string
    {
        return [
            'submitted' => 'Pendiente de dictamen',
            'approved' => 'Completada',
            'annulled' => 'Anulada',
        ][$value] ?? $value;
    }

    private function stockBuilder($db, int $companyId, int $siteId, int $warehouseId): BaseBuilder
    {
        $builder = $db->table('stock_units su')
            ->join('lots l', 'l.id = su.lot_id')
            ->join('species s', 's.id = su.species_id')
            ->join('size_grades sg', 'sg.id = su.size_grade_id')
            ->join('voyages v', 'v.id = l.voyage_id')
            ->join('vessels vs', 'vs.id = v.vessel_id')
            ->join('quality_inspections qi', "qi.weighing_id = su.weighing_id AND qi.status <> 'annulled'", 'left')
            ->where(['su.company_id' => $companyId, 'su.site_id' => $siteId])
            ->where('su.on_hand_weight_kg >', 0);
        if ($warehouseId > 0) {
            $builder->where('su.warehouse_id', $warehouseId);
        }
        return $builder;
    }

    private function applyStockFilters(BaseBuilder $builder, array $filters): void
    {
        if ($filters['q'] !== '') {
            $builder->groupStart()
                ->like('su.unit_code', $filters['q'])
                ->orLike('l.code', $filters['q'])
                ->orLike('s.name', $filters['q'])
                ->orLike('sg.name', $filters['q'])
                ->orLike('vs.code', $filters['q'])
                ->orLike('vs.name', $filters['q'])
                ->groupEnd();
        }
        if ($filters['date'] !== '') {
            $start = $filters['date'] . ' 00:00:00';
            $end = date('Y-m-d H:i:s', strtotime($filters['date'] . ' +1 day'));
            $builder->where('su.received_at >=', $start)->where('su.received_at <', $end);
        }
        if ($filters['voyage_id'] > 0) {
            $builder->where('v.id', $filters['voyage_id']);
        }
        if ($filters['species_id'] > 0) {
            $builder->where('su.species_id', $filters['species_id']);
        }
        if ($filters['size_grade_id'] > 0) {
            $builder->where('su.size_grade_id', $filters['size_grade_id']);
        }
        if ($filters['lot_id'] > 0) {
            $builder->where('l.id', $filters['lot_id']);
        }
        if ($filters['result'] === 'held') {
            $builder->where('su.status', 'held');
        } elseif ($filters['result'] === 'rejected') {
            $builder->where('su.status', 'rejected');
        } elseif ($filters['result'] === 'observed') {
            $builder->where('qi.disposition', 'observed');
        } elseif ($filters['result'] === 'clear') {
            $builder->whereNotIn('su.status', ['held', 'rejected']);
            $builder->groupStart()->where('qi.id', null)->orWhere('qi.disposition', 'accepted')->orWhere('qi.disposition', 'released')->orWhere('qi.disposition', 'released_lot')->groupEnd();
        }
        if ($filters['incidents_only']) {
            $builder->groupStart()->whereIn('su.status', ['held', 'rejected'])->orWhere('qi.disposition', 'observed')->groupEnd();
        }
    }

    private function applyHistoryFilters(BaseBuilder $builder, array $filters): void
    {
        if ($filters['q'] !== '') {
            $builder->groupStart()->like('w.folio', $filters['q'])->orLike('l.code', $filters['q'])->groupEnd();
        }
        if ($filters['date'] !== '') {
            $start = $filters['date'] . ' 00:00:00';
            $end = date('Y-m-d H:i:s', strtotime($filters['date'] . ' +1 day'));
            $builder->where('qi.inspected_at >=', $start)->where('qi.inspected_at <', $end);
        }
        if ($filters['voyage_id'] > 0) {
            $builder->where('v.id', $filters['voyage_id']);
        }
        if ($filters['lot_id'] > 0) {
            $builder->where('l.id', $filters['lot_id']);
        }
        if ($filters['species_id'] > 0) {
            $builder->where('tf.species_id', $filters['species_id']);
        }
        if ($filters['size_grade_id'] > 0) {
            $builder->where('tf.size_grade_id', $filters['size_grade_id']);
        }
        if ($filters['result'] === 'clear') {
            $builder->whereIn('qi.disposition', ['accepted', 'released', 'released_lot']);
        } elseif ($filters['result'] === 'observed') {
            $builder->where('qi.disposition', 'observed');
        } elseif ($filters['result'] === 'held') {
            $builder->whereIn('qi.disposition', ['hold', 'hold_lot']);
        } elseif ($filters['result'] === 'rejected') {
            $builder->where('qi.disposition', 'rejected');
        }
        if ($filters['incidents_only']) {
            $builder->whereIn('qi.disposition', ['observed', 'hold', 'hold_lot', 'rejected']);
        }
    }
}
