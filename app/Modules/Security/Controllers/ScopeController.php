<?php

namespace App\Modules\Security\Controllers;

use App\Controllers\BaseController;

final class ScopeController extends BaseController
{
    public function select()
    {
        $userId = (int) auth()->id();
        $scopes = db_connect()->table('operational_user_scopes ous')
            ->select('ous.*, c.trade_name AS company_name, s.name AS site_name, w.name AS warehouse_name')
            ->join('companies c', 'c.id = ous.company_id')
            ->join('sites s', 's.id = ous.site_id AND s.company_id = ous.company_id AND s.active = 1')
            ->join('warehouses w', 'w.id = ous.warehouse_id AND w.company_id = ous.company_id AND w.site_id = ous.site_id AND w.active = 1', 'left')
            ->where(['ous.user_id' => $userId, 'ous.active' => 1, 'c.active' => 1])
            ->groupStart()->where('ous.warehouse_id', null)->orWhere('w.id IS NOT NULL', null, false)->groupEnd()
            ->orderBy('c.trade_name')
            ->get()
            ->getResultArray();

        if ($this->request->getMethod() === 'POST') {
            $scopeId = (int) $this->request->getPost('scope_id');
            $selected = null;
            foreach ($scopes as $scope) {
                if ((int) $scope['id'] === $scopeId) {
                    $selected = $scope;
                    break;
                }
            }
            if ($selected === null) {
                return redirect()->back()->with('error', 'El alcance seleccionado no está autorizado.');
            }
            session()->set('operational_scope', [
                'scope_id' => (int) $selected['id'],
                'company_id' => (int) $selected['company_id'],
                'site_id' => $selected['site_id'] === null ? null : (int) $selected['site_id'],
                'warehouse_id' => $selected['warehouse_id'] === null ? null : (int) $selected['warehouse_id'],
                'station_code' => $selected['station_code'],
                'company_name' => $selected['company_name'],
                'site_name' => $selected['site_name'],
                'warehouse_name' => $selected['warehouse_name'],
            ]);
            return redirect()->to('/home');
        }

        return view('App\\Modules\\Security\\Views\\scope_select', ['title' => 'Alcance operativo', 'scopes' => $scopes]);
    }
}
