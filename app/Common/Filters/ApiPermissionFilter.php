<?php

namespace App\Common\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class ApiPermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $permission = (string) ($arguments[0] ?? '');

        if ($permission === '' || ! service('apiClientContext')->can($permission)) {
            $client = service('apiClientContext')->client();
            service('operationalLogger')->write('security', 'warning', 'api.permission_denied', ['client_id' => $client['client_id'] ?? null, 'permission' => $permission, 'ip' => $request->getIPAddress()]);
            return service('response')->setStatusCode(403)->setJSON([
                'success' => false,
                'data' => null,
                'meta' => [],
                'error' => ['code' => 'API_PERMISSION_DENIED', 'message' => 'El cliente no tiene permiso para este endpoint.', 'details' => []],
            ]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
