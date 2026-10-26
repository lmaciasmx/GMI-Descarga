<?php

namespace App\Modules\Weighing\Services;

use App\Common\Exceptions\DomainException;
use App\Modules\Weighing\DTO\WeightCalculation;

final class WeightCalculator
{
    public function calculate(float $grossWeightKg, float $tareKg, float $penaltyPercent): WeightCalculation
    {
        if ($grossWeightKg <= 0 || $tareKg <= 0 || $grossWeightKg <= $tareKg) {
            throw new DomainException('El peso bruto debe ser mayor que una tara positiva.', 'INVALID_WEIGHT_VALUES');
        }

        if ($penaltyPercent < 0 || $penaltyPercent > 100) {
            throw new DomainException('El castigo debe estar entre 0 y 100%.', 'INVALID_PENALTY');
        }

        $physicalNet = round($grossWeightKg - $tareKg, 3);
        $penaltyKg = round($physicalNet * $penaltyPercent / 100, 3);
        $acceptedNet = round($physicalNet - $penaltyKg, 3);

        return new WeightCalculation(
            round($grossWeightKg, 3),
            round($tareKg, 3),
            $physicalNet,
            round($penaltyPercent, 3),
            $penaltyKg,
            $acceptedNet,
        );
    }
}
