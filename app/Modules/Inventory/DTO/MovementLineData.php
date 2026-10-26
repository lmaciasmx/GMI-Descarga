<?php

namespace App\Modules\Inventory\DTO;

final readonly class MovementLineData
{
    public function __construct(
        public int $stockUnitId,
        public float $quantityKg,
        public ?int $fromLocationId = null,
        public ?int $toLocationId = null,
        public ?int $fromWarehouseId = null,
        public ?int $toWarehouseId = null,
        public ?string $statusAfter = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['stock_unit_id'],
            (float) $data['quantity_kg'],
            isset($data['from_location_id']) ? (int) $data['from_location_id'] : null,
            isset($data['to_location_id']) ? (int) $data['to_location_id'] : null,
            isset($data['from_warehouse_id']) ? (int) $data['from_warehouse_id'] : null,
            isset($data['to_warehouse_id']) ? (int) $data['to_warehouse_id'] : null,
            isset($data['status_after']) ? (string) $data['status_after'] : null,
        );
    }
}
