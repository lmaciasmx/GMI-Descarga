<?php

namespace App\Modules\Weighing\DTO;

final readonly class WeightReading
{
    public function __construct(
        public float $weight,
        public string $unit,
        public bool $stable,
        public \DateTimeImmutable $capturedAt,
        public string $scaleIdentifier,
        public string $originalValue,
        public string $connectionStatus,
        public string $source,
    ) {
    }

    public function toArray(): array
    {
        return [
            'weight' => $this->weight,
            'unit' => $this->unit,
            'stable' => $this->stable,
            'captured_at' => $this->capturedAt->format(DATE_ATOM),
            'scale_identifier' => $this->scaleIdentifier,
            'original_value' => $this->originalValue,
            'connection_status' => $this->connectionStatus,
            'source' => $this->source,
        ];
    }
}
