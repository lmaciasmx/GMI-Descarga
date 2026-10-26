<?php

namespace App\Modules\Users\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;

final class UserAdministrationService
{
    private const PROTECTED_GROUPS = ['process_owner', 'identity_admin'];

    public function __construct(private readonly BaseConnection $db, private readonly AuditService $audit)
    {
    }

    public function create(array $payload, int $actorUserId): array
    {
        $this->assertGroupAssignable((string) $payload['group']);
        $this->assertScope((int) $payload['company_id'], (int) $payload['site_id'], isset($payload['warehouse_id']) ? (int) $payload['warehouse_id'] : null);
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
            $user->addGroup((string) $payload['group']);
            $this->db->table('operational_user_scopes')->insert([
                'user_id' => (int) $user->id,
                'company_id' => (int) $payload['company_id'],
                'site_id' => (int) $payload['site_id'],
                'warehouse_id' => empty($payload['warehouse_id']) ? null : (int) $payload['warehouse_id'],
                'station_code' => empty($payload['station_code']) ? null : trim((string) $payload['station_code']),
                'active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->audit->record('user.created', 'user', (int) $user->id, $actorUserId, (int) $payload['company_id'], (int) $payload['site_id'], null, ['username' => $user->username, 'group' => $payload['group']]);
            $this->db->transCommit();
            return ['id' => (int) $user->id, 'username' => $user->username, 'status' => 'active'];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function syncGroup(int $userId, string $group, int $actorUserId): array
    {
        $this->assertUserWithinScope($userId);
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
            $this->audit->record('user.group_changed', 'user', $userId, $actorUserId, before: ['groups' => $before], after: ['groups' => [$group]]);
            $this->db->transCommit();
            return ['id' => $userId, 'group' => $group];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function setActive(int $userId, bool $active, int $actorUserId): array
    {
        $this->assertUserWithinScope($userId);
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
            $this->audit->record('user.status_changed', 'user', $userId, $actorUserId, before: ['active' => $before], after: ['active' => $active]);
            $this->db->transCommit();
            return ['id' => $userId, 'active' => $active];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
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

    private function assertScope(int $companyId, int $siteId, ?int $warehouseId): void
    {
        service('scopeContext')->assertCompanySite($companyId, $siteId);
        $site = $this->db->table('sites')->where(['id' => $siteId, 'company_id' => $companyId, 'active' => 1])->get()->getRowArray();
        if ($site === null) {
            throw new DomainException('La instalación no pertenece a la empresa.', 'INVALID_USER_SCOPE');
        }
        if ($warehouseId !== null) {
            service('scopeContext')->assertWarehouse($warehouseId);
            if ($this->db->table('warehouses')->where(['id' => $warehouseId, 'company_id' => $companyId, 'site_id' => $siteId, 'active' => 1])->countAllResults() !== 1) {
                throw new DomainException('El almacén no pertenece a la instalación.', 'INVALID_USER_SCOPE');
            }
        }
    }

    private function assertUserWithinScope(int $userId): void
    {
        $scope = service('scopeContext')->restrictions();
        if (! $scope['configured']) {
            return;
        }
        $builder = $this->db->table('operational_user_scopes')->where(['user_id' => $userId, 'active' => 1]);
        if ($scope['company_id'] !== null) {
            $builder->where('company_id', $scope['company_id']);
        }
        if ($scope['site_id'] !== null) {
            $builder->groupStart()->where('site_id', $scope['site_id'])->orWhere('site_id', null)->groupEnd();
        }
        if ($scope['warehouse_id'] !== null) {
            $builder->groupStart()->where('warehouse_id', $scope['warehouse_id'])->orWhere('warehouse_id', null)->groupEnd();
        }
        if ($builder->countAllResults() === 0) {
            throw new DomainException('El usuario está fuera del alcance autorizado.', 'USER_SCOPE_MISMATCH');
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
            throw new DomainException('Debe permanecer al menos un propietario del proceso activo.', 'LAST_PROCESS_OWNER_PROTECTED');
        }
    }
}
