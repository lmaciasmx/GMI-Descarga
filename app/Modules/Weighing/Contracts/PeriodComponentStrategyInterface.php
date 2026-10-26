<?php

namespace App\Modules\Weighing\Contracts;

interface PeriodComponentStrategyInterface
{
    public function component(\DateTimeInterface $date): string;

    public function key(): string;
}
