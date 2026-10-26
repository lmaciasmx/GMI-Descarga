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
                // V2
                'base_role' => 'permit_empty|max_length[60]',
                'permissions' => 'permit_empty|is_array',
                'scopes' => 'permit_empty|is_array',
                // Compatibilidad V1
                'group' => 'permit_empty|max_length[60]',
                'company_id' => 'permit_empty|is_natural_no_zero',
                'site_id' => 'permit_empty|is_natural_no_zero',
                'warehouse_id' => 'permit_empty|is_natural_no_zero',
                'station_code' => 'permit_empty|max_length[40]',
            ]);

            return service('userAdministrationService')->create($payload, $this->actorId());
        }, 201);
    }

    /**
     * La URL se conserva por compatibilidad (/group), pero en V2 este endpoint
     * sincroniza el perfil completo: rol base, permisos exactos y alcances.
     */
    public function group(int $id)
    {
        return $this->execute(function () use ($id): array {
            $payload = $this->payload();

            if (isset($payload['base_role']) || isset($payload['permissions']) || isset($payload['scopes'])) {
                $this->validatePayload($payload, [
                    'base_role' => 'required|max_length[60]',
                    'permissions' => 'permit_empty|is_array',
                    'scopes' => 'required|is_array',
                ]);

                return service('userAdministrationService')->updateAccess($id, $payload, $this->actorId());
            }

            // Contrato anterior: {group: "..."}
            $this->validatePayload($payload, ['group' => 'required|max_length[60]']);
            return service('userAdministrationService')->syncGroup(
                $id,
                (string) $payload['group'],
                $this->actorId(),
            );
        });
    }

    public function status(int $id)
    {
        return $this->execute(function () use ($id): array {
            $payload = $this->payload();
            $this->validatePayload($payload, ['active' => 'required|in_list[0,1,true,false]']);
            return service('userAdministrationService')->setActive(
                $id,
                filter_var($payload['active'], FILTER_VALIDATE_BOOLEAN),
                $this->actorId(),
            );
        });
    }
}
