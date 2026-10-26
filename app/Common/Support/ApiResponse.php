<?php

namespace App\Common\Support;

use CodeIgniter\HTTP\ResponseInterface;

trait ApiResponse
{
    protected function success(array $data = [], int $status = 200, array $meta = []): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'success' => true,
            'data' => $data,
            'meta' => $meta,
            'error' => null,
        ]);
    }

    protected function failure(string $code, string $message, int $status = 422, array $details = []): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'success' => false,
            'data' => null,
            'meta' => [],
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ]);
    }
}
