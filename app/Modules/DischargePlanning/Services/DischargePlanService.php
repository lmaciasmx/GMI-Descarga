<?php

namespace App\Modules\DischargePlanning\Services;

use App\Common\Domain\WorkflowTransitions;
use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Common\Services\FolioService;
use App\Common\Services\SettingsService;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class DischargePlanService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly FolioService $folios,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {
    }

    public function detail(int $planId): array
    {
        $plan = $this->db->table('discharge_plans dp')
            ->select(
                'dp.*, v.company_id, v.site_id, v.status AS voyage_status, ' .
                'v.annual_number, v.year, ve.code AS vessel_code, ve.name AS vessel_name, ' .
                'r.code AS rsa_code, r.issued_on AS rsa_issued_on, ' .
                'r.expires_on AS rsa_expires_on, r.status AS rsa_status'
            )
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->join('rsa_records r', 'r.id = dp.rsa_id', 'left')
            ->where('dp.id', $planId)
            ->get()
            ->getRowArray();

        if ($plan === null) {
            throw new DomainException('Plan no encontrado.', 'PLAN_NOT_FOUND');
        }

        $plan['tank_lines'] = $this->loadTankLines($planId);
        $plan['review'] = $this->buildReview($plan);

        $fillingCount = $this->db->table('tub_fillings')
            ->where('discharge_plan_id', $planId)
            ->countAllResults();

        $plan['has_operations'] = $fillingCount > 0;
        $plan['can_edit'] = $plan['status'] === 'draft' && $fillingCount === 0;
        $plan['can_return_to_draft'] = $plan['status'] === 'approved' && $fillingCount === 0;

        return $plan;
    }

    public function create(array $payload, int $actorUserId): array
    {
        $tankLines = array_values($payload['tank_lines'] ?? []);
        $this->assertBasicPayload($payload, $tankLines);

        $this->db->transException(true)->transBegin();

        try {
            $voyage = $this->lockPlannableVoyage((int) $payload['voyage_id']);
            $this->validateRsaForPlan(
                $payload['rsa_id'] ?? null,
                $voyage,
                $payload['planned_start_at'] ?? null
            );

            $now = date('Y-m-d H:i:s');
            $folio = $this->folios->next('PLD');

            $this->db->table('discharge_plans')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'folio' => $folio,
                'voyage_id' => (int) $voyage['id'],
                'rsa_id' => empty($payload['rsa_id']) ? null : (int) $payload['rsa_id'],
                'planned_start_at' => $payload['planned_start_at'] ?? null,
                'planned_end_at' => $payload['planned_end_at'] ?? null,
                'status' => 'draft',
                'notes' => $payload['notes'] ?? null,
                'created_by' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $planId = (int) $this->db->insertID();
            $this->replaceTankLines($planId, $voyage, $tankLines, $now);

            $this->audit->record(
                'discharge_plan.created',
                'discharge_plan',
                $planId,
                $actorUserId,
                (int) $voyage['company_id'],
                (int) $voyage['site_id'],
                null,
                ['folio' => $folio, 'tank_lines' => count($tankLines)]
            );

            $this->db->transCommit();

            return ['id' => $planId, 'folio' => $folio, 'status' => 'draft'];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function update(int $planId, array $payload, int $actorUserId): array
    {
        $tankLines = array_values($payload['tank_lines'] ?? []);
        $this->assertBasicPayload($payload, $tankLines);

        $this->db->transException(true)->transBegin();

        try {
            $plan = $this->db->query(
                'SELECT * FROM discharge_plans WHERE id = ? FOR UPDATE',
                [$planId]
            )->getRowArray();

            if ($plan === null) {
                throw new DomainException('Plan no encontrado.', 'PLAN_NOT_FOUND');
            }

            if ($plan['status'] !== 'draft') {
                throw new DomainException(
                    'Sólo un plan en borrador puede modificarse.',
                    'PLAN_NOT_EDITABLE'
                );
            }

            if ((int) $plan['voyage_id'] !== (int) $payload['voyage_id']) {
                throw new DomainException(
                    'La marea de un plan existente no puede cambiarse. Cancela el plan y crea uno nuevo.',
                    'PLAN_VOYAGE_CHANGE_NOT_ALLOWED'
                );
            }

            if (
                $this->db->table('tub_fillings')
                    ->where('discharge_plan_id', $planId)
                    ->countAllResults() > 0
            ) {
                throw new DomainException(
                    'El plan ya tiene operación de tinas y no puede modificarse.',
                    'PLAN_HAS_OPERATIONS'
                );
            }

            $voyage = $this->lockPlannableVoyage((int) $plan['voyage_id']);
            $this->validateRsaForPlan(
                $payload['rsa_id'] ?? null,
                $voyage,
                $payload['planned_start_at'] ?? null
            );

            $now = date('Y-m-d H:i:s');

            $this->db->table('discharge_plans')
                ->where('id', $planId)
                ->update([
                    'rsa_id' => empty($payload['rsa_id']) ? null : (int) $payload['rsa_id'],
                    'planned_start_at' => $payload['planned_start_at'] ?? null,
                    'planned_end_at' => $payload['planned_end_at'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'lock_version' => (int) $plan['lock_version'] + 1,
                    'updated_by' => $actorUserId,
                    'updated_at' => $now,
                ]);

            $this->db->table('discharge_plan_tanks')
                ->where('discharge_plan_id', $planId)
                ->delete();

            $this->replaceTankLines($planId, $voyage, $tankLines, $now);

            $this->audit->record(
                'discharge_plan.updated',
                'discharge_plan',
                $planId,
                $actorUserId,
                (int) $voyage['company_id'],
                (int) $voyage['site_id'],
                [
                    'rsa_id' => $plan['rsa_id'],
                    'planned_start_at' => $plan['planned_start_at'],
                    'planned_end_at' => $plan['planned_end_at'],
                ],
                [
                    'rsa_id' => empty($payload['rsa_id']) ? null : (int) $payload['rsa_id'],
                    'planned_start_at' => $payload['planned_start_at'] ?? null,
                    'planned_end_at' => $payload['planned_end_at'] ?? null,
                    'tank_lines' => count($tankLines),
                ]
            );

            $this->db->transCommit();

            return ['id' => $planId, 'folio' => $plan['folio'], 'status' => 'draft'];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function duplicate(int $sourcePlanId, int $actorUserId): array
    {
        $source = $this->detail($sourcePlanId);

        if ($source['status'] !== 'canceled') {
            throw new DomainException(
                'Sólo los planes cancelados pueden duplicarse desde esta opción.',
                'PLAN_DUPLICATE_STATUS_NOT_ALLOWED'
            );
        }

        if (! in_array($source['voyage_status'], ['planned', 'unloading'], true)) {
            throw new DomainException(
                'La marea del plan no está activa. Restáurala antes de duplicar el plan.',
                'PLAN_DUPLICATE_VOYAGE_NOT_ACTIVE'
            );
        }

        $rsaId = $source['rsa_id'];

        if ($rsaId !== null) {
            $rsa = $this->db->table('rsa_records')
                ->where([
                    'id' => (int) $rsaId,
                    'voyage_id' => (int) $source['voyage_id'],
                    'status' => 'active',
                ])
                ->get()
                ->getRowArray();

            $referenceDate = $this->referenceDate($source['planned_start_at']);

            if (
                $rsa === null
                || ($rsa['issued_on'] !== null && $rsa['issued_on'] > $referenceDate)
                || ($rsa['expires_on'] !== null && $rsa['expires_on'] < $referenceDate)
            ) {
                $rsaId = null;
            }
        }

        $payload = [
            'voyage_id' => (int) $source['voyage_id'],
            'rsa_id' => $rsaId,
            'planned_start_at' => $source['planned_start_at'],
            'planned_end_at' => $source['planned_end_at'],
            'notes' => $source['notes'],
            'tank_lines' => array_map(
                static fn (array $line): array => [
                    'voyage_tank_id' => (int) $line['voyage_tank_id'],
                    'species_id' => (int) $line['species_id'],
                    'size_grade_id' => $line['size_grade_id'] === null
                        ? null
                        : (int) $line['size_grade_id'],
                    'planned_weight_kg' => (float) $line['planned_weight_kg'],
                    'msc_code' => (string) $line['msc_code'],
                    'dolphin_safe_code' => (string) $line['dolphin_safe_code'],
                    'freezing_temperature_c' => (float) $line['freezing_temperature_c'],
                    'sequence_number' => (int) $line['sequence_number'],
                ],
                $source['tank_lines']
            ),
        ];

        $result = $this->create($payload, $actorUserId);

        $this->audit->record(
            'discharge_plan.duplicated',
            'discharge_plan',
            (int) $result['id'],
            $actorUserId,
            (int) $source['company_id'],
            (int) $source['site_id'],
            ['source_plan_id' => $sourcePlanId],
            [
                'new_plan_id' => (int) $result['id'],
                'rsa_copied' => $rsaId !== null,
            ]
        );

        $result['source_plan_id'] = $sourcePlanId;
        $result['rsa_copied'] = $rsaId !== null;

        return $result;
    }

    public function transition(
        int $planId,
        string $target,
        int $actorUserId,
        ?string $reason = null
    ): array {
        $reason = trim((string) $reason);

        if (
            in_array($target, ['canceled', 'draft'], true)
            && (mb_strlen($reason) < 5 || mb_strlen($reason) > 500)
        ) {
            throw new DomainException(
                $target === 'draft'
                    ? 'Regresar un plan a borrador requiere un motivo de 5 a 500 caracteres.'
                    : 'Cancelar un plan requiere un motivo de 5 a 500 caracteres.',
                $target === 'draft'
                    ? 'PLAN_RETURN_DRAFT_REASON_REQUIRED'
                    : 'PLAN_CANCEL_REASON_REQUIRED'
            );
        }

        $this->db->transException(true)->transBegin();

        try {
            $plan = $this->db->query(
                <<<'SQL'
SELECT dp.*, v.company_id, v.site_id, v.status AS voyage_status
FROM discharge_plans dp
INNER JOIN voyages v ON v.id = dp.voyage_id
WHERE dp.id = ?
FOR UPDATE
SQL,
                [$planId]
            )->getRowArray();

            if ($plan === null) {
                throw new DomainException('Plan no encontrado.', 'PLAN_NOT_FOUND');
            }

            WorkflowTransitions::plan()->assertCanTransition(
                (string) $plan['status'],
                $target
            );

            $fillings = $this->db->query(
                'SELECT id, status FROM tub_fillings WHERE discharge_plan_id = ? FOR UPDATE',
                [$planId]
            )->getResultArray();

            if ($target === 'approved') {
                $review = $this->buildReview($plan);

                if (! $review['can_approve']) {
                    $failure = $this->firstFailedValidation($review);
                    throw new DomainException(
                        $failure['message']
                            ?? 'El plan no cumple todas las validaciones para aprobarse.',
                        'PLAN_NOT_READY_FOR_APPROVAL',
                        ['review' => $review]
                    );
                }
            }

            if ($target === 'draft' && $fillings !== []) {
                throw new DomainException(
                    'El plan ya tiene operación y no puede regresar a borrador.',
                    'PLAN_RETURN_DRAFT_HAS_OPERATIONS'
                );
            }

            if ($target === 'finished') {
                foreach ($fillings as $filling) {
                    if (in_array($filling['status'], ['filling', 'pending_weighing'], true)) {
                        throw new DomainException(
                            'No se puede finalizar un plan con tinas en llenado o pendientes de pesaje.',
                            'PLAN_HAS_PENDING_FILLINGS'
                        );
                    }
                }
            }

            if ($target === 'canceled' && $fillings !== []) {
                throw new DomainException(
                    'Un plan que ya tiene operación de tinas no puede cancelarse.',
                    'PLAN_HAS_OPERATIONS'
                );
            }

            $now = date('Y-m-d H:i:s');
            $update = [
                'status' => $target,
                'lock_version' => (int) $plan['lock_version'] + 1,
                'updated_by' => $actorUserId,
                'updated_at' => $now,
            ];

            if ($target === 'approved') {
                $update['approved_by'] = $actorUserId;
                $update['approved_at'] = $now;
            }

            if ($target === 'draft') {
                $update['approved_by'] = null;
                $update['approved_at'] = null;
            }

            $this->db->table('discharge_plans')
                ->where('id', $planId)
                ->update($update);

            if ($target === 'in_progress' && $plan['voyage_status'] === 'planned') {
                $this->db->table('voyages')
                    ->where('id', $plan['voyage_id'])
                    ->set('lock_version', 'lock_version + 1', false)
                    ->update([
                        'status' => 'unloading',
                        'unloading_started_at' => $now,
                        'updated_by' => $actorUserId,
                        'updated_at' => $now,
                    ]);
            }

            $this->audit->record(
                'discharge_plan.transitioned',
                'discharge_plan',
                $planId,
                $actorUserId,
                (int) $plan['company_id'],
                (int) $plan['site_id'],
                ['status' => $plan['status']],
                ['status' => $target],
                $reason === '' ? null : $reason
            );

            $this->db->transCommit();

            return ['id' => $planId, 'status' => $target];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function startFilling(array $payload, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();

        try {
            $result = $this->startFillingInTransaction($payload, $actorUserId);
            $this->db->transCommit();
            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /**
     * Variante para orquestadores que ya poseen una transacción abierta.
     * No inicia ni confirma transacción por sí misma.
     */
    public function startFillingInTransaction(array $payload, int $actorUserId): array
    {
        $sourceTankIds = [];
        $sourceTankSeen = [];

        foreach (array_values($payload['source_tank_ids'] ?? []) as $sourceTankId) {
            if (
                ! is_int($sourceTankId)
                && ! (is_string($sourceTankId) && ctype_digit($sourceTankId))
            ) {
                throw new DomainException(
                    'La lista de tanques de procedencia contiene identificadores inválidos.',
                    'INVALID_SOURCE_TANKS'
                );
            }

            $sourceTankId = (int) $sourceTankId;

            if ($sourceTankId <= 0 || isset($sourceTankSeen[$sourceTankId])) {
                throw new DomainException(
                    'Los tanques de procedencia deben ser positivos y no repetirse.',
                    'INVALID_SOURCE_TANKS'
                );
            }

            $sourceTankSeen[$sourceTankId] = true;
            $sourceTankIds[] = $sourceTankId;
        }

        $actualTankId = (int) ($payload['actual_tank_id'] ?? 0);

        if ($sourceTankIds === [] || ! in_array($actualTankId, $sourceTankIds, true)) {
            throw new DomainException(
                'La procedencia debe incluir el tanque real seleccionado.',
                'SOURCE_TANKS_REQUIRED'
            );
        }

        $plan = $this->db->query(
            <<<'SQL'
SELECT dp.*, v.company_id, v.site_id
FROM discharge_plans dp
INNER JOIN voyages v ON v.id = dp.voyage_id
WHERE dp.id = ?
FOR UPDATE
SQL,
            [(int) ($payload['plan_id'] ?? 0)]
        )->getRowArray();

        if ($plan === null || $plan['status'] !== 'in_progress') {
            throw new DomainException(
                'El plan no está en operación.',
                'PLAN_NOT_IN_PROGRESS'
            );
        }

        // Las tinas son activos globales: no existe tubs.company_id.
        $tub = $this->db->query(
            'SELECT * FROM tubs WHERE id = ? FOR UPDATE',
            [(int) ($payload['tub_id'] ?? 0)]
        )->getRowArray();

        if (
            $tub === null
            || $tub['status'] !== 'available'
            || ! (bool) $tub['active']
        ) {
            throw new DomainException(
                'La tina no está disponible.',
                'TUB_NOT_AVAILABLE'
            );
        }

        // Candado transversal de tina física: una tina disponible sólo puede
        // iniciar un nuevo ciclo si no conserva producto activo de un ciclo
        // anterior ni otro llenado realmente abierto. Los históricos con
        // on_hand_weight_kg = 0 y los fillings en estado weighed NO bloquean
        // la reutilización. La fila de tubs ya está bloqueada FOR UPDATE.
        $activeStock = $this->db->query(
            <<<'SQL'
SELECT id, unit_code, status, on_hand_weight_kg
FROM stock_units
WHERE tub_id = ?
  AND on_hand_weight_kg > 0
ORDER BY id ASC
LIMIT 1
FOR UPDATE
SQL,
            [(int) $tub['id']]
        )->getRowArray();

        if ($activeStock !== null) {
            throw new DomainException(
                'La tina conserva producto activo en inventario y no puede iniciar una nueva descarga.',
                'TUB_HAS_ACTIVE_STOCK',
                [
                    'tub_id' => (int) $tub['id'],
                    'stock_unit_id' => (int) $activeStock['id'],
                    'unit_code' => (string) $activeStock['unit_code'],
                ]
            );
        }

        $openFilling = $this->db->query(
            <<<'SQL'
SELECT id, status
FROM tub_fillings
WHERE tub_id = ?
  AND status IN ('filling', 'pending_weighing')
ORDER BY id DESC
LIMIT 1
FOR UPDATE
SQL,
            [(int) $tub['id']]
        )->getRowArray();

        if ($openFilling !== null) {
            throw new DomainException(
                'La tina tiene un llenado pendiente y no puede iniciar un nuevo ciclo.',
                'TUB_HAS_OPEN_FILLING',
                [
                    'tub_id' => (int) $tub['id'],
                    'tub_filling_id' => (int) $openFilling['id'],
                    'filling_status' => (string) $openFilling['status'],
                ]
            );
        }

        $actualTank = $this->validateTankForPlan(
            (int) $plan['voyage_id'],
            $actualTankId
        );

        $speciesId = (int) ($payload['species_id'] ?? 0);
        $sizeGradeId = (int) ($payload['size_grade_id'] ?? 0);

        $species = $this->db->table('species')
            ->where(['id' => $speciesId, 'active' => 1])
            ->get()
            ->getRowArray();

        if ($species === null) {
            throw new DomainException(
                'La especie capturada no está disponible.',
                'SPECIES_NOT_FOUND'
            );
        }

        $size = $this->db->table('size_grades')
            ->where(['id' => $sizeGradeId, 'active' => 1])
            ->get()
            ->getRowArray();

        if (
            $size === null
            || (
                $size['species_id'] !== null
                && (int) $size['species_id'] !== $speciesId
            )
        ) {
            throw new DomainException(
                'La talla no corresponde a la especie capturada.',
                'SIZE_SPECIES_MISMATCH'
            );
        }

        $plannedProduct = $this->db->table('discharge_plan_tanks')
            ->where([
                'discharge_plan_id' => $plan['id'],
                'voyage_tank_id' => $actualTankId,
                'species_id' => $speciesId,
            ])
            ->groupStart()
                ->where('size_grade_id', $sizeGradeId)
                ->orWhere('size_grade_id', null)
            ->groupEnd()
            ->orderBy('(size_grade_id = ' . $sizeGradeId . ')', 'DESC', false)
            ->get()
            ->getRowArray();

        $outOfPlan = $plannedProduct === null;
        $plannedLineId = $plannedProduct === null ? null : (int) $plannedProduct['id'];

        if ($plannedProduct !== null) {
            $mscCode = (string) $plannedProduct['msc_code'];
            $dolphinSafeCode = (string) $plannedProduct['dolphin_safe_code'];
            $freezingTemperature = (float) $plannedProduct['freezing_temperature_c'];
        } else {
            [$mscCode, $dolphinSafeCode, $freezingTemperature] =
                $this->validateEntryCertification($payload, $plan);
        }

        foreach ($sourceTankIds as $sourceTankId) {
            $this->validateTankForPlan((int) $plan['voyage_id'], $sourceTankId);
        }

        $sorterUserId = $this->validateOperator(
            isset($payload['sorter_user_id']) ? (int) $payload['sorter_user_id'] : null,
            'discharge.operate',
            (int) $plan['company_id'],
            (int) $plan['site_id'],
            'clasificador',
        );

        $labelerUserId = $this->validateOperator(
            isset($payload['labeler_user_id']) ? (int) $payload['labeler_user_id'] : null,
            'labels.print',
            (int) $plan['company_id'],
            (int) $plan['site_id'],
            'etiquetador',
        );

        $sorterParticipantId = $this->validateParticipant(
            isset($payload['sorter_participant_id']) ? (int) $payload['sorter_participant_id'] : null,
            'can_sort',
            (int) $plan['company_id'],
            (int) $plan['site_id'],
            'sorteador'
        );

        $labelerParticipantId = $this->validateParticipant(
            isset($payload['labeler_participant_id']) ? (int) $payload['labeler_participant_id'] : null,
            'can_label',
            (int) $plan['company_id'],
            (int) $plan['site_id'],
            'etiquetador'
        );

        $now = date('Y-m-d H:i:s');

        $this->db->table('tub_fillings')->insert([
            'uuid' => Uuid::uuid7()->toString(),
            'discharge_plan_id' => (int) $plan['id'],
            'tub_id' => (int) $tub['id'],
            'actual_voyage_tank_id' => $actualTankId,
            'species_id' => $speciesId,
            'size_grade_id' => $sizeGradeId,
            'planned_line_id' => $plannedLineId,
            'out_of_plan' => $outOfPlan ? 1 : 0,
            'msc_code_snapshot' => $mscCode,
            'dolphin_safe_code_snapshot' => $dolphinSafeCode,
            'freezing_temperature_c_snapshot' => $freezingTemperature,
            'sorter_user_id' => $sorterUserId,
            'labeler_user_id' => $labelerUserId,
            'sorter_participant_id' => $sorterParticipantId,
            'labeler_participant_id' => $labelerParticipantId,
            'status' => 'filling',
            'started_at' => $now,
            'observations' => $payload['observations'] ?? null,
            'created_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fillingId = (int) $this->db->insertID();

        foreach ($sourceTankIds as $sourceTankId) {
            $this->db->table('filling_source_tanks')->insert([
                'tub_filling_id' => $fillingId,
                'voyage_tank_id' => $sourceTankId,
                'is_primary' => $sourceTankId === $actualTankId,
                'created_at' => $now,
            ]);
        }

        $this->db->table('tubs')
            ->where('id', $tub['id'])
            ->update([
                'status' => 'filling',
                'lock_version' => (int) $tub['lock_version'] + 1,
                'updated_at' => $now,
            ]);

        $this->audit->record(
            'tub_filling.started',
            'tub_filling',
            $fillingId,
            $actorUserId,
            (int) $plan['company_id'],
            (int) $plan['site_id'],
            null,
            [
                'actual_tank' => $actualTank['tank_number'],
                'source_tank_ids' => $sourceTankIds,
                'sorter_user_id' => $sorterUserId,
                'labeler_user_id' => $labelerUserId,
                'sorter_participant_id' => $sorterParticipantId,
                'labeler_participant_id' => $labelerParticipantId,
                'planned_line_id' => $plannedLineId,
                'out_of_plan' => $outOfPlan,
                'msc_code' => $mscCode,
                'dolphin_safe_code' => $dolphinSafeCode,
                'freezing_temperature_c' => $freezingTemperature,
            ]
        );

        if ($outOfPlan) {
            $this->audit->record(
                'tub_filling.out_of_plan',
                'tub_filling',
                $fillingId,
                $actorUserId,
                (int) $plan['company_id'],
                (int) $plan['site_id'],
                null,
                [
                    'actual_tank' => $actualTank['tank_number'],
                    'species_id' => $speciesId,
                    'size_grade_id' => $sizeGradeId,
                    'msc_code' => $mscCode,
                    'dolphin_safe_code' => $dolphinSafeCode,
                    'freezing_temperature_c' => $freezingTemperature,
                ],
                trim((string) ($payload['observations'] ?? '')) ?: null
            );
        }

        return [
            'id' => $fillingId,
            'status' => 'filling',
            'out_of_plan' => $outOfPlan,
            'planned_line_id' => $plannedLineId,
        ];
    }

    public function completeFilling(int $fillingId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();

        try {
            $result = $this->completeFillingInTransaction($fillingId, $actorUserId);
            $this->db->transCommit();
            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /**
     * Variante para orquestadores que ya poseen una transacción abierta.
     */
    public function completeFillingInTransaction(int $fillingId, int $actorUserId): array
    {
        $filling = $this->db->query(
            <<<'SQL'
SELECT tf.*, v.company_id, v.site_id
FROM tub_fillings tf
INNER JOIN discharge_plans dp ON dp.id = tf.discharge_plan_id
INNER JOIN voyages v ON v.id = dp.voyage_id
WHERE tf.id = ?
FOR UPDATE
SQL,
            [$fillingId]
        )->getRowArray();

        if ($filling === null || $filling['status'] !== 'filling') {
            throw new DomainException(
                'El llenado no está activo.',
                'FILLING_NOT_ACTIVE'
            );
        }

        $tub = $this->db->query(
            'SELECT * FROM tubs WHERE id = ? FOR UPDATE',
            [$filling['tub_id']]
        )->getRowArray();

        if ($tub === null || $tub['status'] !== 'filling') {
            throw new DomainException(
                'La tina vinculada ya no está en el estado esperado de llenado.',
                'TUB_FILLING_STATE_MISMATCH'
            );
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('tub_fillings')
            ->where('id', $fillingId)
            ->update([
                'status' => 'pending_weighing',
                'completed_at' => $now,
                'lock_version' => (int) $filling['lock_version'] + 1,
                'updated_at' => $now,
            ]);

        $this->db->table('tubs')
            ->where('id', $tub['id'])
            ->update([
                'status' => 'pending_weighing',
                'lock_version' => (int) $tub['lock_version'] + 1,
                'updated_at' => $now,
            ]);

        $this->audit->record(
            'tub_filling.completed',
            'tub_filling',
            $fillingId,
            $actorUserId,
            (int) $filling['company_id'],
            (int) $filling['site_id'],
            ['status' => 'filling'],
            ['status' => 'pending_weighing']
        );

        return ['id' => $fillingId, 'status' => 'pending_weighing'];
    }

    private function assertBasicPayload(array $payload, array $tankLines): void
    {
        if ($tankLines === []) {
            throw new DomainException(
                'El plan requiere al menos un tanque y producto.',
                'PLAN_TANKS_REQUIRED'
            );
        }

        if (
            ! empty($payload['planned_start_at'])
            && ! empty($payload['planned_end_at'])
            && $payload['planned_end_at'] <= $payload['planned_start_at']
        ) {
            throw new DomainException(
                'El fin planeado debe ser posterior al inicio.',
                'INVALID_PLAN_WINDOW'
            );
        }
    }

    private function lockPlannableVoyage(int $voyageId): array
    {
        $voyage = $this->db->query(
            'SELECT * FROM voyages WHERE id = ? FOR UPDATE',
            [$voyageId]
        )->getRowArray();

        if (
            $voyage === null
            || ! in_array($voyage['status'], ['planned', 'unloading'], true)
        ) {
            throw new DomainException(
                'La marea no admite un nuevo plan.',
                'VOYAGE_NOT_PLANNABLE'
            );
        }

        return $voyage;
    }

    private function referenceDate(?string $plannedStartAt): string
    {
        $today = date('Y-m-d');
        $planned = $plannedStartAt
            ? substr($plannedStartAt, 0, 10)
            : $today;

        return $planned < $today ? $today : $planned;
    }

    private function validateRsaForPlan(
        mixed $rsaId,
        array $voyage,
        ?string $plannedStartAt
    ): ?array {
        if ($rsaId === null || $rsaId === '' || (int) $rsaId <= 0) {
            return null;
        }

        $rsa = $this->db->table('rsa_records')
            ->where([
                'id' => (int) $rsaId,
                'voyage_id' => (int) $voyage['id'],
                'status' => 'active',
            ])
            ->get()
            ->getRowArray();

        if ($rsa === null) {
            throw new DomainException(
                'El RSA no pertenece a la marea o no está activo.',
                'RSA_VOYAGE_MISMATCH'
            );
        }

        $referenceDate = $this->referenceDate($plannedStartAt);

        if ($rsa['issued_on'] !== null && $rsa['issued_on'] > $referenceDate) {
            throw new DomainException(
                'El RSA todavía no será vigente en la fecha de referencia del plan.',
                'PLAN_RSA_NOT_EFFECTIVE'
            );
        }

        if ($rsa['expires_on'] !== null && $rsa['expires_on'] < $referenceDate) {
            throw new DomainException(
                'El RSA estará vencido en la fecha de referencia del plan.',
                'PLAN_RSA_EXPIRED'
            );
        }

        return $rsa;
    }

    private function replaceTankLines(
        int $planId,
        array $voyage,
        array $tankLines,
        string $now
    ): void {
        $seen = [];
        $seenSequences = [];
        $plannedByTank = [];

        foreach ($tankLines as $index => $line) {
            $sequence = isset($line['sequence_number'])
                ? (int) $line['sequence_number']
                : $index + 1;

            if ($sequence <= 0 || isset($seenSequences[$sequence])) {
                throw new DomainException(
                    'Las secuencias del plan deben ser positivas y únicas.',
                    'DUPLICATE_PLAN_SEQUENCE'
                );
            }

            $seenSequences[$sequence] = true;

            $tank = $this->db->table('voyage_tanks')
                ->where([
                    'id' => (int) $line['voyage_tank_id'],
                    'voyage_id' => $voyage['id'],
                ])
                ->get()
                ->getRowArray();

            if ($tank === null) {
                throw new DomainException(
                    'Un tanque no pertenece a la marea.',
                    'PLAN_TANK_VOYAGE_MISMATCH'
                );
            }

            $composition = $this->validateComposition($line, $voyage);

            $fingerprint = implode(':', [
                (int) $line['voyage_tank_id'],
                (int) $line['species_id'],
                (int) ($line['size_grade_id'] ?? 0),
            ]);

            if (isset($seen[$fingerprint])) {
                throw new DomainException(
                    'La composición de tanque está duplicada.',
                    'DUPLICATE_TANK_COMPOSITION'
                );
            }

            $seen[$fingerprint] = true;
            $tankId = (int) $line['voyage_tank_id'];
            $plannedByTank[$tankId] = round(
                ($plannedByTank[$tankId] ?? 0)
                + (float) $line['planned_weight_kg'],
                3
            );

            if (
                $tank['capacity_kg'] !== null
                && $plannedByTank[$tankId] > (float) $tank['capacity_kg']
            ) {
                throw new DomainException(
                    'El tonelaje planeado supera la capacidad fotografiada del tanque.',
                    'PLAN_TANK_CAPACITY_EXCEEDED',
                    [
                        'tank_number' => $tank['tank_number'],
                        'capacity_kg' => (float) $tank['capacity_kg'],
                    ]
                );
            }

            $this->db->table('discharge_plan_tanks')->insert([
                'discharge_plan_id' => $planId,
                'voyage_tank_id' => $tankId,
                'species_id' => (int) $line['species_id'],
                'size_grade_id' => empty($line['size_grade_id'])
                    ? null
                    : (int) $line['size_grade_id'],
                'planned_weight_kg' => (float) $line['planned_weight_kg'],
                'msc_code' => $composition['msc_code'],
                'dolphin_safe_code' => $composition['dolphin_safe_code'],
                'freezing_temperature_c' => $composition['freezing_temperature_c'],
                'sequence_number' => $sequence,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function loadTankLines(int $planId): array
    {
        return $this->db->table('discharge_plan_tanks dpt')
            ->select(
                'dpt.*, vt.tank_number, vt.capacity_kg, ' .
                's.code AS species_code, s.name AS species_name, ' .
                's.active AS species_active, sg.name AS size_name, ' .
                'sg.species_id AS size_species_id, sg.active AS size_active'
            )
            ->join('voyage_tanks vt', 'vt.id = dpt.voyage_tank_id')
            ->join('species s', 's.id = dpt.species_id')
            ->join('size_grades sg', 'sg.id = dpt.size_grade_id', 'left')
            ->where('dpt.discharge_plan_id', $planId)
            ->orderBy('dpt.sequence_number')
            ->get()
            ->getResultArray();
    }

    private function buildReview(array $plan): array
    {
        $lines = isset($plan['tank_lines'])
            ? $plan['tank_lines']
            : $this->loadTankLines((int) $plan['id']);

        $referenceDate = $this->referenceDate($plan['planned_start_at'] ?? null);
        $rsa = null;

        if (! empty($plan['rsa_id'])) {
            $rsa = $this->db->table('rsa_records')
                ->where([
                    'id' => (int) $plan['rsa_id'],
                    'voyage_id' => (int) $plan['voyage_id'],
                    'status' => 'active',
                ])
                ->get()
                ->getRowArray();
        }

        $rsaOk = $rsa !== null
            && ($rsa['issued_on'] === null || $rsa['issued_on'] <= $referenceDate)
            && ($rsa['expires_on'] === null || $rsa['expires_on'] >= $referenceDate);

        $windowOk = ! (
            ! empty($plan['planned_start_at'])
            && ! empty($plan['planned_end_at'])
            && $plan['planned_end_at'] <= $plan['planned_start_at']
        );

        $hasLines = $lines !== [];
        $compositionOk = true;
        $duplicatesOk = true;
        $capacityOk = true;
        $seen = [];
        $plannedByTank = [];

        $mscValues = (array) $this->settings->get(
            'certification.msc_values',
            (int) $plan['company_id'],
            (int) $plan['site_id'],
            []
        );

        $dolphinValues = (array) $this->settings->get(
            'certification.dolphin_safe_values',
            (int) $plan['company_id'],
            (int) $plan['site_id'],
            []
        );

        foreach ($lines as $line) {
            $fingerprint = implode(':', [
                (int) $line['voyage_tank_id'],
                (int) $line['species_id'],
                (int) ($line['size_grade_id'] ?? 0),
            ]);

            if (isset($seen[$fingerprint])) {
                $duplicatesOk = false;
            }
            $seen[$fingerprint] = true;

            if (
                (float) $line['planned_weight_kg'] <= 0
                || ! is_numeric($line['freezing_temperature_c'])
                || ! in_array($line['msc_code'], $mscValues, true)
                || ! in_array($line['dolphin_safe_code'], $dolphinValues, true)
                || ! (bool) $line['species_active']
                || (
                    $line['size_grade_id'] !== null
                    && (
                        ! (bool) $line['size_active']
                        || (
                            $line['size_species_id'] !== null
                            && (int) $line['size_species_id'] !== (int) $line['species_id']
                        )
                    )
                )
            ) {
                $compositionOk = false;
            }

            $tankId = (int) $line['voyage_tank_id'];
            $plannedByTank[$tankId] =
                ($plannedByTank[$tankId] ?? 0)
                + (float) $line['planned_weight_kg'];

            if (
                $line['capacity_kg'] !== null
                && $plannedByTank[$tankId] > (float) $line['capacity_kg']
            ) {
                $capacityOk = false;
            }
        }

        $validations = [
            [
                'key' => 'rsa',
                'label' => 'RSA vigente para la fecha de referencia',
                'ok' => $rsaOk,
                'message' => $rsaOk
                    ? 'RSA válido.'
                    : 'Asocia un RSA activo y vigente para la fecha de referencia del plan.',
            ],
            [
                'key' => 'window',
                'label' => 'Ventana de planeación válida',
                'ok' => $windowOk,
                'message' => $windowOk
                    ? 'Fechas válidas.'
                    : 'La fecha de fin debe ser posterior a la fecha de inicio.',
            ],
            [
                'key' => 'lines',
                'label' => 'Composición capturada',
                'ok' => $hasLines,
                'message' => $hasLines
                    ? sprintf('%d línea(s) capturada(s).', count($lines))
                    : 'El plan no tiene composición.',
            ],
            [
                'key' => 'composition',
                'label' => 'Especies, tallas y certificaciones válidas',
                'ok' => $compositionOk,
                'message' => $compositionOk
                    ? 'Composición válida.'
                    : 'Existe una línea con especie, talla, MSC, Dolphin Safe, peso o temperatura inválida.',
            ],
            [
                'key' => 'duplicates',
                'label' => 'Sin composiciones duplicadas',
                'ok' => $duplicatesOk,
                'message' => $duplicatesOk
                    ? 'No hay duplicados.'
                    : 'Existe una combinación tanque/especie/talla duplicada.',
            ],
            [
                'key' => 'capacity',
                'label' => 'Capacidad de tanques respetada',
                'ok' => $capacityOk,
                'message' => $capacityOk
                    ? 'Capacidades válidas.'
                    : 'El peso planeado supera la capacidad de al menos un tanque.',
            ],
        ];

        $canApprove = true;
        foreach ($validations as $validation) {
            if (! $validation['ok']) {
                $canApprove = false;
                break;
            }
        }

        return [
            'reference_date' => $referenceDate,
            'can_approve' => $canApprove,
            'validations' => $validations,
        ];
    }

    private function firstFailedValidation(array $review): array
    {
        foreach ($review['validations'] as $validation) {
            if (! $validation['ok']) {
                return $validation;
            }
        }
        return [];
    }

    private function validateTankForPlan(int $voyageId, int $tankId): array
    {
        $tank = $this->db->table('voyage_tanks')
            ->where(['id' => $tankId, 'voyage_id' => $voyageId])
            ->get()
            ->getRowArray();

        if ($tank === null) {
            throw new DomainException(
                'Tanque fuera de la marea activa.',
                'TANK_VOYAGE_MISMATCH'
            );
        }
        return $tank;
    }

    private function validateOperator(
        ?int $userId,
        string $requiredPermission,
        int $companyId,
        int $siteId,
        string $label
    ): ?int {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $operator = $this->db->table('users u')
            ->select('u.id')
            ->join('operational_user_scopes ous', 'ous.user_id = u.id')
            ->where([
                'u.id' => $userId,
                'u.active' => 1,
                'ous.company_id' => $companyId,
                'ous.active' => 1,
            ])
            ->groupStart()
                ->where('ous.site_id', $siteId)
                ->orWhere('ous.site_id', null)
            ->groupEnd()
            ->get()
            ->getRowArray();

        $user = $operator === null
            ? null
            : auth()->getProvider()->findById($userId);

        if ($user === null || ! $user->can($requiredPermission)) {
            throw new DomainException(
                'El usuario seleccionado no es un ' . $label . ' autorizado en este alcance.',
                'OPERATOR_SCOPE_MISMATCH'
            );
        }

        return $userId;
    }

    private function validateParticipant(
        ?int $participantId,
        string $capabilityColumn,
        int $companyId,
        int $siteId,
        string $label
    ): ?int {
        if ($participantId === null || $participantId <= 0) {
            return null;
        }

        if (! in_array($capabilityColumn, ['can_sort', 'can_label'], true)) {
            throw new \LogicException('Capacidad de participante no soportada.');
        }

        $participant = $this->db->table('operation_participants')
            ->where([
                'id' => $participantId,
                'company_id' => $companyId,
                'site_id' => $siteId,
                'active' => 1,
                $capabilityColumn => 1,
            ])
            ->get()
            ->getRowArray();

        if ($participant === null) {
            throw new DomainException(
                'El ' . $label . ' seleccionado no está activo o no pertenece al alcance operativo.',
                'PARTICIPANT_SCOPE_MISMATCH'
            );
        }

        return $participantId;
    }

    /** @return array{0:string,1:string,2:float} */
    private function validateEntryCertification(array $payload, array $plan): array
    {
        $mscCode = trim((string) ($payload['msc_code'] ?? ''));
        $dolphinCode = trim((string) ($payload['dolphin_safe_code'] ?? ''));
        $temperature = $payload['freezing_temperature_c'] ?? null;

        $mscValues = (array) $this->settings->get(
            'certification.msc_values',
            (int) $plan['company_id'],
            (int) $plan['site_id'],
            []
        );
        $dolphinValues = (array) $this->settings->get(
            'certification.dolphin_safe_values',
            (int) $plan['company_id'],
            (int) $plan['site_id'],
            []
        );

        if (! in_array($mscCode, $mscValues, true)) {
            throw new DomainException(
                'Para una captura fuera de plan debes seleccionar una clasificación MSC válida.',
                'OUT_OF_PLAN_MSC_REQUIRED'
            );
        }
        if (! in_array($dolphinCode, $dolphinValues, true)) {
            throw new DomainException(
                'Para una captura fuera de plan debes seleccionar una categoría Dolphin Safe válida.',
                'OUT_OF_PLAN_DOLPHIN_REQUIRED'
            );
        }
        if (! is_numeric($temperature)) {
            throw new DomainException(
                'Para una captura fuera de plan debes indicar la temperatura de congelación.',
                'OUT_OF_PLAN_TEMPERATURE_REQUIRED'
            );
        }

        return [$mscCode, $dolphinCode, round((float) $temperature, 2)];
    }

    private function validateComposition(array $line, array $voyage): array
    {
        $mscCode = trim((string) ($line['msc_code'] ?? ''));
        $dolphinCode = trim((string) ($line['dolphin_safe_code'] ?? ''));
        $temperature = $line['freezing_temperature_c'] ?? null;

        $mscValues = (array) $this->settings->get(
            'certification.msc_values',
            (int) $voyage['company_id'],
            (int) $voyage['site_id'],
            []
        );

        $dolphinValues = (array) $this->settings->get(
            'certification.dolphin_safe_values',
            (int) $voyage['company_id'],
            (int) $voyage['site_id'],
            []
        );

        if (! in_array($mscCode, $mscValues, true)) {
            throw new DomainException(
                'Clasificación MSC inválida para la composición.',
                'INVALID_MSC_CODE'
            );
        }

        if (! in_array($dolphinCode, $dolphinValues, true)) {
            throw new DomainException(
                'Categoría Dolphin Safe inválida para la composición.',
                'INVALID_DOLPHIN_SAFE_CODE'
            );
        }

        if (! is_numeric($temperature)) {
            throw new DomainException(
                'La temperatura de congelación es obligatoria.',
                'FREEZING_TEMPERATURE_REQUIRED'
            );
        }

        if (
            ! isset($line['planned_weight_kg'])
            || ! is_numeric($line['planned_weight_kg'])
            || (float) $line['planned_weight_kg'] <= 0
        ) {
            throw new DomainException(
                'El tonelaje planeado debe ser positivo.',
                'INVALID_PLANNED_WEIGHT'
            );
        }

        $species = $this->db->table('species')
            ->where([
                'id' => (int) ($line['species_id'] ?? 0),
                'active' => 1,
            ])
            ->get()
            ->getRowArray();

        if ($species === null) {
            throw new DomainException(
                'La especie de la composición no existe.',
                'SPECIES_NOT_FOUND'
            );
        }

        if (! empty($line['size_grade_id'])) {
            $size = $this->db->table('size_grades')
                ->where([
                    'id' => (int) $line['size_grade_id'],
                    'active' => 1,
                ])
                ->get()
                ->getRowArray();

            if (
                $size === null
                || (
                    $size['species_id'] !== null
                    && (int) $size['species_id'] !== (int) $species['id']
                )
            ) {
                throw new DomainException(
                    'La talla no corresponde a la especie.',
                    'SIZE_SPECIES_MISMATCH'
                );
            }
        }

        return [
            'msc_code' => $mscCode,
            'dolphin_safe_code' => $dolphinCode,
            'freezing_temperature_c' => round((float) $temperature, 2),
        ];
    }
}
