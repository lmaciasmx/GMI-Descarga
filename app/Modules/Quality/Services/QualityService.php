<?php

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Modules\Quality\Contracts\QualityRuleStrategyInterface;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class QualityService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly QualityRuleStrategyInterface $strategy,
        private readonly AuditService $audit,
    ) {
    }

    public function inspect(array $payload, int $actorUserId): array
    {
        $this->assertQualityCorrectionSchema();
        $autoGrade = filter_var($payload['auto_grade'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($autoGrade && ! isset($payload['defect_percent'])) {
            throw new DomainException('La clasificación automática requiere porcentaje de defecto.', 'QUALITY_PERCENT_REQUIRED');
        }
        if (! $autoGrade && trim((string) ($payload['quality_grade'] ?? '')) === '') {
            throw new DomainException('Captura el grado de calidad; las reglas automáticas siguen deshabilitadas.', 'QUALITY_GRADE_REQUIRED');
        }

        $disposition = (string) ($payload['disposition'] ?? 'accepted');
        if (! in_array($disposition, ['accepted', 'observed', 'hold', 'hold_lot', 'rejected'], true)) {
            throw new DomainException('Resultado de calidad inválido.', 'INVALID_QUALITY_DISPOSITION');
        }
        if (in_array($disposition, ['hold', 'hold_lot', 'rejected'], true) && mb_strlen(trim((string) ($payload['observations'] ?? ''))) < 5) {
            throw new DomainException('La retención o rechazo requiere una observación de al menos 5 caracteres.', 'QUALITY_REASON_REQUIRED');
        }

        $this->db->transException(true)->transBegin();
        try {
            $weighing = $this->db->query(<<<'SQL'
SELECT w.*, v.company_id, v.site_id
FROM weighings w
INNER JOIN tub_fillings tf ON tf.id = w.tub_filling_id
INNER JOIN discharge_plans dp ON dp.id = tf.discharge_plan_id
INNER JOIN voyages v ON v.id = dp.voyage_id
WHERE w.id = ?
FOR UPDATE
SQL, [(int) $payload['weighing_id']])->getRowArray();
            if ($weighing === null || ! in_array($weighing['status'], ['confirmed', 'pending_inspection', 'received'], true)) {
                throw new DomainException('El pesaje no está disponible para inspección.', 'WEIGHING_NOT_INSPECTABLE');
            }

            $openInspection = $this->db->table('quality_inspections')
                ->where('weighing_id', $weighing['id'])
                ->whereIn('status', ['submitted', 'approved'])
                ->countAllResults();
            if ($openInspection > 0) {
                throw new DomainException('La tina ya tiene una inspección vigente. Anúlala si necesitas corregirla.', 'OPEN_QUALITY_INSPECTION_EXISTS');
            }

            $supersedesId = max(0, (int) ($payload['supersedes_inspection_id'] ?? 0));
            if ($supersedesId > 0) {
                $previous = $this->db->query(<<<'SQL'
SELECT qi.*, v.company_id, v.site_id
FROM quality_inspections qi
INNER JOIN weighings pw ON pw.id = qi.weighing_id
INNER JOIN tub_fillings ptf ON ptf.id = pw.tub_filling_id
INNER JOIN discharge_plans pdp ON pdp.id = ptf.discharge_plan_id
INNER JOIN voyages v ON v.id = pdp.voyage_id
WHERE qi.id = ?
FOR UPDATE
SQL, [$supersedesId])->getRowArray();
                if ($previous === null || $previous['status'] !== 'annulled') {
                    throw new DomainException('La inspección que deseas corregir no está anulada.', 'QUALITY_CORRECTION_SOURCE_INVALID');
                }
                if ((int) $previous['company_id'] !== (int) $weighing['company_id'] || (int) $previous['site_id'] !== (int) $weighing['site_id']) {
                    throw new DomainException('La inspección a corregir pertenece a otro alcance operativo.', 'QUALITY_CORRECTION_SCOPE_MISMATCH');
                }
                if (($previous['superseded_by_inspection_id'] ?? null) !== null) {
                    throw new DomainException('La inspección anulada ya fue sustituida por otra.', 'QUALITY_CORRECTION_ALREADY_REPLACED');
                }
            }

            $grade = $payload['quality_grade'] ?? null;
            if ($autoGrade) {
                $grade = $this->strategy->grade((float) $payload['defect_percent'], (int) $weighing['company_id'], (int) $weighing['site_id']);
            }

            $now = date('Y-m-d H:i:s');
            $this->db->table('quality_inspections')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'weighing_id' => (int) $weighing['id'],
                'quality_grade' => $grade,
                'defect_percent' => $payload['defect_percent'] ?? null,
                'temperature_c' => $payload['temperature_c'] ?? null,
                'disposition' => $disposition,
                'status' => 'submitted',
                'observations' => $payload['observations'] ?? null,
                'inspected_by' => $actorUserId,
                'inspected_at' => $now,
                'supersedes_inspection_id' => $supersedesId > 0 ? $supersedesId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inspectionId = (int) $this->db->insertID();

            if ($supersedesId > 0) {
                $this->db->table('quality_inspections')->where('id', $supersedesId)->update([
                    'superseded_by_inspection_id' => $inspectionId,
                    'updated_at' => $now,
                ]);
            }

            $seenDefects = [];
            foreach (array_values($payload['defects'] ?? []) as $defect) {
                $defectId = (int) ($defect['defect_id'] ?? 0);
                if ($defectId <= 0 || isset($seenDefects[$defectId])) {
                    throw new DomainException('La lista de defectos contiene valores inválidos o repetidos.', 'INVALID_QUALITY_DEFECTS');
                }
                $catalogDefect = $this->db->table('quality_defects')->where(['id' => $defectId, 'active' => 1])->get()->getRowArray();
                if ($catalogDefect === null) {
                    throw new DomainException('Uno de los defectos ya no está activo.', 'QUALITY_DEFECT_NOT_FOUND', ['defect_id' => $defectId]);
                }
                if ((int) $catalogDefect['requires_hold'] === 1 && in_array($disposition, ['accepted', 'observed'], true)) {
                    throw new DomainException('El defecto seleccionado exige retención o rechazo.', 'QUALITY_DEFECT_REQUIRES_HOLD', ['defect_id' => $defectId]);
                }
                $seenDefects[$defectId] = true;
                $this->db->table('quality_inspection_defects')->insert([
                    'quality_inspection_id' => $inspectionId,
                    'defect_id' => $defectId,
                    'quantity' => $defect['quantity'] ?? null,
                    'unit' => $defect['unit'] ?? null,
                    'notes' => $defect['notes'] ?? null,
                ]);
            }

            $this->audit->record(
                $supersedesId > 0 ? 'quality.corrected' : 'quality.inspected',
                'quality_inspection',
                $inspectionId,
                $actorUserId,
                (int) $weighing['company_id'],
                (int) $weighing['site_id'],
                null,
                [
                    'weighing_id' => $weighing['id'],
                    'grade' => $grade,
                    'disposition' => $disposition,
                    'supersedes_inspection_id' => $supersedesId > 0 ? $supersedesId : null,
                ]
            );
            $this->db->transCommit();
            return [
                'id' => $inspectionId,
                'status' => 'submitted',
                'quality_grade' => $grade,
                'disposition' => $disposition,
                'supersedes_inspection_id' => $supersedesId > 0 ? $supersedesId : null,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function assertQualityCorrectionSchema(): void
    {
        foreach (['annulled_by', 'annulled_at', 'annul_reason', 'supersedes_inspection_id', 'superseded_by_inspection_id'] as $field) {
            if (! $this->db->fieldExists($field, 'quality_inspections')) {
                throw new DomainException(
                    'La estructura de Calidad está pendiente de actualización. Ejecuta las migraciones antes de registrar correcciones.',
                    'QUALITY_SCHEMA_UPDATE_REQUIRED',
                    ['missing_field' => $field]
                );
            }
        }
    }

}
