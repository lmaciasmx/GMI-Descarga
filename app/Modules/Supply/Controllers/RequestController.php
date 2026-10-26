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


        /*
         * Historial vigente de solicitudes.
         *
         * IMPORTANTE:
         * - Sólo lectura.
         * - Usa warehouse_requests, que es la fuente del flujo actual.
         * - Mantiene debajo el historial anterior de raw_material_requests.
         * - No cambia estados, reservas, traspasos ni inventario.
         */
        $currentRequests = [];
        $currentSummary = [
            'total' => 0,
            'pending' => 0,
            'in_progress' => 0,
            'finalized' => 0,
        ];

        if ($scopeWarehouse !== null) {
            $currentRequests = $db->table('warehouse_requests r')
                ->select("
                    r.id,
                    r.folio,
                    r.request_type,
                    r.status,
                    r.required_at,
                    r.observations,
                    r.requested_by,
                    r.created_at,
                    r.updated_at,
                    r.source_warehouse_id,
                    r.destination_warehouse_id,
                    r.provider_company_id,
                    r.company_id,
                    sw.code AS source_warehouse_code,
                    sw.name AS source_warehouse_name,
                    ss.name AS source_site_name,
                    sc.trade_name AS source_company_name,
                    dw.code AS destination_warehouse_code,
                    dw.name AS destination_warehouse_name,
                    ds.name AS destination_site_name,
                    dc.trade_name AS destination_company_name,
                    u.username AS requester_username,
                    (SELECT COUNT(*) FROM warehouse_request_lines hrl WHERE hrl.request_id = r.id) AS line_count,
                    (SELECT COALESCE(ROUND(SUM(hrl.requested_weight_kg), 3), 0) FROM warehouse_request_lines hrl WHERE hrl.request_id = r.id) AS requested_kg,
                    (SELECT COALESCE(SUM(hrl.requested_unit_count), 0) FROM warehouse_request_lines hrl WHERE hrl.request_id = r.id) AS requested_units,
                    (SELECT COUNT(*) FROM warehouse_request_units hru WHERE hru.request_id = r.id AND hru.status <> 'released') AS linked_units
                ", false)
                ->join('warehouses sw', 'sw.id = r.source_warehouse_id')
                ->join('sites ss', 'ss.id = sw.site_id')
                ->join('companies sc', 'sc.id = r.provider_company_id')
                ->join('warehouses dw', 'dw.id = r.destination_warehouse_id')
                ->join('sites ds', 'ds.id = dw.site_id')
                ->join('companies dc', 'dc.id = r.company_id')
                ->join('users u', 'u.id = r.requested_by', 'left')
                ->groupStart()
                    ->groupStart()
                        ->where('r.provider_company_id', (int) $scope['company_id'])
                        ->where('r.source_warehouse_id', (int) $scopeWarehouse['id'])
                    ->groupEnd()
                    ->orGroupStart()
                        ->where('r.company_id', (int) $scope['company_id'])
                        ->where('r.destination_warehouse_id', (int) $scopeWarehouse['id'])
                    ->groupEnd()
                ->groupEnd()
                ->orderBy('r.id', 'DESC')
                ->limit(500)
                ->get()
                ->getResultArray();

            $currentSummary['total'] = count($currentRequests);

            foreach ($currentRequests as $currentRequest) {
                $status = (string) ($currentRequest['status'] ?? '');

                if ($status === 'open') {
                    $currentSummary['pending']++;
                    continue;
                }

                if (in_array($status, ['accepted', 'preparing', 'dispatching', 'dispatched'], true)) {
                    $currentSummary['in_progress']++;
                    continue;
                }

                if (in_array($status, ['closed', 'rejected'], true)) {
                    $currentSummary['finalized']++;
                }
            }
        }

        return view('App\\Modules\\Supply\\Views\\index', [
            'title' => 'Historial de solicitudes',
            'subtitle' => 'Consulta y seguimiento de las solicitudes vigentes de materia prima y movimientos entre almacenes.',
            'currentRequests' => $currentRequests,
            'currentSummary' => $currentSummary,
            'requests' => $requests,
            'reservations' => $reservations,
            'species' => $db->table('species')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'sizes' => $db->table('size_grades')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'lots' => $db->table('lots l')
                ->select('l.id, l.code, l.species_id, v.owner_company_id')
                ->join('voyages vg', 'vg.id = l.voyage_id')
                ->join('vessels v', 'v.id = vg.vessel_id')
                ->join('stock_units su', 'su.lot_id = l.id')
                ->where('su.company_id', (int) $scope['company_id'])
                ->where('su.on_hand_weight_kg >', 0)
                ->groupBy('l.id, l.code, l.species_id, v.owner_company_id')
                ->orderBy('l.code')->get()->getResultArray(),
            'owners' => $db->table('companies c')
                ->select('c.id, c.code, c.trade_name')
                ->join('vessels v', 'v.owner_company_id = c.id')
                ->join('voyages vg', 'vg.vessel_id = v.id')
                ->join('lots l', 'l.voyage_id = vg.id')
                ->join('stock_units su', 'su.lot_id = l.id')
                ->where('su.company_id', (int) $scope['company_id'])
                ->where('su.on_hand_weight_kg >', 0)
                ->where('c.active', 1)
                ->groupBy('c.id, c.code, c.trade_name')
                ->orderBy('c.trade_name')->get()->getResultArray(),
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
