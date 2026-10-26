<?php

namespace App\Modules\Sales\Controllers\Api;

use App\Common\Controllers\ApiController;

final class SalesController extends ApiController
{
    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'company_id' => 'required|is_natural_no_zero',
                'site_id' => 'required|is_natural_no_zero',
                'customer_company_id' => 'required|is_natural_no_zero',
                'required_at' => 'permit_empty|valid_date[Y-m-d H:i:s]',
                'observations' => 'permit_empty|max_length[1000]',
                'lines' => 'required|is_array',
                'lines.*.stock_unit_id' => 'required|is_natural_no_zero',
                'lines.*.quantity_kg' => 'required|decimal|greater_than[0]',
                'lines.*.unit_price' => 'permit_empty|decimal|greater_than_equal_to[0]',
                'lines.*.currency' => 'permit_empty|exact_length[3]|alpha',
            ]);
            return service('salesService')->create($payload, $this->actorId());
        }, 201);
    }

    public function dispatch(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('sales_order', $id);
            return service('inventoryService')->dispatchSale($id, $this->actorId());
        });
    }
}
