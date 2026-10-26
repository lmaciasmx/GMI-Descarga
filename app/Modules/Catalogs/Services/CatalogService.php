<?php

namespace App\Modules\Catalogs\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Catalogs;

final class CatalogService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Catalogs $config,
        private readonly AuditService $audit,
    ) {
    }

    public function definitions(): array
    {
        return $this->config->definitions;
    }

    public function list(string $catalog, ?string $query = null, int $limit = 500, bool $includeInactive = false): array
    {
        // Catálogos Maestros son globales; el alcance operativo no debe limitar su administración.
        service('scopeContext')->clear();
        $definition = $this->definition($catalog);
        $selectableFields = array_values(array_filter(
            array_unique(array_merge($definition['list'], array_keys($definition['fields']))),
            static fn (string $field): bool => $field !== 'configuration',
        ));
        $builder = $this->db->table($definition['table'])->select(implode(', ', $selectableFields));
        $this->applyScope($catalog, $builder);
        if (! $includeInactive && $this->db->fieldExists('active', $definition['table'])) {
            $builder->where('active', 1);
        }
        if (trim((string) $query) !== '') {
            $builder->groupStart();
            foreach ($definition['search'] as $index => $field) {
                $index === 0 ? $builder->like($field, trim((string) $query)) : $builder->orLike($field, trim((string) $query));
            }
            $builder->groupEnd();
        }

        return $builder->orderBy($definition['search'][0] ?? 'id')->limit(min(1000, max(1, $limit)))->get()->getResultArray();
    }

    public function create(string $catalog, array $payload, int $actorUserId): array
    {
        // Catálogos Maestros son globales; catalogs.manage autoriza la administración.
        service('scopeContext')->clear();
        $definition = $this->definition($catalog);
        $data = $this->normalize($definition, $payload);
        if (array_key_exists('active', $definition['fields']) && ! array_key_exists('active', $data)) {
            $data['active'] = 1;
        }
        $this->validate($definition, $data);
        if (! in_array($catalog, ['companies', 'vessels', 'vessel-tanks', 'tubs'], true)) {
            service('scopeContext')->assertPayload($data);
        }
        $now = date('Y-m-d H:i:s');
        if ($this->db->fieldExists('created_by', $definition['table'])) {
            $data['created_by'] = $actorUserId;
        }
        if ($this->db->fieldExists('created_at', $definition['table'])) {
            $data['created_at'] = $now;
        }
        if ($this->db->fieldExists('updated_at', $definition['table'])) {
            $data['updated_at'] = $now;
        }

        $this->db->transException(true)->transBegin();
        try {
            $this->guardRelations($catalog, $data);
            $this->applyDeviceDefaults($catalog, $data);
            $this->db->table($definition['table'])->insert($data);
            $id = (int) $this->db->insertID();
            $this->audit->record('catalog.created', $catalog, $id, $actorUserId, after: $this->withoutSensitiveConfiguration($data));
            $this->db->transCommit();
            return ['id' => $id, 'catalog' => $catalog, 'status' => 'created'];
        } catch (DatabaseException $exception) {
            $this->db->transRollback();
            throw new DomainException('El registro duplica una clave o incumple una relación del catálogo.', 'CATALOG_INTEGRITY_ERROR', [], 0, $exception);
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function update(string $catalog, int $id, array $payload, int $actorUserId): array
    {
        // Catálogos Maestros son globales; no aplicar operational_scope al mantenimiento.
        service('scopeContext')->clear();
        $definition = $this->definition($catalog);
        $this->db->transException(true)->transBegin();
        try {
            $current = $this->db->query('SELECT * FROM ' . $definition['table'] . ' WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            if ($current === null) {
                throw new DomainException('Registro de catálogo no encontrado.', 'CATALOG_RECORD_NOT_FOUND');
            }
            $this->assertRowScope($catalog, $current);
            $data = $this->normalize($definition, $payload);
            if ($data === []) {
                throw new DomainException('No se enviaron campos editables.', 'CATALOG_FIELDS_REQUIRED');
            }
            $candidate = array_replace($current, $data);
            $this->validate($definition, $candidate);
            if (! in_array($catalog, ['companies', 'vessels', 'vessel-tanks', 'tubs'], true)) {
                service('scopeContext')->assertPayload($candidate);
            }
            $this->guardRelations($catalog, $candidate, $id);
            $this->applyDeviceDefaults($catalog, $candidate, $id);
            $this->guardOperationalUpdate($catalog, $current, $candidate);
            if ($this->db->fieldExists('updated_by', $definition['table'])) {
                $data['updated_by'] = $actorUserId;
            }
            if ($this->db->fieldExists('updated_at', $definition['table'])) {
                $data['updated_at'] = date('Y-m-d H:i:s');
            }
            $this->db->table($definition['table'])->where('id', $id)->update($data);
            $this->audit->record('catalog.updated', $catalog, $id, $actorUserId, before: $this->withoutSensitiveConfiguration($current), after: $this->withoutSensitiveConfiguration($data));
            $this->db->transCommit();
            return ['id' => $id, 'catalog' => $catalog, 'status' => 'updated'];
        } catch (DatabaseException $exception) {
            $this->db->transRollback();
            throw new DomainException('La modificación duplica una clave o incumple una relación del catálogo.', 'CATALOG_INTEGRITY_ERROR', [], 0, $exception);
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function deactivate(string $catalog, int $id, int $actorUserId): array
    {
        // Mantener la baja lógica independiente del alcance operativo actual.
        service('scopeContext')->clear();
        $definition = $this->definition($catalog);
        if (! array_key_exists('active', $definition['fields'])) {
            throw new DomainException('Este catálogo no admite baja lógica.', 'CATALOG_DEACTIVATION_NOT_SUPPORTED');
        }

        $result = $this->update($catalog, $id, ['active' => 0], $actorUserId);
        $result['status'] = 'deactivated';

        return $result;
    }

    private function definition(string $catalog): array
    {
        $definition = $this->config->definitions[$catalog] ?? null;
        if ($definition === null) {
            throw new DomainException('Catálogo no permitido.', 'CATALOG_NOT_FOUND');
        }
        return $definition;
    }

    private function normalize(array $definition, array $payload): array
    {
        $data = [];
        foreach ($definition['fields'] as $field => $metadata) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            if (($metadata['type'] ?? null) === 'checkbox') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            } elseif (($metadata['json'] ?? false) === true) {
                try {
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_THROW_ON_ERROR);
                    } elseif (trim((string) $value) !== '') {
                        json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
                    }
                } catch (\JsonException $exception) {
                    throw new DomainException(
                        'La configuración debe ser JSON válido.',
                        'INVALID_JSON_CONFIGURATION',
                        [$field => $exception->getMessage()],
                        0,
                        $exception,
                    );
                }
            } elseif (is_string($value)) {
                $value = trim($value);
                if (($metadata['type'] ?? null) === 'datetime-local') {
                    $value = str_replace('T', ' ', $value);
                    if (strlen($value) === 16) {
                        $value .= ':00';
                    }
                }
            }
            $data[$field] = $value === '' && str_contains((string) $metadata['rule'], 'permit_empty') ? null : $value;
        }
        return $data;
    }

    private function validate(array $definition, array $candidate): void
    {
        $rules = [];
        foreach ($definition['fields'] as $field => $metadata) {
            $rules[$field] = ['label' => $metadata['label'], 'rules' => $metadata['rule']];
        }
        $validation = service('validation');
        $validation->reset();
        $validation->setRules($rules);
        if (! $validation->run($candidate)) {
            throw new DomainException('Revisa los datos del catálogo.', 'VALIDATION_FAILED', $validation->getErrors());
        }
        if (isset($candidate['min_temperature_c'], $candidate['max_temperature_c']) && (float) $candidate['min_temperature_c'] > (float) $candidate['max_temperature_c']) {
            throw new DomainException('La temperatura mínima no puede superar a la máxima.', 'INVALID_TEMPERATURE_RANGE');
        }
        if (isset($candidate['min_kg'], $candidate['max_kg']) && (float) $candidate['min_kg'] > (float) $candidate['max_kg']) {
            throw new DomainException('El rango de talla es inválido.', 'INVALID_SIZE_RANGE');
        }
    }

    private function applyScope(string $catalog, object $builder): void
    {
        $scope = service('scopeContext')->restrictions();
        if (! $scope['configured']) {
            return;
        }
        if ($catalog === 'sites') {
            $scope['company_id'] !== null && $builder->where('company_id', $scope['company_id']);
            $scope['site_id'] !== null && $builder->where('id', $scope['site_id']);
        } elseif ($catalog === 'warehouses') {
            $scope['company_id'] !== null && $builder->where('company_id', $scope['company_id']);
            $scope['site_id'] !== null && $builder->where('site_id', $scope['site_id']);
            $scope['warehouse_id'] !== null && $builder->where('id', $scope['warehouse_id']);
        } elseif (in_array($catalog, ['chambers', 'locations'], true)) {
            $warehouseIds = $this->allowedWarehouseIds($scope);
            $builder->whereIn('warehouse_id', $warehouseIds === [] ? [0] : $warehouseIds);
        } elseif (in_array($catalog, ['scales', 'printers'], true) && $scope['site_id'] !== null) {
            $builder->where('site_id', $scope['site_id']);
        }
    }

    private function assertRowScope(string $catalog, array $row): void
    {
        if ($catalog === 'sites') {
            service('scopeContext')->assertCompanySite((int) $row['company_id'], (int) $row['id']);
        } elseif ($catalog === 'warehouses') {
            service('scopeContext')->assertWarehouse((int) $row['id']);
        } elseif (in_array($catalog, ['chambers', 'locations'], true)) {
            service('scopeContext')->assertWarehouse((int) $row['warehouse_id']);
        } elseif (in_array($catalog, ['scales', 'printers'], true)) {
            $site = $this->db->table('sites')->where('id', $row['site_id'])->get()->getRowArray();
            service('scopeContext')->assertCompanySite($site === null ? null : (int) $site['company_id'], (int) $row['site_id']);
        }
    }

    private function allowedWarehouseIds(array $scope): array
    {
        if ($scope['warehouse_id'] !== null) {
            return [$scope['warehouse_id']];
        }
        $builder = $this->db->table('warehouses')->select('id');
        $scope['company_id'] !== null && $builder->where('company_id', $scope['company_id']);
        $scope['site_id'] !== null && $builder->where('site_id', $scope['site_id']);
        return array_map('intval', array_column($builder->get()->getResultArray(), 'id'));
    }

    private function guardOperationalUpdate(string $catalog, array $current, array $candidate): void
    {
        if ($catalog === 'warehouses') {
            $identityChanged = (int) $candidate['company_id'] !== (int) $current['company_id']
                || (int) $candidate['site_id'] !== (int) $current['site_id'];
            $physicalStock = $this->db->table('stock_units')->where('warehouse_id', $current['id'])->where('on_hand_weight_kg >', 0)->whereNotIn('status', ['in_transit', 'dispatched', 'exhausted'])->countAllResults();
            $openTransfers = $this->db->table('transfers')
                ->groupStart()->where('origin_warehouse_id', $current['id'])->orWhere('destination_warehouse_id', $current['id'])->groupEnd()
                ->whereIn('status', ['requested', 'authorized', 'prepared', 'shipped', 'in_transit', 'discrepancy'])
                ->countAllResults();
            if ($identityChanged && ($this->db->table('stock_units')->where('warehouse_id', $current['id'])->countAllResults() > 0
                || $this->db->table('receptions')->where('warehouse_id', $current['id'])->countAllResults() > 0
                || $openTransfers > 0)) {
                throw new DomainException('Un almacén con trazabilidad no puede cambiar de empresa o instalación.', 'WAREHOUSE_SCOPE_IMMUTABLE');
            }
            if ((int) ($candidate['active'] ?? 1) === 0 && ($physicalStock > 0 || $openTransfers > 0)) {
                throw new DomainException('No se puede desactivar un almacén con inventario físico o traspasos abiertos.', 'WAREHOUSE_HAS_OPEN_OPERATIONS');
            }
        }
        if ($catalog === 'locations' && (int) $candidate['warehouse_id'] !== (int) $current['warehouse_id']) {
            $references = $this->db->table('stock_units')->where('location_id', $current['id'])->countAllResults()
                + $this->db->table('stock_unit_location_history')->groupStart()->where('from_location_id', $current['id'])->orWhere('to_location_id', $current['id'])->groupEnd()->countAllResults();
            if ($references > 0) {
                throw new DomainException('Una ubicación con trazabilidad no puede cambiar de almacén.', 'LOCATION_WAREHOUSE_IMMUTABLE');
            }
        }
        if ($catalog === 'locations' && (int) ($candidate['active'] ?? 1) === 0) {
            $activeUnits = $this->db->table('stock_units')->where('location_id', $current['id'])->where('on_hand_weight_kg >', 0)->whereNotIn('status', ['in_transit', 'dispatched', 'exhausted'])->countAllResults();
            if ($activeUnits > 0) {
                throw new DomainException('No se puede desactivar una ubicación con inventario físico.', 'LOCATION_HAS_STOCK');
            }
        }
        if ($catalog === 'tubs' && (($candidate['status'] ?? $current['status']) !== $current['status'] || (int) ($candidate['active'] ?? 1) === 0)) {
            $open = $this->db->table('tub_fillings')->where('tub_id', $current['id'])->whereIn('status', ['filling', 'pending_weighing'])->countAllResults();
            if ($open > 0) {
                throw new DomainException('La tina participa en una operación abierta.', 'TUB_HAS_OPEN_OPERATION');
            }
        }
    }

    private function guardRelations(string $catalog, array $candidate, ?int $recordId = null): void
    {
        if ($catalog === 'sites') {
            $this->requireRow('companies', (int) $candidate['company_id'], 'La empresa de la instalación no existe.');
            return;
        }

        if ($catalog === 'warehouses') {
            $company = $this->requireRow('companies', (int) $candidate['company_id'], 'La empresa del almacén no existe.');
            $site = $this->requireRow('sites', (int) $candidate['site_id'], 'La instalación del almacén no existe.');
            if ((int) $site['company_id'] !== (int) $company['id']) {
                throw new DomainException('La instalación y el almacén deben pertenecer a la misma empresa.', 'WAREHOUSE_SITE_COMPANY_MISMATCH');
            }
            if (! empty($candidate['external_company_id'])) {
                $this->requireRow('companies', (int) $candidate['external_company_id'], 'La empresa externa no existe.');
            }
            return;
        }

        if ($catalog === 'chambers') {
            $this->requireRow('warehouses', (int) $candidate['warehouse_id'], 'El almacén de la cámara no existe.');
            return;
        }

        if ($catalog === 'locations') {
            $warehouseId = (int) $candidate['warehouse_id'];
            $this->requireRow('warehouses', $warehouseId, 'El almacén de la ubicación no existe.');
            if (! empty($candidate['chamber_id'])) {
                $chamber = $this->requireRow('chambers', (int) $candidate['chamber_id'], 'La cámara de la ubicación no existe.');
                if ((int) $chamber['warehouse_id'] !== $warehouseId) {
                    throw new DomainException('La cámara no pertenece al almacén de la ubicación.', 'LOCATION_CHAMBER_MISMATCH');
                }
            }
            if (! empty($candidate['parent_id'])) {
                $parentId = (int) $candidate['parent_id'];
                $parent = $this->requireRow('locations', $parentId, 'La ubicación padre no existe.');
                if ((int) $parent['warehouse_id'] !== $warehouseId) {
                    throw new DomainException('La ubicación padre pertenece a otro almacén.', 'LOCATION_PARENT_MISMATCH');
                }
                $this->assertLocationHierarchyHasNoCycle($parentId, $recordId);
            }
            return;
        }

        if ($catalog === 'vessels') {
            $this->requireRow('companies', (int) $candidate['owner_company_id'], 'La empresa propietaria no existe.');
            return;
        }

        if ($catalog === 'vessel-tanks') {
            $this->requireRow('vessels', (int) $candidate['vessel_id'], 'La embarcación del tanque no existe.');
            return;
        }

        if ($catalog === 'sizes' && ! empty($candidate['species_id'])) {
            $this->requireRow('species', (int) $candidate['species_id'], 'La especie de la talla no existe.');
            return;
        }

        if (in_array($catalog, ['scales', 'printers'], true)) {
            $this->requireRow('sites', (int) $candidate['site_id'], 'La instalación del dispositivo no existe.');
            if ($catalog === 'scales') {
                $this->guardScaleConfiguration($candidate);
            }
        }
    }

    private function guardScaleConfiguration(array $candidate): void
    {
        $connection = strtolower(trim((string) ($candidate['connection_type'] ?? '')));
        $adapter = strtolower(trim((string) ($candidate['adapter_key'] ?? '')));
        $allowed = ['simulator', 'rs232', 'usb_serial', 'tcp', 'middleware'];

        if (! in_array($connection, $allowed, true) || ! in_array($adapter, $allowed, true)) {
            throw new DomainException('El tipo de conexión o adapter de la báscula no es válido.', 'SCALE_CONFIGURATION_INVALID');
        }

        if ($connection !== $adapter && ! ($connection === 'middleware' && in_array($adapter, ['rs232', 'usb_serial', 'tcp', 'middleware'], true))) {
            throw new DomainException('La conexión y el adapter de la báscula no son compatibles.', 'SCALE_ADAPTER_MISMATCH');
        }

        if ($connection === 'simulator') {
            return;
        }

        $agentUrl = trim((string) ($candidate['agent_url'] ?? ''));
        if ($agentUrl === '' || filter_var($agentUrl, FILTER_VALIDATE_URL) === false) {
            throw new DomainException('Configura una URL válida para el agente de báscula.', 'SCALE_AGENT_URL_REQUIRED', ['agent_url' => 'Indica la URL del agente local.']);
        }
        $agentParts = parse_url($agentUrl);
        $agentHost = strtolower((string) ($agentParts['host'] ?? ''));
        if (in_array($connection, ['rs232', 'usb_serial'], true) && ! in_array($agentHost, ['127.0.0.1', 'localhost'], true)) {
            throw new DomainException('El agente serial debe ejecutarse localmente en la estación.', 'SCALE_AGENT_MUST_BE_LOCAL', ['agent_url' => 'Usa http://127.0.0.1:18182 para el agente serial.']);
        }

        if (in_array($connection, ['rs232', 'usb_serial'], true)) {
            $port = strtoupper(trim((string) ($candidate['port_name'] ?? '')));
            if (! preg_match('/^COM\d{1,3}$/', $port)) {
                throw new DomainException('Selecciona un puerto COM válido para la báscula serial.', 'SCALE_PORT_REQUIRED', ['port_name' => 'Usa un puerto como COM3 o detecta los puertos disponibles.']);
            }
            if (trim((string) ($candidate['read_command'] ?? '')) === '') {
                throw new DomainException('El comando de lectura de la báscula es obligatorio.', 'SCALE_READ_COMMAND_REQUIRED', ['read_command' => 'Indica el comando que solicita el peso.']);
            }
        }

        if (strtolower(trim((string) ($candidate['unit'] ?? 'kg'))) !== 'kg') {
            throw new DomainException('La integración operativa de báscula trabaja en kilogramos.', 'SCALE_UNIT_NOT_SUPPORTED');
        }
    }

    private function applyDeviceDefaults(string $catalog, array $candidate, ?int $recordId = null): void
    {
        if ($catalog !== 'scales' || (int) ($candidate['is_default'] ?? 0) !== 1) {
            return;
        }

        $builder = $this->db->table('scales')
            ->where('site_id', (int) $candidate['site_id']);
        if ($recordId !== null) {
            $builder->where('id !=', $recordId);
        }
        $builder->update(['is_default' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function requireRow(string $table, int $id, string $message): array
    {
        $row = $id > 0 ? $this->db->table($table)->where('id', $id)->get()->getRowArray() : null;
        if ($row === null) {
            throw new DomainException($message, 'CATALOG_RELATION_NOT_FOUND');
        }

        return $row;
    }

    private function assertLocationHierarchyHasNoCycle(int $parentId, ?int $recordId): void
    {
        $seen = [];
        $currentId = $parentId;
        for ($depth = 0; $currentId > 0 && $depth < 256; $depth++) {
            if (($recordId !== null && $currentId === $recordId) || isset($seen[$currentId])) {
                throw new DomainException('La jerarquía de ubicaciones produciría un ciclo.', 'LOCATION_HIERARCHY_CYCLE');
            }
            $seen[$currentId] = true;
            $parent = $this->db->table('locations')->select('parent_id')->where('id', $currentId)->get()->getRowArray();
            $currentId = $parent === null || $parent['parent_id'] === null ? 0 : (int) $parent['parent_id'];
        }
        if ($currentId > 0) {
            throw new DomainException('La jerarquía de ubicaciones excede el límite permitido.', 'LOCATION_HIERARCHY_TOO_DEEP');
        }
    }

    private function withoutSensitiveConfiguration(array $data): array
    {
        if (array_key_exists('configuration', $data)) {
            $data['configuration'] = '[CONFIGURATION_REDACTED]';
        }
        return $data;
    }
}
