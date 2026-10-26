<?php

namespace App\Modules\DischargePlanning\Controllers;

use App\Controllers\BaseController;

final class DischargeStationController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();

        $plans = $db->table('discharge_plans dp')
            ->select('dp.id, dp.folio, dp.voyage_id, rsa.code AS rsa_code, v.annual_number, ve.code AS vessel_code, ve.name AS vessel_name')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->join('rsa_records rsa', 'rsa.id = dp.rsa_id', 'left')
            ->where(['v.company_id' => $scope['company_id'], 'v.site_id' => $scope['site_id'], 'dp.status' => 'in_progress'])
            ->orderBy('dp.planned_start_at')
            ->get()->getResultArray();
        $planIds = array_map('intval', array_column($plans, 'id'));
        $voyageIds = array_map('intval', array_column($plans, 'voyage_id'));

        $lines = $db->table('discharge_plan_tanks dpt')
            ->select('dpt.id, dpt.discharge_plan_id, dpt.voyage_tank_id, dpt.species_id, dpt.size_grade_id, dpt.planned_weight_kg, dpt.msc_code, dpt.dolphin_safe_code, dpt.freezing_temperature_c, vt.tank_number, s.code AS species_code, s.name AS species_name, sg.name AS size_name')
            ->join('voyage_tanks vt', 'vt.id = dpt.voyage_tank_id')
            ->join('species s', 's.id = dpt.species_id')
            ->join('size_grades sg', 'sg.id = dpt.size_grade_id', 'left')
            ->whereIn('dpt.discharge_plan_id', $planIds ?: [0])
            ->orderBy('dpt.sequence_number')
            ->get()->getResultArray();

        $tanks = $db->table('voyage_tanks')
            ->select('id, voyage_id, tank_number')
            ->whereIn('voyage_id', $voyageIds ?: [0])
            ->orderBy('tank_number')
            ->get()->getResultArray();

        // tubs es un catálogo físico global; no existe tubs.company_id.
        $tubs = $db->table('tubs')
            ->select('id, code, barcode, status')
            ->where(['active' => 1, 'status' => 'available'])
            ->orderBy('code')
            ->get()->getResultArray();

        $fillings = $db->table('tub_fillings tf')
            ->select("tf.id, tf.status, tf.started_at, tf.completed_at, dp.folio AS plan_folio, t.code AS tub_code, vt.tank_number, s.name AS species_name, sg.name AS size_name, GROUP_CONCAT(srcvt.tank_number ORDER BY fst.is_primary DESC, srcvt.tank_number SEPARATOR ', ') AS source_tanks", false)
            ->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('tubs t', 't.id = tf.tub_id')
            ->join('voyage_tanks vt', 'vt.id = tf.actual_voyage_tank_id')
            ->join('species s', 's.id = tf.species_id')
            ->join('size_grades sg', 'sg.id = tf.size_grade_id')
            ->join('filling_source_tanks fst', 'fst.tub_filling_id = tf.id')
            ->join('voyage_tanks srcvt', 'srcvt.id = fst.voyage_tank_id')
            ->where(['v.company_id' => $scope['company_id'], 'v.site_id' => $scope['site_id']])
            ->whereIn('tf.status', ['filling', 'pending_weighing'])
            ->groupBy('tf.id')
            ->orderBy('tf.started_at', 'DESC')
            ->limit(300)
            ->get()->getResultArray();

        return view('App\\Modules\\DischargePlanning\\Views\\station', [
            'title' => 'Descarga y llenado de tinas',
            'subtitle' => 'Registro de procedencia por uno o varios tanques antes de enviar cada tina a pesaje.',
            'plans' => $plans,
            'lines' => $lines,
            'tanks' => $tanks,
            'tubs' => $tubs,
            'fillings' => $fillings,
            'sizes' => $db->table('size_grades')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'currentUserId' => (int) auth()->id(),
        ]);
    }
}
