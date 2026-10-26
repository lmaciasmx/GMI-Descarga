<?php

namespace App\Modules\Sales\Controllers;

use App\Controllers\BaseController;

final class SalesController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();

        $ordersBuilder = $db->table('sales_orders so')
            ->select(
                'so.*, c.trade_name AS customer_name, br.fiscal_status, br.fiscal_uuid,' .
                '(SELECT COUNT(*) FROM sales_order_lines sol WHERE sol.sales_order_id = so.id) AS unit_count,' .
                '(SELECT COALESCE(SUM(sol.quantity_kg),0) FROM sales_order_lines sol WHERE sol.sales_order_id = so.id) AS total_kg,' .
                '(SELECT COUNT(*) FROM sales_request_lines srl WHERE srl.sales_order_id = so.id) AS request_line_count,' .
                '(SELECT COALESCE(SUM(srl.requested_weight_kg),0) FROM sales_request_lines srl WHERE srl.sales_order_id = so.id) AS requested_kg,' .
                '(SELECT COUNT(*) FROM sales_request_proposals srp WHERE srp.sales_order_id = so.id) AS proposed_unit_count',
                false
            )
            ->join('companies c', 'c.id = so.customer_company_id')
            ->join(
                'billing_references br',
                'br.id = (SELECT MAX(br2.id) FROM billing_references br2 WHERE br2.sales_order_id = so.id)',
                'left',
                false
            )
            ->where([
                'so.company_id' => $scope['company_id'],
                'so.site_id' => $scope['site_id'],
            ]);

        if (! empty($scope['warehouse_id'])) {
            $ordersBuilder
                ->groupStart()
                ->where('so.source_warehouse_id', (int) $scope['warehouse_id'])
                ->orWhere('so.source_warehouse_id IS NULL', null, false)
                ->groupEnd();
        }

        $orders = $ordersBuilder
            ->orderBy('so.created_at', 'DESC')
            ->limit(500)
            ->get()
            ->getResultArray();

        $unitBuilder = $db->table('v_available_stock vas')
            ->select(
                'vas.id, vas.unit_code, vas.on_hand_weight_kg, vas.available_weight_kg,' .
                'l.code AS lot_code, s.name AS species_name, loc.path AS location_path'
            )
            ->join('lots l', 'l.id = vas.lot_id')
            ->join('species s', 's.id = vas.species_id')
            ->join('locations loc', 'loc.id = vas.location_id')
            ->where([
                'vas.company_id' => $scope['company_id'],
                'vas.site_id' => $scope['site_id'],
            ]);

        if (! empty($scope['warehouse_id'])) {
            $unitBuilder->where('vas.warehouse_id', $scope['warehouse_id']);
        }

        $units = $unitBuilder
            ->orderBy('vas.received_at')
            ->limit(500)
            ->get()
            ->getResultArray();

        return view('App\\Modules\\Sales\\Views\\index', [
            'title' => 'Venta, despacho y referencia fiscal',
            'subtitle' => 'La salida física y el ciclo CFDI permanecen desacoplados.',
            'orders' => $orders,
            'customers' => $db->table('companies')
                ->whereIn('company_type', ['customer', 'owner'])
                ->where('active', 1)
                ->orderBy('trade_name')
                ->get()
                ->getResultArray(),
            'units' => $units,
            'scope' => $scope,
            'canBill' => auth()->user()->can('billing.reference'),
        ]);
    }
}
