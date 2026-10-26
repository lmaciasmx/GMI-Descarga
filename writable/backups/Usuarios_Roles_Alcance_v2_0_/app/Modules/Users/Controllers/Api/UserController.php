<?php

namespace App\Modules\Users\Controllers\Api;

use App\Common\Controllers\ApiController;

final class UserController extends ApiController
{
    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'username' => 'required|alpha_numeric_space|min_length[3]|max_length[40]',
                'email' => 'required|valid_email|max_length[254]',
                'password' => 'required|min_length[12]|max_length[72]',
                'group' => 'required|max_length[60]',
                'company_id' => 'required|is_natural_no_zero',
                'site_id' => 'required|is_natural_no_zero',
                'warehouse_id' => 'permit_empty|is_natural_no_zero',
                'station_code' => 'permit_empty|max_length[40]',
            ]);
            return service('userAdministrationService')->create($payload, $this->actorId());
        }, 201);
    }

    public function group(int $id)
    {
        return $this->execute(function () use ($id): array {
            $payload = $this->payload();
            $this->validatePayload($payload, ['group' => 'required|max_length[60]']);
            return service('userAdministrationService')->syncGroup($id, (string) $payload['group'], $this->actorId());
        });
    }

    public function status(int $id)
    {
        return $this->execute(function () use ($id): array {
            $payload = $this->payload();
            $this->validatePayload($payload, ['active' => 'required|in_list[0,1,true,false]']);
            return service('userAdministrationService')->setActive($id, filter_var($payload['active'], FILTER_VALIDATE_BOOLEAN), $this->actorId());
        });
    }
}
