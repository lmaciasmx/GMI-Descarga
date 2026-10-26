<?php

namespace App\Modules\Weighing\Controllers\Api;

use App\Common\Controllers\ApiController;
use App\Common\Exceptions\DomainException;
use App\Modules\Weighing\DTO\WeightReading;

final class ScaleController extends ApiController
{
    public function status()
    {
        return $this->execute(function (): array {
            $adapter = service('scaleAdapter');
            return ['status' => $adapter->getStatus(), 'device' => $adapter->getDeviceInformation()];
        });
    }

    public function captureLocal()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();

            $this->validatePayload(
                $payload,
                ['scale_id' => 'required|is_natural_no_zero']
            );

            $scale = $this->resolveScale((int) $payload['scale_id']);

            if (! array_key_exists('reading', $payload)) {
                throw new DomainException(
                    'Debes enviar la lectura del agente local.',
                    'LOCAL_SCALE_READING_REQUIRED'
                );
            }

            $reading = $this->localAgentReading($scale, $payload['reading']);

            return service('scaleReadingService')->capture(
                $reading,
                (int) $scale['id'],
                $this->actorId()
            );
        });
    }

    public function readStable()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, ['scale_id' => 'permit_empty|is_natural_no_zero']);
            $scale = $this->resolveScale(isset($payload['scale_id']) ? (int) $payload['scale_id'] : null);

            if (isset($payload['local_agent_reading'])) {
                $reading = $this->localAgentReading($scale, $payload['local_agent_reading']);

                return service('scaleReadingService')->capture(
                    $reading,
                    (int) $scale['id'],
                    $this->actorId()
                );
            }

            $this->assertConfiguredAdapter($scale);
            $adapter = service('scaleAdapter');
            $adapter->connect();

            try {
                $reading = $adapter->readStableWeight();

                return service('scaleReadingService')->capture(
                    $reading,
                    (int) $scale['id'],
                    $this->actorId()
                );
            } finally {
                try {
                    $adapter->disconnect();
                } catch (\Throwable $exception) {
                    log_message(
                        'warning',
                        'No fue posible cerrar la conexión con la báscula ({exception}).',
                        ['exception' => $exception::class]
                    );
                }
            }
        });
    }

    private function resolveScale(?int $requestedId): array
    {
        if ($requestedId !== null) {
            $scale = db_connect()->table('scales')
                ->select(
                    'id, site_id, code, name, station_code, adapter_key, connection_type, '
                    . 'agent_url, port_name'
                )
                ->where(['id' => $requestedId, 'active' => 1])
                ->get()
                ->getRowArray();
            if ($scale === null) {
                throw new DomainException('No existe una báscula activa con el identificador indicado.', 'SCALE_NOT_AVAILABLE');
            }

            return $scale;
        }

        $scope = service('scopeContext')->restrictions();
        if ($scope['site_id'] === null) {
            throw new DomainException('Debes indicar scale_id cuando el cliente no tiene una instalación fija.', 'SCALE_ID_REQUIRED');
        }

        $scale = db_connect()->table('scales')
            ->select(
                'id, site_id, code, name, station_code, adapter_key, connection_type, '
                . 'agent_url, port_name'
            )
            ->where(['site_id' => $scope['site_id'], 'active' => 1])
            ->orderBy('is_default', 'DESC')
            ->orderBy('id')
            ->get()
            ->getRowArray();

        if ($scale === null) {
            throw new DomainException('No existe una báscula activa en la instalación seleccionada.', 'SCALE_NOT_AVAILABLE');
        }

        return $scale;
    }

    private function localAgentReading(array $scale, mixed $payload): WeightReading
    {
        $connection = strtolower((string) ($scale['connection_type'] ?? ''));
        $adapter = strtolower((string) ($scale['adapter_key'] ?? ''));
        $agentUrl = trim((string) ($scale['agent_url'] ?? ''));
        $portName = strtoupper(trim((string) ($scale['port_name'] ?? '')));

        if (
            ! in_array($connection, ['usb_serial', 'rs232'], true)
            || $adapter !== $connection
            || $agentUrl === ''
            || $portName === ''
        ) {
            throw new DomainException(
                'La báscula seleccionada no está configurada para lectura mediante agente serial local.',
                'LOCAL_SCALE_AGENT_NOT_CONFIGURED',
                ['scale_id' => (int) $scale['id']]
            );
        }

        $host = strtolower((string) parse_url($agentUrl, PHP_URL_HOST));
        if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new DomainException(
                'El agente serial local debe estar configurado en localhost.',
                'LOCAL_SCALE_AGENT_URL_INVALID',
                ['scale_id' => (int) $scale['id']]
            );
        }

        if (! is_array($payload)) {
            throw new DomainException(
                'La lectura del agente local no tiene el formato esperado.',
                'INVALID_LOCAL_SCALE_READING'
            );
        }

        foreach ([
            'weight',
            'unit',
            'stable',
            'captured_at',
            'scale_identifier',
            'original_value',
            'connection_status',
        ] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new DomainException(
                    'La lectura del agente local está incompleta.',
                    'INVALID_LOCAL_SCALE_READING',
                    ['missing' => $field]
                );
            }
        }

        $stable = filter_var(
            $payload['stable'],
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        $weight = is_numeric($payload['weight'])
            ? (float) $payload['weight']
            : 0.0;

        if (
            ! is_finite($weight)
            || $weight <= 0
            || strtolower(trim((string) $payload['unit'])) !== 'kg'
            || $stable !== true
        ) {
            throw new DomainException(
                'La lectura local debe contener un peso estable, positivo y expresado en kg.',
                'INVALID_LOCAL_SCALE_READING'
            );
        }

        $expectedIdentifier = trim((string) ($scale['station_code'] ?? ''));
        if ($expectedIdentifier === '') {
            $expectedIdentifier = trim((string) ($scale['code'] ?? ''));
        }

        $receivedIdentifier = trim((string) $payload['scale_identifier']);
        if (
            $expectedIdentifier === ''
            || ! hash_equals($expectedIdentifier, $receivedIdentifier)
        ) {
            throw new DomainException(
                'La lectura recibida no corresponde a la báscula seleccionada.',
                'LOCAL_SCALE_IDENTIFIER_MISMATCH',
                ['scale_id' => (int) $scale['id']]
            );
        }

        $raw = trim((string) $payload['original_value']);
        if ($raw === '' || mb_strlen($raw) > 255) {
            throw new DomainException(
                'El valor original de la báscula no es válido.',
                'INVALID_LOCAL_SCALE_READING'
            );
        }

        try {
            $capturedAt = new \DateTimeImmutable((string) $payload['captured_at']);
        } catch (\Throwable $exception) {
            throw new DomainException(
                'La fecha de la lectura local no es válida.',
                'INVALID_LOCAL_SCALE_READING',
                ['field' => 'captured_at'],
                0,
                $exception
            );
        }

        $connectionStatus = trim((string) $payload['connection_status']);
        if ($connectionStatus === '' || mb_strlen($connectionStatus) > 30) {
            throw new DomainException(
                'El estado de conexión de la lectura local no es válido.',
                'INVALID_LOCAL_SCALE_READING'
            );
        }

        return new WeightReading(
            $weight,
            'kg',
            true,
            $capturedAt,
            $receivedIdentifier,
            $raw,
            $connectionStatus,
            'local_agent:' . $connection,
        );
    }

    private function assertConfiguredAdapter(array $scale): void
    {
        $driver = strtolower((string) env('integrations.scale.driver', 'simulator'));
        if ((string) $scale['adapter_key'] !== $driver || (string) $scale['connection_type'] !== $driver) {
            throw new DomainException(
                'La báscula seleccionada no corresponde al driver activo de esta estación.',
                'SCALE_DRIVER_MISMATCH',
                ['scale_id' => (int) $scale['id']]
            );
        }
    }
}
