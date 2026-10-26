<?php

namespace App\Modules\Quality\Strategies;

use App\Common\Exceptions\DomainException;
use App\Common\Services\SettingsService;
use App\Modules\Quality\Contracts\QualityRuleStrategyInterface;
use App\Modules\Quality\Services\QualityRangeEvaluator;

final class ConfigurableQualityRuleStrategy implements QualityRuleStrategyInterface
{
    public function __construct(private readonly SettingsService $settings, private readonly QualityRangeEvaluator $evaluator = new QualityRangeEvaluator())
    {
    }

    public function grade(float $defectPercent, int $companyId, int $siteId): string
    {
        $active = (bool) $this->settings->get('quality.preliminary_rules_active', $companyId, $siteId, false);

        if (! $active) {
            throw new DomainException(
                'La clasificación automática de calidad está pendiente de validación.',
                'QUALITY_RULES_NOT_APPROVED'
            );
        }

        $ranges = (array) $this->settings->get('quality.preliminary_ranges', $companyId, $siteId, []);
        return $this->evaluator->evaluate($defectPercent, $ranges);
    }
}
