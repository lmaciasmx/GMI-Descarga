<?php

namespace App\Modules\Security\Controllers;

use App\Controllers\BaseController;

final class HomeController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');

        if (! is_array($scope) || empty($scope['scope_id'])) {
            return redirect()->to('/scope/select');
        }

        $authorized = db_connect()->table('operational_user_scopes ous')
            ->select('ous.*, c.active AS company_active, s.active AS site_active, w.active AS warehouse_active, s.company_id AS site_company_id, w.company_id AS warehouse_company_id, w.site_id AS warehouse_site_id')
            ->join('companies c', 'c.id = ous.company_id')
            ->join('sites s', 's.id = ous.site_id', 'left')
            ->join('warehouses w', 'w.id = ous.warehouse_id', 'left')
            ->where([
                'ous.id' => (int) $scope['scope_id'],
                'ous.user_id' => (int) auth()->id(),
                'ous.active' => 1,
            ])->get()->getRowArray();

        if ($authorized === null
            || (int) $authorized['company_active'] !== 1
            || (int) ($authorized['site_active'] ?? 0) !== 1
            || ($authorized['warehouse_id'] !== null && (int) ($authorized['warehouse_active'] ?? 0) !== 1)
            || (int) $authorized['site_company_id'] !== (int) $authorized['company_id']
            || ($authorized['warehouse_id'] !== null
                && ((int) $authorized['warehouse_company_id'] !== (int) $authorized['company_id']
                    || (int) $authorized['warehouse_site_id'] !== (int) $authorized['site_id']))
            || (int) $authorized['company_id'] !== (int) ($scope['company_id'] ?? 0)
            || (int) ($authorized['site_id'] ?? 0) !== (int) ($scope['site_id'] ?? 0)
            || (int) ($authorized['warehouse_id'] ?? 0) !== (int) ($scope['warehouse_id'] ?? 0)) {
            session()->remove('operational_scope');

            return redirect()->to('/scope/select')->with('warning', 'Selecciona nuevamente un alcance autorizado.');
        }

        $user = auth()->user();
        $destinations = [
            'dashboard.view' => '/dashboard',
            'plans.manage' => '/operacion/planes',
            'discharge.operate' => '/operacion/descarga',
            'weighings.create' => '/operacion/pesaje',
            'labels.print' => '/operacion/etiquetas',
            'labels.reprint' => '/operacion/etiquetas',
            'quality.inspect' => '/operacion/calidad',
            'receiving.confirm' => '/operacion/recepcion',
            'inventory.view' => '/inventario',
            'requests.create' => '/solicitudes',
            'requests.approve' => '/solicitudes',
            'reservations.manage' => '/solicitudes',
            'transfers.manage' => '/traspasos',
            'sales.manage' => '/ventas',
            'billing.reference' => '/facturacion',
            'users.manage' => '/usuarios',
            'catalogs.manage' => '/catalogos',
            'configuration.manage' => '/configuracion',
            'integrations.manage' => '/integraciones',
            'audit.view' => '/auditoria',
            'reports.view' => '/reportes',
        ];

        foreach ($destinations as $permission => $path) {
            if ($user->can($permission)) {
                return redirect()->to($path);
            }
        }

        return redirect()->to('/scope/select')->with('warning', 'Tu cuenta no tiene operaciones asignadas en este alcance.');
    }
}
