<?php

namespace App\Modules\Weighing\Services;

use App\Common\Domain\WorkflowTransitions;
use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Common\Services\FolioService;
use App\Common\Services\SettingsService;
use App\Modules\Weighing\DTO\CreateWeighingData;
use App\Modules\Weighing\Entities\Weighing;
use App\Modules\Weighing\Models\WeighingModel;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class WeighingService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly WeighingModel $weighings,
        private readonly LotCodeService $lotCodes,
        private readonly SettingsService $settings,
        private readonly FolioService $folios,
        private readonly AuditService $audit,
        private readonly WeightCalculator $calculator,
    ) {
    }

    public function confirm(CreateWeighingData $data): Weighing
    {
        $this->db->transException(true)->transBegin();

        try {
            $weighing = $this->confirmInTransaction($data);
            $this->db->transCommit();
            return $weighing;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /**
     * Confirma un pesaje dentro de una transacción ya abierta.
     * Es la misma regla de negocio de confirm(), sin abrir ni cerrar la transacción.
     */
    public function confirmInTransaction(CreateWeighingData $data): Weighing
    {
        $filling = $this->db->query(<<<'SQL'
SELECT tf.*, t.current_tare_kg, t.status AS tub_status, dp.voyage_id, dp.rsa_id AS plan_rsa_id, v.company_id, v.site_id
FROM tub_fillings tf
INNER JOIN tubs t ON t.id = tf.tub_id
INNER JOIN discharge_plans dp ON dp.id = tf.discharge_plan_id
INNER JOIN voyages v ON v.id = dp.voyage_id
WHERE tf.id = ?
FOR UPDATE
SQL, [$data->fillingId])->getRowArray();

        if ($filling === null) {
            throw new DomainException('No existe el llenado indicado.', 'FILLING_NOT_FOUND');
        }

        if ($filling['status'] !== 'pending_weighing') {
            throw new DomainException(
                'El llenado no está listo para pesarse.',
                'FILLING_NOT_READY',
                ['status' => $filling['status']]
            );
        }

        $rsaId = $data->rsaId
            ?? ($filling['plan_rsa_id'] === null ? null : (int) $filling['plan_rsa_id']);

        if ($rsaId === null) {
            throw new DomainException(
                'El pesaje requiere el RSA aprobado del plan.',
                'WEIGHING_RSA_REQUIRED'
            );
        }
        if (
            $filling['plan_rsa_id'] !== null
            && $rsaId !== (int) $filling['plan_rsa_id']
        ) {
            throw new DomainException(
                'El RSA del pesaje no coincide con el plan.',
                'WEIGHING_RSA_PLAN_MISMATCH'
            );
        }

        $rsa = $this->db->table('rsa_records')
            ->where([
                'id' => $rsaId,
                'voyage_id' => $filling['voyage_id'],
                'status' => 'active',
            ])
            ->get()
            ->getRowArray();

        if (
            $rsa === null
            || ($rsa['expires_on'] !== null && $rsa['expires_on'] < date('Y-m-d'))
        ) {
            throw new DomainException(
                'El RSA del pesaje no está activo para la marea.',
                'WEIGHING_RSA_INVALID'
            );
        }

        if (
            $data->weightSource === 'simulator'
            && ENVIRONMENT === 'production'
            && ! filter_var(
                env('integrations.scale.allowSimulatorInProduction', false),
                FILTER_VALIDATE_BOOLEAN
            )
        ) {
            throw new DomainException(
                'El simulador de báscula está bloqueado en producción.',
                'SIMULATOR_FORBIDDEN_IN_PRODUCTION'
            );
        }

        $scaleReading = null;
        $scaleId = $data->scaleId;
        $grossWeightKg = $data->grossWeightKg;
        $stable = $data->stable;
        $originalValue = $data->originalValue;
        $deviceTimestamp = $this->normalizeDeviceTimestamp($data->deviceTimestamp);

        if ($data->weightSource !== 'manual') {
            $scaleReading = $this->claimableScaleReading($data, $filling);
            $scaleId = (int) $scaleReading['scale_id'];
            $grossWeightKg = (float) $scaleReading['weight'];
            $stable = true;
            $originalValue = (string) $scaleReading['original_value'];
            $deviceTimestamp = (string) $scaleReading['device_timestamp'];
        } else {
            if ($data->scaleReadingId !== null) {
                throw new DomainException(
                    'La contingencia manual no puede consumir una lectura automática.',
                    'MANUAL_SCALE_READING_FORBIDDEN'
                );
            }

            if ($scaleId !== null) {
                $scale = $this->db->table('scales')
                    ->where([
                        'id' => $scaleId,
                        'site_id' => $filling['site_id'],
                        'active' => 1,
                    ])
                    ->get()
                    ->getRowArray();

                if ($scale === null) {
                    throw new DomainException(
                        'La báscula no pertenece a la instalación o está inactiva.',
                        'SCALE_SCOPE_MISMATCH'
                    );
                }
            }

            $stable = false;
            $originalValue = null;
            $deviceTimestamp = null;
        }

        $lot = $data->lotId === null
            ? $this->lotCodes->findOrCreateForFilling(
                $data->fillingId,
                $data->certification,
                $data->actorUserId
            )
            : $this->validateLot($data->lotId, $filling);

        [$tare, $tareSource] = $this->resolveTare($data, $filling);

        if ($data->weightSource !== 'manual' && ! $data->stable) {
            throw new DomainException(
                'La báscula aún no reporta un peso estable.',
                'UNSTABLE_WEIGHT'
            );
        }

        if (
            $data->weightSource === 'manual'
            && ($data->reasonCode === null || trim($data->reasonCode) === '')
        ) {
            throw new DomainException(
                'La captura manual requiere un motivo.',
                'MANUAL_WEIGHT_REASON_REQUIRED'
            );
        }

        if (
            $data->penaltyPercent > 0
            && ($data->reasonCode === null || trim($data->reasonCode) === '')
        ) {
            throw new DomainException(
                'El castigo requiere un motivo.',
                'PENALTY_REASON_REQUIRED'
            );
        }

        $calculation = $this->calculator->calculate(
            $grossWeightKg,
            $tare,
            $data->penaltyPercent
        );

        $physicalNet = $calculation->physicalNetKg;
        $penaltyKg = $calculation->penaltyKg;
        $acceptedNet = $calculation->acceptedNetKg;
        $now = date('Y-m-d H:i:s');
        $folio = $this->folios->next('PES');

        $id = $this->weighings->insert([
            'uuid' => Uuid::uuid7()->toString(),
            'folio' => $folio,
            'tub_filling_id' => $data->fillingId,
            'tub_id' => (int) $filling['tub_id'],
            'lot_id' => (int) $lot['id'],
            'rsa_id' => $rsaId,
            'scale_id' => $scaleId,
            'scale_reading_id' => $scaleReading === null
                ? null
                : (int) $scaleReading['id'],
            'gross_weight_kg' => round($grossWeightKg, 3),
            'applied_tare_kg' => $tare,
            'tare_source' => $tareSource,
            'physical_net_kg' => $physicalNet,
            'penalty_percent' => round($data->penaltyPercent, 3),
            'penalty_kg' => $penaltyKg,
            'accepted_net_kg' => $acceptedNet,
            'weight_source' => $data->weightSource,
            'stable' => $stable,
            'original_value' => $originalValue,
            'device_timestamp' => $deviceTimestamp,
            'reason_code' => $data->reasonCode,
            'observations' => $data->observations,
            'status' => 'confirmed',
            'is_current' => 1,
            'confirmed_by' => $data->actorUserId,
            'confirmed_at' => $now,
            'created_by' => $data->actorUserId,
        ], true);

        if (! is_int($id)) {
            throw new DomainException(
                'No se pudo registrar el pesaje.',
                'WEIGHING_INSERT_FAILED'
            );
        }

        if ($scaleReading !== null) {
            $this->db->table('scale_readings')
                ->where('id', (int) $scaleReading['id'])
                ->where('weighing_id', null)
                ->update(['weighing_id' => $id]);

            if ($this->db->affectedRows() !== 1) {
                throw new DomainException(
                    'La lectura fue reclamada por otro proceso.',
                    'CONCURRENT_SCALE_READING_CLAIM'
                );
            }
        }

        $this->db->table('tub_fillings')
            ->where('id', $data->fillingId)
            ->update([
                'status' => 'weighed',
                'completed_at' => $now,
                'lock_version' => (int) $filling['lock_version'] + 1,
                'updated_at' => $now,
            ]);

        WorkflowTransitions::tub()->assertCanTransition(
            (string) $filling['tub_status'],
            'weighed'
        );

        $currentTubLockVersion = (int) $this->db
            ->query('SELECT lock_version FROM tubs WHERE id = ?', [$filling['tub_id']])
            ->getRow('lock_version');

        $this->db->table('tubs')
            ->where('id', (int) $filling['tub_id'])
            ->update([
                'status' => 'weighed',
                'lock_version' => $currentTubLockVersion + 1,
                'updated_at' => $now,
            ]);

        $this->audit->record(
            'weighing.confirmed',
            'weighing',
            $id,
            $data->actorUserId,
            (int) $filling['company_id'],
            (int) $filling['site_id'],
            null,
            [
                'folio' => $folio,
                'gross_weight_kg' => $grossWeightKg,
                'applied_tare_kg' => $tare,
                'physical_net_kg' => $physicalNet,
                'penalty_kg' => $penaltyKg,
                'accepted_net_kg' => $acceptedNet,
                'weight_source' => $data->weightSource,
                'scale_reading_id' => $scaleReading === null
                    ? null
                    : (int) $scaleReading['id'],
            ],
            $data->reasonCode
        );

        $weighing = $this->weighings->find($id);

        if (! $weighing instanceof Weighing) {
            throw new DomainException(
                'El pesaje fue confirmado pero no pudo recuperarse.',
                'WEIGHING_READ_FAILED'
            );
        }

        return $weighing;
    }

    public function reverse(int $weighingId, string $reason, int $actorUserId): void
    {
        if (trim($reason) === '') {
            throw new DomainException('El reverso exige un motivo.', 'REVERSAL_REASON_REQUIRED');
        }

        $this->db->transException(true)->transStart();

        try {
            $row = $this->db->query('SELECT * FROM weighings WHERE id = ? FOR UPDATE', [$weighingId])->getRowArray();

            if ($row === null) {
                throw new DomainException('Pesaje no encontrado.', 'WEIGHING_NOT_FOUND');
            }

            WorkflowTransitions::weighing()->assertCanTransition((string) $row['status'], 'reversed');

            $inventory = $this->db->query(<<<'SQL'
SELECT su.id AS stock_unit_id, receipt_document.id AS receipt_document_id, reversal_document.id AS reversal_document_id
FROM stock_units su
LEFT JOIN reception_lines rl ON rl.id = su.reception_line_id
LEFT JOIN inventory_documents receipt_document
  ON receipt_document.source_document_type = 'reception'
 AND receipt_document.source_document_id = rl.reception_id
 AND receipt_document.movement_type = 'receipt'
LEFT JOIN inventory_documents reversal_document ON reversal_document.reversal_of_id = receipt_document.id
WHERE su.weighing_id = ?
LIMIT 1
SQL, [$weighingId])->getRowArray();

            if ($inventory !== null && $inventory['reversal_document_id'] === null) {
                throw new DomainException(
                    'El pesaje ya originó inventario; primero debe reversarse la recepción mediante InventoryService.',
                    'WEIGHING_HAS_INVENTORY'
                );
            }

            $now = date('Y-m-d H:i:s');
            $this->db->table('weighings')->where('id', $weighingId)->update([
                'status' => 'reversed',
                'is_current' => null,
                'reversed_by' => $actorUserId,
                'reversed_at' => $now,
                'reversal_reason' => $reason,
                'lock_version' => (int) $row['lock_version'] + 1,
                'updated_at' => $now,
            ]);
            $this->db->table('tub_fillings')->where('id', $row['tub_filling_id'])->update(['status' => 'pending_weighing', 'updated_at' => $now]);
            $this->db->table('tubs')->where('id', $row['tub_id'])->update(['status' => 'pending_weighing', 'updated_at' => $now]);
            $this->db->table('labels')->where('weighing_id', $weighingId)->where('status !=', 'canceled')->update([
                'status' => 'canceled',
                'canceled_at' => $now,
            ]);
            $canceledLabels = $this->db->affectedRows();
            if ($this->db->tableExists('warehouse_entry_destinations')) {
                $this->db->table('warehouse_entry_destinations')
                    ->where(['weighing_id' => $weighingId, 'status' => 'proposed'])
                    ->update([
                        'status' => 'canceled',
                        'change_reason' => $reason,
                        'updated_at' => $now,
                    ]);
            }
            $this->audit->record('weighing.reversed', 'weighing', $weighingId, $actorUserId, after: ['labels_canceled' => $canceledLabels], reason: $reason);
            $this->db->transComplete();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function resolveTare(CreateWeighingData $data, array $filling): array
    {
        if ($data->tareSource === 'catalog') {
            return [round((float) $filling['current_tare_kg'], 3), 'catalog_snapshot'];
        }

        if ($data->tareSource === 'entry_override') {
            if (
                $data->declaredTareKg === null
                || ! is_finite($data->declaredTareKg)
                || $data->declaredTareKg <= 0
            ) {
                throw new DomainException(
                    'La tara corregida en Entrada a Frigorífico debe ser mayor que cero.',
                    'WAREHOUSE_ENTRY_TARE_OVERRIDE_INVALID'
                );
            }

            return [round($data->declaredTareKg, 3), 'warehouse_entry_override'];
        }

        if ($data->tareSource === 'painted') {
            if ($data->declaredTareKg === null || $data->declaredTareKg <= 0 || trim((string) $data->reasonCode) === '') {
                throw new DomainException('La tara pintada requiere valor positivo y motivo.', 'PAINTED_TARE_REASON_REQUIRED');
            }

            return [round($data->declaredTareKg, 3), 'painted'];
        }

        if ($data->tareSource === 'estimated') {
            if (trim((string) $data->reasonCode) === '') {
                throw new DomainException('La tara estimada requiere un motivo.', 'ESTIMATED_TARE_REASON_REQUIRED');
            }

            $tare = (float) $this->settings->get(
                'tare.estimated_kg',
                (int) $filling['company_id'],
                (int) $filling['site_id'],
                176.000
            );

            return [round($tare, 3), 'estimated_config'];
        }

        throw new DomainException('Fuente de tara no reconocida.', 'INVALID_TARE_SOURCE');
    }

    private function validateLot(int $lotId, array $filling): array
    {
        $lot = $this->db->table('lots')->where('id', $lotId)->get()->getRowArray();

        if ($lot === null || (int) $lot['voyage_id'] !== (int) $filling['voyage_id'] || (int) $lot['species_id'] !== (int) $filling['species_id']) {
            throw new DomainException('El lote no corresponde al viaje y especie del llenado.', 'LOT_CONTEXT_MISMATCH');
        }

        return $lot;
    }

    /** @return array<string, mixed> */
    private function claimableScaleReading(CreateWeighingData $data, array $filling): array
    {
        if ($data->scaleId === null || $data->scaleReadingId === null || $data->originalValue === null || $data->deviceTimestamp === null) {
            throw new DomainException(
                'La lectura automática requiere báscula, identificador de captura, valor original y fecha del dispositivo.',
                'SCALE_READING_EVIDENCE_REQUIRED'
            );
        }

        $reading = $this->db->query(<<<'SQL'
SELECT sr.*, sc.site_id, sc.active AS scale_active
FROM scale_readings sr
INNER JOIN scales sc ON sc.id = sr.scale_id
WHERE sr.id = ?
FOR UPDATE
SQL, [$data->scaleReadingId])->getRowArray();

        if ($reading === null) {
            throw new DomainException('La captura de báscula no existe.', 'SCALE_READING_NOT_FOUND');
        }
        // Lecturas consumidas en solicitudes no pueden reutilizarse en la entrada inicial.
        $usedInRequest=$this->db->table('transfer_weighings')->where('scale_reading_id',$data->scaleReadingId)->countAllResults()>0
            || $this->db->table('warehouse_request_units')->where('return_reading_id',$data->scaleReadingId)->countAllResults()>0;
        if ($reading['weighing_id'] !== null || $usedInRequest) {
            throw new DomainException('La lectura de báscula ya fue utilizada.', 'SCALE_READING_ALREADY_USED');
        }
        if ((int) $reading['scale_active'] !== 1 || (int) $reading['site_id'] !== (int) $filling['site_id'] || (int) $reading['scale_id'] !== $data->scaleId) {
            throw new DomainException('La lectura o la báscula están fuera de la instalación operativa.', 'SCALE_SCOPE_MISMATCH');
        }
        if ((int) $reading['stable'] !== 1 || strtolower((string) $reading['unit']) !== 'kg' || ! $data->stable) {
            throw new DomainException('La captura no representa una lectura estable en kg.', 'UNSTABLE_WEIGHT');
        }

        $this->assertReadingActor($reading, $data->actorUserId);
        $this->assertReadingFreshness($reading, $filling);

        $normalizedTimestamp = $this->normalizeDeviceTimestamp($data->deviceTimestamp);
        $grossMatches = abs(round($data->grossWeightKg, 3) - (float) $reading['weight']) < 0.0005;
        $rawMatches = hash_equals((string) $reading['original_hash'], hash('sha256', $data->originalValue));
        $timestampMatches = $normalizedTimestamp === (string) $reading['device_timestamp'];
        $sourceMatches = $data->weightSource === $this->publicWeightSource((string) $reading['source']);

        if (! $grossMatches || ! $rawMatches || ! $timestampMatches || ! $sourceMatches) {
            throw new DomainException(
                'Los datos enviados no coinciden con la lectura capturada por el servidor.',
                'SCALE_READING_TAMPERED'
            );
        }

        return $reading;
    }

    /** @param array<string, mixed> $reading */
    private function assertReadingActor(array $reading, int $actorUserId): void
    {
        if ($actorUserId > 0) {
            if ($reading['actor_user_id'] === null || (int) $reading['actor_user_id'] !== $actorUserId) {
                throw new DomainException('La lectura fue capturada por otro usuario.', 'SCALE_READING_ACTOR_MISMATCH');
            }
            return;
        }

        $client = service('apiClientContext')->client();
        $currentReference = $client === null ? null : (string) $client['client_id'];
        $readingReference = $reading['actor_reference'] === null ? null : (string) $reading['actor_reference'];
        if ($currentReference === null || $readingReference === null || ! hash_equals($readingReference, $currentReference)) {
            throw new DomainException('La lectura fue capturada por otro cliente de integración.', 'SCALE_READING_ACTOR_MISMATCH');
        }
    }

    /** @param array<string, mixed> $reading @param array<string, mixed> $filling */
    private function assertReadingFreshness(array $reading, array $filling): void
    {
        $timezone = new \DateTimeZone(config('App')->appTimezone);
        try {
            $receivedAt = new \DateTimeImmutable((string) $reading['received_at'], $timezone);
        } catch (\Throwable $exception) {
            throw new DomainException('La hora de recepción de la lectura no es válida.', 'INVALID_SCALE_TIMESTAMP', [], 0, $exception);
        }

        $maxAge = (int) $this->settings->get(
            'scale.reading_max_age_seconds',
            (int) $filling['company_id'],
            (int) $filling['site_id'],
            120,
        );
        $maxAge = max(5, min(3600, $maxAge));
        $ageSeconds = (new \DateTimeImmutable('now', $timezone))->getTimestamp() - $receivedAt->getTimestamp();
        if ($ageSeconds > $maxAge || $ageSeconds < -30) {
            throw new DomainException(
                'La lectura de báscula expiró; solicita una nueva captura estable.',
                'STALE_SCALE_READING',
                ['max_age_seconds' => $maxAge]
            );
        }
    }

    private function publicWeightSource(string $source): string
    {
        if ($source === 'simulator') {
            return 'simulator';
        }
        if ($source === 'middleware' || str_starts_with($source, 'local_middleware:')) {
            return 'middleware';
        }

        return 'scale';
    }

    private function normalizeDeviceTimestamp(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone(config('App')->appTimezone))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable $exception) {
            throw new DomainException('La fecha de la báscula no es válida.', 'INVALID_SCALE_TIMESTAMP', [], 0, $exception);
        }
    }
}

