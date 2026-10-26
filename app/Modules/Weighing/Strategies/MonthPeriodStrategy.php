<?php

namespace App\Modules\Weighing\Strategies;

use App\Modules\Weighing\Contracts\PeriodComponentStrategyInterface;

final class MonthPeriodStrategy implements PeriodComponentStrategyInterface
{
    public function component(\DateTimeInterface $date): string
    {
        return $date->format('m');
    }

    public function key(): string
    {
        return 'month';
    }
}
