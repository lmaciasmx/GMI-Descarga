<?php

namespace Tests\Unit;

use App\Modules\Weighing\Strategies\JulianDayPeriodStrategy;
use App\Modules\Weighing\Strategies\MonthPeriodStrategy;
use CodeIgniter\Test\CIUnitTestCase;

final class LotPeriodStrategyTest extends CIUnitTestCase
{
    public function testMonthStrategyIsTwoDigits(): void
    {
        self::assertSame('09', (new MonthPeriodStrategy())->component(new \DateTimeImmutable('2026-09-13')));
    }

    public function testJulianStrategyAccountsForLeapYear(): void
    {
        self::assertSame('060', (new JulianDayPeriodStrategy())->component(new \DateTimeImmutable('2024-02-29')));
        self::assertSame('365', (new JulianDayPeriodStrategy())->component(new \DateTimeImmutable('2025-12-31')));
    }
}
