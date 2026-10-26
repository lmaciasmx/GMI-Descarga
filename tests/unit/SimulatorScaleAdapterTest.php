<?php

namespace Tests\Unit;

use App\Modules\Weighing\Adapters\SimulatorScaleAdapter;
use CodeIgniter\Test\CIUnitTestCase;

final class SimulatorScaleAdapterTest extends CIUnitTestCase
{
    public function testSimulatorIsExplicitAndStableReadIsDeterministic(): void
    {
        $adapter = new SimulatorScaleAdapter(975.250);
        $adapter->connect();
        $reading = $adapter->readStableWeight();
        self::assertSame(975.250, $reading->weight);
        self::assertTrue($reading->stable);
        self::assertSame('simulator', $reading->source);
        self::assertTrue($adapter->getDeviceInformation()['simulated']);
    }
}
