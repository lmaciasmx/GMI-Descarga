<?php

namespace App\Common\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class SecurityHeadersFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        $response
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('X-Frame-Options', 'SAMEORIGIN')
            ->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->setHeader('Permissions-Policy', 'camera=(self), geolocation=(), microphone=(), payment=(), usb=()')
            ->setHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->setHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->setHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->setHeader('Cache-Control', 'no-store, private')
            ->setHeader(
                'Content-Security-Policy',
                "default-src 'self'; " .
                "script-src 'self'; " .
                "style-src 'self' 'unsafe-inline'; " .
                "img-src 'self' data:; " .
                "font-src 'self'; " .
                "connect-src 'self' http://127.0.0.1:18181 http://127.0.0.1:18182; " .
                "object-src 'none'; " .
                "frame-ancestors 'self'; " .
                "base-uri 'self'; " .
                "form-action 'self'"
            );

        if ($request->isSecure()) {
            $response->setHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }
    }
}