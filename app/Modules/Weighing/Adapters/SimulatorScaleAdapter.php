<?php

namespace App\Modules\Weighing\Adapters;

use App\Modules\Weighing\Contracts\ScaleAdapterInterface;
use App\Modules\Weighing\DTO\WeightReading;

final class SimulatorScaleAdapter implements ScaleAdapterInterface
{
    private bool $connected = false;

    public function __construct(
        private readonly float $weightKg = 986.500,
        private readonly string $identifier = 'SIMULATOR-NOT-A-REAL-SCALE'
    ) {
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function readWeight(): WeightReading
    {
        $this->ensureConnected();
        $value = round($this->weightKg + random_int(-25, 25) / 100, 3);

        return $this->reading($value, false);
    }

    public function readStableWeight(): WeightReading
    {
        $this->ensureConnected();

        return $this->reading($this->weightKg, true);
    }

    public function getStatus(): array
    {
        return [
            'connected' => $this->connected,
            'status' => $this->connected ? 'simulated_connected' : 'simulated_disconnected',
            'simulated' => true,
        ];
    }

    public function getDeviceInformation(): array
    {
        return [
            'identifier' => $this->identifier,
            'manufacturer' => 'SIMULATOR',
            'model' => 'DEVELOPMENT ONLY',
            'protocol' => 'in_memory',
            'simulated' => true,
        ];
    }

    private function ensureConnected(): void
    {
        if (! $this->connected) {
            $this->connect();
        }
    }

    private function reading(float $weight, bool $stable): WeightReading
    {
        $raw = sprintf('SIM|%.3f|kg|%s', $weight, $stable ? 'STABLE' : 'UNSTABLE');

        return new WeightReading(
            $weight,
            'kg',
            $stable,
            new \DateTimeImmutable(),
            $this->identifier,
            $raw,
            'connected',
            'simulator',
        );
    }
}
