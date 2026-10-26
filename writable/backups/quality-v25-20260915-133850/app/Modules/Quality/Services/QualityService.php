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
        $autoGrade = filter_var($payload['auto_grade'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($autoGrade && ! isset($payload['defect_percent'])) {
            throw new DomainException('La clasificación automática requiere porcentaje de defecto.', 'QUALITY_PERCENT_REQUIRED');
        }
        if (! $autoGrade && trim((string) ($payload['quality_grade'] ?? '')) === '') {
            throw new DomainException('Captura el grado de calidad; las reglas automáticas siguen deshabilitadas.', 'QUALITY_GRADE_REQUIRED');
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
            if ($weighing === null || ! in_array($weighing['status'], ['confirmed', 'pending_inspection'], true)) {
                throw new DomainException('El pesaje no está disponible para inspección.', 'WEIGHING_NOT_INSPECTABLE');
            }
            if ($this->db->table('quality_inspections')->where('weighing_id', $weighing['id'])->countAllResults() > 0) {
                throw new DomainException('El pesaje ya tiene inspección.', 'DUPLICATE_QUALITY_INSPECTION');
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
                'disposition' => $payload['disposition'] ?? 'pending',
                'status' => 'submitted',
                'observations' => $payload['observations'] ?? null,
                'inspected_by' => $actorUserId,
                'inspected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inspectionId = (int) $this->db->insertID();
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
                if ((int) $catalogDefect['requires_hold'] === 1 && ($payload['disposition'] ?? 'pending') === 'accepted') {
                    throw new DomainException('Un defecto crítico no puede proponerse como aceptado.', 'QUALITY_DEFECT_REQUIRES_HOLD', ['defect_id' => $defectId]);
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
            $this->db->table('weighings')->where('id', $weighing['id'])->update(['status' => 'pending_inspection', 'updated_at' => $now]);
            $this->audit->record('quality.inspected', 'quality_inspection', $inspectionId, $actorUserId, (int) $weighing['company_id'], (int) $weighing['site_id'], null, ['weighing_id' => $weighing['id'], 'grade' => $grade, 'disposition' => $payload['disposition'] ?? 'pending']);
            $this->db->transCommit();
            return ['id' => $inspectionId, 'status' => 'submitted', 'quality_grade' => $grade];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
