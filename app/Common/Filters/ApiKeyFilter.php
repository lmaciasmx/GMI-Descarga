<?php

namespace App\Common\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class ApiKeyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $clientId = trim((string) $request->getHeaderLine('X-API-Client'));
        if ($clientId === '') {
            $clientId = trim((string) $request->getHeaderLine('X-Client-Id'));
        }
        $clientId = mb_substr($clientId, 0, 100);
        $provided = trim((string) $request->getHeaderLine('X-API-Key'));
        $client = null;

        if ($clientId !== '') {
            try {
                $client = db_connect()->table('api_clients')->where(['client_id' => $clientId, 'active' => 1])->get()->getRowArray();
            } catch (\Throwable) {
                $client = null;
            }
        }

        if ($client !== null) {
            $configuredHash = (string) $client['key_hash'];
            $permissions = json_decode((string) $client['permissions'], true) ?: [];
        } else {
            $configuredHash = (string) env('api.keyHash', '');
            $environmentClient = (string) env('api.clientId', 'environment');
            $permissions = array_map('trim', explode(',', (string) env('api.permissions', '*')));

            if ($clientId === '' || ! hash_equals($environmentClient, $clientId)) {
                $configuredHash = '';
            }
        }

        if ($configuredHash === '' || $provided === '' || ! hash_equals($configuredHash, hash('sha256', $provided))) {
            log_message('warning', 'API authentication failed from {ip}', ['ip' => $request->getIPAddress()]);
            service('operationalLogger')->write('security', 'warning', 'api.authentication_failed', ['ip' => $request->getIPAddress(), 'client_id' => $clientId]);
            try {
                db_connect()->table('security_events')->insert([
                    'event_type' => 'api.authentication_failed', 'severity' => 'warning',
                    'ip_address' => $request->getIPAddress(), 'user_agent' => mb_substr((string) $request->getUserAgent(), 0, 255),
                    'outcome' => 'denied', 'safe_context' => json_encode(['client_id' => mb_substr($clientId, 0, 80)], JSON_THROW_ON_ERROR),
                    'occurred_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable) {
            }

            return service('response')->setStatusCode(401)->setJSON([
                'success' => false,
                'data' => null,
                'meta' => [],
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Credencial de integración inválida.', 'details' => []],
            ]);
        }

        service('apiClientContext')->authenticate([
            'id' => $client['id'] ?? null,
            'client_id' => $client['client_id'] ?? ((string) env('api.clientId', 'environment')),
            'permissions' => $permissions,
            'company_id' => $client['company_id'] ?? (($value = env('api.companyId')) !== null && $value !== '' ? (int) $value : null),
            'site_id' => $client['site_id'] ?? (($value = env('api.siteId')) !== null && $value !== '' ? (int) $value : null),
        ]);
        $authenticatedClient = service('apiClientContext')->client();
        service('scopeContext')->configure(
            $authenticatedClient['company_id'] === null ? null : (int) $authenticatedClient['company_id'],
            $authenticatedClient['site_id'] === null ? null : (int) $authenticatedClient['site_id'],
        );

        if ($client !== null) {
            db_connect()->table('api_clients')->where('id', $client['id'])->update(['last_used_at' => date('Y-m-d H:i:s')]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        service('apiClientContext')->clear();
        service('scopeContext')->clear();
    }
}
