<?php

namespace App\Modules\Weighing\DTO;

final readonly class WeightCalculation
{
    public function __construct(
        public float $grossWeightKg,
        public float $tareKg,
        public float $physicalNetKg,
        public float $penaltyPercent,
        public float $penaltyKg,
        public float $acceptedNetKg,
    ) {
    }

    public function toArray(): array
    {
        return [
            'gross_weight_kg' => $this->grossWeightKg,
            'tare_kg' => $this->tareKg,
            'physical_net_kg' => $this->physicalNetKg,
            'penalty_percent' => $this->penaltyPercent,
            'penalty_kg' => $this->penaltyKg,
            'accepted_net_kg' => $this->acceptedNetKg,
        ];
    }
}
