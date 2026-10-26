<?php

namespace App\Modules\Billing\Controllers;

use App\Controllers\BaseController;

final class BillingController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();
        return view('App\\Modules\\Billing\\Views\\index', [
            'title' => 'Referencias fiscales',
            'subtitle' => 'Sincronización documental con INVoiice sin modificar la existencia física.',
            'orders' => $db->table('sales_orders so')->select('so.id, so.folio, so.status, c.trade_name AS customer_name')->join('companies c', 'c.id = so.customer_company_id')->where(['so.company_id' => $scope['company_id'], 'so.site_id' => $scope['site_id']])->orderBy('so.created_at', 'DESC')->limit(500)->get()->getResultArray(),
            'references' => $db->table('billing_references br')->select('br.*, so.folio AS sales_folio, c.trade_name AS customer_name')->join('sales_orders so', 'so.id = br.sales_order_id', 'left')->join('companies c', 'c.id = so.customer_company_id', 'left')->where(['br.company_id' => $scope['company_id'], 'br.site_id' => $scope['site_id']])->orderBy('br.updated_at', 'DESC')->limit(500)->get()->getResultArray(),
            'scope' => $scope,
        ]);
    }
}
