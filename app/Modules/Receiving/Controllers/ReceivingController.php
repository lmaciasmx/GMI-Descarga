<?php

namespace App\Modules\Receiving\Controllers;

use App\Controllers\BaseController;

final class ReceivingController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();
        $pending = $db->table('weighings w')->select('w.id, w.folio, w.accepted_net_kg, w.confirmed_at, l.code AS label_code, t.code AS tub_code, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name')->join('labels l', "l.weighing_id = w.id AND l.status <> 'canceled'")->join('tubs t', 't.id = w.tub_id')->join('lots lo', 'lo.id = w.lot_id')->join('species s', 's.id = lo.species_id')->join('tub_fillings tf', 'tf.id = w.tub_filling_id')->join('size_grades sg', 'sg.id = tf.size_grade_id')->join('voyages v', 'v.id = lo.voyage_id')->where(['v.company_id' => $scope['company_id'], 'v.site_id' => $scope['site_id']])->whereIn('w.status', ['confirmed', 'pending_inspection'])->orderBy('w.confirmed_at')->get()->getResultArray();
        $warehouseBuilder = $db->table('warehouses')->where(['company_id' => $scope['company_id'], 'site_id' => $scope['site_id'], 'active' => 1]);
        if (! empty($scope['warehouse_id'])) {
            $warehouseBuilder->where('id', $scope['warehouse_id']);
        }
        $warehouses = $warehouseBuilder->get()->getResultArray();
        $locations = $db->table('locations')->where('active', 1)->whereIn('warehouse_id', array_column($warehouses, 'id') ?: [0])->orderBy('path')->get()->getResultArray();
        return view('App\\Modules\\Receiving\\Views\\index', ['title' => 'Recepción en frigorífico', 'pending' => $pending, 'warehouses' => $warehouses, 'locations' => $locations]);
    }
}
