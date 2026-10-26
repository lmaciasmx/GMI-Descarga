<?php

namespace App\Modules\Users\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;

final class UserAdministrationService
{
    private const PROTECTED_GROUPS = ['process_owner', 'identity_admin'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Mantiene compatibilidad con la creación anterior y habilita el perfil v2.
     */
    public function create(array $payload, int $actorUserId): array
    {
        if (! empty($payload['base_role']) || isset($payload['permissions']) || isset($payload['scopes'])) {
            return $this->createV2($payload, $actorUserId);
        }

        return $this->createLegacy($payload, $actorUserId);
    }

    /**
     * Actualiza rol base + permisos exactos + alcances, sin depender del alcance
     * operativo actualmente seleccionado por el administrador.
     */
    public function updateAccess(int $userId, array $payload, int $actorUserId): array
    {
        if ($userId === $actorUserId) {
            throw new DomainException(
                'Por seguridad no puedes modificar tu propio rol, permisos o alcance desde esta pantalla.',
                'SELF_ACCESS_CHANGE_FORBIDDEN'
            );
        }

        $baseRole = trim((string) ($payload['base_role'] ?? ''));
        $this->assertBaseRoleAssignable($baseRole);
        $permissions = $this->normalizePermissions((array) ($payload['permissions'] ?? []));
        $scopes = $this->normalizeScopes((array) ($payload['scopes'] ?? []));

        $provider = auth()->getProvider();
        $this->db->transException(true)->transBegin();

        try {
            $this->db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $user = $provider->withGroups()->findById($userId);

            if ($user === null) {
                throw new DomainException('Usuario no encontrado.', 'USER_NOT_FOUND');
            }

            if ($user->inGroup('process_owner')) {
                throw new DomainException(
                    'El propietario del proceso es una cuenta protegida. Crea primero administradores especializados antes de migrar esta cuenta.',
                    'PROCESS_OWNER_ACCESS_PROTECTED'
                );
            }

            $beforeGroups = $user->getGroups() ?? [];
            $beforePermissions = $user->getPermissions() ?? [];
            $beforeScopes = $this->userScopes($userId);

            // Los grupos access_* no contienen permisos en la matriz.
            // El conjunto exacto queda guardado directamente en Shield.
            $user->syncGroups($baseRole);
            $user->syncPermissions(...$permissions);
            $this->replaceScopes($userId, $scopes);

            $this->audit->record(
                'user.access_updated',
                'user',
                $userId,
                $actorUserId,
                before: [
                    'groups' => $beforeGroups,
                    'permissions' => $beforePermissions,
                    'scopes' => $beforeScopes,
                ],
                after: [
                    'groups' => [$baseRole],
                    'permissions' => $permissions,
                    'scopes' => $scopes,
                ],
            );

            $this->db->transCommit();

            return [
                'id' => $userId,
                'base_role' => $baseRole,
                'permissions' => $permissions,
                'scopes' => $scopes,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /**
     * Endpoint heredado: se conserva para no romper clientes anteriores.
     */
    public function syncGroup(int $userId, string $group, int $actorUserId): array
    {
        $this->assertGroupAssignable($group);
        $provider = auth()->getProvider();
        $this->db->transException(true)->transBegin();

        try {
            $this->db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $user = $provider->withGroups()->findById($userId);
            if ($user === null) {
                throw new DomainException('Usuario no encontrado.', 'USER_NOT_FOUND');
            }
            if ($userId === $actorUserId && $user->inGroup('process_owner') && $group !== 'process_owner') {
                throw new DomainException('No puedes retirar tu propio rol de propietario del proceso.', 'SELF_PRIVILEGE_REMOVAL_FORBIDDEN');
            }
            if ($user->inGroup('process_owner') && $group !== 'process_owner') {
                $this->assertAnotherActiveProcessOwner($userId);
            }
            $before = $user->getGroups() ?? [];
            $user->syncGroups($group);
            $this->audit->record(
                'user.group_changed',
                'user',
                $userId,
                $actorUserId,
                before: ['groups' => $before],
                after: ['groups' => [$group]],
            );
            $this->db->transCommit();
            return ['id' => $userId, 'group' => $group];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function setActive(int $userId, bool $active, int $actorUserId): array
    {
        if ($userId === $actorUserId && ! $active) {
            throw new DomainException('No puedes desactivar tu propia cuenta.', 'SELF_DEACTIVATION_FORBIDDEN');
        }

        $provider = auth()->getProvider();
        $this->db->transException(true)->transBegin();

        try {
            $this->db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $user = $provider->withGroups()->findById($userId);
            if ($user === null) {
                throw new DomainException('Usuario no encontrado.', 'USER_NOT_FOUND');
            }
            if (! $active && $user->inGroup('process_owner')) {
                $this->assertAnotherActiveProcessOwner($userId);
            }

            $before = (bool) $user->active;
            $user->active = $active;
            if (! $provider->save($user)) {
                throw new DomainException('No se pudo actualizar la cuenta.', 'USER_VALIDATION_FAILED', $provider->errors());
            }

            $this->audit->record(
                'user.status_changed',
                'user',
                $userId,
                $actorUserId,
                before: ['active' => $before],
                after: ['active' => $active],
            );

            $this->db->transCommit();
            return ['id' => $userId, 'active' => $active];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function createV2(array $payload, int $actorUserId): array
    {
        $baseRole = trim((string) ($payload['base_role'] ?? ''));
        $this->assertBaseRoleAssignable($baseRole);

        $role = config('UserAccess')->roles[$baseRole];
        $permissions = array_key_exists('permissions', $payload)
            ? $this->normalizePermissions((array) $payload['permissions'])
            : $this->normalizePermissions((array) ($role['permissions'] ?? []));
        $scopes = $this->normalizeScopes((array) ($payload['scopes'] ?? []));

        $provider = auth()->getProvider();
        $this->db->transException(true)->transBegin();

        try {
            $user = new User([
                'username' => trim((string) $payload['username']),
                'email' => strtolower(trim((string) $payload['email'])),
                'password' => (string) $payload['password'],
                'active' => 1,
            ]);

            if (! $provider->save($user)) {
                throw new DomainException('Revisa los datos del usuario.', 'USER_VALIDATION_FAILED', $provider->errors());
            }

            $user = $provider->findById($provider->getInsertID());
            if ($user === null) {
                throw new DomainException('No fue posible recuperar el usuario creado.', 'USER_NOT_FOUND');
            }

            $user->syncGroups($baseRole);
            $user->syncPermissions(...$permissions);
            $this->replaceScopes((int) $user->id, $scopes);

            $this->audit->record(
                'user.created',
                'user',
                (int) $user->id,
                $actorUserId,
                after: [
                    'username' => $user->username,
                    'base_role' => $baseRole,
                    'permissions' => $permissions,
                    'scopes' => $scopes,
                ],
            );

            $this->db->transCommit();

            return [
                'id' => (int) $user->id,
                'username' => $user->username,
                'status' => 'active',
                'base_role' => $baseRole,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function createLegacy(array $payload, int $actorUserId): array
    {
        $this->assertGroupAssignable((string) ($payload['group'] ?? ''));
        $scope = $this->normalizeScope([
            'company_id' => $payload['company_id'] ?? null,
            'site_id' => $payload['site_id'] ?? null,
            'warehouse_id' => $payload['warehouse_id'] ?? null,
            'station_code' => $payload['station_code'] ?? null,
        ]);

        $provider = auth()->getProvider();
        $this->db->transException(true)->transBegin();

        try {
            $user = new User([
                'username' => trim((string) $payload['username']),
                'email' => strtolower(trim((string) $payload['email'])),
                'password' => (string) $payload['password'],
                'active' => 1,
            ]);
            if (! $provider->save($user)) {
                throw new DomainException('Revisa los datos del usuario.', 'USER_VALIDATION_FAILED', $provider->errors());
            }
            $user = $provider->findById($provider->getInsertID());
            if ($user === null) {
                throw new DomainException('No fue posible recuperar el usuario creado.', 'USER_NOT_FOUND');
            }
            $user->addGroup((string) $payload['group']);
            $this->insertOrActivateScope((int) $user->id, $scope);
            $this->audit->record(
                'user.created',
                'user',
                (int) $user->id,
                $actorUserId,
                (int) $scope['company_id'],
                (int) $scope['site_id'],
                null,
                ['username' => $user->username, 'group' => $payload['group']],
            );
            $this->db->transCommit();
            return ['id' => (int) $user->id, 'username' => $user->username, 'status' => 'active'];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /** @return list<string> */
    private function normalizePermissions(array $permissions): array
    {
        $allowed = array_keys(config('AuthGroups')->permissions);
        $normalized = [];

        foreach ($permissions as $permission) {
            $permission = trim((string) $permission);
            if ($permission === '') {
                continue;
            }
            if (! in_array($permission, $allowed, true)) {
                throw new DomainException(
                    'Se recibió un permiso no reconocido: ' . $permission,
                    'USER_PERMISSION_NOT_ALLOWED'
                );
            }
            $normalized[$permission] = $permission;
        }

        $normalized = array_values($normalized);
        sort($normalized);
        return $normalized;
    }

    /** @return list<array{company_id:int,site_id:int,warehouse_id:?int,station_code:?string}> */
    private function normalizeScopes(array $scopes): array
    {
        if ($scopes === []) {
            throw new DomainException(
                'Asigna al menos una empresa e instalación al usuario.',
                'USER_SCOPE_REQUIRED'
            );
        }

        $normalized = [];
        foreach ($scopes as $scope) {
            if (! is_array($scope)) {
                throw new DomainException('El alcance recibido no es válido.', 'INVALID_USER_SCOPE');
            }
            $row = $this->normalizeScope($scope);
            $key = implode(':', [
                $row['company_id'],
                $row['site_id'],
                $row['warehouse_id'] ?? 0,
                $row['station_code'] ?? '',
            ]);
            $normalized[$key] = $row;
        }

        return array_values($normalized);
    }

    /** @return array{company_id:int,site_id:int,warehouse_id:?int,station_code:?string} */
    private function normalizeScope(array $scope): array
    {
        $companyId = (int) ($scope['company_id'] ?? 0);
        $siteId = (int) ($scope['site_id'] ?? 0);
        $warehouseId = empty($scope['warehouse_id']) ? null : (int) $scope['warehouse_id'];
        $stationCode = trim((string) ($scope['station_code'] ?? ''));
        $stationCode = $stationCode === '' ? null : mb_substr($stationCode, 0, 40);

        if ($companyId <= 0 || $siteId <= 0) {
            throw new DomainException('Empresa e instalación son obligatorias en cada alcance.', 'INVALID_USER_SCOPE');
        }

        $this->assertScopeRelations($companyId, $siteId, $warehouseId);

        return [
            'company_id' => $companyId,
            'site_id' => $siteId,
            'warehouse_id' => $warehouseId,
            'station_code' => $stationCode,
        ];
    }

    private function assertScopeRelations(int $companyId, int $siteId, ?int $warehouseId): void
    {
        $company = $this->db->table('companies')->where(['id' => $companyId, 'active' => 1])->countAllResults();
        if ($company !== 1) {
            throw new DomainException('La empresa seleccionada no existe o está inactiva.', 'INVALID_USER_SCOPE');
        }

        $site = $this->db->table('sites')->where([
            'id' => $siteId,
            'company_id' => $companyId,
            'active' => 1,
        ])->countAllResults();
        if ($site !== 1) {
            throw new DomainException('La instalación no pertenece a la empresa.', 'INVALID_USER_SCOPE');
        }

        if ($warehouseId !== null) {
            $warehouse = $this->db->table('warehouses')->where([
                'id' => $warehouseId,
                'company_id' => $companyId,
                'site_id' => $siteId,
                'active' => 1,
            ])->countAllResults();
            if ($warehouse !== 1) {
                throw new DomainException('El almacén no pertenece a la empresa e instalación.', 'INVALID_USER_SCOPE');
            }
        }
    }

    /** @param list<array{company_id:int,site_id:int,warehouse_id:?int,station_code:?string}> $scopes */
    private function replaceScopes(int $userId, array $scopes): void
    {
        // Baja lógica: conserva trazabilidad de alcances anteriores.
        $this->db->table('operational_user_scopes')
            ->where('user_id', $userId)
            ->update(['active' => 0]);

        foreach ($scopes as $scope) {
            $this->insertOrActivateScope($userId, $scope);
        }
    }

    /** @param array{company_id:int,site_id:int,warehouse_id:?int,station_code:?string} $scope */
    private function insertOrActivateScope(int $userId, array $scope): void
    {
        $builder = $this->db->table('operational_user_scopes')
            ->where('user_id', $userId)
            ->where('company_id', $scope['company_id'])
            ->where('site_id', $scope['site_id']);

        $scope['warehouse_id'] === null
            ? $builder->where('warehouse_id', null)
            : $builder->where('warehouse_id', $scope['warehouse_id']);

        $scope['station_code'] === null
            ? $builder->where('station_code', null)
            : $builder->where('station_code', $scope['station_code']);

        $existing = $builder->orderBy('id', 'ASC')->get()->getRowArray();

        if ($existing !== null) {
            $this->db->table('operational_user_scopes')
                ->where('id', (int) $existing['id'])
                ->update(['active' => 1]);
            return;
        }

        $this->db->table('operational_user_scopes')->insert([
            'user_id' => $userId,
            'company_id' => $scope['company_id'],
            'site_id' => $scope['site_id'],
            'warehouse_id' => $scope['warehouse_id'],
            'station_code' => $scope['station_code'],
            'active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function userScopes(int $userId): array
    {
        return $this->db->table('operational_user_scopes')
            ->select('company_id, site_id, warehouse_id, station_code, active')
            ->where('user_id', $userId)
            ->where('active', 1)
            ->orderBy('company_id')
            ->orderBy('site_id')
            ->orderBy('warehouse_id')
            ->get()
            ->getResultArray();
    }

    private function assertBaseRoleAssignable(string $baseRole): void
    {
        if ($baseRole === '' || ! isset(config('UserAccess')->roles[$baseRole])) {
            throw new DomainException('Selecciona un rol base válido.', 'BASE_ROLE_NOT_FOUND');
        }

        if (! isset(config('AuthGroups')->groups[$baseRole])) {
            throw new DomainException(
                'El perfil base no está registrado en AuthGroups. Ejecuta nuevamente INSTALAR.cmd.',
                'BASE_ROLE_NOT_REGISTERED'
            );
        }
    }

    private function assertGroupAssignable(string $group): void
    {
        if (! isset(config('AuthGroups')->groups[$group])) {
            throw new DomainException('Rol no válido.', 'GROUP_NOT_FOUND');
        }
        if (in_array($group, self::PROTECTED_GROUPS, true) && ! auth()->user()->inGroup('process_owner')) {
            throw new DomainException('Sólo un propietario del proceso puede asignar este rol.', 'PROTECTED_GROUP_FORBIDDEN');
        }
    }

    private function assertAnotherActiveProcessOwner(int $excludedUserId): void
    {
        $groupsTable = (string) config('Auth')->tables['groups_users'];
        $otherOwners = $this->db->table($groupsTable . ' agu')
            ->join('users u', 'u.id = agu.user_id')
            ->where('agu.group', 'process_owner')
            ->where('agu.user_id !=', $excludedUserId)
            ->where('u.active', 1)
            ->countAllResults();

        if ($otherOwners === 0) {
            throw new DomainException(
                'Debe permanecer al menos un propietario del proceso activo.',
                'LAST_PROCESS_OWNER_PROTECTED'
            );
        }
    }
}
