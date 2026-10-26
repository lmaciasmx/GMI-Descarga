<?php

namespace App\Common\Filters;

use App\Common\Support\ActorContext;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class IdempotencyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $key = trim((string) $request->getHeaderLine('Idempotency-Key'));

        if ($key === '' || mb_strlen($key) < 8 || mb_strlen($key) > 128) {
            return service('response')->setStatusCode(400)->setJSON([
                'success' => false,
                'data' => null,
                'meta' => [],
                'error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED', 'message' => 'Envía Idempotency-Key de 8 a 128 caracteres.', 'details' => []],
            ]);
        }

        $client = service('apiClientContext')->client();
        $ownerKey = $client === null
            ? 'user:' . ActorContext::id($request)
            : 'client:' . (string) $client['client_id'];
        if ($ownerKey === 'user:0') {
            $ownerKey = 'ip:' . hash('sha256', $request->getIPAddress());
        }
        $path = $request->getUri()->getPath();
        $payloadHash = hash('sha256', (string) $request->getBody());
        $hash = hash('sha256', $ownerKey . '|' . $request->getMethod() . '|' . $path . '|' . $key);
        $db = db_connect();
        $existing = $db->table('api_idempotency_keys')->where('request_hash', $hash)->get()->getRowArray();

        if ($existing !== null) {
            if ($existing['expires_at'] < date('Y-m-d H:i:s')) {
                $db->table('api_idempotency_keys')->where('id', $existing['id'])->delete();
                $existing = null;
            }
        }

        if ($existing !== null) {
            if (! hash_equals((string) $existing['payload_hash'], $payloadHash)) {
                return service('response')->setStatusCode(409)->setJSON([
                    'success' => false,
                    'data' => null,
                    'meta' => [],
                    'error' => ['code' => 'IDEMPOTENCY_KEY_REUSED', 'message' => 'La llave ya fue usada con un contenido diferente.', 'details' => []],
                ]);
            }
            if ($existing['status'] === 'completed') {
                return service('response')
                    ->setStatusCode((int) $existing['response_status'])
                    ->setHeader('Idempotency-Replayed', 'true')
                    ->setJSON(json_decode((string) $existing['response_body'], true));
            }

            return service('response')->setStatusCode(409)->setJSON([
                'success' => false,
                'data' => null,
                'meta' => [],
                'error' => ['code' => 'REQUEST_IN_PROGRESS', 'message' => 'Ya existe una solicitud idéntica en proceso.', 'details' => []],
            ]);
        }

        try {
            $db->table('api_idempotency_keys')->insert([
                'request_hash' => $hash,
                'idempotency_key' => $key,
                'owner_key' => $ownerKey,
                'payload_hash' => $payloadHash,
                'method' => $request->getMethod(),
                'path' => $path,
                'status' => 'processing',
                'expires_at' => date('Y-m-d H:i:s', time() + DAY),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (DatabaseException) {
            return service('response')->setStatusCode(409)->setJSON([
                'success' => false,
                'data' => null,
                'meta' => [],
                'error' => ['code' => 'REQUEST_IN_PROGRESS', 'message' => 'La solicitud ya está siendo procesada.', 'details' => []],
            ]);
        }

        service('idempotencyContext')->begin($hash);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        $context = service('idempotencyContext');
        $hash = $context->requestHash();

        if (! is_string($hash) || $hash === '') {
            return;
        }

        $builder = db_connect()->table('api_idempotency_keys')->where('request_hash', $hash);
        if ($response->getStatusCode() >= 500) {
            $builder->delete();
        } else {
            $builder->update([
                'status' => 'completed',
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getBody(),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $context->clear();
    }
}
