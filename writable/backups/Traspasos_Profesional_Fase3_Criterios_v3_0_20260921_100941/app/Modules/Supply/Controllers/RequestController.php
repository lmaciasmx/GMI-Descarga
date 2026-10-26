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
            ->select("r.*, sw.code AS source_warehouse_code, sw.name AS source_warehouse_name, ss.name AS source_site_name, dw.code AS destination_warehouse_code, dw.name AS destination_warehouse_name, ds.name AS destination_site_name, MAX(u.username) AS requester_username, COUNT(rl.id) AS line_count, ROUND(SUM(rl.requested_weight_kg), 3) AS requested_kg, ROUND(SUM(rl.reserved_weight_kg), 3) AS reserved_kg", false)
            ->join('warehouses sw', 'sw.id = r.source_warehouse_id', 'left')
            ->join('sites ss', 'ss.id = sw.site_id', 'left')
            ->join('warehouses dw', 'dw.id = r.destination_warehouse_id', 'left')
            ->join('sites ds', 'ds.id = dw.site_id', 'left')
            ->join('users u', 'u.id = r.requested_by', 'left')
            ->join('raw_material_request_lines rl', 'rl.request_id = r.id')
            ->where(['r.company_id' => $scope['company_id'], 'r.site_id' => $scope['site_id']])
            ->groupBy('r.id')
            ->orderBy('r.required_at')
            ->limit(500)
            ->get()->getResultArray();

        $reservations = $db->table('reservations r')
            ->select("r.id, r.folio, r.status, r.expires_at, req.id AS request_id, req.folio AS request_folio, req.request_type, req.destination, req.destination_warehouse_id, req.source_warehouse_id, dw.code AS destination_warehouse_code, dw.name AS destination_warehouse_name, ds.name AS destination_site_name, COALESCE((SELECT tx.origin_warehouse_id FROM transfers tx WHERE tx.reservation_id = r.id AND tx.status != 'canceled' ORDER BY tx.id LIMIT 1), MAX(ow.id)) AS origin_warehouse_id, COALESCE((SELECT tow.code FROM transfers tx INNER JOIN warehouses tow ON tow.id = tx.origin_warehouse_id WHERE tx.reservation_id = r.id AND tx.status != 'canceled' ORDER BY tx.id LIMIT 1), MAX(ow.code)) AS origin_warehouse_code, COALESCE((SELECT tow.name FROM transfers tx INNER JOIN warehouses tow ON tow.id = tx.origin_warehouse_id WHERE tx.reservation_id = r.id AND tx.status != 'canceled' ORDER BY tx.id LIMIT 1), MAX(ow.name)) AS origin_warehouse_name, COALESCE((SELECT tos.name FROM transfers tx INNER JOIN warehouses tow ON tow.id = tx.origin_warehouse_id INNER JOIN sites tos ON tos.id = tow.site_id WHERE tx.reservation_id = r.id AND tx.status != 'canceled' ORDER BY tx.id LIMIT 1), MAX(os.name)) AS origin_site_name, COUNT(rl.id) AS unit_count, ROUND(SUM(rl.reserved_weight_kg), 3) AS reserved_kg, ROUND(SUM(rl.fulfilled_weight_kg), 3) AS fulfilled_kg, (SELECT COUNT(*) FROM transfers tx WHERE tx.reservation_id = r.id AND tx.status != 'canceled') AS transfer_count, (SELECT GROUP_CONCAT(tx.folio ORDER BY tx.id SEPARATOR ' · ') FROM transfers tx WHERE tx.reservation_id = r.id AND tx.status != 'canceled') AS transfer_folios", false)
            ->join('raw_material_requests req', 'req.id = r.request_id')
            ->join('reservation_lines rl', 'rl.reservation_id = r.id')
            ->join('stock_units su', 'su.id = rl.stock_unit_id')
            ->join('warehouses ow', 'ow.id = su.warehouse_id', 'left')
            ->join('sites os', 'os.id = ow.site_id', 'left')
            ->join('warehouses dw', 'dw.id = req.destination_warehouse_id', 'left')
            ->join('sites ds', 'ds.id = dw.site_id', 'left')
            ->where(['req.company_id' => $scope['company_id'], 'req.site_id' => $scope['site_id']])
            ->groupBy('r.id')
            ->orderBy('r.created_at', 'DESC')
            ->limit(500)
            ->get()->getResultArray();

        $scopeWarehouse = null;
        if (! empty($scope['warehouse_id'])) {
            $scopeWarehouse = $db->table('warehouses w')
                ->select('w.id, w.code, w.name, w.company_id, w.site_id, s.name AS site_name')
                ->join('sites s', 's.id = w.site_id')
                ->where([
                    'w.id' => (int) $scope['warehouse_id'],
                    'w.company_id' => (int) $scope['company_id'],
                    'w.site_id' => (int) $scope['site_id'],
                    'w.active' => 1,
                    's.active' => 1,
                ])
                ->get()->getRowArray();
        }

        return view('App\\Modules\\Supply\\Views\\index', [
            'title' => 'Solicitudes de materia prima',
            'subtitle' => 'Necesidad, autorización, selección del almacén surtidor, reserva y trazabilidad de la operación.',
            'requests' => $requests,
            'reservations' => $reservations,
            'species' => $db->table('species')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'sizes' => $db->table('size_grades')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'scopeWarehouse' => $scopeWarehouse,
            'requesterName' => (string) (auth()->user()->username ?? 'Usuario'),
            'scope' => $scope,
            'canCreate' => auth()->user()->can('requests.create'),
            'canApprove' => auth()->user()->can('requests.approve'),
            'canReserve' => auth()->user()->can('reservations.manage'),
            'canTransfer' => auth()->user()->can('transfers.manage'),
        ]);
    }
}
