<?php

namespace App\Modules\Quality\Controllers;

use App\Controllers\BaseController;

final class QualityController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();
        $pending = $db->table('weighings w')
            ->select('w.id, w.folio, w.accepted_net_kg, w.confirmed_at, t.code AS tub_code, l.code AS lot_code, s.name AS species_name, sg.name AS size_name')
            ->join('tub_fillings tf', 'tf.id = w.tub_filling_id')
            ->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('tubs t', 't.id = w.tub_id')
            ->join('lots l', 'l.id = w.lot_id')
            ->join('species s', 's.id = tf.species_id')
            ->join('size_grades sg', 'sg.id = tf.size_grade_id')
            ->join('quality_inspections qi', 'qi.weighing_id = w.id', 'left')
            ->where(['v.company_id' => $scope['company_id'], 'v.site_id' => $scope['site_id'], 'qi.id' => null])
            ->whereIn('w.status', ['confirmed', 'pending_inspection'])
            ->orderBy('w.confirmed_at')
            ->limit(300)
            ->get()->getResultArray();

        $inspections = $db->table('quality_inspections qi')
            ->select('qi.*, w.folio AS weighing_folio, t.code AS tub_code, l.code AS lot_code, s.name AS species_name')
            ->join('weighings w', 'w.id = qi.weighing_id')
            ->join('tubs t', 't.id = w.tub_id')
            ->join('lots l', 'l.id = w.lot_id')
            ->join('species s', 's.id = l.species_id')
            ->join('voyages v', 'v.id = l.voyage_id')
            ->where(['v.company_id' => $scope['company_id'], 'v.site_id' => $scope['site_id']])
            ->orderBy('qi.inspected_at', 'DESC')
            ->limit(300)
            ->get()->getResultArray();

        return view('App\\Modules\\Quality\\Views\\index', [
            'title' => 'Calidad y disposición',
            'subtitle' => 'La calidad y el castigo permanecen como conceptos independientes.',
            'pending' => $pending,
            'inspections' => $inspections,
            'defects' => $db->table('quality_defects')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'canApprove' => auth()->user()->can('quality.approve'),
        ]);
    }
}
