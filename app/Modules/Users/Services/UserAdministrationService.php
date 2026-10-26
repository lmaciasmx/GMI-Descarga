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
     * Actualiza identidad + rol base + permisos exactos + alcances.
     *
     * La identidad se persiste mediante el User Provider de Shield para que
     * correo y contraseña se actualicen en auth_identities correctamente.
     * Cada bloque se compara antes de escribir para evitar tocar acceso cuando
     * sólo cambia correo/nombre/contraseña y viceversa.
     */
    public function updateAccess(int $userId, array $payload, int $actorUserId): array
    {
        if ($userId === $actorUserId) {
            throw new DomainException(
                'Por seguridad no puedes modificar tu propia cuenta desde esta pantalla.',
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
            $user = $provider->withIdentities()->withGroups()->findById($userId);

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
            $beforePermissions = array_values($user->getPermissions() ?? []);
            sort($beforePermissions);
            $beforeScopes = $this->userScopes($userId);

            $identity = $this->applyIdentityUpdate($user, $payload, $actorUserId, $provider);
            $user = $identity['user'];
            $username = $identity['username'];
            $email = $identity['email'];
            $passwordChanged = $identity['password_changed'];
            $identityChanged = $identity['changed'];

            $beforeGroup = (string) ($beforeGroups[0] ?? '');
            $groupsChanged = $beforeGroup !== $baseRole;
            $permissionsChanged = $beforePermissions !== $permissions;
            $scopesChanged = $this->canonicalScopes($beforeScopes) !== $this->canonicalScopes($scopes);

            if ($groupsChanged) {
                $user->syncGroups($baseRole);
            }
            if ($permissionsChanged) {
                $user->syncPermissions(...$permissions);
            }
            if ($scopesChanged) {
                $this->replaceScopes($userId, $scopes);
            }

            if ($groupsChanged || $permissionsChanged || $scopesChanged) {
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
            }

            $this->db->transCommit();

            return [
                'id' => $userId,
                'username' => $username,
                'email' => $email,
                'password_changed' => $passwordChanged,
                'base_role' => $baseRole,
                'permissions' => $permissions,
                'scopes' => $scopes,
                'identity_changed' => $identityChanged,
                'access_changed' => $groupsChanged || $permissionsChanged || $scopesChanged,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /**
     * Actualiza únicamente nombre/usuario, correo y contraseña.
     * No toca grupo, permisos ni operational_user_scopes.
     */
    public function updateIdentity(int $userId, array $payload, int $actorUserId): array
    {
        $provider = auth()->getProvider();
        $this->db->transException(true)->transBegin();

        try {
            $this->db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $user = $provider->withIdentities()->withGroups()->findById($userId);

            if ($user === null) {
                throw new DomainException('Usuario no encontrado.', 'USER_NOT_FOUND');
            }

            if (
                $user->inGroup('process_owner')
                && $userId !== $actorUserId
                && ! auth()->user()->inGroup('process_owner')
            ) {
                throw new DomainException(
                    'Sólo un propietario del proceso puede modificar la identidad de esta cuenta protegida.',
                    'PROCESS_OWNER_IDENTITY_PROTECTED'
                );
            }

            $identity = $this->applyIdentityUpdate($user, $payload, $actorUserId, $provider);

            $this->db->transCommit();

            return [
                'id' => $userId,
                'username' => $identity['username'],
                'email' => $identity['email'],
                'password_changed' => $identity['password_changed'],
                'identity_changed' => $identity['changed'],
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

            $current = $this->db->table('users')
                ->select('id, active')
                ->where('id', $userId)
                ->get()
                ->getRowArray();
            if ($current === null) {
                throw new DomainException('Usuario no encontrado.', 'USER_NOT_FOUND');
            }

            $before = (bool) $current['active'];
            $update = ['active' => $active ? 1 : 0];
            if ($this->db->fieldExists('updated_at', 'users')) {
                $update['updated_at'] = date('Y-m-d H:i:s');
            }

            if (! $this->db->table('users')->where('id', $userId)->update($update)) {
                throw new DomainException('No se pudo actualizar la cuenta.', 'USER_STATUS_UPDATE_FAILED');
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

    /**
     * @return array{user:User,username:string,email:string,password_changed:bool,changed:bool}
     */
    private function applyIdentityUpdate(User $user, array $payload, int $actorUserId, object $provider): array
    {
        $userId = (int) $user->id;
        $beforeUsername = (string) ($user->username ?? '');
        $beforeEmail = strtolower(trim((string) ($user->email ?? '')));

        $username = array_key_exists('username', $payload) && trim((string) $payload['username']) !== ''
            ? trim((string) $payload['username'])
            : $beforeUsername;
        $email = array_key_exists('email', $payload) && trim((string) $payload['email']) !== ''
            ? strtolower(trim((string) $payload['email']))
            : $beforeEmail;
        $password = (string) ($payload['password'] ?? '');
        $passwordConfirm = (string) ($payload['password_confirm'] ?? '');

        if ($password !== '' && $password !== $passwordConfirm) {
            throw new DomainException(
                'La confirmación de contraseña no coincide.',
                'PASSWORD_CONFIRMATION_MISMATCH'
            );
        }

        $changed = $username !== $beforeUsername || $email !== $beforeEmail || $password !== '';
        if (! $changed) {
            return [
                'user' => $user,
                'username' => $beforeUsername,
                'email' => $beforeEmail,
                'password_changed' => false,
                'changed' => false,
            ];
        }

        $this->assertIdentityAvailable($userId, $username, $email);

        $identityChanges = [];
        if ($username !== $beforeUsername) {
            $identityChanges['username'] = $username;
        }
        if ($email !== $beforeEmail) {
            $identityChanges['email'] = $email;
        }
        if ($password !== '') {
            $identityChanges['password'] = $password;
        }

        $user->fill($identityChanges);
        if (! $provider->save($user)) {
            throw new DomainException(
                'Revisa los datos de identidad del usuario.',
                'USER_IDENTITY_VALIDATION_FAILED',
                $provider->errors()
            );
        }

        $updatedUser = $provider->withIdentities()->withGroups()->findById($userId);
        if ($updatedUser === null) {
            throw new DomainException('No fue posible recuperar el usuario actualizado.', 'USER_NOT_FOUND');
        }

        $this->audit->record(
            'user.identity_updated',
            'user',
            $userId,
            $actorUserId,
            before: [
                'username' => $beforeUsername,
                'email' => $beforeEmail,
            ],
            after: [
                'username' => $username,
                'email' => $email,
                'password_changed' => $password !== '',
            ],
        );

        return [
            'user' => $updatedUser,
            'username' => $username,
            'email' => $email,
            'password_changed' => $password !== '',
            'changed' => true,
        ];
    }

    private function assertIdentityAvailable(int $userId, string $username, string $email): void
    {
        $usernameExists = $this->db->table('users')
            ->where('username', $username)
            ->where('id !=', $userId)
            ->countAllResults();

        if ($usernameExists > 0) {
            throw new DomainException(
                'El nombre / usuario ya está registrado.',
                'USERNAME_ALREADY_EXISTS'
            );
        }

        $identitiesTable = (string) (config('Auth')->tables['identities'] ?? 'auth_identities');
        $emailExists = $this->db->table($identitiesTable)
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->where('user_id !=', $userId)
            ->countAllResults();

        if ($emailExists > 0) {
            throw new DomainException(
                'El correo ya está registrado en otra cuenta.',
                'EMAIL_ALREADY_EXISTS'
            );
        }
    }

    /** @param list<array<string,mixed>> $scopes */
    private function canonicalScopes(array $scopes): array
    {
        $normalized = [];

        foreach ($scopes as $scope) {
            $normalized[] = [
                'company_id' => (int) ($scope['company_id'] ?? 0),
                'site_id' => (int) ($scope['site_id'] ?? 0),
                'warehouse_id' => empty($scope['warehouse_id']) ? null : (int) $scope['warehouse_id'],
                'station_code' => trim((string) ($scope['station_code'] ?? '')) ?: null,
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            return [
                $a['company_id'],
                $a['site_id'],
                $a['warehouse_id'] ?? 0,
                $a['station_code'] ?? '',
            ] <=> [
                $b['company_id'],
                $b['site_id'],
                $b['warehouse_id'] ?? 0,
                $b['station_code'] ?? '',
            ];
        });

        return $normalized;
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
