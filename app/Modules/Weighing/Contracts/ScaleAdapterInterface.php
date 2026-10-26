<?php

namespace App\Modules\Weighing\Contracts;

use App\Modules\Weighing\DTO\WeightReading;

interface ScaleAdapterInterface
{
    public function connect(): void;

    public function disconnect(): void;

    public function readWeight(): WeightReading;

    public function readStableWeight(): WeightReading;

    public function getStatus(): array;

    public function getDeviceInformation(): array;
}
