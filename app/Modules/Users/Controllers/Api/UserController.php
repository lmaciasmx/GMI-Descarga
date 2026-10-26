<?php

namespace App\Modules\Users\Controllers\Api;

use App\Common\Controllers\ApiController;
use App\Common\Exceptions\DomainException;

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
                'password_confirm' => 'permit_empty|max_length[72]',
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

            if (
                isset($payload['password_confirm'])
                && (string) $payload['password_confirm'] !== ''
                && (string) $payload['password_confirm'] !== (string) $payload['password']
            ) {
                throw new DomainException(
                    'Revisa los datos enviados.',
                    'VALIDATION_FAILED',
                    ['password_confirm' => 'La confirmación de contraseña no coincide.'],
                );
            }

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

            if (! empty($payload['identity_only'])) {
                $this->validatePayload($payload, [
                    'username' => 'required|alpha_numeric_space|min_length[3]|max_length[40]',
                    'email' => 'required|valid_email|max_length[254]',
                    'password' => 'permit_empty|min_length[12]|max_length[72]',
                    'password_confirm' => 'permit_empty|max_length[72]',
                ]);

                if (
                    isset($payload['password'])
                    && (string) $payload['password'] !== ''
                    && (string) ($payload['password_confirm'] ?? '') !== (string) $payload['password']
                ) {
                    throw new DomainException(
                        'Revisa los datos enviados.',
                        'VALIDATION_FAILED',
                        ['password_confirm' => 'La confirmación de contraseña no coincide.'],
                    );
                }

                return service('userAdministrationService')->updateIdentity($id, $payload, $this->actorId());
            }

            if (isset($payload['base_role']) || isset($payload['permissions']) || isset($payload['scopes'])) {
                $this->validatePayload($payload, [
                    'base_role' => 'required|max_length[60]',
                    'permissions' => 'permit_empty|is_array',
                    'scopes' => 'required|is_array',
                    'username' => 'permit_empty|alpha_numeric_space|min_length[3]|max_length[40]',
                    'email' => 'permit_empty|valid_email|max_length[254]',
                    'password' => 'permit_empty|min_length[12]|max_length[72]',
                    'password_confirm' => 'permit_empty|max_length[72]',
                ]);

                if (
                    isset($payload['password'])
                    && (string) $payload['password'] !== ''
                    && (string) ($payload['password_confirm'] ?? '') !== (string) $payload['password']
                ) {
                    throw new DomainException(
                        'Revisa los datos enviados.',
                        'VALIDATION_FAILED',
                        ['password_confirm' => 'La confirmación de contraseña no coincide.'],
                    );
                }

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

            // No usar la regla `required` para un booleano: `false` es un valor
            // válido para desactivar una cuenta y puede ser interpretado como
            // vacío por el validador. Normalizamos de forma explícita.
            if (! array_key_exists('active', $payload)) {
                throw new DomainException(
                    'Revisa los datos enviados.',
                    'VALIDATION_FAILED',
                    ['active' => 'El estado del usuario es obligatorio.'],
                );
            }

            $raw = $payload['active'];
            if (is_bool($raw)) {
                $active = $raw;
            } elseif (in_array($raw, [1, '1', 'true'], true)) {
                $active = true;
            } elseif (in_array($raw, [0, '0', 'false'], true)) {
                $active = false;
            } else {
                throw new DomainException(
                    'Revisa los datos enviados.',
                    'VALIDATION_FAILED',
                    ['active' => 'El estado del usuario sólo puede ser activo o inactivo.'],
                );
            }

            return service('userAdministrationService')->setActive(
                $id,
                $active,
                $this->actorId(),
            );
        });
    }
}
