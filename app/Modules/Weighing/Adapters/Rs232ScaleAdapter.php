<?php

namespace App\Modules\Weighing\Adapters;

use CodeIgniter\HTTP\CURLRequest;

final class Rs232ScaleAdapter extends LocalMiddlewareScaleAdapter
{
    public function __construct(CURLRequest $client, string $baseUrl, string $apiKey, int $timeoutSeconds = 5)
    {
        parent::__construct($client, $baseUrl, $apiKey, 'rs232', $timeoutSeconds);
    }
}
