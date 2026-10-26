<?php

namespace App\Common\Controllers;

use App\Common\Exceptions\DomainException;
use App\Common\Support\ActorContext;
use App\Common\Support\ApiResponse;
use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

abstract class ApiController extends BaseController
{
    use ApiResponse;

    protected function payload(): array
    {
        $payload = $this->request->getJSON(true);

        if (! is_array($payload)) {
            throw new DomainException('El cuerpo debe ser JSON válido.', 'INVALID_JSON');
        }

        service('scopeContext')->assertPayload($payload);

        return $payload;
    }

    protected function actorId(): int
    {
        return ActorContext::id($this->request);
    }

    protected function assertAggregateScope(string $type, int $id): void
    {
        service('scopeContext')->assertAggregate($type, $id);
    }

    protected function validatePayload(array $payload, array $rules): void
    {
        $validation = service('validation');
        $validation->reset();
        $validation->setRules($rules);

        if (! $validation->run($payload)) {
            throw new DomainException('Revisa los datos enviados.', 'VALIDATION_FAILED', $validation->getErrors());
        }
    }

    protected function execute(callable $callback, int $successStatus = 200): ResponseInterface
    {
        try {
            $data = $callback();
            return $this->withCsrf($this->success(is_array($data) ? $data : ['result' => $data], $successStatus));
        } catch (DomainException $exception) {
            return $this->withCsrf($this->failure($exception->domainCode, $exception->getMessage(), $this->statusFor($exception->domainCode), $exception->context));
        } catch (\Throwable $exception) {
            log_message('error', 'Unhandled API exception {exception} at {path}.', [
                'exception' => $exception::class,
                'path' => $this->request->getUri()->getPath(),
            ]);
            service('operationalLogger')->write('errors', 'error', 'api.unhandled_exception', [
                'exception' => $exception::class,
                'path' => $this->request->getUri()->getPath(),
                'method' => $this->request->getMethod(),
            ]);
            return $this->withCsrf($this->failure('INTERNAL_ERROR', 'No fue posible completar la operación.', 500));
        }
    }

    private function withCsrf(ResponseInterface $response): ResponseInterface
    {
        return function_exists('csrf_hash') ? $response->setHeader('X-CSRF-TOKEN', csrf_hash()) : $response;
    }

    private function statusFor(string $code): int
    {
        if (str_contains($code, 'NOT_FOUND')) {
            return 404;
        }
        if (str_contains($code, 'PERMISSION') || str_contains($code, 'SCOPE')) {
            return 403;
        }
        if (str_contains($code, 'DUPLICATE') || str_contains($code, 'ALREADY') || str_contains($code, 'CONCURRENT') || str_contains($code, 'STALE')) {
            return 409;
        }
        return 422;
    }
}
