<?php

namespace Tests\Unit;

use App\Common\Exceptions\DomainException;
use App\Modules\Quality\Services\QualityRangeEvaluator;
use CodeIgniter\Test\CIUnitTestCase;

final class QualityRangeEvaluatorTest extends CIUnitTestCase
{
    public function testReturnsSingleMatchingGrade(): void
    {
        $ranges = ['AAA' => [0, 5], 'AA' => [6, 10], 'A' => [11, 14.999], 'B' => [15, 100]];
        self::assertSame('AA', (new QualityRangeEvaluator())->evaluate(8, $ranges));
    }

    public function testRejectsDocumentedOverlapAtFifteenPercent(): void
    {
        $ranges = ['AAA' => [0, 5], 'AA' => [6, 10], 'A' => [11, 15], 'B' => [15, 100]];
        try {
            (new QualityRangeEvaluator())->evaluate(15, $ranges);
            self::fail('El traslape no debe resolverse de forma implícita.');
        } catch (DomainException $exception) {
            self::assertSame('AMBIGUOUS_QUALITY_RANGE', $exception->domainCode);
            self::assertSame(['A', 'B'], $exception->context['matches']);
        }
    }
}
