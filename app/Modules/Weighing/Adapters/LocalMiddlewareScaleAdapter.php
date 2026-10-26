<?php

namespace App\Modules\Weighing\Adapters;

use App\Common\Exceptions\DomainException;
use App\Modules\Weighing\Contracts\ScaleAdapterInterface;
use App\Modules\Weighing\DTO\WeightReading;
use CodeIgniter\HTTP\CURLRequest;

class LocalMiddlewareScaleAdapter implements ScaleAdapterInterface
{
    public function __construct(
        protected readonly CURLRequest $client,
        protected readonly string $baseUrl,
        protected readonly string $apiKey,
        protected readonly string $transport = 'configured',
        protected readonly int $timeoutSeconds = 5,
    ) {
    }

    public function connect(): void
    {
        $this->request('POST', '/api/v1/scale/connect', ['transport' => $this->transport]);
    }

    public function disconnect(): void
    {
        $this->request('POST', '/api/v1/scale/disconnect', ['transport' => $this->transport]);
    }

    public function readWeight(): WeightReading
    {
        return $this->mapReading($this->request('GET', '/api/v1/scale/weight'));
    }

    public function readStableWeight(): WeightReading
    {
        return $this->mapReading($this->request('GET', '/api/v1/scale/weight/stable'));
    }

    public function getStatus(): array
    {
        return $this->request('GET', '/api/v1/scale/status');
    }

    public function getDeviceInformation(): array
    {
        return $this->request('GET', '/api/v1/scale/device');
    }

    protected function request(string $method, string $path, array $json = []): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new DomainException(
                'El middleware de báscula no está configurado.',
                'SCALE_MIDDLEWARE_NOT_CONFIGURED',
                ['transport' => $this->transport]
            );
        }

        $options = [
            'headers' => ['X-API-Key' => $this->apiKey, 'Accept' => 'application/json'],
            'timeout' => $this->timeoutSeconds,
            'http_errors' => false,
        ];

        if ($json !== []) {
            $options['json'] = $json;
        }

        try {
            $response = $this->client->request($method, rtrim($this->baseUrl, '/') . $path, $options);
            $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            log_message('error', 'Scale middleware failure ({exception}).', ['exception' => $exception::class]);
            service('operationalLogger')->write('scales', 'error', 'scale.middleware_connection_failed', ['transport' => $this->transport, 'exception' => $exception::class]);
            throw new DomainException('No fue posible comunicarse con la báscula.', 'SCALE_CONNECTION_FAILED', [], 0, $exception);
        }

        if ($response->getStatusCode() >= 400 || ! is_array($body)) {
            throw new DomainException('El middleware de báscula rechazó la operación.', 'SCALE_MIDDLEWARE_ERROR');
        }

        return $body['data'] ?? $body;
    }

    private function mapReading(array $data): WeightReading
    {
        foreach (['weight', 'unit', 'stable', 'captured_at', 'scale_identifier', 'original_value', 'connection_status'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new DomainException('Respuesta incompleta del middleware de báscula.', 'INVALID_SCALE_RESPONSE', ['missing' => $field]);
            }
        }

        $stable = filter_var($data['stable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (! is_numeric($data['weight']) || ! is_finite((float) $data['weight']) || (float) $data['weight'] <= 0 || strtolower(trim((string) $data['unit'])) !== 'kg' || $stable === null) {
            throw new DomainException('La lectura del middleware contiene peso, unidad o estabilidad inválidos.', 'INVALID_SCALE_RESPONSE');
        }
        try {
            $capturedAt = new \DateTimeImmutable((string) $data['captured_at']);
        } catch (\Throwable $exception) {
            throw new DomainException('La fecha de lectura del middleware no es válida.', 'INVALID_SCALE_RESPONSE', ['field' => 'captured_at'], 0, $exception);
        }
        if (trim((string) $data['scale_identifier']) === '' || trim((string) $data['original_value']) === '') {
            throw new DomainException('La lectura no contiene identidad o valor original.', 'INVALID_SCALE_RESPONSE');
        }

        return new WeightReading(
            (float) $data['weight'],
            'kg',
            $stable,
            $capturedAt,
            (string) $data['scale_identifier'],
            (string) $data['original_value'],
            (string) $data['connection_status'],
            'local_middleware:' . $this->transport,
        );
    }
}
