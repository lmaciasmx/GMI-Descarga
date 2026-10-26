<?php

namespace App\Modules\Weighing\Controllers\Api;

use App\Common\Controllers\ApiController;
use App\Common\Exceptions\DomainException;
use App\Modules\Weighing\DTO\CreateWeighingData;

final class WeighingController extends ApiController
{
    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'filling_id' => 'required|is_natural_no_zero',
                'gross_weight_kg' => 'required|decimal|greater_than[0]',
                'tare_source' => 'required|in_list[catalog,painted,estimated]',
                'penalty_percent' => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[100]',
                'weight_source' => 'required|in_list[scale,middleware,manual,simulator]',
                'scale_id' => 'permit_empty|is_natural_no_zero',
                'scale_reading_id' => 'permit_empty|is_natural_no_zero',
                'lot_id' => 'permit_empty|is_natural_no_zero',
                'rsa_id' => 'permit_empty|is_natural_no_zero',
                'certification' => 'permit_empty|max_length[20]',
                'declared_tare_kg' => 'permit_empty|decimal|greater_than[0]',
                'stable' => 'permit_empty|in_list[0,1,true,false]',
                'original_value' => 'permit_empty|max_length[255]',
                'device_timestamp' => 'permit_empty|max_length[40]',
                'reason_code' => 'permit_empty|max_length[50]',
                'observations' => 'permit_empty|max_length[1000]',
            ]);

            if (($payload['weight_source'] ?? '') === 'manual') {
                $userCan = function_exists('auth') && auth()->loggedIn() && auth()->user()->can('weighings.manual');
                $apiCan = service('apiClientContext')->can('weighings.manual');
                if (! $userCan && ! $apiCan) {
                    throw new DomainException('No tienes permiso para captura manual.', 'PERMISSION_DENIED');
                }
            }

            $weighing = service('weighingService')->confirm(CreateWeighingData::fromArray($payload, $this->actorId()));
            return $weighing->toRawArray();
        }, 201);
    }

    public function reverse(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('weighing', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, ['reason' => 'required|min_length[5]|max_length[255]']);
            service('weighingService')->reverse($id, (string) $payload['reason'], $this->actorId());
            return ['id' => $id, 'status' => 'reversed'];
        });
    }
}
