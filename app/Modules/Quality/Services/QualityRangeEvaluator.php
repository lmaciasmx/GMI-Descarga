<?php

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\DomainException;

final class QualityRangeEvaluator
{
    public function evaluate(float $defectPercent, array $ranges): string
    {
        if ($defectPercent < 0 || $defectPercent > 100) {
            throw new DomainException('El porcentaje de defecto debe estar entre 0 y 100.', 'INVALID_QUALITY_PERCENT');
        }

        $matches = [];
        foreach ($ranges as $grade => $range) {
            if (is_array($range) && count($range) === 2 && $defectPercent >= (float) $range[0] && $defectPercent <= (float) $range[1]) {
                $matches[] = (string) $grade;
            }
        }

        if (count($matches) !== 1) {
            throw new DomainException(
                'Los rangos de calidad son ambiguos o no cubren el valor.',
                'AMBIGUOUS_QUALITY_RANGE',
                ['defect_percent' => $defectPercent, 'matches' => $matches]
            );
        }

        return $matches[0];
    }
}
