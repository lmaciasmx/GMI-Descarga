<?php

namespace App\Modules\Users\Controllers;

use App\Controllers\BaseController;

final class UserController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $provider = auth()->getProvider();
        $scopeBuilder = db_connect()->table('operational_user_scopes')->select('user_id')->where([
            'company_id' => $scope['company_id'],
            'active' => 1,
        ])->groupStart()->where('site_id', $scope['site_id'])->orWhere('site_id', null)->groupEnd();
        if (! empty($scope['warehouse_id'])) {
            $scopeBuilder->groupStart()->where('warehouse_id', $scope['warehouse_id'])->orWhere('warehouse_id', null)->groupEnd();
        }
        $allowedUserIds = array_map('intval', array_column($scopeBuilder->get()->getResultArray(), 'user_id'));
        $allUsers = $provider->withIdentities()->withGroups()->findAll(500);
        $users = array_values(array_filter($allUsers, static fn ($user): bool => in_array((int) $user->id, $allowedUserIds, true)));
        $userIds = array_map(static fn ($user): int => (int) $user->id, $users);
        $scopes = [];
        if ($userIds !== []) {
            $rows = db_connect()->table('operational_user_scopes ous')
                ->select('ous.*, c.code AS company_code, s.code AS site_code, w.code AS warehouse_code')
                ->join('companies c', 'c.id = ous.company_id')
                ->join('sites s', 's.id = ous.site_id', 'left')
                ->join('warehouses w', 'w.id = ous.warehouse_id', 'left')
                ->whereIn('ous.user_id', $userIds)->where('ous.active', 1)->get()->getResultArray();
            foreach ($rows as $row) {
                $scopes[(int) $row['user_id']][] = $row;
            }
        }
        $db = db_connect();
        $sites = $db->table('sites')->where(['company_id' => $scope['company_id'], 'active' => 1])->orderBy('name')->get()->getResultArray();
        return view('App\\Modules\\Users\\Views\\index', [
            'title' => 'Usuarios, roles y alcance',
            'subtitle' => 'Autorización efectiva en backend por permiso, empresa, instalación y almacén.',
            'users' => $users,
            'scopes' => $scopes,
            'groups' => config('AuthGroups')->groups,
            'scope' => $scope,
            'sites' => $sites,
            'warehouses' => $db->table('warehouses')->whereIn('site_id', array_column($sites, 'id') ?: [0])->where('active', 1)->orderBy('name')->get()->getResultArray(),
        ]);
    }
}
