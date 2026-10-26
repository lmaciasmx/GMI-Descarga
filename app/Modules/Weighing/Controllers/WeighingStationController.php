<?php

namespace App\Modules\Weighing\Controllers;

use App\Controllers\BaseController;

final class WeighingStationController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();
        $plans = $db->table('discharge_plans dp')->select('dp.id, dp.folio, dp.voyage_id, dp.rsa_id, rsa.code AS rsa_code, ve.code AS vessel_code, ve.name AS vessel_name, v.annual_number')->join('voyages v', 'v.id = dp.voyage_id')->join('vessels ve', 've.id = v.vessel_id')->join('rsa_records rsa', 'rsa.id = dp.rsa_id', 'left')->where(['v.company_id' => $scope['company_id'], 'v.site_id' => $scope['site_id'], 'dp.status' => 'in_progress'])->get()->getResultArray();
        $tanks = $db->table('voyage_tanks vt')->select('vt.id, vt.voyage_id, vt.tank_number')->join('voyages v', 'v.id = vt.voyage_id')->where(['v.company_id' => $scope['company_id'], 'v.site_id' => $scope['site_id']])->whereIn('v.status', ['planned', 'unloading'])->get()->getResultArray();
        $tubs = $db->table('tubs')->select('id, code, barcode, current_tare_kg, status')->where(['company_id' => $scope['company_id'], 'active' => 1])->whereIn('status', ['available', 'pending_weighing'])->orderBy('code')->get()->getResultArray();
        $species = $db->table('species')->where('active', 1)->orderBy('name')->get()->getResultArray();
        $sizes = $db->table('size_grades')->where('active', 1)->orderBy('name')->get()->getResultArray();
        $pending = $db->table('tub_fillings tf')->select('tf.id, tf.discharge_plan_id, tf.tub_id, tf.actual_voyage_tank_id, tf.species_id, tf.size_grade_id, t.code AS tub_code, vt.tank_number, s.name AS species_name, sg.name AS size_name')->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')->join('voyages v', 'v.id = dp.voyage_id')->join('tubs t', 't.id = tf.tub_id')->join('voyage_tanks vt', 'vt.id = tf.actual_voyage_tank_id')->join('species s', 's.id = tf.species_id')->join('size_grades sg', 'sg.id = tf.size_grade_id')->where(['v.company_id' => $scope['company_id'], 'v.site_id' => $scope['site_id'], 'tf.status' => 'pending_weighing'])->orderBy('tf.completed_at')->get()->getResultArray();
        $sourceRows = $db->table('filling_source_tanks')->select('tub_filling_id, voyage_tank_id')->whereIn('tub_filling_id', array_column($pending, 'id') ?: [0])->get()->getResultArray();
        $sourcesByFilling = [];
        foreach ($sourceRows as $source) {
            $sourcesByFilling[(int) $source['tub_filling_id']][] = (int) $source['voyage_tank_id'];
        }
        $templateCode = (string) env('integrations.printer.templateCode', in_array((string) env('integrations.printer.driver', 'pdf'), ['zpl', 'thermal'], true) ? 'TINA_RECEPCION_ZPL' : 'TINA_RECEPCION');
        $template = $db->table('label_templates')->where(['code' => $templateCode, 'active' => 1])->orderBy('version', 'DESC')->get()->getRowArray();
        $printer = $db->table('printers')->where(['site_id' => $scope['site_id'], 'active' => 1])->get()->getRowArray();
        $scale = $db->table('scales')->where(['site_id' => $scope['site_id'], 'active' => 1])->get()->getRowArray();
        $estimatedTare = (float) service('settingsService')->get('tare.estimated_kg', (int) $scope['company_id'], (int) $scope['site_id'], 176.000);

        return view('App\\Modules\\Weighing\\Views\\station', compact('plans', 'tanks', 'tubs', 'species', 'sizes', 'pending', 'sourcesByFilling', 'template', 'printer', 'scale', 'estimatedTare') + [
            'title' => 'Estación de pesaje',
            'canOperateDischarge' => auth()->user()->can('discharge.operate'),
            'currentUserId' => (int) auth()->id(),
            'currentUsername' => (string) auth()->user()->username,
        ]);
    }
}
