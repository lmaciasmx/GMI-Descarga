<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Matriz operativa de tipos de solicitud permitidos por almacén.
 *
 * Los códigos provienen del catálogo warehouses:
 * - FRIO-01 = Frigorífico Marfrigo (ID 3)
 * - SUAR    = Frigorífico Suarcream (ID 4)
 *
 * Esta configuración controla CAPTURA y validación backend.
 * No altera ni oculta solicitudes históricas ya existentes.
 */
final class WarehouseRequestTypes extends BaseConfig
{
    /**
     * @var array<string,list<string>>
     */
    public array $allowedByWarehouseCode = [
        'FRIO-01' => ['transfer', 'sale'],
        'SUAR'    => ['transfer', 'production'],
    ];

    /**
     * Para almacenes nuevos/no configurados, sólo Traspaso interno queda habilitado.
     *
     * @var list<string>
     */
    public array $defaultAllowed = ['transfer'];

    /**
     * @param array<string,mixed> $warehouse
     * @return list<string>
     */
    public function allowedForWarehouse(array $warehouse): array
    {
        $code = strtoupper(trim((string) ($warehouse['code'] ?? '')));

        return $this->allowedByWarehouseCode[$code] ?? $this->defaultAllowed;
    }

    /**
     * @param array<string,mixed> $warehouse
     */
    public function allows(array $warehouse, string $requestType): bool
    {
        return in_array($requestType, $this->allowedForWarehouse($warehouse), true);
    }
}
