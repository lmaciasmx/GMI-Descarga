<?php

namespace App\Modules\Supply\Controllers;

use App\Controllers\BaseController;

final class RequestController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();
        $requests = $db->table('raw_material_requests r')
            ->select('r.*, dw.code AS destination_warehouse_code, dw.name AS destination_warehouse_name, COUNT(rl.id) AS line_count, ROUND(SUM(rl.requested_weight_kg), 3) AS requested_kg, ROUND(SUM(rl.reserved_weight_kg), 3) AS reserved_kg')
            ->join('warehouses dw', 'dw.id = r.destination_warehouse_id', 'left')
            ->join('raw_material_request_lines rl', 'rl.request_id = r.id')
            ->where(['r.company_id' => $scope['company_id'], 'r.site_id' => $scope['site_id']])
            ->groupBy('r.id')->orderBy('r.required_at')->limit(500)->get()->getResultArray();
        $reservations = $db->table('reservations r')
            ->select("r.id, r.folio, r.status, r.expires_at, req.id AS request_id, req.folio AS request_folio, req.request_type, req.destination, req.destination_warehouse_id, dw.code AS destination_warehouse_code, dw.name AS destination_warehouse_name, COUNT(rl.id) AS unit_count, ROUND(SUM(rl.reserved_weight_kg), 3) AS reserved_kg, ROUND(SUM(rl.fulfilled_weight_kg), 3) AS fulfilled_kg, (SELECT COUNT(*) FROM transfers tx WHERE tx.reservation_id = r.id AND tx.status != 'canceled') AS transfer_count, (SELECT GROUP_CONCAT(tx.folio ORDER BY tx.id SEPARATOR ' · ') FROM transfers tx WHERE tx.reservation_id = r.id AND tx.status != 'canceled') AS transfer_folios", false)
            ->join('raw_material_requests req', 'req.id = r.request_id')
            ->join('reservation_lines rl', 'rl.reservation_id = r.id')
            ->join('warehouses dw', 'dw.id = req.destination_warehouse_id', 'left')
            ->where(['req.company_id' => $scope['company_id'], 'req.site_id' => $scope['site_id']])
            ->groupBy('r.id')
            ->orderBy('r.created_at', 'DESC')
            ->limit(500)
            ->get()->getResultArray();
        $destinationWarehouses = $db->table('warehouses w')
            ->select('w.id, w.company_id, w.site_id, w.code, w.name, s.name AS site_name')
            ->join('sites s', 's.id = w.site_id')
            ->where(['w.company_id' => (int) $scope['company_id'], 'w.active' => 1, 's.active' => 1])
            ->orderBy('s.name')->orderBy('w.name')
            ->get()->getResultArray();

        return view('App\\Modules\\Supply\\Views\\index', [
            'title' => 'Solicitudes de materia prima',
            'subtitle' => 'Demanda, autorización, disponibilidad y reserva de unidades físicas específicas.',
            'requests' => $requests,
            'reservations' => $reservations,
            'species' => $db->table('species')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'sizes' => $db->table('size_grades')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'destinationWarehouses' => $destinationWarehouses,
            'scope' => $scope,
            'canCreate' => auth()->user()->can('requests.create'),
            'canApprove' => auth()->user()->can('requests.approve'),
            'canReserve' => auth()->user()->can('reservations.manage'),
            'canTransfer' => auth()->user()->can('transfers.manage'),
        ]);
    }
}
