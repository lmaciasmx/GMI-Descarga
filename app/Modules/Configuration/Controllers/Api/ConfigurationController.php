<?php

namespace App\Modules\Configuration\Controllers\Api;

use App\Common\Controllers\ApiController;

final class ConfigurationController extends ApiController
{
    public function update(int $id)
    {
        return $this->execute(function () use ($id): array {
            $payload = $this->payload();
            $this->validatePayload($payload, ['value' => 'required|max_length[5000]']);
            return service('settingsService')->update($id, (string) $payload['value'], $this->actorId());
        });
    }
}
