<?php

namespace App\Modules\Transfers\Controllers;

use App\Controllers\BaseController;

final class TransferController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope') ?: [];
        $companyId = (int) ($scope['company_id'] ?? 0);
        $siteId = (int) ($scope['site_id'] ?? 0);
        $warehouseId = empty($scope['warehouse_id']) ? null : (int) $scope['warehouse_id'];
        $db = db_connect();

        $builder = $db->table('transfers t')
            ->select('t.*, ow.name AS origin_name, ow.code AS origin_code, ow.site_id AS origin_site_id, dw.name AS destination_name, dw.code AS destination_code, dw.site_id AS destination_site_id, req.folio AS request_folio, rs.folio AS reservation_folio, COUNT(tl.id) AS unit_count, ROUND(COALESCE(SUM(tl.shipped_weight_kg), 0), 3) AS shipped_kg')
            ->join('warehouses ow', 'ow.id = t.origin_warehouse_id')
            ->join('warehouses dw', 'dw.id = t.destination_warehouse_id')
            ->join('raw_material_requests req', 'req.id = t.request_id', 'left')
            ->join('reservations rs', 'rs.id = t.reservation_id', 'left')
            ->join('transfer_lines tl', 'tl.transfer_id = t.id', 'left')
            ->where('t.company_id', $companyId)
            ->groupStart()->where('ow.site_id', $siteId)->orWhere('dw.site_id', $siteId)->groupEnd();

        if ($warehouseId !== null) {
            $builder->groupStart()->where('t.origin_warehouse_id', $warehouseId)->orWhere('t.destination_warehouse_id', $warehouseId)->groupEnd();
        }

        $transfers = $builder->groupBy('t.id')->orderBy('t.requested_at', 'DESC')->limit(500)->get()->getResultArray();

        $warehouses = $db->table('warehouses')
            ->where(['company_id' => $companyId, 'site_id' => $siteId, 'active' => 1])
            ->orderBy('name')->get()->getResultArray();

        $destinationWarehouses = $db->table('warehouses w')
            ->select('w.*, s.name AS site_name')
            ->join('sites s', 's.id = w.site_id')
            ->where(['w.company_id' => $companyId, 'w.active' => 1, 's.active' => 1])
            ->orderBy('s.name')->orderBy('w.name')->get()->getResultArray();

        $scales = $db->table('scales')
            ->select('id, site_id, code, name, connection_type, adapter_key')
            ->where(['site_id' => $siteId, 'active' => 1])
            ->orderBy('name')
            ->get()->getResultArray();

        $summary = [
            'requested' => 0,
            'authorized' => 0,
            'in_transit' => 0,
            'discrepancy' => 0,
            'received' => 0,
        ];
        foreach ($transfers as $transfer) {
            $status = (string) $transfer['status'];
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return view('App\\Modules\\Transfers\\Views\\index', [
            'title' => 'Traspasos',
            'subtitle' => 'Control de salida, tránsito y recepción de tinas entre almacenes.',
            'transfers' => $transfers,
            'warehouses' => $warehouses,
            'destinationWarehouses' => $destinationWarehouses,
            'summary' => $summary,
            'scales' => $scales,
            'scope' => $scope,
        ]);
    }
}
