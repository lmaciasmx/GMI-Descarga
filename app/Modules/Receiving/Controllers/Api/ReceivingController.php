<?php

namespace App\Modules\Receiving\Controllers\Api;

use App\Common\Controllers\ApiController;

final class ReceivingController extends ApiController
{
    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'company_id' => 'required|is_natural_no_zero',
                'site_id' => 'required|is_natural_no_zero',
                'warehouse_id' => 'required|is_natural_no_zero',
                'location_id' => 'required|is_natural_no_zero',
                'weighing_ids' => 'required|is_array',
                'weighing_ids.*' => 'required|is_natural_no_zero',
                'observations' => 'permit_empty|max_length[1000]',
            ]);
            return service('inventoryService')->receiveWeighings($payload, $this->actorId());
        }, 201);
    }
}
