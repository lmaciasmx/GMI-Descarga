<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class CreateApiClient extends BaseCommand
{
    protected $group = 'Grupo Mar';
    protected $name = 'app:create-api-client';
    protected $description = 'Genera una credencial API, almacenando únicamente su hash.';
    protected $usage = 'app:create-api-client <client-id> <nombre> <permisos> [empresa-id] [sitio-id]';

    public function run(array $params): int
    {
        $clientId = trim((string) ($params[0] ?? CLI::prompt('Client ID')));
        $name = trim((string) ($params[1] ?? CLI::prompt('Nombre de integración')));
        $permissionText = trim((string) ($params[2] ?? CLI::prompt('Permisos separados por coma', null, 'required')));
        $permissions = array_values(array_unique(array_filter(array_map('trim', explode(',', $permissionText)))));
        if (! preg_match('/^[A-Za-z0-9._-]{3,80}$/', $clientId) || $name === '' || $permissions === []) {
            CLI::error('Client ID, nombre o permisos inválidos.');
            return EXIT_ERROR;
        }
        $known = array_keys(config('AuthGroups')->permissions);
        foreach ($permissions as $permission) {
            if ($permission !== '*' && ! in_array($permission, $known, true) && ! in_array($permission, ['health.read', 'catalogs.read', 'voyages.read', 'plans.read', 'inventory.read', 'scale.read'], true)) {
                CLI::error('Permiso no reconocido: ' . $permission);
                return EXIT_ERROR;
            }
        }
        $companyId = isset($params[3]) ? (int) $params[3] : (($value = env('api.companyId')) === null || $value === '' ? null : (int) $value);
        $siteId = isset($params[4]) ? (int) $params[4] : (($value = env('api.siteId')) === null || $value === '' ? null : (int) $value);
        if ($siteId !== null) {
            $site = db_connect()->table('sites')->select('company_id')->where('id', $siteId)->get()->getRowArray();
            if ($site === null || ($companyId !== null && (int) $site['company_id'] !== $companyId)) {
                CLI::error('La instalación no existe o no pertenece a la empresa indicada.');
                return EXIT_ERROR;
            }
            $companyId ??= (int) $site['company_id'];
        }
        if ($companyId !== null && db_connect()->table('companies')->where('id', $companyId)->countAllResults() !== 1) {
            CLI::error('La empresa indicada no existe.');
            return EXIT_ERROR;
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        try {
            db_connect()->table('api_clients')->insert([
                'client_id' => $clientId,
                'name' => $name,
                'key_hash' => hash('sha256', $secret),
                'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                'company_id' => $companyId,
                'site_id' => $siteId,
                'active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $exception) {
            CLI::error('No se creó el cliente: ' . $exception->getMessage());
            return EXIT_ERROR;
        }

        CLI::write('Cliente creado: ' . $clientId, 'green');
        CLI::write('API key (se muestra una sola vez): ' . $secret, 'yellow');
        return EXIT_SUCCESS;
    }
}
