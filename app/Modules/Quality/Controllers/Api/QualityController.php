<?php

namespace App\Modules\Quality\Controllers\Api;

use App\Common\Controllers\ApiController;

final class QualityController extends ApiController
{
    public function inspect()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();

            // JSON puede enviar booleanos reales y la UI puede omitir defectos.
            // Normalizamos antes de validar para no convertir una retención válida
            // en un error por wildcard cuando no se seleccionó un defecto de catálogo.
            if (array_key_exists('auto_grade', $payload)) {
                $payload['auto_grade'] = filter_var(
                    $payload['auto_grade'],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );
                if ($payload['auto_grade'] === null) {
                    $payload['auto_grade'] = false;
                }
                $payload['auto_grade'] = $payload['auto_grade'] ? 1 : 0;
            }

            $normalizedDefects = [];
            foreach ((array) ($payload['defects'] ?? []) as $defect) {
                if (is_scalar($defect)) {
                    $normalizedDefects[] = ['defect_id' => (int) $defect];
                    continue;
                }
                if (is_array($defect)) {
                    $normalizedDefects[] = $defect;
                }
            }
            $payload['defects'] = $normalizedDefects;

            $rules = [
                'weighing_id' => 'required|is_natural_no_zero',
                'quality_grade' => 'permit_empty|max_length[20]',
                'defect_percent' => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[100]',
                'temperature_c' => 'permit_empty|decimal',
                'auto_grade' => 'permit_empty|in_list[0,1]',
                'disposition' => 'required|in_list[accepted,observed,hold,hold_lot,rejected]',
                'observations' => 'permit_empty|max_length[1000]',
                'defects' => 'permit_empty|is_array',
                'supersedes_inspection_id' => 'permit_empty|is_natural_no_zero',
            ];

            // Sólo exigimos defect_id cuando realmente existen defectos enviados.
            if ($normalizedDefects !== []) {
                $rules += [
                    'defects.*.defect_id' => 'required|is_natural_no_zero',
                    'defects.*.quantity' => 'permit_empty|decimal|greater_than_equal_to[0]',
                    'defects.*.unit' => 'permit_empty|max_length[20]',
                    'defects.*.notes' => 'permit_empty|max_length[255]',
                ];
            }

            $this->validatePayload($payload, $rules);

            $result = service('qualityService')->inspect($payload, $this->actorId());
            $disposition = (string) $payload['disposition'];

            if (in_array($disposition, ['accepted', 'observed'], true)) {
                return service('inventoryService')->applyQualityDecision(
                    (int) $result['id'],
                    $disposition,
                    $result['quality_grade'] ?? null,
                    $this->actorId(),
                    $payload['observations'] ?? null,
                );
            }

            if (auth()->user()->can('quality.approve')) {
                if ($disposition === 'hold_lot') {
                    return service('inventoryService')->applyQualityLotDecision(
                        (int) $result['id'],
                        'hold_lot',
                        $result['quality_grade'] ?? null,
                        $this->actorId(),
                        $payload['observations'] ?? null,
                    );
                }
                return service('inventoryService')->applyQualityDecision(
                    (int) $result['id'],
                    $disposition,
                    $result['quality_grade'] ?? null,
                    $this->actorId(),
                    $payload['observations'] ?? null,
                );
            }

            return $result + ['requires_approval' => true];
        }, 201);
    }

    public function approve(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('quality_inspection', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'disposition' => 'required|in_list[accepted,observed,released,hold,hold_lot,released_lot,rejected]',
                'quality_grade' => 'permit_empty|max_length[20]',
                'reason' => 'permit_empty|max_length[255]',
            ]);

            $disposition = (string) $payload['disposition'];
            if (in_array($disposition, ['hold_lot', 'released_lot'], true)) {
                return service('inventoryService')->applyQualityLotDecision(
                    $id,
                    $disposition,
                    $payload['quality_grade'] ?? null,
                    $this->actorId(),
                    $payload['reason'] ?? null,
                );
            }

            return service('inventoryService')->applyQualityDecision(
                $id,
                $disposition,
                $payload['quality_grade'] ?? null,
                $this->actorId(),
                $payload['reason'] ?? null,
            );
        });
    }
    public function annul(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('quality_inspection', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'reason' => 'required|min_length[5]|max_length[255]',
            ]);

            return service('inventoryService')->annulQualityInspection(
                $id,
                $this->actorId(),
                (string) $payload['reason'],
                (bool) (auth()->user()?->can('quality.approve') ?? false),
            );
        });
    }

}
