<?php

namespace App\Modules\Supply\Controllers\Api;

use App\Common\Controllers\ApiController;
use App\Common\Exceptions\DomainException;

final class RequestController extends ApiController
{
    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $requestType = (string) ($payload['request_type'] ?? 'production');

            $scope = session('operational_scope') ?: [];
            $companyId = (int) ($scope['company_id'] ?? 0);
            $siteId = (int) ($scope['site_id'] ?? 0);
            $scopeWarehouseId = (int) ($scope['warehouse_id'] ?? 0);

            if ($companyId <= 0 || $siteId <= 0 || $scopeWarehouseId <= 0) {
                throw new DomainException(
                    'Selecciona empresa, instalación y almacén en Cambiar alcance antes de crear la solicitud.',
                    'REQUEST_OPERATIONAL_SCOPE_REQUIRED'
                );
            }

            $warehouse = db_connect()->table('warehouses w')
                ->select('w.id, w.company_id, w.site_id, w.code, w.name, s.name AS site_name')
                ->join('sites s', 's.id = w.site_id')
                ->where([
                    'w.id' => $scopeWarehouseId,
                    'w.company_id' => $companyId,
                    'w.site_id' => $siteId,
                    'w.active' => 1,
                    's.active' => 1,
                ])->get()->getRowArray();

            if ($warehouse === null) {
                throw new DomainException('El almacén del alcance actual no está disponible.', 'REQUEST_SCOPE_WAREHOUSE_INVALID');
            }

            // La identidad operativa nunca se toma del navegador: siempre nace del alcance autenticado.
            $payload['company_id'] = $companyId;
            $payload['site_id'] = $siteId;

            if ($requestType === 'warehouse_transfer') {
                // Modelo pull: quien necesita el material solicita. El alcance actual es el destino.
                $payload['source_warehouse_id'] = null;
                $payload['destination_warehouse_id'] = $scopeWarehouseId;
                $payload['destination'] = trim((string) $warehouse['site_name'])
                    . ' · ' . trim((string) $warehouse['code'])
                    . ' · ' . trim((string) $warehouse['name']);
            } else {
                // Salida a producción: el alcance actual identifica de qué almacén se surtirá.
                $payload['source_warehouse_id'] = $scopeWarehouseId;
                $payload['destination_warehouse_id'] = null;
            }

            $this->validatePayload($payload, [
                'company_id' => 'required|is_natural_no_zero',
                'site_id' => 'required|is_natural_no_zero',
                'request_type' => 'permit_empty|in_list[production,warehouse_transfer]',
                'source_warehouse_id' => 'permit_empty|is_natural_no_zero',
                'destination' => 'required|min_length[2]|max_length[120]',
                'destination_warehouse_id' => 'permit_empty|is_natural_no_zero',
                'required_at' => 'required|valid_date[Y-m-d H:i:s]',
                'priority' => 'permit_empty|in_list[low,normal,high,urgent]',
                'observations' => 'permit_empty|max_length[1000]',
                'lines' => 'required|is_array',
                'lines.*.species_id' => 'required|is_natural_no_zero',
                'lines.*.size_grade_id' => 'permit_empty|is_natural_no_zero',
                'lines.*.quality_grade' => 'permit_empty|max_length[20]',
                'lines.*.requested_weight_kg' => 'required|decimal|greater_than[0]',
            ]);

            return service('requestService')->create($payload, $this->actorId());
        }, 201);
    }

    public function authorize(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('request', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, ['reason' => 'permit_empty|max_length[255]']);
            if (! array_key_exists('approved', $payload)
                || (! is_bool($payload['approved']) && ! in_array(strtolower((string) $payload['approved']), ['0', '1', 'true', 'false'], true))) {
                throw new DomainException('El campo approved debe ser booleano.', 'VALIDATION_FAILED', ['approved' => 'Valor booleano inválido.']);
            }
            return service('requestService')->authorize($id, filter_var($payload['approved'], FILTER_VALIDATE_BOOLEAN), $this->actorId(), $payload['reason'] ?? null);
        });
    }

    public function availability(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('request', $id);
            return service('requestService')->availability($id);
        });
    }
}
