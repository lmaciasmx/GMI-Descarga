<?php

namespace App\Modules\Supply\Controllers\Api;

use App\Common\Controllers\ApiController;
use App\Common\Exceptions\DomainException;

final class RequestController extends ApiController
{
    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'company_id' => 'required|is_natural_no_zero',
                'site_id' => 'required|is_natural_no_zero',
                'request_type' => 'permit_empty|in_list[production,warehouse_transfer]',
                'destination' => 'required|min_length[2]|max_length[120]',
                'destination_warehouse_id' => 'permit_empty|is_natural_no_zero',
                'required_at' => 'required|valid_date[Y-m-d H:i:s]',
                'priority' => 'permit_empty|in_list[low,normal,high,urgent]',
                'observations' => 'permit_empty|max_length[1000]',
                'lines' => 'required|is_array',
                'lines.*.species_id' => 'required|is_natural_no_zero',
                'lines.*.size_grade_id' => 'permit_empty|is_natural_no_zero',
                'lines.*.quality_grade' => 'permit_empty|max_length[20]',
                'lines.*.requested_weight_kg' => 'required|decimal|greater_than[0]',
            ]);
            return service('requestService')->create($payload, $this->actorId());
        }, 201);
    }

    public function authorize(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('request', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, ['reason' => 'permit_empty|max_length[255]']);
            if (! array_key_exists('approved', $payload)
                || (! is_bool($payload['approved']) && ! in_array(strtolower((string) $payload['approved']), ['0', '1', 'true', 'false'], true))) {
                throw new DomainException('El campo approved debe ser booleano.', 'VALIDATION_FAILED', ['approved' => 'Valor booleano inválido.']);
            }
            return service('requestService')->authorize($id, filter_var($payload['approved'], FILTER_VALIDATE_BOOLEAN), $this->actorId(), $payload['reason'] ?? null);
        });
    }

    public function availability(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('request', $id);
            return service('requestService')->availability($id);
        });
    }
}
