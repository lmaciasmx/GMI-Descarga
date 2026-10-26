<?php

namespace App\Modules\Quality\Contracts;

interface QualityRuleStrategyInterface
{
    public function grade(float $defectPercent, int $companyId, int $siteId): string;
}
