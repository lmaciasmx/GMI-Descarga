<?php

namespace Tests\Unit;

use App\Common\Exceptions\DomainException;
use App\Modules\Weighing\Services\WeightCalculator;
use CodeIgniter\Test\CIUnitTestCase;

final class WeightCalculatorTest extends CIUnitTestCase
{
    public function testCalculatesPhysicalNetPenaltyAndAcceptedNetSeparately(): void
    {
        $result = (new WeightCalculator())->calculate(1000.000, 176.000, 5.000);
        self::assertSame(824.000, $result->physicalNetKg);
        self::assertSame(41.200, $result->penaltyKg);
        self::assertSame(782.800, $result->acceptedNetKg);
        self::assertSame(824.000, $result->physicalNetKg, 'El castigo no debe sobrescribir el neto físico.');
    }

    public function testRoundsEveryOperationalResultToThreeDecimals(): void
    {
        $result = (new WeightCalculator())->calculate(999.9994, 176.0001, 3.333);
        self::assertSame(round(999.9994 - 176.0001, 3), $result->physicalNetKg);
        self::assertSame(round($result->physicalNetKg * 3.333 / 100, 3), $result->penaltyKg);
    }

    public function testRejectsGrossWeightBelowTare(): void
    {
        $this->expectException(DomainException::class);
        (new WeightCalculator())->calculate(175, 176, 0);
    }

    public function testRejectsPenaltyOutsidePercentageRange(): void
    {
        $this->expectException(DomainException::class);
        (new WeightCalculator())->calculate(1000, 176, 176);
    }
}
