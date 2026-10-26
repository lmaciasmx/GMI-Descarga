<?php

namespace App\Modules\Maritime\Services;

use App\Common\Domain\WorkflowTransitions;
use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class VoyageService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditService $audit
    ) {
    }

    public function create(array $payload, int $actorUserId): array
    {
        $tanks = array_values($payload['tanks'] ?? []);

        $this->db->transException(true)->transBegin();

        try {
            if (
                $this->db->table('sites')
                    ->where([
                        'id' => (int) $payload['site_id'],
                        'company_id' => (int) $payload['company_id'],
                        'active' => 1,
                    ])
                    ->countAllResults() !== 1
            ) {
                throw new DomainException(
                    'La instalación no pertenece a la empresa.',
                    'SITE_COMPANY_MISMATCH'
                );
            }

            // La embarcación es un catálogo maestro global. owner_company_id
            // identifica al propietario, no a la empresa que atiende la marea.
            $vessel = $this->db->table('vessels')
                ->where([
                    'id' => (int) $payload['vessel_id'],
                    'active' => 1,
                ])
                ->get()
                ->getRowArray();

            if ($vessel === null) {
                throw new DomainException(
                    'La embarcación seleccionada no existe o está inactiva.',
                    'VESSEL_NOT_AVAILABLE'
                );
            }

            $existing = $this->db->table('voyages')
                ->select('id, status')
                ->where([
                    'vessel_id' => (int) $payload['vessel_id'],
                    'annual_number' => (int) $payload['annual_number'],
                    'year' => (int) $payload['year'],
                ])
                ->get()
                ->getRowArray();

            if ($existing !== null) {
                $status = (string) $existing['status'];
                $message = sprintf(
                    'La marea %d/%d ya está registrada para esta embarcación.',
                    (int) $payload['annual_number'],
                    (int) $payload['year']
                );

                if ($status === 'canceled') {
                    $message .= ' Está cancelada; restáurala desde Historial en lugar de crearla nuevamente.';
                }

                throw new DomainException(
                    $message,
                    'VOYAGE_ALREADY_EXISTS',
                    [
                        'voyage_id' => (int) $existing['id'],
                        'status' => $status,
                    ]
                );
            }

            if (! empty($payload['receiving_warehouse_id'])) {
                $warehouse = $this->db->table('warehouses')
                    ->where([
                        'id' => (int) $payload['receiving_warehouse_id'],
                        'company_id' => (int) $payload['company_id'],
                        'site_id' => (int) $payload['site_id'],
                        'active' => 1,
                    ])
                    ->get()
                    ->getRowArray();

                if ($warehouse === null) {
                    throw new DomainException(
                        'El frigorífico receptor está fuera del alcance de la marea.',
                        'RECEIVING_WAREHOUSE_MISMATCH'
                    );
                }
            }

            if ($tanks === []) {
                $masterTanks = $this->db->table('vessel_tanks')
                    ->where([
                        'vessel_id' => $vessel['id'],
                        'active' => 1,
                    ])
                    ->orderBy('tank_number')
                    ->get()
                    ->getResultArray();

                $tanks = array_map(
                    static fn (array $tank): array => [
                        'vessel_tank_id' => (int) $tank['id'],
                        'tank_number' => $tank['tank_number'],
                        'side' => $tank['side'],
                        'capacity_kg' => $tank['capacity_kg'],
                    ],
                    $masterTanks
                );
            }

            if ($tanks === []) {
                throw new DomainException(
                    'La marea requiere tanques; captura el catálogo de la embarcación antes de continuar.',
                    'VOYAGE_TANKS_REQUIRED'
                );
            }

            $now = date('Y-m-d H:i:s');

            $this->db->table('voyages')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'company_id' => (int) $payload['company_id'],
                'site_id' => (int) $payload['site_id'],
                'vessel_id' => (int) $payload['vessel_id'],
                'receiving_warehouse_id' => empty($payload['receiving_warehouse_id'])
                    ? null
                    : (int) $payload['receiving_warehouse_id'],
                'annual_number' => (int) $payload['annual_number'],
                'year' => (int) $payload['year'],
                'port_name' => $payload['port_name'] ?? null,
                'arrival_at' => $payload['arrival_at'] ?? null,
                'status' => 'planned',
                'notes' => $payload['notes'] ?? null,
                'created_by' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $voyageId = (int) $this->db->insertID();
            $seen = [];

            foreach ($tanks as $tank) {
                $number = strtoupper(trim((string) $tank['tank_number']));

                if ($number === '' || isset($seen[$number])) {
                    throw new DomainException(
                        'Los números de tanque deben ser únicos y no vacíos.',
                        'INVALID_VOYAGE_TANK'
                    );
                }

                $seen[$number] = true;

                $masterId = empty($tank['vessel_tank_id'])
                    ? null
                    : (int) $tank['vessel_tank_id'];

                if (
                    $masterId !== null
                    && $this->db->table('vessel_tanks')
                        ->where([
                            'id' => $masterId,
                            'vessel_id' => $vessel['id'],
                        ])
                        ->countAllResults() !== 1
                ) {
                    throw new DomainException(
                        'Un tanque maestro no pertenece a la embarcación.',
                        'VESSEL_TANK_MISMATCH'
                    );
                }

                $this->db->table('voyage_tanks')->insert([
                    'voyage_id' => $voyageId,
                    'vessel_tank_id' => $masterId,
                    'tank_number' => $number,
                    'side' => $tank['side'] ?? null,
                    'capacity_kg' => $tank['capacity_kg'] ?? null,
                    'planned_weight_kg' => $tank['planned_weight_kg'] ?? null,
                    'status' => 'available',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->audit->record(
                'voyage.created',
                'voyage',
                $voyageId,
                $actorUserId,
                (int) $payload['company_id'],
                (int) $payload['site_id'],
                null,
                [
                    'tank_count' => count($tanks),
                    'vessel_id' => (int) $vessel['id'],
                    'vessel_owner_company_id' => (int) $vessel['owner_company_id'],
                ]
            );

            $this->db->transCommit();

            return [
                'id' => $voyageId,
                'status' => 'planned',
                'tank_count' => count($tanks),
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function transition(
        int $voyageId,
        string $target,
        int $actorUserId,
        ?string $reason = null
    ): array {
        $reason = trim((string) $reason);

        if (
            in_array($target, ['canceled', 'planned'], true)
            && (mb_strlen($reason) < 5 || mb_strlen($reason) > 500)
        ) {
            throw new DomainException(
                $target === 'planned'
                    ? 'Restaurar una marea requiere un motivo de 5 a 500 caracteres.'
                    : 'Cancelar una marea requiere un motivo de 5 a 500 caracteres.',
                $target === 'planned'
                    ? 'VOYAGE_RESTORE_REASON_REQUIRED'
                    : 'VOYAGE_CANCEL_REASON_REQUIRED'
            );
        }

        $this->db->transException(true)->transBegin();

        try {
            $voyage = $this->db->query(
                'SELECT * FROM voyages WHERE id = ? FOR UPDATE',
                [$voyageId]
            )->getRowArray();

            if ($voyage === null) {
                throw new DomainException(
                    'Marea no encontrada.',
                    'VOYAGE_NOT_FOUND'
                );
            }

            WorkflowTransitions::voyage()->assertCanTransition(
                (string) $voyage['status'],
                $target
            );

            $plans = $this->db->query(
                'SELECT id, status FROM discharge_plans WHERE voyage_id = ? FOR UPDATE',
                [$voyageId]
            )->getResultArray();

            $fillings = $this->db->query(
                <<<'SQL'
SELECT tf.id, tf.status
FROM tub_fillings tf
INNER JOIN discharge_plans dp ON dp.id = tf.discharge_plan_id
WHERE dp.voyage_id = ?
FOR UPDATE
SQL,
                [$voyageId]
            )->getResultArray();

            $weighings = $this->db->query(
                <<<'SQL'
SELECT w.id, w.status
FROM weighings w
INNER JOIN tub_fillings tf ON tf.id = w.tub_filling_id
INNER JOIN discharge_plans dp ON dp.id = tf.discharge_plan_id
WHERE dp.voyage_id = ?
FOR UPDATE
SQL,
                [$voyageId]
            )->getResultArray();

            $now = date('Y-m-d H:i:s');

            if (
                $target === 'unloading'
                && ! $this->containsStatus($plans, ['approved', 'in_progress'])
            ) {
                throw new DomainException(
                    'La marea requiere al menos un plan aprobado antes de iniciar descarga.',
                    'VOYAGE_APPROVED_PLAN_REQUIRED'
                );
            }

            if ($target === 'closed') {
                if (
                    $this->containsStatus(
                        $plans,
                        ['draft', 'approved', 'in_progress', 'paused']
                    )
                ) {
                    throw new DomainException(
                        'Finaliza o cancela todos los planes antes de cerrar la marea.',
                        'VOYAGE_HAS_OPEN_PLANS'
                    );
                }

                if (
                    $this->containsStatus(
                        $fillings,
                        ['filling', 'pending_weighing']
                    )
                ) {
                    throw new DomainException(
                        'La marea conserva tinas en llenado o pendientes de pesaje.',
                        'VOYAGE_HAS_PENDING_FILLINGS'
                    );
                }

                if (! $this->containsStatus($plans, ['finished'])) {
                    throw new DomainException(
                        'La marea requiere al menos un plan finalizado para cerrarse.',
                        'VOYAGE_FINISHED_PLAN_REQUIRED'
                    );
                }
            }

            $canceledPlans = 0;

            if ($target === 'canceled') {
                if ($this->containsStatusExcept($weighings, ['reversed'])) {
                    throw new DomainException(
                        'La marea tiene pesajes vigentes y no puede cancelarse.',
                        'VOYAGE_HAS_WEIGHINGS'
                    );
                }

                if ($fillings !== []) {
                    throw new DomainException(
                        'La marea ya tiene operación de tinas y no puede cancelarse.',
                        'VOYAGE_HAS_OPERATIONS'
                    );
                }

                if ($this->containsStatus($plans, ['finished'])) {
                    throw new DomainException(
                        'Una marea con planes finalizados debe cerrarse, no cancelarse.',
                        'VOYAGE_HAS_FINISHED_PLANS'
                    );
                }

                $openPlans = array_values(
                    array_filter(
                        $plans,
                        static fn (array $plan): bool => in_array(
                            $plan['status'],
                            ['draft', 'approved', 'in_progress', 'paused'],
                            true
                        )
                    )
                );

                $openPlanIds = array_map(
                    static fn (array $plan): int => (int) $plan['id'],
                    $openPlans
                );

                if ($openPlanIds !== []) {
                    $this->db->table('discharge_plans')
                        ->whereIn('id', $openPlanIds)
                        ->set('lock_version', 'lock_version + 1', false)
                        ->update([
                            'status' => 'canceled',
                            'updated_by' => $actorUserId,
                            'updated_at' => $now,
                        ]);

                    $canceledPlans = $this->db->affectedRows();

                    foreach ($openPlans as $openPlan) {
                        $this->audit->record(
                            'discharge_plan.canceled_with_voyage',
                            'discharge_plan',
                            (int) $openPlan['id'],
                            $actorUserId,
                            (int) $voyage['company_id'],
                            (int) $voyage['site_id'],
                            ['status' => $openPlan['status']],
                            [
                                'status' => 'canceled',
                                'voyage_id' => $voyageId,
                            ],
                            $reason
                        );
                    }
                }
            }

            if ($target === 'planned') {
                if ($fillings !== [] || $weighings !== []) {
                    throw new DomainException(
                        'La marea ya tiene operación física y no puede restaurarse.',
                        'VOYAGE_RESTORE_HAS_OPERATIONS'
                    );
                }

                if ($this->containsStatus($plans, ['finished'])) {
                    throw new DomainException(
                        'La marea contiene planes finalizados y no puede restaurarse.',
                        'VOYAGE_RESTORE_HAS_FINISHED_PLANS'
                    );
                }
            }

            $update = [
                'status' => $target,
                'lock_version' => (int) $voyage['lock_version'] + 1,
                'updated_by' => $actorUserId,
                'updated_at' => $now,
            ];

            if (
                $target === 'unloading'
                && $voyage['unloading_started_at'] === null
            ) {
                $update['unloading_started_at'] = $now;
            }

            if ($target === 'closed') {
                $update['unloading_finished_at'] = $now;
            }

            if ($target === 'planned') {
                $update['unloading_started_at'] = null;
                $update['unloading_finished_at'] = null;
            }

            $this->db->table('voyages')
                ->where('id', $voyageId)
                ->update($update);

            $this->audit->record(
                $target === 'planned'
                    ? 'voyage.restored'
                    : 'voyage.transitioned',
                'voyage',
                $voyageId,
                $actorUserId,
                (int) $voyage['company_id'],
                (int) $voyage['site_id'],
                ['status' => $voyage['status']],
                [
                    'status' => $target,
                    'canceled_plans' => $canceledPlans,
                ],
                $reason === '' ? null : $reason
            );

            $this->db->transCommit();

            return [
                'id' => $voyageId,
                'status' => $target,
                'canceled_plans' => $canceledPlans,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /** @param list<array<string, mixed>> $rows @param list<string> $statuses */
    private function containsStatus(array $rows, array $statuses): bool
    {
        foreach ($rows as $row) {
            if (in_array($row['status'], $statuses, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $rows @param list<string> $excluded */
    private function containsStatusExcept(array $rows, array $excluded): bool
    {
        foreach ($rows as $row) {
            if (! in_array($row['status'], $excluded, true)) {
                return true;
            }
        }

        return false;
    }
}
