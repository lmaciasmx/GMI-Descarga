<?php

namespace App\Modules\Inventory\DTO;

use App\Common\Domain\MovementType;

final readonly class CreateMovementData
{
    /** @param list<MovementLineData> $lines */
    public function __construct(
        public MovementType $type,
        public int $companyId,
        public int $siteId,
        public ?int $originWarehouseId,
        public ?int $destinationWarehouseId,
        public ?string $sourceDocumentType,
        public ?int $sourceDocumentId,
        public ?string $destinationDocumentType,
        public ?int $destinationDocumentId,
        public ?string $reasonCode,
        public ?string $observations,
        public int $actorUserId,
        public array $lines,
    ) {
    }

    public static function fromArray(array $data, int $actorUserId): self
    {
        return new self(
            MovementType::from((string) $data['movement_type']),
            (int) $data['company_id'],
            (int) $data['site_id'],
            isset($data['origin_warehouse_id']) ? (int) $data['origin_warehouse_id'] : null,
            isset($data['destination_warehouse_id']) ? (int) $data['destination_warehouse_id'] : null,
            isset($data['source_document_type']) ? (string) $data['source_document_type'] : null,
            isset($data['source_document_id']) ? (int) $data['source_document_id'] : null,
            isset($data['destination_document_type']) ? (string) $data['destination_document_type'] : null,
            isset($data['destination_document_id']) ? (int) $data['destination_document_id'] : null,
            isset($data['reason_code']) ? (string) $data['reason_code'] : null,
            isset($data['observations']) ? (string) $data['observations'] : null,
            $actorUserId,
            array_map(static fn (array $line): MovementLineData => MovementLineData::fromArray($line), $data['lines']),
        );
    }
}
