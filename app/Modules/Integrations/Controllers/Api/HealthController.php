<?php

namespace App\Modules\Integrations\Controllers\Api;

use App\Common\Controllers\ApiController;

final class HealthController extends ApiController
{
    public function index()
    {
        return $this->execute(function (): array {
            $database = 'down';
            try {
                db_connect()->query('SELECT 1');
                $database = 'up';
            } catch (\Throwable) {
            }

            return [
                'service' => 'grupo-mar-operaciones',
                'status' => $database === 'up' ? 'up' : 'degraded',
                'database' => $database,
                'environment' => ENVIRONMENT,
                'time' => date(DATE_ATOM),
            ];
        });
    }
}
