<?php

namespace App\Modules\Inventory\Controllers\Api;

use App\Common\Controllers\ApiController;

final class InventoryCountController extends ApiController
{
    public function start()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'warehouse_id' => 'required|is_natural_no_zero',
                'chamber_id' => 'permit_empty|is_natural_no_zero',
                'observations' => 'permit_empty|max_length[1000]',
            ]);
            return service('inventoryCountService')->start(
                (int) $payload['warehouse_id'],
                isset($payload['chamber_id']) && $payload['chamber_id'] !== '' ? (int) $payload['chamber_id'] : null,
                $this->actorId(),
                isset($payload['observations']) ? (string) $payload['observations'] : null,
            );
        }, 201);
    }

    public function lookup(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('inventory_count', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, ['code' => 'required|max_length[160]']);
            return service('inventoryCountService')->lookup($id, (string) $payload['code']);
        });
    }

    public function items(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('inventory_count', $id);
            $result = trim((string) ($this->request->getGet('result') ?? 'pending'));
            $search = trim((string) ($this->request->getGet('q') ?? ''));
            $page = max(1, (int) ($this->request->getGet('page') ?? 1));
            $perPage = max(10, min(100, (int) ($this->request->getGet('per_page') ?? 25)));
            return service('inventoryCountService')->listItems($id, $result, $search, $page, $perPage);
        });
    }

    public function record(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('inventory_count', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'stock_unit_id' => 'required|is_natural_no_zero',
                'row_location_id' => 'required|is_natural_no_zero',
            ]);
            return service('inventoryCountService')->record($id, (int) $payload['stock_unit_id'], (int) $payload['row_location_id'], $this->actorId());
        });
    }

    public function missing(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('inventory_count', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, ['stock_unit_id' => 'required|is_natural_no_zero']);
            return service('inventoryCountService')->markMissing($id, (int) $payload['stock_unit_id'], $this->actorId());
        });
    }

    public function finish(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('inventory_count', $id);
            return service('inventoryCountService')->finish($id, $this->actorId());
        });
    }

    public function reconcile(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('inventory_count', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, ['reason' => 'required|min_length[5]|max_length[500]']);
            return service('inventoryService')->reconcileCount($id, (string) $payload['reason'], $this->actorId());
        }, 201);
    }
}
