<?php

namespace App\Common\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class RateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $clientLimit = max(1, (int) env('api.rateLimitPerMinute', 120));
        $ipLimit = max($clientLimit, (int) env('api.ipRateLimitPerMinute', $clientLimit * 5));
        $ip = $request->getIPAddress();
        $clientId = mb_substr(trim((string) ($request->getHeaderLine('X-API-CLIENT') ?: $request->getHeaderLine('X-Client-Id'))), 0, 100);
        $throttler = service('throttler');
        $ipAllowed = $throttler->check('api-ip-' . hash('sha256', $ip), $ipLimit, MINUTE);
        $clientAllowed = $clientId === '' || $throttler->check('api-client-' . hash('sha256', $clientId), $clientLimit, MINUTE);

        if (! $ipAllowed || ! $clientAllowed) {
            service('operationalLogger')->write('security', 'warning', 'api.rate_limited', ['ip' => $request->getIPAddress()]);
            return service('response')
                ->setHeader('Retry-After', '60')
                ->setStatusCode(429)
                ->setJSON([
                    'success' => false,
                    'data' => null,
                    'meta' => [],
                    'error' => ['code' => 'RATE_LIMITED', 'message' => 'Límite temporal excedido.', 'details' => []],
                ]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
