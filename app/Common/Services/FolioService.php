<?php

namespace App\Common\Services;

use Ramsey\Uuid\Uuid;

final class FolioService
{
    public function next(string $prefix): string
    {
        $token = strtoupper(substr(str_replace('-', '', Uuid::uuid7()->toString()), -8));

        return sprintf('%s-%s-%s', strtoupper($prefix), date('Ymd'), $token);
    }
}
