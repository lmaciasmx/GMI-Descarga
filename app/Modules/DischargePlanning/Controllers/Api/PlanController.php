<?php

namespace App\Modules\DischargePlanning\Controllers\Api;

use App\Common\Controllers\ApiController;

final class PlanController extends ApiController
{
    public function show(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('plan', $id);
            return service('dischargePlanService')->detail($id);
        });
    }

    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePlanPayload($payload);

            return service('dischargePlanService')->create(
                $payload,
                $this->actorId()
            );
        }, 201);
    }

    public function update(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('plan', $id);
            $payload = $this->payload();
            $this->validatePlanPayload($payload);

            return service('dischargePlanService')->update(
                $id,
                $payload,
                $this->actorId()
            );
        });
    }

    public function duplicate(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('plan', $id);

            return service('dischargePlanService')->duplicate(
                $id,
                $this->actorId()
            );
        }, 201);
    }

    public function transition(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('plan', $id);
            $payload = $this->payload();

            $this->validatePayload($payload, [
                'target' => 'required|in_list[draft,approved,in_progress,paused,finished,canceled]',
                'reason' => 'permit_empty|min_length[5]|max_length[500]',
            ]);

            return service('dischargePlanService')->transition(
                $id,
                (string) $payload['target'],
                $this->actorId(),
                isset($payload['reason']) ? (string) $payload['reason'] : null,
            );
        });
    }

    public function startFilling()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();

            $this->validatePayload($payload, [
                'plan_id' => 'required|is_natural_no_zero',
                'tub_id' => 'required|is_natural_no_zero',
                'actual_tank_id' => 'required|is_natural_no_zero',
                'species_id' => 'required|is_natural_no_zero',
                'size_grade_id' => 'required|is_natural_no_zero',
                'source_tank_ids' => 'required|is_array',
                'source_tank_ids.*' => 'required|is_natural_no_zero',
                'sorter_user_id' => 'permit_empty|is_natural_no_zero',
                'labeler_user_id' => 'permit_empty|is_natural_no_zero',
                'observations' => 'permit_empty|max_length[1000]',
            ]);

            return service('dischargePlanService')->startFilling(
                $payload,
                $this->actorId()
            );
        }, 201);
    }

    public function completeFilling(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('filling', $id);

            return service('dischargePlanService')->completeFilling(
                $id,
                $this->actorId()
            );
        });
    }

    private function validatePlanPayload(array $payload): void
    {
        $this->validatePayload($payload, [
            'voyage_id' => 'required|is_natural_no_zero',
            'rsa_id' => 'permit_empty|is_natural_no_zero',
            'planned_start_at' => 'permit_empty|valid_date[Y-m-d H:i:s]',
            'planned_end_at' => 'permit_empty|valid_date[Y-m-d H:i:s]',
            'notes' => 'permit_empty|max_length[1000]',
            'tank_lines' => 'required|is_array',
            'tank_lines.*.voyage_tank_id' => 'required|is_natural_no_zero',
            'tank_lines.*.species_id' => 'required|is_natural_no_zero',
            'tank_lines.*.size_grade_id' => 'permit_empty|is_natural_no_zero',
            'tank_lines.*.planned_weight_kg' => 'required|decimal|greater_than[0]',
            'tank_lines.*.msc_code' => 'required|max_length[12]',
            'tank_lines.*.dolphin_safe_code' => 'required|max_length[12]',
            'tank_lines.*.freezing_temperature_c' => 'required|decimal',
            'tank_lines.*.sequence_number' => 'permit_empty|is_natural_no_zero',
        ]);
    }
}
