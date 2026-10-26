<?php

namespace App\Modules\Weighing\DTO;

final readonly class CreateWeighingData
{
    public function __construct(
        public int $fillingId,
        public ?int $lotId,
        public ?string $certification,
        public ?int $rsaId,
        public ?int $scaleId,
        public ?int $scaleReadingId,
        public float $grossWeightKg,
        public ?float $declaredTareKg,
        public string $tareSource,
        public float $penaltyPercent,
        public string $weightSource,
        public bool $stable,
        public ?string $originalValue,
        public ?string $deviceTimestamp,
        public ?string $reasonCode,
        public ?string $observations,
        public int $actorUserId,
    ) {
    }

    public static function fromArray(array $data, int $actorUserId): self
    {
        return new self(
            (int) $data['filling_id'],
            isset($data['lot_id']) ? (int) $data['lot_id'] : null,
            isset($data['certification']) ? (string) $data['certification'] : null,
            isset($data['rsa_id']) ? (int) $data['rsa_id'] : null,
            isset($data['scale_id']) ? (int) $data['scale_id'] : null,
            isset($data['scale_reading_id']) ? (int) $data['scale_reading_id'] : null,
            (float) $data['gross_weight_kg'],
            isset($data['declared_tare_kg']) ? (float) $data['declared_tare_kg'] : null,
            (string) $data['tare_source'],
            (float) ($data['penalty_percent'] ?? 0),
            (string) $data['weight_source'],
            filter_var($data['stable'] ?? false, FILTER_VALIDATE_BOOLEAN),
            isset($data['original_value']) ? (string) $data['original_value'] : null,
            isset($data['device_timestamp']) ? (string) $data['device_timestamp'] : null,
            isset($data['reason_code']) ? (string) $data['reason_code'] : null,
            isset($data['observations']) ? (string) $data['observations'] : null,
            $actorUserId,
        );
    }
}
