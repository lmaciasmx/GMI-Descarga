<?php

namespace App\Modules\Maritime\Controllers\Api;

use App\Common\Controllers\ApiController;

final class RsaController extends ApiController
{
    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'voyage_id' => 'required|is_natural_no_zero',
                'code' => 'required|max_length[80]|alpha_numeric_punct',
                'issued_on' => 'permit_empty|valid_date[Y-m-d]',
                'expires_on' => 'permit_empty|valid_date[Y-m-d]',
            ]);
            return service('rsaService')->create($payload, $this->actorId());
        }, 201);
    }
}
