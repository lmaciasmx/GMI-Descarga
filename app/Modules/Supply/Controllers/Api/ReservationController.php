<?php

namespace App\Modules\Supply\Controllers\Api;

use App\Common\Controllers\ApiController;

final class ReservationController extends ApiController
{
    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'request_id' => 'required|is_natural_no_zero',
                'selections' => 'required|is_array',
                'selections.*.request_line_id' => 'required|is_natural_no_zero',
                'selections.*.stock_unit_id' => 'required|is_natural_no_zero',
                'selections.*.quantity_kg' => 'required|decimal|greater_than[0]',
                'expires_at' => 'permit_empty|valid_date[Y-m-d H:i:s]',
            ]);
            return service('inventoryService')->reserveForRequest((int) $payload['request_id'], (array) $payload['selections'], $this->actorId(), $payload['expires_at'] ?? null);
        }, 201);
    }

    public function release(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('reservation', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, ['reason' => 'required|min_length[5]|max_length[255]']);
            return service('inventoryService')->releaseReservation($id, (string) $payload['reason'], $this->actorId());
        });
    }

    public function fulfill(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('reservation', $id);
            return service('inventoryService')->fulfillReservation($id, $this->actorId());
        }, 201);
    }
}
