<?php

namespace App\Modules\Labeling\Adapters;

use App\Common\Exceptions\DomainException;
use App\Modules\Labeling\Contracts\LabelPrinterInterface;
use App\Modules\Labeling\DTO\PrintResult;
use CodeIgniter\HTTP\CURLRequest;

class LocalMiddlewareLabelPrinter implements LabelPrinterInterface
{
    public function __construct(
        private readonly CURLRequest $client,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    public function printLabel(array $label): PrintResult
    {
        return $this->send('/api/v1/labels/print', ['label' => $label]);
    }

    public function reprintLabel(array $label, string $reason): PrintResult
    {
        return $this->send('/api/v1/labels/reprint', ['label' => $label, 'reason' => $reason]);
    }

    public function getPrinterStatus(): array
    {
        return $this->request('GET', '/api/v1/printer/status');
    }

    public function cancelPrintJob(string $jobId): bool
    {
        $result = $this->request('POST', '/api/v1/labels/cancel', ['job_id' => $jobId]);

        return (bool) ($result['canceled'] ?? false);
    }

    private function send(string $path, array $payload): PrintResult
    {
        $data = $this->request('POST', $path, $payload);

        return new PrintResult(
            (bool) ($data['successful'] ?? false),
            (string) ($data['status'] ?? 'unknown'),
            isset($data['provider_reference']) ? (string) $data['provider_reference'] : null,
            null,
            isset($data['error_message']) ? (string) $data['error_message'] : null,
        );
    }

    private function request(string $method, string $path, array $json = []): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new DomainException('El servicio local de impresión no está configurado.', 'PRINTER_MIDDLEWARE_NOT_CONFIGURED');
        }

        try {
            $response = $this->client->request($method, rtrim($this->baseUrl, '/') . $path, [
                'headers' => ['X-API-Key' => $this->apiKey, 'Accept' => 'application/json'],
                'json' => $json,
                'timeout' => $this->timeoutSeconds,
                'http_errors' => false,
            ]);
            $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            log_message('error', 'Printer middleware failure ({exception}).', ['exception' => $exception::class]);
            service('operationalLogger')->write('printing', 'error', 'printer.middleware_connection_failed', ['exception' => $exception::class]);
            throw new DomainException('No fue posible comunicarse con la impresora.', 'PRINTER_CONNECTION_FAILED', [], 0, $exception);
        }

        if ($response->getStatusCode() >= 400 || ! is_array($body)) {
            throw new DomainException('El servicio de impresión rechazó la operación.', 'PRINTER_MIDDLEWARE_ERROR');
        }

        return $body['data'] ?? $body;
    }
}
