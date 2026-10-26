<?php

namespace App\Modules\Supply\Services;

use App\Common\Domain\WorkflowTransitions;
use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Common\Services\FolioService;
use App\Modules\Inventory\Repositories\InventoryRepository;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class RequestService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly InventoryRepository $inventory,
        private readonly FolioService $folios,
        private readonly AuditService $audit,
    ) {
    }

    public function create(array $payload, int $actorUserId): array
    {
        $lines = array_values($payload['lines'] ?? []);
        if ($lines === []) {
            throw new DomainException('La solicitud requiere al menos un producto.', 'REQUEST_LINES_REQUIRED');
        }

        $requestType = trim((string) ($payload['request_type'] ?? 'production')) ?: 'production';
        if (! in_array($requestType, ['production', 'warehouse_transfer'], true)) {
            throw new DomainException('Tipo de solicitud inválido.', 'REQUEST_TYPE_INVALID');
        }

        $this->db->transException(true)->transBegin();
        try {
            if ($this->db->table('sites')->where([
                'id' => (int) $payload['site_id'],
                'company_id' => (int) $payload['company_id'],
                'active' => 1,
            ])->countAllResults() !== 1) {
                throw new DomainException('La instalación no pertenece a la empresa.', 'SITE_COMPANY_MISMATCH');
            }

            $sourceWarehouseId = null;
            $destinationWarehouseId = null;
            $destination = trim((string) ($payload['destination'] ?? ''));

            if ($requestType === 'warehouse_transfer') {
                $destinationWarehouseId = (int) ($payload['destination_warehouse_id'] ?? 0);
                if ($destinationWarehouseId <= 0) {
                    throw new DomainException('Selecciona el almacén destino del traspaso.', 'REQUEST_DESTINATION_WAREHOUSE_REQUIRED');
                }

                $destinationWarehouse = $this->db->table('warehouses w')
                    ->select('w.id, w.company_id, w.site_id, w.code, w.name, s.name AS site_name')
                    ->join('sites s', 's.id = w.site_id')
                    ->where([
                        'w.id' => $destinationWarehouseId,
                        'w.company_id' => (int) $payload['company_id'],
                        'w.site_id' => (int) $payload['site_id'],
                        'w.active' => 1,
                        's.active' => 1,
                    ])->get()->getRowArray();

                if ($destinationWarehouse === null) {
                    throw new DomainException('El almacén destino no pertenece a la empresa o está inactivo.', 'REQUEST_DESTINATION_WAREHOUSE_INVALID');
                }

                $destination = trim((string) $destinationWarehouse['site_name'])
                    . ' · ' . trim((string) $destinationWarehouse['code'])
                    . ' · ' . trim((string) $destinationWarehouse['name']);
            } else {
                $sourceWarehouseId = (int) ($payload['source_warehouse_id'] ?? 0);
                if ($sourceWarehouseId <= 0) {
                    throw new DomainException('Selecciona el almacén que surtirá la salida a producción.', 'REQUEST_SOURCE_WAREHOUSE_REQUIRED');
                }

                $sourceWarehouse = $this->db->table('warehouses')->where([
                    'id' => $sourceWarehouseId,
                    'company_id' => (int) $payload['company_id'],
                    'site_id' => (int) $payload['site_id'],
                    'active' => 1,
                ])->get()->getRowArray();

                if ($sourceWarehouse === null) {
                    throw new DomainException('El almacén surtidor no pertenece al alcance de la solicitud o está inactivo.', 'REQUEST_SOURCE_WAREHOUSE_INVALID');
                }

                if ($destination === '') {
                    throw new DomainException('Captura el destino de producción.', 'REQUEST_DESTINATION_REQUIRED');
                }
            }

            $now = date('Y-m-d H:i:s');
            $folio = $this->folios->next('SMP');
            $this->db->table('raw_material_requests')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'folio' => $folio,
                'company_id' => (int) $payload['company_id'],
                'site_id' => (int) $payload['site_id'],
                'source_warehouse_id' => $sourceWarehouseId,
                'request_type' => $requestType,
                'destination_warehouse_id' => $destinationWarehouseId,
                'destination' => $destination,
                'priority' => $payload['priority'] ?? 'normal',
                'required_at' => (string) $payload['required_at'],
                'status' => 'requested',
                'requested_by' => $actorUserId,
                'requested_at' => $now,
                'observations' => $payload['observations'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $requestId = (int) $this->db->insertID();

            foreach ($lines as $index => $line) {
                $quantity = round((float) $line['requested_weight_kg'], 3);
                if ($quantity <= 0) {
                    throw new DomainException('El peso solicitado debe ser positivo.', 'INVALID_REQUEST_QUANTITY');
                }

                $species = $this->db->table('species')->where([
                    'id' => (int) ($line['species_id'] ?? 0),
                    'active' => 1,
                ])->get()->getRowArray();
                if ($species === null) {
                    throw new DomainException('La especie solicitada no existe.', 'SPECIES_NOT_FOUND');
                }

                if (! empty($line['size_grade_id'])) {
                    $size = $this->db->table('size_grades')->where([
                        'id' => (int) $line['size_grade_id'],
                        'active' => 1,
                    ])->get()->getRowArray();
                    if ($size === null || ($size['species_id'] !== null && (int) $size['species_id'] !== (int) $species['id'])) {
                        throw new DomainException('La talla no corresponde a la especie solicitada.', 'SIZE_SPECIES_MISMATCH');
                    }
                }

                $this->db->table('raw_material_request_lines')->insert([
                    'request_id' => $requestId,
                    'line_number' => $index + 1,
                    'species_id' => (int) $line['species_id'],
                    'size_grade_id' => isset($line['size_grade_id']) && $line['size_grade_id'] !== '' ? (int) $line['size_grade_id'] : null,
                    'quality_grade' => $line['quality_grade'] ?? null,
                    'requested_weight_kg' => $quantity,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->audit->record(
                'raw_material_request.created',
                'raw_material_request',
                $requestId,
                $actorUserId,
                (int) $payload['company_id'],
                (int) $payload['site_id'],
                null,
                [
                    'folio' => $folio,
                    'request_type' => $requestType,
                    'source_warehouse_id' => $sourceWarehouseId,
                    'destination_warehouse_id' => $destinationWarehouseId,
                    'line_count' => count($lines),
                ]
            );

            $this->db->transCommit();
            return [
                'id' => $requestId,
                'folio' => $folio,
                'status' => 'requested',
                'request_type' => $requestType,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function authorize(int $requestId, bool $approved, int $actorUserId, ?string $reason = null): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $request = $this->db->query('SELECT * FROM raw_material_requests WHERE id = ? FOR UPDATE', [$requestId])->getRowArray();
            if ($request === null) {
                throw new DomainException('Solicitud no encontrada.', 'REQUEST_NOT_FOUND');
            }
            $target = $approved ? 'authorized' : 'rejected';
            WorkflowTransitions::request()->assertCanTransition((string) $request['status'], $target);
            if (! $approved && trim((string) $reason) === '') {
                throw new DomainException('El rechazo requiere motivo.', 'REJECTION_REASON_REQUIRED');
            }
            $now = date('Y-m-d H:i:s');
            $update = [
                'status' => $target,
                'lock_version' => (int) $request['lock_version'] + 1,
                'updated_at' => $now,
            ];
            if ($approved) {
                $update['authorized_by'] = $actorUserId;
                $update['authorized_at'] = $now;
            } else {
                $update['rejected_by'] = $actorUserId;
                $update['rejected_at'] = $now;
                $update['rejection_reason'] = $reason;
            }
            $this->db->table('raw_material_requests')->where('id', $requestId)->update($update);
            $this->audit->record(
                'raw_material_request.' . $target,
                'raw_material_request',
                $requestId,
                $actorUserId,
                (int) $request['company_id'],
                (int) $request['site_id'],
                ['status' => $request['status']],
                ['status' => $target],
                $reason
            );
            $this->db->transCommit();
            return ['id' => $requestId, 'status' => $target];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function availability(int $requestId): array
    {
        $request = $this->db->table('raw_material_requests r')
            ->select('r.*, sw.code AS source_warehouse_code, sw.name AS source_warehouse_name, ss.name AS source_site_name, dw.code AS destination_warehouse_code, dw.name AS destination_warehouse_name, ds.name AS destination_site_name')
            ->join('warehouses sw', 'sw.id = r.source_warehouse_id', 'left')
            ->join('sites ss', 'ss.id = sw.site_id', 'left')
            ->join('warehouses dw', 'dw.id = r.destination_warehouse_id', 'left')
            ->join('sites ds', 'ds.id = dw.site_id', 'left')
            ->where('r.id', $requestId)
            ->get()->getRowArray();
        if ($request === null) {
            throw new DomainException('Solicitud no encontrada.', 'REQUEST_NOT_FOUND');
        }

        $lines = $this->db->table('raw_material_request_lines')
            ->where('request_id', $requestId)
            ->orderBy('line_number')
            ->get()->getResultArray();

        foreach ($lines as &$line) {
            $isTransferRequest = ($request['request_type'] ?? 'production') === 'warehouse_transfer';

            if ($isTransferRequest) {
                $destinationWarehouseId = (int) ($request['destination_warehouse_id'] ?? 0);
                if ($destinationWarehouseId <= 0) {
                    throw new DomainException('La solicitud de traspaso no tiene almacén destino.', 'REQUEST_DESTINATION_WAREHOUSE_REQUIRED');
                }

                $candidates = $this->inventory->availableForWarehouseTransfer(
                    (int) $request['company_id'],
                    (int) $line['species_id'],
                    $line['size_grade_id'] === null ? null : (int) $line['size_grade_id'],
                    $line['quality_grade'],
                    $destinationWarehouseId,
                );

                $lineRemaining = max(0, round(
                    (float) $line['requested_weight_kg']
                    - (float) $line['reserved_weight_kg']
                    - (float) $line['fulfilled_weight_kg'],
                    3
                ));

                $candidates = array_values(array_filter(
                    $candidates,
                    static fn (array $candidate): bool =>
                        (float) ($candidate['reserved_weight_kg'] ?? 0) <= 0.0001
                        && (float) ($candidate['on_hand_weight_kg'] ?? 0) <= $lineRemaining + 0.001
                ));

                foreach ($candidates as &$candidate) {
                    $candidate['transfer_full_unit'] = true;
                    $candidate['available_weight_kg'] = round((float) ($candidate['on_hand_weight_kg'] ?? 0), 3);
                }
                unset($candidate);
            } else {
                $sourceWarehouseId = (int) ($request['source_warehouse_id'] ?? 0);
                if ($sourceWarehouseId > 0) {
                    $candidates = $this->inventory->availableByWarehouseCriteria(
                        (int) $request['company_id'],
                        (int) $request['site_id'],
                        $sourceWarehouseId,
                        (int) $line['species_id'],
                        $line['size_grade_id'] === null ? null : (int) $line['size_grade_id'],
                        $line['quality_grade'],
                    );
                } else {
                    // Compatibilidad con solicitudes históricas creadas antes de registrar almacén surtidor.
                    $candidates = $this->inventory->availableByCriteria(
                        (int) $request['company_id'],
                        (int) $request['site_id'],
                        (int) $line['species_id'],
                        $line['size_grade_id'] === null ? null : (int) $line['size_grade_id'],
                        $line['quality_grade'],
                    );
                }
            }

            $line['candidates'] = $candidates;
        }
        unset($line);

        $originWarehouses = [];
        if (($request['request_type'] ?? 'production') === 'warehouse_transfer') {
            $seenUnits = [];
            foreach ($lines as $line) {
                foreach ($line['candidates'] as $candidate) {
                    $warehouseId = (int) ($candidate['warehouse_id'] ?? 0);
                    $stockUnitId = (int) ($candidate['id'] ?? 0);
                    if ($warehouseId <= 0 || $stockUnitId <= 0 || isset($seenUnits[$stockUnitId])) {
                        continue;
                    }
                    $seenUnits[$stockUnitId] = true;

                    if (! isset($originWarehouses[$warehouseId])) {
                        $originWarehouses[$warehouseId] = [
                            'warehouse_id' => $warehouseId,
                            'site_name' => (string) ($candidate['site_name'] ?? ''),
                            'warehouse_code' => (string) ($candidate['warehouse_code'] ?? ''),
                            'warehouse_name' => (string) ($candidate['warehouse_name'] ?? ''),
                            'unit_count' => 0,
                            'available_weight_kg' => 0.0,
                        ];
                    }

                    $originWarehouses[$warehouseId]['unit_count']++;
                    $originWarehouses[$warehouseId]['available_weight_kg'] = round(
                        (float) $originWarehouses[$warehouseId]['available_weight_kg']
                        + (float) ($candidate['available_weight_kg'] ?? 0),
                        3
                    );
                }
            }

            uasort($originWarehouses, static function (array $a, array $b): int {
                return [$a['site_name'], $a['warehouse_name']] <=> [$b['site_name'], $b['warehouse_name']];
            });
        }

        return [
            'request' => $request,
            'lines' => $lines,
            'origin_warehouses' => array_values($originWarehouses),
        ];
    }
}
