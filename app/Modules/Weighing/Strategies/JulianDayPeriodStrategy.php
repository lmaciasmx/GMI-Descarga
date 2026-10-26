<?php

namespace App\Modules\Weighing\Strategies;

use App\Modules\Weighing\Contracts\PeriodComponentStrategyInterface;

final class JulianDayPeriodStrategy implements PeriodComponentStrategyInterface
{
    public function component(\DateTimeInterface $date): string
    {
        return str_pad((string) ((int) $date->format('z') + 1), 3, '0', STR_PAD_LEFT);
    }

    public function key(): string
    {
        return 'julian_day';
    }
}
