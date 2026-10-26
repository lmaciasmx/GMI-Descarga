<?php

namespace App\Modules\Users\Controllers;

use App\Controllers\BaseController;

final class UserController extends BaseController
{
    public function index()
    {
        $db = db_connect();
        $provider = auth()->getProvider();
        $authGroups = config('AuthGroups');
        $accessConfig = config('UserAccess');
        $permissionCodes = array_keys($authGroups->permissions);

        // La administración de identidades es global para quien tenga users.manage.
        // El alcance de cada usuario se administra explícitamente en operational_user_scopes.
        $users = $provider->withIdentities()->withGroups()->findAll(1000);
        $userIds = array_map(static fn ($user): int => (int) $user->id, $users);

        $scopes = [];
        if ($userIds !== []) {
            $rows = $db->table('operational_user_scopes ous')
                ->select('ous.*, c.code AS company_code, c.trade_name AS company_name, s.code AS site_code, s.name AS site_name, w.code AS warehouse_code, w.name AS warehouse_name')
                ->join('companies c', 'c.id = ous.company_id')
                ->join('sites s', 's.id = ous.site_id', 'left')
                ->join('warehouses w', 'w.id = ous.warehouse_id', 'left')
                ->whereIn('ous.user_id', $userIds)
                ->where('ous.active', 1)
                ->orderBy('c.trade_name')
                ->orderBy('s.name')
                ->orderBy('w.name')
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $scopes[(int) $row['user_id']][] = $row;
            }
        }

        $userMeta = [];
        foreach ($users as $item) {
            $groups = $item->getGroups() ?? [];
            $group = (string) ($groups[0] ?? '');
            $directPermissions = array_values($item->getPermissions() ?? []);
            sort($directPermissions);

            $effectivePermissions = [];
            foreach ($permissionCodes as $permission) {
                if ($item->can($permission)) {
                    $effectivePermissions[] = $permission;
                }
            }
            sort($effectivePermissions);

            $isV2 = isset($accessConfig->roles[$group]);
            $mappedRole = $isV2 ? $group : ($accessConfig->legacyMap[$group] ?? 'access_warehouse');
            $roleTitle = $isV2
                ? $accessConfig->roles[$group]['title']
                : ($authGroups->groups[$group]['title'] ?? ($group !== '' ? $group : 'Sin rol'));

            if (! $isV2 && $group !== '') {
                $roleTitle .= $group === 'process_owner' ? ' · protegido' : ' · heredado';
            }

            $defaultPermissions = (array) ($accessConfig->roles[$mappedRole]['permissions'] ?? []);
            sort($defaultPermissions);
            $isCustomized = $isV2 && $directPermissions !== $defaultPermissions;

            $userMeta[(int) $item->id] = [
                'id' => (int) $item->id,
                'username' => (string) ($item->username ?? ''),
                'email' => (string) ($item->email ?? ''),
                'active' => (bool) $item->active,
                'group' => $group,
                'base_role' => $mappedRole,
                'role_title' => $roleTitle,
                'is_v2' => $isV2,
                'is_protected' => $group === 'process_owner',
                'is_customized' => $isCustomized,
                'direct_permissions' => $directPermissions,
                'effective_permissions' => $effectivePermissions,
                'scopes' => array_map(static fn (array $scope): array => [
                    'id' => (int) $scope['id'],
                    'company_id' => (int) $scope['company_id'],
                    'site_id' => $scope['site_id'] === null ? null : (int) $scope['site_id'],
                    'warehouse_id' => $scope['warehouse_id'] === null ? null : (int) $scope['warehouse_id'],
                    'station_code' => $scope['station_code'],
                    'company_code' => $scope['company_code'],
                    'company_name' => $scope['company_name'],
                    'site_code' => $scope['site_code'],
                    'site_name' => $scope['site_name'],
                    'warehouse_code' => $scope['warehouse_code'],
                    'warehouse_name' => $scope['warehouse_name'],
                ], $scopes[(int) $item->id] ?? []),
            ];
        }

        $companies = $db->table('companies')
            ->select('id, code, trade_name, legal_name')
            ->where('active', 1)
            ->orderBy('trade_name')
            ->get()
            ->getResultArray();

        $sites = $db->table('sites')
            ->select('id, company_id, code, name')
            ->where('active', 1)
            ->orderBy('name')
            ->get()
            ->getResultArray();

        $warehouses = $db->table('warehouses')
            ->select('id, company_id, site_id, code, name, warehouse_type')
            ->where('active', 1)
            ->orderBy('name')
            ->get()
            ->getResultArray();

        $activeUsers = count(array_filter($users, static fn ($user): bool => (bool) $user->active));
        $scopeCount = array_sum(array_map('count', $scopes));

        return view('App\\Modules\\Users\\Views\\index', [
            'title' => 'Usuarios, roles y alcance',
            'subtitle' => 'Rol base, permisos efectivos y alcance por empresa, instalación y almacén.',
            'users' => $users,
            'userMeta' => $userMeta,
            'scopes' => $scopes,
            'roles' => $accessConfig->roles,
            'permissionSections' => $accessConfig->permissionSections,
            'permissions' => $authGroups->permissions,
            'companies' => $companies,
            'sites' => $sites,
            'warehouses' => $warehouses,
            'metrics' => [
                'users' => count($users),
                'active' => $activeUsers,
                'roles' => count($accessConfig->roles),
                'scopes' => $scopeCount,
            ],
        ]);
    }
}
