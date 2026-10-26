<?php

namespace App\Modules\Inventory\Services;

use App\Common\Domain\MovementType;
use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Common\Services\FolioService;
use App\Common\Services\SettingsService;
use App\Modules\Inventory\DTO\CreateMovementData;
use App\Modules\Inventory\DTO\MovementLineData;
use App\Modules\Inventory\Repositories\InventoryRepository;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class InventoryService
{
    private const TERMINAL_STATES = ['dispatched', 'exhausted'];
    private const NON_CAPACITY_STATES = ['in_transit', 'dispatched', 'exhausted'];
    private const DIRECTLY_REVERSIBLE_TYPES = [
        'receipt',
        'relocation',
        'return',
        'loss',
        'adjustment_increase',
        'adjustment_decrease',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly InventoryRepository $inventory,
        private readonly FolioService $folios,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {
    }

    public function receiveWeighings(array $payload, int $actorUserId): array
    {
        $companyId = (int) $payload['company_id'];
        $siteId = (int) $payload['site_id'];
        $warehouseId = (int) $payload['warehouse_id'];
        $locationId = (int) $payload['location_id'];
        $weighingIds = array_values(array_map('intval', $payload['weighing_ids'] ?? []));

        if ($weighingIds === []) {
            throw new DomainException('La recepción requiere al menos un pesaje.', 'RECEPTION_LINES_REQUIRED');
        }
        if (in_array(0, $weighingIds, true) || count($weighingIds) !== count(array_unique($weighingIds))) {
            throw new DomainException('La recepción contiene pesajes inválidos o repetidos.', 'INVALID_RECEPTION_LINES');
        }

        $this->db->transException(true)->transBegin();

        try {
            $warehouse = $this->db->query('SELECT * FROM warehouses WHERE id = ? FOR UPDATE', [$warehouseId])->getRowArray();
            if ($warehouse === null || ! (bool) $warehouse['active'] || (int) $warehouse['company_id'] !== $companyId || (int) $warehouse['site_id'] !== $siteId) {
                throw new DomainException('El almacén receptor está fuera del alcance de la recepción.', 'RECEPTION_WAREHOUSE_MISMATCH');
            }
            $location = $this->lockAndValidateLocation($locationId, $warehouseId);
            $now = date('Y-m-d H:i:s');
            $receptionFolio = $this->folios->next('REC');
            $this->db->table('receptions')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'folio' => $receptionFolio,
                'company_id' => $companyId,
                'site_id' => $siteId,
                'warehouse_id' => $warehouseId,
                'status' => 'confirmed',
                'received_by' => $actorUserId,
                'received_at' => $now,
                'observations' => $payload['observations'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $receptionId = (int) $this->db->insertID();
            $documentId = $this->insertDocument(
                MovementType::Receipt,
                $companyId,
                $siteId,
                null,
                $warehouseId,
                'reception',
                $receptionId,
                $actorUserId,
                $payload['observations'] ?? null,
            );

            $createdUnits = [];
            $projectedUnits = $this->locationUnitCount($locationId);
            $projectedWeight = $this->locationWeight($locationId);

            foreach ($weighingIds as $index => $weighingId) {
                $context = $this->lockWeighingForReception($weighingId);
                $this->assertReceptionContext($context, $companyId, $siteId);
                $weight = round((float) $context['accepted_net_kg'], 3);
                $this->assertCapacity($location, ++$projectedUnits, $projectedWeight += $weight);
                $this->assertLocationSegregation($locationId, (int) $context['species_id'], (int) $context['lot_id']);
                $status = $this->initialStockStatus($context);

                $this->db->table('reception_lines')->insert([
                    'reception_id' => $receptionId,
                    'weighing_id' => $weighingId,
                    'label_id' => (int) $context['label_id'],
                    'destination_location_id' => $locationId,
                    'accepted_weight_kg' => $weight,
                    'status' => $status === 'rejected' ? 'rejected' : 'received',
                    'created_at' => $now,
                ]);
                $receptionLineId = (int) $this->db->insertID();
                $unitCode = (string) $context['label_code'];

                $this->db->table('stock_units')->insert([
                    'uuid' => Uuid::uuid7()->toString(),
                    'unit_code' => $unitCode,
                    'company_id' => $companyId,
                    'site_id' => $siteId,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId,
                    'reception_line_id' => $receptionLineId,
                    'weighing_id' => $weighingId,
                    'label_id' => (int) $context['label_id'],
                    'tub_id' => (int) $context['tub_id'],
                    'lot_id' => (int) $context['lot_id'],
                    'species_id' => (int) $context['species_id'],
                    'size_grade_id' => (int) $context['size_grade_id'],
                    'quality_grade' => $context['quality_grade'],
                    'original_weight_kg' => $weight,
                    'on_hand_weight_kg' => $weight,
                    'reserved_weight_kg' => 0,
                    'status' => $status,
                    'hold_reason' => $status === 'held' ? ($context['disposition'] ?? 'Inspección pendiente') : null,
                    'received_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $stockUnitId = (int) $this->db->insertID();
                $this->snapshotInitialOwner($stockUnitId);
                $lineId = $this->insertDocumentLine(
                    $documentId,
                    $index + 1,
                    $stockUnitId,
                    (int) $context['lot_id'],
                    $weight,
                    null,
                    $warehouseId,
                    null,
                    $locationId,
                    null,
                    $status,
                );
                $movementId = $this->insertLedgerEntry(
                    $documentId,
                    $lineId,
                    $stockUnitId,
                    (int) $context['lot_id'],
                    MovementType::Receipt,
                    $weight,
                    0,
                    null,
                    $locationId,
                    $weight,
                    0,
                    $actorUserId,
                    $now,
                );
                $this->insertLocationHistory($stockUnitId, $movementId, null, $locationId, $actorUserId, $now);
                $this->db->table('weighings')->where('id', $weighingId)->update([
                    'status' => 'received',
                    'lock_version' => (int) $context['weighing_lock_version'] + 1,
                    'updated_at' => $now,
                ]);
                $this->db->table('tubs')->where('id', (int) $context['tub_id'])->update([
                    'status' => 'received',
                    'lock_version' => (int) $context['tub_lock_version'] + 1,
                    'updated_at' => $now,
                ]);
                $createdUnits[] = ['id' => $stockUnitId, 'unit_code' => $unitCode, 'weight_kg' => $weight, 'status' => $status];
            }

            $this->audit->record(
                'inventory.receipt_confirmed',
                'reception',
                $receptionId,
                $actorUserId,
                $companyId,
                $siteId,
                null,
                ['folio' => $receptionFolio, 'inventory_document_id' => $documentId, 'stock_units' => $createdUnits]
            );
            $this->db->transCommit();

            return [
                'reception_id' => $receptionId,
                'folio' => $receptionFolio,
                'inventory_document_id' => $documentId,
                'stock_units' => $createdUnits,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function createMovement(CreateMovementData $data): array
    {
        $allowed = [
            MovementType::Relocation,
            MovementType::Return,
            MovementType::Loss,
            MovementType::AdjustmentIncrease,
            MovementType::AdjustmentDecrease,
        ];

        if (! in_array($data->type, $allowed, true)) {
            throw new DomainException('Este tipo de movimiento requiere su caso de uso especializado.', 'SPECIALIZED_MOVEMENT_REQUIRED');
        }

        if ($data->lines === []) {
            throw new DomainException('El movimiento requiere detalles.', 'MOVEMENT_LINES_REQUIRED');
        }

        $stockUnitIds = array_map(static fn (MovementLineData $line): int => $line->stockUnitId, $data->lines);
        if (count($stockUnitIds) !== count(array_unique($stockUnitIds))) {
            throw new DomainException('Una unidad no puede repetirse dentro del mismo movimiento.', 'DUPLICATE_MOVEMENT_STOCK_UNIT');
        }

        if (in_array($data->type, [MovementType::Return, MovementType::Loss, MovementType::AdjustmentIncrease, MovementType::AdjustmentDecrease], true)
            && trim((string) $data->reasonCode) === '') {
            throw new DomainException('La devolución, merma o ajuste requiere motivo.', 'MOVEMENT_REASON_REQUIRED');
        }

        $this->db->transException(true)->transBegin();

        try {
            $documentId = $this->insertDocument(
                $data->type,
                $data->companyId,
                $data->siteId,
                $data->originWarehouseId,
                $data->destinationWarehouseId,
                $data->sourceDocumentType,
                $data->sourceDocumentId,
                $data->actorUserId,
                $data->observations,
                $data->destinationDocumentType,
                $data->destinationDocumentId,
                $data->reasonCode,
            );
            $results = [];

            foreach ($data->lines as $index => $line) {
                $results[] = $this->applyExistingLine($documentId, $index + 1, $data, $line);
            }

            $this->audit->record(
                'inventory.movement_confirmed',
                'inventory_document',
                $documentId,
                $data->actorUserId,
                $data->companyId,
                $data->siteId,
                null,
                ['movement_type' => $data->type->value, 'lines' => $results],
                $data->reasonCode
            );
            $this->db->transCommit();

            return ['document_id' => $documentId, 'movement_type' => $data->type->value, 'lines' => $results];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function reserveForRequest(int $requestId, array $selections, int $actorUserId, ?string $expiresAt = null): array
    {
        if ($selections === []) {
            throw new DomainException('Selecciona al menos una unidad específica.', 'RESERVATION_LINES_REQUIRED');
        }

        $this->db->transException(true)->transBegin();

        try {
            $request = $this->db->query('SELECT * FROM raw_material_requests WHERE id = ? FOR UPDATE', [$requestId])->getRowArray();

            if ($request === null || ! in_array($request['status'], ['authorized', 'reserved', 'partially_fulfilled'], true)) {
                throw new DomainException('La solicitud no está autorizada para reservar.', 'REQUEST_NOT_AUTHORIZED');
            }

            $isTransferRequest = ($request['request_type'] ?? 'production') === 'warehouse_transfer';
            $destinationWarehouseId = $isTransferRequest ? (int) ($request['destination_warehouse_id'] ?? 0) : null;
            $sourceWarehouseId = ! $isTransferRequest ? (int) ($request['source_warehouse_id'] ?? 0) : null;
            if ($isTransferRequest && $destinationWarehouseId <= 0) {
                throw new DomainException('La solicitud de traspaso no tiene almacén destino.', 'REQUEST_DESTINATION_WAREHOUSE_REQUIRED');
            }

            $now = date('Y-m-d H:i:s');
            $expiresAt ??= date('Y-m-d H:i:s', time() + ((int) $this->settings->get(
                'reservation.duration_minutes',
                (int) $request['company_id'],
                (int) $request['site_id'],
                120
            ) * MINUTE));
            if ($expiresAt <= $now) {
                throw new DomainException('La vigencia de la reserva debe ser posterior a la fecha actual.', 'INVALID_RESERVATION_EXPIRY');
            }
            $reservationFolio = $this->folios->next('RSV');
            $this->db->table('reservations')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'folio' => $reservationFolio,
                'request_id' => $requestId,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'created_by' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $reservationId = (int) $this->db->insertID();
            $documentId = $this->insertDocument(
                MovementType::Reservation,
                (int) $request['company_id'],
                (int) $request['site_id'],
                null,
                null,
                'reservation',
                $reservationId,
                $actorUserId,
                'Reserva de unidades específicas para ' . $request['folio'],
            );
            $reserved = [];
            $seenStockUnits = [];
            $transferOriginWarehouseId = null;

            foreach (array_values($selections) as $index => $selection) {
                $requestLineId = (int) $selection['request_line_id'];
                $stockUnitId = (int) $selection['stock_unit_id'];
                if ($stockUnitId <= 0 || isset($seenStockUnits[$stockUnitId])) {
                    throw new DomainException('La selección contiene unidades inválidas o repetidas.', 'INVALID_RESERVATION_SELECTION');
                }
                $seenStockUnits[$stockUnitId] = true;
                $quantity = round((float) $selection['quantity_kg'], 3);
                $requestLine = $this->db->query(
                    'SELECT * FROM raw_material_request_lines WHERE id = ? AND request_id = ? FOR UPDATE',
                    [$requestLineId, $requestId]
                )->getRowArray();

                if ($requestLine === null) {
                    throw new DomainException('Detalle de solicitud inválido.', 'REQUEST_LINE_NOT_FOUND');
                }

                $stock = $this->inventory->findStockUnitForUpdate($stockUnitId);
                $this->assertStockMatchesRequest($stock, $requestLine);
                $available = round((float) $stock['on_hand_weight_kg'] - (float) $stock['reserved_weight_kg'], 3);
                $requestRemaining = round((float) $requestLine['requested_weight_kg'] - (float) $requestLine['reserved_weight_kg'] - (float) $requestLine['fulfilled_weight_kg'], 3);

                if ($isTransferRequest) {
                    if ((int) $stock['company_id'] !== (int) $request['company_id']) {
                        throw new DomainException('La tina seleccionada pertenece a otra empresa.', 'TRANSFER_STOCK_COMPANY_MISMATCH', ['stock_unit_id' => $stockUnitId]);
                    }
                    if ((int) $stock['warehouse_id'] === $destinationWarehouseId) {
                        throw new DomainException('La tina ya pertenece al almacén destino.', 'TRANSFER_SAME_WAREHOUSE', ['stock_unit_id' => $stockUnitId]);
                    }
                    if ($transferOriginWarehouseId === null) {
                        $transferOriginWarehouseId = (int) $stock['warehouse_id'];
                    } elseif ($transferOriginWarehouseId !== (int) $stock['warehouse_id']) {
                        throw new DomainException(
                            'Una reserva de traspaso debe contener tinas de un solo almacén origen.',
                            'TRANSFER_SINGLE_ORIGIN_REQUIRED'
                        );
                    }
                    if ((float) $stock['reserved_weight_kg'] > 0.0001
                        || abs($quantity - (float) $stock['on_hand_weight_kg']) > 0.001) {
                        throw new DomainException(
                            'Para una solicitud de traspaso se reserva la tina completa y sin reservas previas.',
                            'TRANSFER_FULL_UNIT_RESERVATION_REQUIRED',
                            ['stock_unit_id' => $stockUnitId]
                        );
                    }
                } elseif ($sourceWarehouseId !== null && $sourceWarehouseId > 0) {
                    if ((int) $stock['company_id'] !== (int) $request['company_id']
                        || (int) $stock['site_id'] !== (int) $request['site_id']
                        || (int) $stock['warehouse_id'] !== $sourceWarehouseId) {
                        throw new DomainException(
                            'La tina seleccionada no pertenece al almacén surtidor definido en la solicitud de producción.',
                            'PRODUCTION_SOURCE_WAREHOUSE_MISMATCH',
                            ['stock_unit_id' => $stockUnitId, 'source_warehouse_id' => $sourceWarehouseId]
                        );
                    }
                }

                if ($quantity <= 0 || $quantity > $available) {
                    throw new DomainException('Cantidad de reserva no disponible.', 'INSUFFICIENT_AVAILABLE_STOCK', ['stock_unit_id' => $stockUnitId, 'available_kg' => $available]);
                }
                if ($isTransferRequest && $requestRemaining <= 0.001) {
                    throw new DomainException('La línea solicitada ya está cubierta.', 'REQUEST_ALREADY_COVERED', ['request_line_id' => $requestLineId]);
                }
                if (! $isTransferRequest && $quantity > $requestRemaining) {
                    throw new DomainException('La cantidad supera el saldo pendiente de la solicitud.', 'REQUEST_QUANTITY_EXCEEDED', ['request_line_id' => $requestLineId, 'remaining_kg' => max(0, $requestRemaining)]);
                }

                $newReserved = round((float) $stock['reserved_weight_kg'] + $quantity, 3);
                $newStatus = $newReserved >= (float) $stock['on_hand_weight_kg'] ? 'reserved' : 'available';
                $lineId = $this->insertDocumentLine(
                    $documentId,
                    $index + 1,
                    $stockUnitId,
                    (int) $stock['lot_id'],
                    $quantity,
                    (int) $stock['warehouse_id'],
                    (int) $stock['warehouse_id'],
                    (int) $stock['location_id'],
                    (int) $stock['location_id'],
                    (string) $stock['status'],
                    $newStatus,
                );
                $this->insertLedgerEntry(
                    $documentId,
                    $lineId,
                    $stockUnitId,
                    (int) $stock['lot_id'],
                    MovementType::Reservation,
                    0,
                    $quantity,
                    (int) $stock['location_id'],
                    (int) $stock['location_id'],
                    (float) $stock['on_hand_weight_kg'],
                    $newReserved,
                    $actorUserId,
                    $now,
                );
                $this->updateStockProjection($stock, (float) $stock['on_hand_weight_kg'], $newReserved, (int) $stock['warehouse_id'], (int) $stock['location_id'], $newStatus, $now);
                $this->db->table('reservation_lines')->insert([
                    'reservation_id' => $reservationId,
                    'request_line_id' => $requestLineId,
                    'stock_unit_id' => $stockUnitId,
                    'reserved_weight_kg' => $quantity,
                    'fulfilled_weight_kg' => 0,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $newLineReserved = round((float) $requestLine['reserved_weight_kg'] + $quantity, 3);
                $this->db->table('raw_material_request_lines')->where('id', $requestLineId)->update([
                    'reserved_weight_kg' => $newLineReserved,
                    'status' => $newLineReserved >= (float) $requestLine['requested_weight_kg'] ? 'reserved' : 'partial',
                    'updated_at' => $now,
                ]);
                $reserved[] = ['stock_unit_id' => $stockUnitId, 'quantity_kg' => $quantity];
            }

            $this->db->table('raw_material_requests')->where('id', $requestId)->update([
                'status' => 'reserved',
                'lock_version' => (int) $request['lock_version'] + 1,
                'updated_at' => $now,
            ]);
            $this->audit->record('inventory.reserved', 'reservation', $reservationId, $actorUserId, (int) $request['company_id'], (int) $request['site_id'], null, ['folio' => $reservationFolio, 'lines' => $reserved]);
            $this->db->transCommit();

            return ['reservation_id' => $reservationId, 'folio' => $reservationFolio, 'expires_at' => $expiresAt, 'inventory_document_id' => $documentId, 'lines' => $reserved];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function releaseReservation(int $reservationId, string $reason, int $actorUserId): array
    {
        if (trim($reason) === '') {
            throw new DomainException('La liberación requiere motivo.', 'RELEASE_REASON_REQUIRED');
        }

        $this->db->transException(true)->transBegin();

        try {
            $reservation = $this->db->query(<<<'SQL'
SELECT r.*, req.company_id, req.site_id
FROM reservations r
INNER JOIN raw_material_requests req ON req.id = r.request_id
WHERE r.id = ?
FOR UPDATE
SQL, [$reservationId])->getRowArray();

            if ($reservation === null || $reservation['status'] !== 'active') {
                throw new DomainException('La reserva no está activa.', 'RESERVATION_NOT_ACTIVE');
            }

            $lines = $this->db->table('reservation_lines')->where(['reservation_id' => $reservationId, 'status' => 'active'])->get()->getResultArray();
            if ($lines === []) {
                throw new DomainException('La reserva no contiene saldos por liberar.', 'RESERVATION_LINES_REQUIRED');
            }
            $documentId = $this->insertDocument(
                MovementType::Release,
                (int) $reservation['company_id'],
                (int) $reservation['site_id'],
                null,
                null,
                'reservation_release',
                $reservationId,
                $actorUserId,
                $reason,
                reasonCode: 'reservation_release',
            );
            $now = date('Y-m-d H:i:s');

            foreach ($lines as $index => $reservationLine) {
                $stock = $this->inventory->findStockUnitForUpdate((int) $reservationLine['stock_unit_id']);
                $remaining = round((float) $reservationLine['reserved_weight_kg'] - (float) $reservationLine['fulfilled_weight_kg'], 3);

                if ($remaining <= 0) {
                    continue;
                }

                if ($remaining > (float) $stock['reserved_weight_kg']) {
                    throw new DomainException('La reserva no concilia con la unidad de inventario.', 'RESERVATION_BALANCE_MISMATCH');
                }

                $newReserved = round((float) $stock['reserved_weight_kg'] - $remaining, 3);
                $newStatus = $newReserved > 0 ? 'reserved' : 'available';
                $lineId = $this->insertDocumentLine(
                    $documentId,
                    $index + 1,
                    (int) $stock['id'],
                    (int) $stock['lot_id'],
                    $remaining,
                    (int) $stock['warehouse_id'],
                    (int) $stock['warehouse_id'],
                    (int) $stock['location_id'],
                    (int) $stock['location_id'],
                    (string) $stock['status'],
                    $newStatus,
                );
                $this->insertLedgerEntry($documentId, $lineId, (int) $stock['id'], (int) $stock['lot_id'], MovementType::Release, 0, -$remaining, (int) $stock['location_id'], (int) $stock['location_id'], (float) $stock['on_hand_weight_kg'], $newReserved, $actorUserId, $now);
                $this->updateStockProjection($stock, (float) $stock['on_hand_weight_kg'], $newReserved, (int) $stock['warehouse_id'], (int) $stock['location_id'], $newStatus, $now);
                $this->db->table('reservation_lines')->where('id', $reservationLine['id'])->update(['status' => 'released', 'updated_at' => $now]);
                $requestLine = $this->db->query('SELECT * FROM raw_material_request_lines WHERE id = ? FOR UPDATE', [$reservationLine['request_line_id']])->getRowArray();
                if ($requestLine === null) {
                    throw new DomainException('El detalle de solicitud asociado ya no existe.', 'REQUEST_LINE_NOT_FOUND');
                }
                $lineReserved = max(0, round((float) $requestLine['reserved_weight_kg'] - $remaining, 3));
                $lineFulfilled = (float) $requestLine['fulfilled_weight_kg'];
                $lineStatus = $lineFulfilled >= (float) $requestLine['requested_weight_kg']
                    ? 'fulfilled'
                    : (($lineReserved > 0 || $lineFulfilled > 0) ? 'partial' : 'pending');
                $this->db->table('raw_material_request_lines')->where('id', $reservationLine['request_line_id'])->update([
                    'reserved_weight_kg' => $lineReserved,
                    'status' => $lineStatus,
                    'updated_at' => $now,
                ]);
            }

            $this->db->table('reservations')->where('id', $reservationId)->update([
                'status' => 'released',
                'released_by' => $actorUserId,
                'released_at' => $now,
                'release_reason' => $reason,
                'lock_version' => (int) $reservation['lock_version'] + 1,
                'updated_at' => $now,
            ]);
            $totals = $this->db->table('raw_material_request_lines')->select('SUM(requested_weight_kg) AS requested, SUM(reserved_weight_kg) AS reserved, SUM(fulfilled_weight_kg) AS fulfilled')->where('request_id', $reservation['request_id'])->get()->getRowArray();
            $requestStatus = match (true) {
                (float) $totals['fulfilled'] >= (float) $totals['requested'] => 'fulfilled',
                (float) $totals['fulfilled'] > 0 => 'partially_fulfilled',
                (float) $totals['reserved'] > 0 => 'reserved',
                default => 'authorized',
            };
            $this->db->table('raw_material_requests')->where('id', $reservation['request_id'])->set('status', $requestStatus)->set('lock_version', 'lock_version + 1', false)->set('updated_at', $now)->update();
            $this->audit->record('inventory.reservation_released', 'reservation', $reservationId, $actorUserId, (int) $reservation['company_id'], (int) $reservation['site_id'], null, ['inventory_document_id' => $documentId], $reason);
            $this->db->transCommit();

            return ['reservation_id' => $reservationId, 'inventory_document_id' => $documentId, 'status' => 'released', 'request_status' => $requestStatus];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function fulfillReservation(int $reservationId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $reservation = $this->db->query(<<<'SQL'
SELECT r.*, req.company_id, req.site_id, req.status AS request_status, req.request_type
FROM reservations r
INNER JOIN raw_material_requests req ON req.id = r.request_id
WHERE r.id = ?
FOR UPDATE
SQL, [$reservationId])->getRowArray();
            if ($reservation === null || $reservation['status'] !== 'active') {
                throw new DomainException('La reserva no está activa para surtido.', 'RESERVATION_NOT_ACTIVE');
            }
            if (($reservation['request_type'] ?? 'production') === 'warehouse_transfer') {
                throw new DomainException(
                    'Esta reserva corresponde a un traspaso. Genera el traspaso; no debe surtirse como salida a producción.',
                    'TRANSFER_REQUEST_MUST_CREATE_TRANSFER'
                );
            }
            if ($reservation['expires_at'] < date('Y-m-d H:i:s')) {
                throw new DomainException('La reserva venció; libérala y realiza una selección nueva.', 'RESERVATION_EXPIRED');
            }
            $lines = $this->db->table('reservation_lines')->where(['reservation_id' => $reservationId, 'status' => 'active'])->orderBy('id')->get()->getResultArray();
            if ($lines === []) {
                throw new DomainException('La reserva no contiene saldos por surtir.', 'RESERVATION_LINES_REQUIRED');
            }
            $documentId = $this->insertDocument(
                MovementType::ProductionIssue,
                (int) $reservation['company_id'],
                (int) $reservation['site_id'],
                null,
                null,
                'reservation_fulfillment',
                $reservationId,
                $actorUserId,
                'Surtido de reserva ' . $reservation['folio'],
            );
            $now = date('Y-m-d H:i:s');
            $results = [];
            foreach ($lines as $index => $reservationLine) {
                $quantity = round((float) $reservationLine['reserved_weight_kg'] - (float) $reservationLine['fulfilled_weight_kg'], 3);
                if ($quantity <= 0) {
                    continue;
                }
                $stock = $this->inventory->findStockUnitForUpdate((int) $reservationLine['stock_unit_id']);
                if ($quantity > (float) $stock['reserved_weight_kg'] || $quantity > (float) $stock['on_hand_weight_kg']) {
                    throw new DomainException('La reserva no concilia con la existencia física.', 'RESERVATION_BALANCE_MISMATCH', ['stock_unit_id' => $stock['id']]);
                }
                $newOnHand = round((float) $stock['on_hand_weight_kg'] - $quantity, 3);
                $newReserved = round((float) $stock['reserved_weight_kg'] - $quantity, 3);
                $newStatus = $newOnHand <= 0 ? 'exhausted' : ($newReserved > 0 ? 'reserved' : 'available');
                $lineId = $this->insertDocumentLine($documentId, $index + 1, (int) $stock['id'], (int) $stock['lot_id'], $quantity, (int) $stock['warehouse_id'], null, (int) $stock['location_id'], null, (string) $stock['status'], $newStatus);
                $this->insertLedgerEntry($documentId, $lineId, (int) $stock['id'], (int) $stock['lot_id'], MovementType::ProductionIssue, -$quantity, -$quantity, (int) $stock['location_id'], null, $newOnHand, $newReserved, $actorUserId, $now);
                $this->updateStockProjection($stock, $newOnHand, $newReserved, (int) $stock['warehouse_id'], (int) $stock['location_id'], $newStatus, $now);
                $this->db->table('reservation_lines')->where('id', $reservationLine['id'])->update(['fulfilled_weight_kg' => $reservationLine['reserved_weight_kg'], 'status' => 'fulfilled', 'updated_at' => $now]);
                $requestLine = $this->db->query('SELECT * FROM raw_material_request_lines WHERE id = ? FOR UPDATE', [$reservationLine['request_line_id']])->getRowArray();
                if ($requestLine === null) {
                    throw new DomainException('El detalle de solicitud asociado ya no existe.', 'REQUEST_LINE_NOT_FOUND');
                }
                $fulfilled = round((float) $requestLine['fulfilled_weight_kg'] + $quantity, 3);
                $remainingReserved = max(0, round((float) $requestLine['reserved_weight_kg'] - $quantity, 3));
                $this->db->table('raw_material_request_lines')->where('id', $requestLine['id'])->update([
                    'fulfilled_weight_kg' => $fulfilled,
                    'reserved_weight_kg' => $remainingReserved,
                    'status' => $fulfilled >= (float) $requestLine['requested_weight_kg'] ? 'fulfilled' : 'partial',
                    'updated_at' => $now,
                ]);
                $results[] = ['stock_unit_id' => (int) $stock['id'], 'quantity_kg' => $quantity, 'remaining_kg' => $newOnHand];
            }
            if ($results === []) {
                throw new DomainException('La reserva no contiene saldo por surtir.', 'RESERVATION_ALREADY_FULFILLED');
            }
            $totals = $this->db->table('raw_material_request_lines')->select('SUM(requested_weight_kg) AS requested, SUM(fulfilled_weight_kg) AS fulfilled')->where('request_id', $reservation['request_id'])->get()->getRowArray();
            $requestStatus = (float) $totals['fulfilled'] >= (float) $totals['requested'] ? 'fulfilled' : 'partially_fulfilled';
            $this->db->table('reservations')->where('id', $reservationId)->update(['status' => 'fulfilled', 'lock_version' => (int) $reservation['lock_version'] + 1, 'updated_at' => $now]);
            $this->db->table('raw_material_requests')->where('id', $reservation['request_id'])->set('status', $requestStatus)->set('lock_version', 'lock_version + 1', false)->set('updated_at', $now)->update();
            $this->audit->record('inventory.reservation_fulfilled', 'reservation', $reservationId, $actorUserId, (int) $reservation['company_id'], (int) $reservation['site_id'], null, ['inventory_document_id' => $documentId, 'request_status' => $requestStatus, 'lines' => $results]);
            $this->db->transCommit();
            return ['reservation_id' => $reservationId, 'request_id' => (int) $reservation['request_id'], 'status' => 'fulfilled', 'request_status' => $requestStatus, 'inventory_document_id' => $documentId, 'lines' => $results];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function shipTransfer(int $transferId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $transfer = $this->db->query('SELECT * FROM transfers WHERE id = ? FOR UPDATE', [$transferId])->getRowArray();
            if ($transfer === null || ! in_array($transfer['status'], ['authorized', 'prepared'], true)) {
                throw new DomainException('El traspaso no está listo para embarque.', 'TRANSFER_NOT_SHIPPABLE');
            }
            if (($transfer['transfer_type'] ?? '') === 'warehouse_request') {
                throw new DomainException('Gestiona este traspaso desde Solicitudes entre almacenes.', 'WAREHOUSE_REQUEST_WORKFLOW_REQUIRED');
            }
            $isRequestTransfer = ($transfer['transfer_type'] ?? 'administrative') === 'request' && ! empty($transfer['reservation_id']);
            $lines = $this->db->table('transfer_lines')->where('transfer_id', $transferId)->orderBy('line_number')->get()->getResultArray();
            if ($lines === []) {
                throw new DomainException('El traspaso no contiene unidades.', 'TRANSFER_LINES_REQUIRED');
            }
            $origin = $this->db->table('warehouses')->where('id', $transfer['origin_warehouse_id'])->get()->getRowArray();
            $destination = $this->db->table('warehouses')->where('id', $transfer['destination_warehouse_id'])->get()->getRowArray();
            if ($origin === null || $destination === null
                || ! (bool) $origin['active'] || ! (bool) $destination['active']
                || (int) $origin['company_id'] !== (int) $transfer['company_id']
                || (int) $destination['company_id'] !== (int) $transfer['company_id']) {
                throw new DomainException('Los almacenes del traspaso ya no están disponibles.', 'TRANSFER_WAREHOUSE_MISMATCH');
            }
            $documentId = $this->insertDocument(
                MovementType::TransferShipment,
                (int) $transfer['company_id'],
                (int) $origin['site_id'],
                (int) $transfer['origin_warehouse_id'],
                (int) $transfer['destination_warehouse_id'],
                'transfer_shipment',
                $transferId,
                $actorUserId,
                $transfer['observations'],
            );
            $now = date('Y-m-d H:i:s');
            $results = [];

            foreach ($lines as $index => $transferLine) {
                $stock = $this->inventory->findStockUnitForUpdate((int) $transferLine['stock_unit_id']);
                $outboundWeight = $this->db->table('transfer_weighings')
                    ->where([
                        'transfer_line_id' => (int) $transferLine['id'],
                        'direction' => 'outbound',
                        'status' => 'confirmed',
                        'is_current' => 1,
                    ])->get()->getRowArray();
                if ($outboundWeight === null) {
                    throw new DomainException(
                        'Todas las tinas deben tener pesaje de salida confirmado antes de embarcar.',
                        'TRANSFER_OUTBOUND_WEIGHT_REQUIRED',
                        ['stock_unit_id' => (int) $transferLine['stock_unit_id']]
                    );
                }
                $shipWeight = round((float) $outboundWeight['weight_kg'], 3);

                if ((string) $transferLine['status'] !== 'prepared'
                    || (int) $stock['company_id'] !== (int) $transfer['company_id']
                    || (int) $stock['site_id'] !== (int) $origin['site_id']
                    || (int) $stock['warehouse_id'] !== (int) $transfer['origin_warehouse_id']) {
                    throw new DomainException('Una unidad ya no está disponible en el almacén origen.', 'TRANSFER_STOCK_NOT_AVAILABLE', ['stock_unit_id' => $stock['id']]);
                }

                $reservedDelta = 0.0;
                if ($isRequestTransfer) {
                    $reservationLine = $this->db->query(
                        'SELECT * FROM reservation_lines WHERE reservation_id = ? AND stock_unit_id = ? FOR UPDATE',
                        [(int) $transfer['reservation_id'], (int) $stock['id']]
                    )->getRowArray();
                    if ($reservationLine === null
                        || ! in_array((string) $reservationLine['status'], ['active', 'in_transfer'], true)
                        || $stock['status'] !== 'reserved'
                        || abs((float) $reservationLine['reserved_weight_kg'] - (float) $stock['on_hand_weight_kg']) > 0.001
                        || abs((float) $stock['reserved_weight_kg'] - (float) $stock['on_hand_weight_kg']) > 0.001) {
                        throw new DomainException(
                            'La tina ya no concilia con la reserva que originó el traspaso.',
                            'TRANSFER_RESERVATION_BALANCE_MISMATCH',
                            ['stock_unit_id' => $stock['id']]
                        );
                    }
                    $reservedDelta = -(float) $stock['reserved_weight_kg'];
                    $this->db->table('reservation_lines')->where('id', $reservationLine['id'])->update([
                        'status' => 'in_transfer',
                        'updated_at' => $now,
                    ]);
                } else {
                    if ($stock['status'] !== 'available'
                        || (float) $stock['reserved_weight_kg'] > 0) {
                        throw new DomainException('El traspaso requiere la unidad completa y sin reserva.', 'TRANSFER_FULL_UNRESERVED_UNIT_REQUIRED', ['stock_unit_id' => $stock['id']]);
                    }
                }

                $weightDelta = round($shipWeight - (float) $stock['on_hand_weight_kg'], 3);
                $lineId = $this->insertDocumentLine($documentId, $index + 1, (int) $stock['id'], (int) $stock['lot_id'], $shipWeight, (int) $stock['warehouse_id'], (int) $transfer['destination_warehouse_id'], (int) $stock['location_id'], null, (string) $stock['status'], 'in_transit');
                $this->insertLedgerEntry($documentId, $lineId, (int) $stock['id'], (int) $stock['lot_id'], MovementType::TransferShipment, $weightDelta, $reservedDelta, (int) $stock['location_id'], null, $shipWeight, 0, $actorUserId, $now);
                $this->updateStockProjection($stock, $shipWeight, 0, (int) $stock['warehouse_id'], (int) $stock['location_id'], 'in_transit', $now);
                $this->db->table('transfer_lines')->where('id', $transferLine['id'])->update([
                    'shipped_weight_kg' => $shipWeight,
                    'status' => 'shipped',
                    'updated_at' => $now,
                ]);
                $results[] = [
                    'stock_unit_id' => (int) $stock['id'],
                    'shipped_weight_kg' => $shipWeight,
                    'difference_from_inventory_kg' => $weightDelta,
                    'status' => 'in_transit',
                ];
            }
            $this->db->table('transfers')->where('id', $transferId)->update(['status' => 'in_transit', 'shipped_by' => $actorUserId, 'shipped_at' => $now, 'lock_version' => (int) $transfer['lock_version'] + 1, 'updated_at' => $now]);
            $this->audit->record('transfer.shipped', 'transfer', $transferId, $actorUserId, (int) $transfer['company_id'], (int) $origin['site_id'], null, ['inventory_document_id' => $documentId, 'lines' => $results]);
            $this->db->transCommit();
            return ['transfer_id' => $transferId, 'status' => 'in_transit', 'inventory_document_id' => $documentId, 'lines' => $results];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function receiveTransfer(int $transferId, int $rowLocationId, array $receivedStockUnitIds, int $actorUserId): array
    {
        $receivedStockUnitIds = array_values(array_unique(array_map('intval', $receivedStockUnitIds)));
        if ($receivedStockUnitIds === [] || in_array(0, $receivedStockUnitIds, true)) {
            throw new DomainException('Selecciona al menos una tina recibida.', 'TRANSFER_RECEIVED_UNITS_REQUIRED');
        }

        $this->db->transException(true)->transBegin();
        try {
            $transfer = $this->db->query('SELECT * FROM transfers WHERE id = ? FOR UPDATE', [$transferId])->getRowArray();
            if ($transfer === null || ! in_array((string) $transfer['status'], ['in_transit', 'discrepancy'], true)) {
                throw new DomainException('El traspaso no está disponible para recepción.', 'TRANSFER_NOT_RECEIVABLE');
            }
            if (($transfer['transfer_type'] ?? '') === 'warehouse_request') {
                throw new DomainException('Gestiona este traspaso desde Solicitudes entre almacenes.', 'WAREHOUSE_REQUEST_WORKFLOW_REQUIRED');
            }
            $isRequestTransfer = ($transfer['transfer_type'] ?? 'administrative') === 'request'
                && ! empty($transfer['reservation_id'])
                && ! empty($transfer['request_id']);

            $origin = $this->db->table('warehouses')->where('id', $transfer['origin_warehouse_id'])->get()->getRowArray();
            $destination = $this->db->table('warehouses')->where('id', $transfer['destination_warehouse_id'])->get()->getRowArray();
            if ($origin === null || (int) $origin['company_id'] !== (int) $transfer['company_id']
                || $destination === null || ! (bool) $destination['active'] || (int) $destination['company_id'] !== (int) $transfer['company_id']) {
                throw new DomainException('Almacén destino no encontrado.', 'DESTINATION_WAREHOUSE_NOT_FOUND');
            }

            // La UI selecciona Cámara + Fila. La posición interna se resuelve por cada tina.
            $row = $this->db->query('SELECT * FROM locations WHERE id = ? FOR UPDATE', [$rowLocationId])->getRowArray();
            if ($row === null || ! (bool) $row['active'] || (int) $row['warehouse_id'] !== (int) $destination['id']
                || ! in_array((string) $row['location_type'], ['row', 'aisle'], true)) {
                throw new DomainException('La fila seleccionada no pertenece al almacén destino.', 'INVALID_TRANSFER_RECEIPT_ROW');
            }

            $lines = $this->db->table('transfer_lines')->where('transfer_id', $transferId)->orderBy('line_number')->get()->getResultArray();
            if ($lines === []) {
                throw new DomainException('El traspaso no contiene unidades.', 'TRANSFER_LINES_REQUIRED');
            }

            $lineByStock = [];
            foreach ($lines as $line) {
                $lineByStock[(int) $line['stock_unit_id']] = $line;
            }
            foreach ($receivedStockUnitIds as $stockUnitId) {
                if (! isset($lineByStock[$stockUnitId])) {
                    throw new DomainException('Se informó una tina que no pertenece al traspaso.', 'TRANSFER_RECEIPT_UNIT_MISMATCH', ['stock_unit_id' => $stockUnitId]);
                }
                if (! in_array((string) $lineByStock[$stockUnitId]['status'], ['shipped', 'missing'], true)) {
                    throw new DomainException('Una tina seleccionada ya fue recibida o no está disponible.', 'TRANSFER_RECEIPT_UNIT_ALREADY_PROCESSED', ['stock_unit_id' => $stockUnitId]);
                }
            }

            $documentId = $this->insertDocument(
                MovementType::TransferReceipt,
                (int) $transfer['company_id'],
                (int) $destination['site_id'],
                (int) $transfer['origin_warehouse_id'],
                (int) $transfer['destination_warehouse_id'],
                'transfer_receipt',
                $transferId,
                $actorUserId,
                $transfer['observations']
            );

            $now = date('Y-m-d H:i:s');
            $results = [];
            $documentLineNumber = 0;

            foreach ($lines as $transferLine) {
                $stockUnitId = (int) $transferLine['stock_unit_id'];
                if (! in_array($stockUnitId, $receivedStockUnitIds, true)) {
                    if (in_array((string) $transferLine['status'], ['shipped', 'missing'], true)) {
                        $this->db->table('transfer_lines')->where('id', $transferLine['id'])->update([
                            'status' => 'missing',
                            'updated_at' => $now,
                        ]);
                    }
                    continue;
                }

                $stock = $this->inventory->findStockUnitForUpdate($stockUnitId);
                if ((int) $stock['company_id'] !== (int) $transfer['company_id']
                    || (int) $stock['site_id'] !== (int) $origin['site_id']
                    || (int) $stock['warehouse_id'] !== (int) $transfer['origin_warehouse_id']
                    || $stock['status'] !== 'in_transit') {
                    throw new DomainException('Una tina ya no está en tránsito.', 'TRANSFER_STOCK_NOT_IN_TRANSIT', ['stock_unit_id' => $stock['id']]);
                }

                $inboundWeight = $this->db->table('transfer_weighings')
                    ->where([
                        'transfer_line_id' => (int) $transferLine['id'],
                        'direction' => 'inbound',
                        'status' => 'confirmed',
                        'is_current' => 1,
                    ])->get()->getRowArray();
                if ($inboundWeight === null) {
                    throw new DomainException(
                        'Cada tina marcada como recibida debe tener pesaje de recepción confirmado.',
                        'TRANSFER_INBOUND_WEIGHT_REQUIRED',
                        ['stock_unit_id' => $stockUnitId]
                    );
                }
                $weight = round((float) $inboundWeight['weight_kg'], 3);
                $transferDifference = round($weight - (float) $transferLine['shipped_weight_kg'], 3);
                $targetLocationId = $this->resolveRelocationTargetForUpdate(
                    $stock,
                    $rowLocationId,
                    (int) $destination['id'],
                    $weight,
                    'available'
                );

                $documentLineNumber++;
                $lineId = $this->insertDocumentLine(
                    $documentId,
                    $documentLineNumber,
                    (int) $stock['id'],
                    (int) $stock['lot_id'],
                    $weight,
                    (int) $stock['warehouse_id'],
                    (int) $destination['id'],
                    (int) $stock['location_id'],
                    $targetLocationId,
                    (string) $stock['status'],
                    'available'
                );
                $movementId = $this->insertLedgerEntry(
                    $documentId,
                    $lineId,
                    (int) $stock['id'],
                    (int) $stock['lot_id'],
                    MovementType::TransferReceipt,
                    round($weight - (float) $stock['on_hand_weight_kg'], 3),
                    0,
                    (int) $stock['location_id'],
                    $targetLocationId,
                    $weight,
                    0,
                    $actorUserId,
                    $now
                );

                $this->updateStockProjection($stock, $weight, 0, (int) $destination['id'], $targetLocationId, 'available', $now, (int) $destination['site_id']);
                $this->insertLocationHistory((int) $stock['id'], $movementId, (int) $stock['location_id'], $targetLocationId, $actorUserId, $now);
                $this->db->table('transfer_lines')->where('id', $transferLine['id'])->update([
                    'received_weight_kg' => $weight,
                    'difference_kg' => $transferDifference,
                    'status' => 'received',
                    'updated_at' => $now,
                ]);

                if ($isRequestTransfer) {
                    $reservationLine = $this->db->query(
                        'SELECT * FROM reservation_lines WHERE reservation_id = ? AND stock_unit_id = ? FOR UPDATE',
                        [(int) $transfer['reservation_id'], $stockUnitId]
                    )->getRowArray();
                    if ($reservationLine === null) {
                        throw new DomainException('No se encontró la reserva asociada a una tina del traspaso.', 'TRANSFER_RESERVATION_LINE_NOT_FOUND', ['stock_unit_id' => $stockUnitId]);
                    }
                    if ((string) $reservationLine['status'] !== 'fulfilled') {
                        $reservedQuantity = round((float) $reservationLine['reserved_weight_kg'], 3);
                        $fulfilledQuantity = $weight;
                        $this->db->table('reservation_lines')->where('id', $reservationLine['id'])->update([
                            'fulfilled_weight_kg' => $fulfilledQuantity,
                            'status' => 'fulfilled',
                            'updated_at' => $now,
                        ]);

                        $requestLine = $this->db->query(
                            'SELECT * FROM raw_material_request_lines WHERE id = ? AND request_id = ? FOR UPDATE',
                            [(int) $reservationLine['request_line_id'], (int) $transfer['request_id']]
                        )->getRowArray();
                        if ($requestLine === null) {
                            throw new DomainException('No se encontró el detalle de solicitud asociado al traspaso.', 'REQUEST_LINE_NOT_FOUND');
                        }

                        $fulfilled = round((float) $requestLine['fulfilled_weight_kg'] + $fulfilledQuantity, 3);
                        $remainingReserved = max(0, round((float) $requestLine['reserved_weight_kg'] - $reservedQuantity, 3));
                        $this->db->table('raw_material_request_lines')->where('id', $requestLine['id'])->update([
                            'fulfilled_weight_kg' => $fulfilled,
                            'reserved_weight_kg' => $remainingReserved,
                            'status' => $fulfilled >= (float) $requestLine['requested_weight_kg'] ? 'fulfilled' : 'partial',
                            'updated_at' => $now,
                        ]);
                    }
                }

                $results[] = [
                    'stock_unit_id' => (int) $stock['id'],
                    'received_weight_kg' => $weight,
                    'difference_kg' => $transferDifference,
                    'status' => 'available',
                    'location_id' => $targetLocationId,
                ];
            }

            $remaining = $this->db->table('transfer_lines')
                ->where('transfer_id', $transferId)
                ->where('status !=', 'received')
                ->countAllResults();
            $weightVariances = $this->db->table('transfer_weighings')
                ->where([
                    'transfer_id' => $transferId,
                    'direction' => 'inbound',
                    'status' => 'confirmed',
                    'is_current' => 1,
                    'within_tolerance' => 0,
                ])->countAllResults();
            $status = ($remaining > 0 || $weightVariances > 0) ? 'discrepancy' : 'received';

            $update = [
                'status' => $status,
                'received_by' => $actorUserId,
                'lock_version' => (int) $transfer['lock_version'] + 1,
                'updated_at' => $now,
            ];
            if ($status === 'received') {
                $update['received_at'] = $now;
            }
            $this->db->table('transfers')->where('id', $transferId)->update($update);

            if ($isRequestTransfer) {
                $reservationPending = $this->db->table('reservation_lines')
                    ->where('reservation_id', (int) $transfer['reservation_id'])
                    ->where('status !=', 'fulfilled')
                    ->countAllResults();

                $reservationStatus = $reservationPending > 0 ? 'in_transfer' : 'fulfilled';
                $this->db->table('reservations')->where('id', (int) $transfer['reservation_id'])
                    ->set('status', $reservationStatus)
                    ->set('lock_version', 'lock_version + 1', false)
                    ->set('updated_at', $now)
                    ->update();

                $linkedTransfersPending = $this->db->table('transfers')
                    ->where('reservation_id', (int) $transfer['reservation_id'])
                    ->where('status !=', 'received')
                    ->where('status !=', 'canceled')
                    ->countAllResults();

                if ($linkedTransfersPending > 0 || $reservationPending > 0) {
                    $requestStatus = 'in_transfer';
                } else {
                    $totals = $this->db->table('raw_material_request_lines')
                        ->select('SUM(requested_weight_kg) AS requested, SUM(fulfilled_weight_kg) AS fulfilled')
                        ->where('request_id', (int) $transfer['request_id'])
                        ->get()->getRowArray();
                    $requestStatus = (float) ($totals['fulfilled'] ?? 0) >= (float) ($totals['requested'] ?? 0)
                        ? 'fulfilled'
                        : 'partially_fulfilled';
                }

                $this->db->table('raw_material_requests')->where('id', (int) $transfer['request_id'])
                    ->set('status', $requestStatus)
                    ->set('lock_version', 'lock_version + 1', false)
                    ->set('updated_at', $now)
                    ->update();
            }

            $missingIds = $this->db->table('transfer_lines')
                ->select('stock_unit_id')->where(['transfer_id' => $transferId, 'status' => 'missing'])
                ->get()->getResultArray();

            $this->audit->record(
                'transfer.received',
                'transfer',
                $transferId,
                $actorUserId,
                (int) $transfer['company_id'],
                (int) $destination['site_id'],
                null,
                [
                    'inventory_document_id' => $documentId,
                    'status' => $status,
                    'lines' => $results,
                    'missing_stock_unit_ids' => array_map('intval', array_column($missingIds, 'stock_unit_id')),
                    'weight_variance_count' => $weightVariances,
                ]
            );

            $this->db->transCommit();
            return [
                'transfer_id' => $transferId,
                'status' => $status,
                'inventory_document_id' => $documentId,
                'received_count' => count($results),
                'missing_count' => count($missingIds),
                'lines' => $results,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function applyQualityDecision(int $inspectionId, string $disposition, ?string $qualityGrade, int $actorUserId, ?string $reason = null): array
    {
        if (! in_array($disposition, ['accepted', 'observed', 'released', 'hold', 'rejected'], true)) {
            throw new DomainException('Dictamen de calidad inválido.', 'INVALID_QUALITY_DISPOSITION');
        }
        if (in_array($disposition, ['hold', 'rejected', 'released'], true) && trim((string) $reason) === '') {
            throw new DomainException('La retención, rechazo o liberación requiere motivo.', 'QUALITY_REASON_REQUIRED');
        }

        $this->db->transException(true)->transBegin();
        try {
            $inspection = $this->db->query('SELECT * FROM quality_inspections WHERE id = ? FOR UPDATE', [$inspectionId])->getRowArray();
            if ($inspection === null) {
                throw new DomainException('Inspección no encontrada.', 'QUALITY_INSPECTION_NOT_FOUND');
            }

            $isRelease = $disposition === 'released';
            if (! $isRelease && $inspection['status'] !== 'submitted') {
                throw new DomainException('Inspección no disponible para dictamen.', 'QUALITY_INSPECTION_NOT_APPROVABLE');
            }
            if ($isRelease && ! ($inspection['status'] === 'approved' && $inspection['disposition'] === 'hold')) {
                throw new DomainException('La inspección no corresponde a una retención activa de tina.', 'QUALITY_RELEASE_NOT_ALLOWED');
            }

            $now = date('Y-m-d H:i:s');
            $this->db->table('quality_inspections')->where('id', $inspectionId)->update([
                'quality_grade' => $qualityGrade ?? $inspection['quality_grade'],
                'disposition' => $disposition,
                'status' => 'approved',
                'approved_by' => $actorUserId,
                'approved_at' => $now,
                'lock_version' => (int) $inspection['lock_version'] + 1,
                'updated_at' => $now,
            ]);

            $stock = $this->db->table('stock_units')->where('weighing_id', $inspection['weighing_id'])->get()->getRowArray();
            $documentId = null;

            if ($stock !== null) {
                $stock = $this->inventory->findStockUnitForUpdate((int) $stock['id']);

                if (! in_array($disposition, ['accepted', 'observed'], true)) {
                    $this->assertWarehouseRequestCommitment($stock, true);
                }
                if (in_array($disposition, ['accepted', 'observed'], true)) {
                    // Registrar una inspección normal no bloquea ni libera inventario: conserva su estado actual.
                    $this->db->table('stock_units')
                        ->where(['id' => $stock['id'], 'lock_version' => $stock['lock_version']])
                        ->update([
                            'quality_grade' => $qualityGrade ?? $inspection['quality_grade'],
                            'lock_version' => (int) $stock['lock_version'] + 1,
                            'updated_at' => $now,
                        ]);
                    if ($this->db->affectedRows() !== 1) {
                        throw new DomainException('La unidad cambió durante el dictamen.', 'CONCURRENT_STOCK_MODIFICATION');
                    }
                } else {
                    $newStatus = match ($disposition) {
                        'released' => (float) $stock['reserved_weight_kg'] > 0 ? 'reserved' : 'available',
                        'hold' => 'held',
                        'rejected' => 'rejected',
                    };
                    $movementType = match ($disposition) {
                        'released' => MovementType::QualityRelease,
                        'hold' => MovementType::QualityHold,
                        'rejected' => MovementType::QualityReject,
                    };
                    $documentId = $this->insertDocument(
                        $movementType,
                        (int) $stock['company_id'],
                        (int) $stock['site_id'],
                        (int) $stock['warehouse_id'],
                        (int) $stock['warehouse_id'],
                        'quality_inspection',
                        $inspectionId,
                        $actorUserId,
                        $reason,
                        reasonCode: 'quality_disposition'
                    );
                    $lineId = $this->insertDocumentLine(
                        $documentId,
                        1,
                        (int) $stock['id'],
                        (int) $stock['lot_id'],
                        (float) $stock['on_hand_weight_kg'],
                        (int) $stock['warehouse_id'],
                        (int) $stock['warehouse_id'],
                        (int) $stock['location_id'],
                        (int) $stock['location_id'],
                        (string) $stock['status'],
                        $newStatus
                    );
                    $this->insertLedgerEntry(
                        $documentId,
                        $lineId,
                        (int) $stock['id'],
                        (int) $stock['lot_id'],
                        $movementType,
                        0,
                        0,
                        (int) $stock['location_id'],
                        (int) $stock['location_id'],
                        (float) $stock['on_hand_weight_kg'],
                        (float) $stock['reserved_weight_kg'],
                        $actorUserId,
                        $now
                    );
                    $this->db->table('stock_units')
                        ->where(['id' => $stock['id'], 'lock_version' => $stock['lock_version']])
                        ->update([
                            'quality_grade' => $qualityGrade ?? $inspection['quality_grade'],
                            'status' => $newStatus,
                            'hold_reason' => $newStatus === 'held' ? $reason : null,
                            'lock_version' => (int) $stock['lock_version'] + 1,
                            'updated_at' => $now,
                        ]);
                    if ($this->db->affectedRows() !== 1) {
                        throw new DomainException('La unidad cambió durante el dictamen.', 'CONCURRENT_STOCK_MODIFICATION');
                    }
                }
            }

            $this->audit->record(
                $isRelease ? 'quality.released' : 'quality.approved',
                'quality_inspection',
                $inspectionId,
                $actorUserId,
                after: ['disposition' => $disposition, 'quality_grade' => $qualityGrade, 'inventory_document_id' => $documentId],
                reason: $reason
            );
            $this->db->transCommit();
            return ['inspection_id' => $inspectionId, 'status' => 'approved', 'disposition' => $disposition, 'inventory_document_id' => $documentId];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function applyQualityLotDecision(int $inspectionId, string $disposition, ?string $qualityGrade, int $actorUserId, ?string $reason = null): array
    {
        if (! in_array($disposition, ['hold_lot', 'released_lot'], true)) {
            throw new DomainException('Dictamen de lote inválido.', 'INVALID_QUALITY_LOT_DISPOSITION');
        }
        if (trim((string) $reason) === '') {
            throw new DomainException('La retención o liberación del lote requiere motivo.', 'QUALITY_REASON_REQUIRED');
        }

        $this->db->transException(true)->transBegin();
        try {
            $inspection = $this->db->query(<<<'SQL'
SELECT qi.*, w.lot_id
FROM quality_inspections qi
INNER JOIN weighings w ON w.id = qi.weighing_id
WHERE qi.id = ?
FOR UPDATE
SQL, [$inspectionId])->getRowArray();
            if ($inspection === null) {
                throw new DomainException('Inspección no encontrada.', 'QUALITY_INSPECTION_NOT_FOUND');
            }

            $isRelease = $disposition === 'released_lot';
            if (! $isRelease && $inspection['status'] !== 'submitted') {
                throw new DomainException('Inspección no disponible para retener el lote.', 'QUALITY_INSPECTION_NOT_APPROVABLE');
            }
            if ($isRelease && ! ($inspection['status'] === 'approved' && $inspection['disposition'] === 'hold_lot')) {
                throw new DomainException('La inspección no corresponde a una retención activa de lote.', 'QUALITY_LOT_RELEASE_NOT_ALLOWED');
            }

            $now = date('Y-m-d H:i:s');
            $lotId = (int) $inspection['lot_id'];

            if ($isRelease) {
                $holdDocument = $this->db->table('inventory_documents')
                    ->where([
                        'movement_type' => MovementType::QualityHold->value,
                        'source_document_type' => 'quality_inspection',
                        'source_document_id' => $inspectionId,
                    ])
                    ->orderBy('id', 'DESC')
                    ->get()->getRowArray();
                if ($holdDocument === null) {
                    throw new DomainException('No se encontró el movimiento que originó la retención del lote.', 'QUALITY_LOT_HOLD_DOCUMENT_NOT_FOUND');
                }
                $lineRows = $this->db->table('inventory_document_lines')
                    ->select('stock_unit_id')
                    ->where('inventory_document_id', (int) $holdDocument['id'])
                    ->get()->getResultArray();
                $stockIds = array_values(array_filter(array_map(static fn (array $row): int => (int) $row['stock_unit_id'], $lineRows)));
                if ($stockIds === []) {
                    throw new DomainException('La retención del lote no contiene unidades para liberar.', 'QUALITY_LOT_HOLD_EMPTY');
                }
                $stocks = $this->db->table('stock_units')->whereIn('id', $stockIds)->orderBy('id')->get()->getResultArray();
                $movementType = MovementType::QualityRelease;
            } else {
                $stocks = $this->db->table('stock_units')
                    ->where('lot_id', $lotId)
                    ->where('on_hand_weight_kg >', 0)
                    ->whereIn('status', ['available', 'reserved', 'received'])
                    ->orderBy('id')
                    ->get()->getResultArray();
                $movementType = MovementType::QualityHold;
            }

            if ($stocks === []) {
                throw new DomainException('No hay existencias activas del lote para aplicar el dictamen.', 'QUALITY_LOT_WITHOUT_ACTIVE_STOCK');
            }

            $warehouseIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['warehouse_id'], $stocks)));
            $originWarehouseId = count($warehouseIds) === 1 ? $warehouseIds[0] : null;
            $first = $stocks[0];
            $documentId = $this->insertDocument(
                $movementType,
                (int) $first['company_id'],
                (int) $first['site_id'],
                $originWarehouseId,
                $originWarehouseId,
                'quality_inspection',
                $inspectionId,
                $actorUserId,
                $reason,
                reasonCode: $isRelease ? 'quality_lot_release' : 'quality_lot_hold'
            );

            $changed = 0;
            foreach ($stocks as $index => $stockRow) {
                $stock = $this->inventory->findStockUnitForUpdate((int) $stockRow['id']);
                if ($isRelease && $stock['status'] !== 'held') {
                    continue;
                }
                $newStatus = $isRelease
                    ? ((float) $stock['reserved_weight_kg'] > 0 ? 'reserved' : 'available')
                    : 'held';
                $lineId = $this->insertDocumentLine(
                    $documentId,
                    $index + 1,
                    (int) $stock['id'],
                    (int) $stock['lot_id'],
                    (float) $stock['on_hand_weight_kg'],
                    (int) $stock['warehouse_id'],
                    (int) $stock['warehouse_id'],
                    (int) $stock['location_id'],
                    (int) $stock['location_id'],
                    (string) $stock['status'],
                    $newStatus
                );
                $this->insertLedgerEntry(
                    $documentId,
                    $lineId,
                    (int) $stock['id'],
                    (int) $stock['lot_id'],
                    $movementType,
                    0,
                    0,
                    (int) $stock['location_id'],
                    (int) $stock['location_id'],
                    (float) $stock['on_hand_weight_kg'],
                    (float) $stock['reserved_weight_kg'],
                    $actorUserId,
                    $now
                );
                $this->db->table('stock_units')
                    ->where(['id' => $stock['id'], 'lock_version' => $stock['lock_version']])
                    ->update([
                        'quality_grade' => $qualityGrade ?? $inspection['quality_grade'],
                        'status' => $newStatus,
                        'hold_reason' => $newStatus === 'held' ? $reason : null,
                        'lock_version' => (int) $stock['lock_version'] + 1,
                        'updated_at' => $now,
                    ]);
                if ($this->db->affectedRows() !== 1) {
                    throw new DomainException('Una unidad del lote cambió durante el dictamen.', 'CONCURRENT_STOCK_MODIFICATION');
                }
                $changed++;
            }

            if ($changed === 0) {
                throw new DomainException('No hubo unidades elegibles para aplicar el dictamen de lote.', 'QUALITY_LOT_WITHOUT_ELIGIBLE_STOCK');
            }

            $this->db->table('quality_inspections')->where('id', $inspectionId)->update([
                'quality_grade' => $qualityGrade ?? $inspection['quality_grade'],
                'disposition' => $disposition,
                'status' => 'approved',
                'approved_by' => $actorUserId,
                'approved_at' => $now,
                'lock_version' => (int) $inspection['lock_version'] + 1,
                'updated_at' => $now,
            ]);

            $this->audit->record(
                $isRelease ? 'quality.lot_released' : 'quality.lot_held',
                'quality_inspection',
                $inspectionId,
                $actorUserId,
                after: ['lot_id' => $lotId, 'disposition' => $disposition, 'affected_units' => $changed, 'inventory_document_id' => $documentId],
                reason: $reason
            );
            $this->db->transCommit();
            return ['inspection_id' => $inspectionId, 'status' => 'approved', 'disposition' => $disposition, 'affected_units' => $changed, 'inventory_document_id' => $documentId];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function annulQualityInspection(int $inspectionId, int $actorUserId, string $reason, bool $canApprove): array
    {
        $this->assertQualityCorrectionSchema();
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Indica el motivo de anulación con al menos 5 caracteres.', 'QUALITY_ANNUL_REASON_REQUIRED');
        }

        $this->db->transException(true)->transBegin();
        try {
            $inspection = $this->db->query('SELECT * FROM quality_inspections WHERE id = ? FOR UPDATE', [$inspectionId])->getRowArray();
            if ($inspection === null) {
                throw new DomainException('Inspección no encontrada.', 'QUALITY_INSPECTION_NOT_FOUND');
            }
            if ($inspection['status'] === 'annulled') {
                throw new DomainException('La inspección ya está anulada.', 'QUALITY_INSPECTION_ALREADY_ANNULLED');
            }
            if (($inspection['superseded_by_inspection_id'] ?? null) !== null) {
                throw new DomainException('La inspección ya fue sustituida por una corrección.', 'QUALITY_INSPECTION_ALREADY_SUPERSEDED');
            }

            $inventorySensitive = $inspection['status'] === 'approved'
                && in_array((string) $inspection['disposition'], ['hold', 'hold_lot', 'rejected'], true);
            if ($inventorySensitive && ! $canApprove) {
                throw new DomainException('Se requiere autorización para anular una inspección que afectó inventario.', 'QUALITY_ANNUL_APPROVAL_REQUIRED');
            }
            if (! $canApprove && (int) $inspection['inspected_by'] !== $actorUserId) {
                throw new DomainException('Sólo puedes anular tus propias inspecciones.', 'QUALITY_ANNUL_NOT_ALLOWED');
            }

            $now = date('Y-m-d H:i:s');
            $reversalDocumentId = null;
            $affectedStockIds = [];

            if ($inventorySensitive) {
                $movementType = $inspection['disposition'] === 'rejected'
                    ? MovementType::QualityReject->value
                    : MovementType::QualityHold->value;
                $sourceDocument = $this->db->table('inventory_documents')
                    ->where([
                        'movement_type' => $movementType,
                        'source_document_type' => 'quality_inspection',
                        'source_document_id' => $inspectionId,
                    ])
                    ->orderBy('id', 'DESC')
                    ->get()->getRowArray();
                if ($sourceDocument === null) {
                    throw new DomainException('No se encontró el movimiento de inventario generado por esta inspección.', 'QUALITY_ANNUL_SOURCE_DOCUMENT_NOT_FOUND');
                }

                $sourceLines = $this->db->table('inventory_document_lines')
                    ->where('inventory_document_id', (int) $sourceDocument['id'])
                    ->orderBy('line_number')
                    ->get()->getResultArray();
                if ($sourceLines === []) {
                    throw new DomainException('El movimiento original no contiene existencias para revertir.', 'QUALITY_ANNUL_SOURCE_DOCUMENT_EMPTY');
                }

                $firstStock = $this->inventory->findStockUnitForUpdate((int) $sourceLines[0]['stock_unit_id']);
                $reversalDocumentId = $this->insertDocument(
                    MovementType::Reversal,
                    (int) $firstStock['company_id'],
                    (int) $firstStock['site_id'],
                    (int) $firstStock['warehouse_id'],
                    (int) $firstStock['warehouse_id'],
                    'quality_inspection_annulment',
                    $inspectionId,
                    $actorUserId,
                    $reason,
                    reasonCode: 'quality_inspection_annulment'
                );

                foreach ($sourceLines as $index => $sourceLine) {
                    $stock = $index === 0
                        ? $firstStock
                        : $this->inventory->findStockUnitForUpdate((int) $sourceLine['stock_unit_id']);
                    if ((string) $stock['status'] !== (string) $sourceLine['stock_status_after']) {
                        throw new DomainException(
                            'No se puede anular porque una existencia cambió después del dictamen de calidad.',
                            'QUALITY_ANNUL_STOCK_CHANGED',
                            ['stock_unit_id' => (int) $stock['id'], 'current_status' => $stock['status']]
                        );
                    }

                    $restoreStatus = trim((string) ($sourceLine['stock_status_before'] ?? ''));
                    if ($restoreStatus === '') {
                        $restoreStatus = (float) $stock['reserved_weight_kg'] > 0 ? 'reserved' : 'available';
                    }
                    $lineId = $this->insertDocumentLine(
                        $reversalDocumentId,
                        $index + 1,
                        (int) $stock['id'],
                        (int) $stock['lot_id'],
                        (float) $stock['on_hand_weight_kg'],
                        (int) $stock['warehouse_id'],
                        (int) $stock['warehouse_id'],
                        (int) $stock['location_id'],
                        (int) $stock['location_id'],
                        (string) $stock['status'],
                        $restoreStatus
                    );
                    $this->insertLedgerEntry(
                        $reversalDocumentId,
                        $lineId,
                        (int) $stock['id'],
                        (int) $stock['lot_id'],
                        MovementType::Reversal,
                        0,
                        0,
                        (int) $stock['location_id'],
                        (int) $stock['location_id'],
                        (float) $stock['on_hand_weight_kg'],
                        (float) $stock['reserved_weight_kg'],
                        $actorUserId,
                        $now
                    );

                    $previousGrade = $this->latestApprovedQualityGrade((int) $stock['weighing_id'], $inspectionId);
                    $this->db->table('stock_units')
                        ->where(['id' => $stock['id'], 'lock_version' => $stock['lock_version']])
                        ->update([
                            'quality_grade' => $previousGrade,
                            'status' => $restoreStatus,
                            'hold_reason' => $restoreStatus === 'held' ? $stock['hold_reason'] : null,
                            'lock_version' => (int) $stock['lock_version'] + 1,
                            'updated_at' => $now,
                        ]);
                    if ($this->db->affectedRows() !== 1) {
                        throw new DomainException('La existencia cambió durante la anulación.', 'CONCURRENT_STOCK_MODIFICATION');
                    }
                    $affectedStockIds[] = (int) $stock['id'];
                }
            } else {
                $stock = $this->db->table('stock_units')->where('weighing_id', $inspection['weighing_id'])->get()->getRowArray();
                if ($stock !== null) {
                    $locked = $this->inventory->findStockUnitForUpdate((int) $stock['id']);
                    $previousGrade = $this->latestApprovedQualityGrade((int) $locked['weighing_id'], $inspectionId);
                    $this->db->table('stock_units')
                        ->where(['id' => $locked['id'], 'lock_version' => $locked['lock_version']])
                        ->update([
                            'quality_grade' => $previousGrade,
                            'lock_version' => (int) $locked['lock_version'] + 1,
                            'updated_at' => $now,
                        ]);
                    if ($this->db->affectedRows() !== 1) {
                        throw new DomainException('La existencia cambió durante la anulación.', 'CONCURRENT_STOCK_MODIFICATION');
                    }
                    $affectedStockIds[] = (int) $locked['id'];
                }
            }

            $this->db->table('quality_inspections')->where('id', $inspectionId)->update([
                'status' => 'annulled',
                'annulled_by' => $actorUserId,
                'annulled_at' => $now,
                'annul_reason' => $reason,
                'lock_version' => (int) $inspection['lock_version'] + 1,
                'updated_at' => $now,
            ]);

            $this->audit->record(
                'quality.annulled',
                'quality_inspection',
                $inspectionId,
                $actorUserId,
                after: [
                    'status' => 'annulled',
                    'reversal_inventory_document_id' => $reversalDocumentId,
                    'affected_stock_unit_ids' => $affectedStockIds,
                ],
                reason: $reason
            );
            $this->db->transCommit();
            return [
                'inspection_id' => $inspectionId,
                'status' => 'annulled',
                'reversal_inventory_document_id' => $reversalDocumentId,
                'affected_stock_units' => count($affectedStockIds),
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function assertQualityCorrectionSchema(): void
    {
        foreach (['annulled_by', 'annulled_at', 'annul_reason', 'supersedes_inspection_id', 'superseded_by_inspection_id'] as $field) {
            if (! $this->db->fieldExists($field, 'quality_inspections')) {
                throw new DomainException(
                    'La estructura de Calidad está pendiente de actualización. Ejecuta las migraciones antes de anular o corregir inspecciones.',
                    'QUALITY_SCHEMA_UPDATE_REQUIRED',
                    ['missing_field' => $field]
                );
            }
        }
    }

    private function latestApprovedQualityGrade(int $weighingId, int $excludeInspectionId): ?string
    {
        $row = $this->db->table('quality_inspections')
            ->select('quality_grade')
            ->where('weighing_id', $weighingId)
            ->where('id !=', $excludeInspectionId)
            ->where('status', 'approved')
            ->orderBy('approved_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();

        return $row === null ? null : ($row['quality_grade'] === null ? null : (string) $row['quality_grade']);
    }

    /**
     * Reserva tinas completas para la solicitud de venta integrada.
     * La transacción la administra SalesRequestService.
     */
    public function reserveSaleOrder(int $salesOrderId, int $warehouseId, int $actorUserId): array
    {
        $order = $this->db->query('SELECT * FROM sales_orders WHERE id = ? FOR UPDATE', [$salesOrderId])->getRowArray();
        if ($order === null || $order['status'] !== 'draft') {
            throw new DomainException('La solicitud de venta no está disponible para reservar.', 'SALE_REQUEST_NOT_RESERVABLE');
        }

        $lines = $this->db->table('sales_order_lines')
            ->where(['sales_order_id' => $salesOrderId, 'status' => 'selected'])
            ->orderBy('line_number')
            ->get()
            ->getResultArray();

        if ($lines === []) {
            throw new DomainException('La solicitud de venta no contiene tinas para reservar.', 'SALE_REQUEST_LINES_REQUIRED');
        }

        $now = date('Y-m-d H:i:s');
        $documentId = $this->insertDocument(
            MovementType::Reservation,
            (int) $order['company_id'],
            (int) $order['site_id'],
            $warehouseId,
            $warehouseId,
            'sales_order_reservation',
            $salesOrderId,
            $actorUserId,
            'Reserva de tinas para venta ' . $order['folio'],
        );

        $reserved = [];
        foreach ($lines as $index => $saleLine) {
            $stock = $this->inventory->findStockUnitForUpdate((int) $saleLine['stock_unit_id']);
            $quantity = round((float) $saleLine['quantity_kg'], 3);

            if (
                (int) $stock['company_id'] !== (int) $order['company_id']
                || (int) $stock['site_id'] !== (int) $order['site_id']
                || (int) $stock['warehouse_id'] !== $warehouseId
                || $stock['status'] !== 'available'
                || (float) $stock['reserved_weight_kg'] > 0.0001
                || $quantity <= 0
                || abs($quantity - (float) $stock['on_hand_weight_kg']) > 0.001
            ) {
                throw new DomainException(
                    'Una tina cambió o ya no está disponible para reservarla completa.',
                    'SALE_REQUEST_STOCK_NOT_AVAILABLE',
                    ['stock_unit_id' => $stock['id']]
                );
            }

            $newReserved = round((float) $stock['reserved_weight_kg'] + $quantity, 3);
            $lineId = $this->insertDocumentLine(
                $documentId,
                $index + 1,
                (int) $stock['id'],
                (int) $stock['lot_id'],
                $quantity,
                (int) $stock['warehouse_id'],
                (int) $stock['warehouse_id'],
                (int) $stock['location_id'],
                (int) $stock['location_id'],
                (string) $stock['status'],
                'reserved',
            );
            $this->insertLedgerEntry(
                $documentId,
                $lineId,
                (int) $stock['id'],
                (int) $stock['lot_id'],
                MovementType::Reservation,
                0,
                $quantity,
                (int) $stock['location_id'],
                (int) $stock['location_id'],
                (float) $stock['on_hand_weight_kg'],
                $newReserved,
                $actorUserId,
                $now,
            );
            $this->updateStockProjection(
                $stock,
                (float) $stock['on_hand_weight_kg'],
                $newReserved,
                (int) $stock['warehouse_id'],
                (int) $stock['location_id'],
                'reserved',
                $now,
            );
            $this->db->table('sales_order_lines')->where('id', $saleLine['id'])->update([
                'status' => 'reserved',
                'updated_at' => $now,
            ]);
            $reserved[] = ['stock_unit_id' => (int) $stock['id'], 'quantity_kg' => $quantity];
        }

        $this->db->table('sales_orders')->where('id', $salesOrderId)->update([
            'status' => 'allocated',
            'lock_version' => (int) $order['lock_version'] + 1,
            'updated_at' => $now,
        ]);
        $this->audit->record(
            'sale.reserved',
            'sales_order',
            $salesOrderId,
            $actorUserId,
            (int) $order['company_id'],
            (int) $order['site_id'],
            $warehouseId,
            ['inventory_document_id' => $documentId, 'lines' => $reserved]
        );

        return ['sales_order_id' => $salesOrderId, 'status' => 'allocated', 'inventory_document_id' => $documentId, 'lines' => $reserved];
    }

    /**
     * Libera una reserva de venta integrada.
     * La transacción la administra SalesRequestService.
     */
    public function releaseSaleReservation(int $salesOrderId, int $warehouseId, string $reason, int $actorUserId): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw new DomainException('Indica un motivo de liberación de al menos cinco caracteres.', 'SALE_RELEASE_REASON_REQUIRED');
        }

        $order = $this->db->query('SELECT * FROM sales_orders WHERE id = ? FOR UPDATE', [$salesOrderId])->getRowArray();
        if ($order === null || $order['status'] !== 'allocated') {
            throw new DomainException('La solicitud de venta no tiene una reserva activa.', 'SALE_RESERVATION_NOT_ACTIVE');
        }

        $lines = $this->db->table('sales_order_lines')
            ->where(['sales_order_id' => $salesOrderId, 'status' => 'reserved'])
            ->orderBy('line_number')
            ->get()
            ->getResultArray();

        if ($lines === []) {
            throw new DomainException('La solicitud de venta no contiene tinas reservadas.', 'SALE_RESERVED_LINES_REQUIRED');
        }

        $now = date('Y-m-d H:i:s');
        $documentId = $this->insertDocument(
            MovementType::Release,
            (int) $order['company_id'],
            (int) $order['site_id'],
            $warehouseId,
            $warehouseId,
            'sales_order_release',
            $salesOrderId,
            $actorUserId,
            $reason,
            reasonCode: 'sale_request_release',
        );

        $released = [];
        foreach ($lines as $index => $saleLine) {
            $stock = $this->inventory->findStockUnitForUpdate((int) $saleLine['stock_unit_id']);
            $quantity = round((float) $saleLine['quantity_kg'], 3);

            if (
                (int) $stock['warehouse_id'] !== $warehouseId
                || $quantity <= 0
                || $quantity > (float) $stock['reserved_weight_kg'] + 0.001
                || $quantity > (float) $stock['on_hand_weight_kg'] + 0.001
            ) {
                throw new DomainException(
                    'La reserva ya no concilia con la existencia física.',
                    'SALE_RESERVATION_BALANCE_MISMATCH',
                    ['stock_unit_id' => $stock['id']]
                );
            }

            $newReserved = max(0, round((float) $stock['reserved_weight_kg'] - $quantity, 3));
            $newStatus = $newReserved > 0.0001 ? 'reserved' : 'available';

            $lineId = $this->insertDocumentLine(
                $documentId,
                $index + 1,
                (int) $stock['id'],
                (int) $stock['lot_id'],
                $quantity,
                (int) $stock['warehouse_id'],
                (int) $stock['warehouse_id'],
                (int) $stock['location_id'],
                (int) $stock['location_id'],
                (string) $stock['status'],
                $newStatus,
            );
            $this->insertLedgerEntry(
                $documentId,
                $lineId,
                (int) $stock['id'],
                (int) $stock['lot_id'],
                MovementType::Release,
                0,
                -$quantity,
                (int) $stock['location_id'],
                (int) $stock['location_id'],
                (float) $stock['on_hand_weight_kg'],
                $newReserved,
                $actorUserId,
                $now,
            );
            $this->updateStockProjection(
                $stock,
                (float) $stock['on_hand_weight_kg'],
                $newReserved,
                (int) $stock['warehouse_id'],
                (int) $stock['location_id'],
                $newStatus,
                $now,
            );
            $this->db->table('sales_order_lines')->where('id', $saleLine['id'])->update([
                'status' => 'released',
                'updated_at' => $now,
            ]);
            $released[] = ['stock_unit_id' => (int) $stock['id'], 'quantity_kg' => $quantity];
        }

        $this->db->table('sales_orders')->where('id', $salesOrderId)->update([
            'status' => 'canceled',
            'lock_version' => (int) $order['lock_version'] + 1,
            'updated_at' => $now,
        ]);
        $this->audit->record(
            'sale.reservation_released',
            'sales_order',
            $salesOrderId,
            $actorUserId,
            (int) $order['company_id'],
            (int) $order['site_id'],
            $warehouseId,
            ['inventory_document_id' => $documentId, 'reason' => $reason, 'lines' => $released]
        );

        return ['sales_order_id' => $salesOrderId, 'status' => 'canceled', 'inventory_document_id' => $documentId, 'lines' => $released];
    }


    /**
     * Despacha una sola tina de una solicitud de venta integrada desde la
     * estación de Salidas. La transacción la administra SalesRequestService.
     *
     * La tina es indivisible: el inventario de esa unidad sale completo.
     * El peso físico de salida queda en inventory_document_lines y la lectura
     * de báscula queda ligada mediante destination_document_type=scale_reading.
     */
    public function dispatchSaleRequestUnit(
        int $salesOrderId,
        int $salesOrderLineId,
        float $outboundWeightKg,
        int $scaleReadingId,
        int $actorUserId
    ): array {
        if (! is_finite($outboundWeightKg) || $outboundWeightKg <= 0 || $scaleReadingId <= 0) {
            throw new DomainException('Peso o lectura de salida inválidos.', 'SALE_OUTGOING_WEIGHT_INVALID');
        }

        $order = $this->db->query(
            'SELECT * FROM sales_orders WHERE id=? FOR UPDATE',
            [$salesOrderId]
        )->getRowArray();

        if ($order === null || ! in_array((string) $order['status'], ['draft','allocated'], true)) {
            throw new DomainException('La solicitud de venta ya no está disponible para salida.', 'SALE_REQUEST_NOT_DISPATCHABLE');
        }

        $saleLine = $this->db->query(
            'SELECT * FROM sales_order_lines WHERE id=? AND sales_order_id=? FOR UPDATE',
            [$salesOrderLineId,$salesOrderId]
        )->getRowArray();

        if ($saleLine === null || ! in_array((string) $saleLine['status'], ['selected','reserved'], true)) {
            throw new DomainException('La tina ya no está disponible en el surtido de esta venta.', 'SALE_LINE_NOT_DISPATCHABLE');
        }

        if (
            $this->db->table('inventory_documents')
                ->where([
                    'destination_document_type'=>'scale_reading',
                    'destination_document_id'=>$scaleReadingId,
                    'status'=>'confirmed',
                ])
                ->countAllResults() > 0
        ) {
            throw new DomainException('La lectura ya fue utilizada.', 'SCALE_READING_ALREADY_USED');
        }

        $stock = $this->inventory->findStockUnitForUpdate((int) $saleLine['stock_unit_id']);
        $referenceQty = round((float) $saleLine['quantity_kg'], 3);
        $isReserved = (string) $saleLine['status'] === 'reserved';

        // SALIDA TERMINAL · VENTA
        // La venta elimina el producto del inventario y devuelve la tina física
        // al pool de tinas disponibles para una nueva descarga.
        //
        // Se bloquea la tina en la misma transacción para evitar que otro proceso
        // la reutilice mientras todavía estamos confirmando el despacho.
        $physicalTub = $this->db->query(
            'SELECT * FROM tubs WHERE id=? FOR UPDATE',
            [(int) $stock['tub_id']]
        )->getRowArray();

        if (
            $physicalTub === null
            || ! (bool) $physicalTub['active']
            || (string) $physicalTub['status'] !== 'received'
        ) {
            throw new DomainException(
                'La tina física no está en estado recibido y no puede liberarse de forma segura.',
                'SALE_PHYSICAL_TUB_NOT_RELEASABLE',
                [
                    'stock_unit_id'=>(int) $stock['id'],
                    'tub_id'=>(int) $stock['tub_id'],
                    'tub_status'=>$physicalTub['status'] ?? null,
                ]
            );
        }

        if (
            (int) $stock['company_id'] !== (int) $order['company_id']
            || (int) $stock['site_id'] !== (int) $order['site_id']
            || (int) $stock['warehouse_id'] !== (int) $order['source_warehouse_id']
            || $referenceQty <= 0
            || (float) $stock['on_hand_weight_kg'] <= 0
        ) {
            throw new DomainException('La tina cambió de alcance o ya no tiene existencia.', 'SALE_STOCK_NOT_AVAILABLE');
        }

        if ($isReserved) {
            if (
                (string) $stock['status'] !== 'reserved'
                || abs((float) $stock['reserved_weight_kg'] - $referenceQty) > 0.001
                || abs((float) $stock['reserved_weight_kg'] - (float) $stock['on_hand_weight_kg']) > 0.001
            ) {
                throw new DomainException(
                    'La reserva de la tina ya no concilia con inventario.',
                    'SALE_RESERVATION_BALANCE_MISMATCH',
                    ['stock_unit_id'=>(int) $stock['id']]
                );
            }
        } else {
            if (
                (string) $stock['status'] !== 'available'
                || (float) $stock['reserved_weight_kg'] > 0.0001
            ) {
                throw new DomainException(
                    'La tina ya no está disponible para salida.',
                    'SALE_STOCK_NOT_AVAILABLE',
                    ['stock_unit_id'=>(int) $stock['id']]
                );
            }
        }

        $now = date('Y-m-d H:i:s');
        $documentId = $this->insertDocument(
            MovementType::SaleDispatch,
            (int) $order['company_id'],
            (int) $order['site_id'],
            (int) $order['source_warehouse_id'],
            null,
            'sales_order',
            $salesOrderId,
            $actorUserId,
            'Salida física de tina para venta ' . $order['folio'],
            'scale_reading',
            $scaleReadingId,
            'sale_outgoing'
        );

        $documentLineId = $this->insertDocumentLine(
            $documentId,
            1,
            (int) $stock['id'],
            (int) $stock['lot_id'],
            round($outboundWeightKg,3),
            (int) $stock['warehouse_id'],
            null,
            (int) $stock['location_id'],
            null,
            (string) $stock['status'],
            'dispatched'
        );

        // La tina sale físicamente completa. El documento conserva el peso real
        // de salida; el ledger elimina el saldo completo de esa unidad.
        $movementId = $this->insertLedgerEntry(
            $documentId,
            $documentLineId,
            (int) $stock['id'],
            (int) $stock['lot_id'],
            MovementType::SaleDispatch,
            -round((float) $stock['on_hand_weight_kg'],3),
            -round((float) $stock['reserved_weight_kg'],3),
            (int) $stock['location_id'],
            null,
            0.0,
            0.0,
            $actorUserId,
            $now
        );

        $this->updateStockProjection(
            $stock,
            0.0,
            0.0,
            (int) $stock['warehouse_id'],
            (int) $stock['location_id'],
            'dispatched',
            $now
        );

        // El producto ya salió definitivamente. La tina física queda libre.
        // No se modifica la lógica de traspasos ni la primera salida de
        // Materia Prima: esos flujos conservan la tina ocupada.
        $this->db->table('tubs')
            ->where([
                'id'=>(int) $physicalTub['id'],
                'lock_version'=>(int) $physicalTub['lock_version'],
            ])
            ->update([
                'status'=>'available',
                'lock_version'=>(int) $physicalTub['lock_version'] + 1,
                'updated_at'=>$now,
            ]);

        if ($this->db->affectedRows() !== 1) {
            throw new DomainException(
                'La tina física cambió durante el despacho; no se liberó ni se confirmó la venta.',
                'SALE_PHYSICAL_TUB_CONCURRENT_MODIFICATION'
            );
        }

        $this->db->table('sales_order_lines')->where('id',$salesOrderLineId)->update([
            'status'=>'dispatched',
            'updated_at'=>$now,
        ]);

        $this->audit->record(
            'sale.unit_dispatched',
            'sales_order',
            $salesOrderId,
            $actorUserId,
            (int) $order['company_id'],
            (int) $order['site_id'],
            after: [
                'source_warehouse_id'=>(int) $order['source_warehouse_id'],
                'sales_order_line_id'=>$salesOrderLineId,
                'stock_unit_id'=>(int) $stock['id'],
                'scale_reading_id'=>$scaleReadingId,
                'selected_weight_kg'=>$referenceQty,
                'inventory_weight_before_kg'=>round((float) $stock['on_hand_weight_kg'],3),
                'outbound_weight_kg'=>round($outboundWeightKg,3),
                'difference_kg'=>round($outboundWeightKg-(float) $stock['on_hand_weight_kg'],3),
                'inventory_document_id'=>$documentId,
                'inventory_movement_id'=>$movementId,
                'physical_tub_id'=>(int) $physicalTub['id'],
                'physical_tub_status_before'=>(string) $physicalTub['status'],
                'physical_tub_status_after'=>'available',
            ]
        );

        return [
            'sales_order_id'=>$salesOrderId,
            'sales_order_line_id'=>$salesOrderLineId,
            'stock_unit_id'=>(int) $stock['id'],
            'status'=>'dispatched',
            'outbound_weight_kg'=>round($outboundWeightKg,3),
            'inventory_document_id'=>$documentId,
            'inventory_movement_id'=>$movementId,
            'scale_reading_id'=>$scaleReadingId,
            'physical_tub_id'=>(int) $physicalTub['id'],
            'physical_tub_status'=>'available',
        ];
    }

    public function dispatchSale(int $salesOrderId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $order = $this->db->query('SELECT * FROM sales_orders WHERE id = ? FOR UPDATE', [$salesOrderId])->getRowArray();
            if ($order === null || ! in_array($order['status'], ['draft', 'allocated'], true)) {
                throw new DomainException('La venta no está disponible para despacho.', 'SALE_NOT_DISPATCHABLE');
            }
            $lines = $this->db->table('sales_order_lines')->where('sales_order_id', $salesOrderId)->orderBy('line_number')->get()->getResultArray();
            if ($lines === []) {
                throw new DomainException('La venta no contiene unidades.', 'SALE_LINES_REQUIRED');
            }

            $lockedStocks = [];
            $originWarehouses = [];
            $reservedLine = [];

            foreach ($lines as $saleLine) {
                if (! in_array((string) $saleLine['status'], ['selected', 'reserved'], true)) {
                    throw new DomainException('Una línea de venta ya no está disponible para despacho.', 'SALE_LINE_NOT_DISPATCHABLE', ['sales_order_line_id' => $saleLine['id']]);
                }

                $stock = $this->inventory->findStockUnitForUpdate((int) $saleLine['stock_unit_id']);
                $quantity = round((float) $saleLine['quantity_kg'], 3);
                $isReserved = (string) $saleLine['status'] === 'reserved';

                if ((int) $stock['company_id'] !== (int) $order['company_id']
                    || (int) $stock['site_id'] !== (int) $order['site_id']
                    || $quantity <= 0
                    || $quantity > (float) $stock['on_hand_weight_kg'] + 0.001) {
                    throw new DomainException('Una unidad ya no está disponible para despacho.', 'SALE_STOCK_NOT_AVAILABLE', ['stock_unit_id' => $stock['id']]);
                }

                if ($isReserved) {
                    if ($stock['status'] !== 'reserved' || $quantity > (float) $stock['reserved_weight_kg'] + 0.001) {
                        throw new DomainException('La reserva de una tina ya no concilia con inventario.', 'SALE_RESERVATION_BALANCE_MISMATCH', ['stock_unit_id' => $stock['id']]);
                    }
                } else {
                    $available = round((float) $stock['on_hand_weight_kg'] - (float) $stock['reserved_weight_kg'], 3);
                    if ($stock['status'] !== 'available' || $quantity > $available + 0.001) {
                        throw new DomainException('Una unidad ya no está disponible para despacho.', 'SALE_STOCK_NOT_AVAILABLE', ['stock_unit_id' => $stock['id']]);
                    }
                }

                $lockedStocks[(int) $stock['id']] = $stock;
                $originWarehouses[(int) $stock['warehouse_id']] = true;
                $reservedLine[(int) $saleLine['id']] = $isReserved;
            }

            $originWarehouseId = count($originWarehouses) === 1 ? (int) array_key_first($originWarehouses) : null;
            $documentId = $this->insertDocument(MovementType::SaleDispatch, (int) $order['company_id'], (int) $order['site_id'], $originWarehouseId, null, 'sales_order', $salesOrderId, $actorUserId, $order['observations']);
            $now = date('Y-m-d H:i:s');
            $results = [];

            foreach ($lines as $index => $saleLine) {
                $stock = $lockedStocks[(int) $saleLine['stock_unit_id']];
                $quantity = round((float) $saleLine['quantity_kg'], 3);
                $isReserved = $reservedLine[(int) $saleLine['id']] ?? false;
                $newOnHand = max(0, round((float) $stock['on_hand_weight_kg'] - $quantity, 3));
                $newReserved = $isReserved ? max(0, round((float) $stock['reserved_weight_kg'] - $quantity, 3)) : (float) $stock['reserved_weight_kg'];
                $newStatus = $newOnHand <= 0.0001 ? 'dispatched' : ($newReserved > 0.0001 ? 'reserved' : 'available');

                $lineId = $this->insertDocumentLine($documentId, $index + 1, (int) $stock['id'], (int) $stock['lot_id'], $quantity, (int) $stock['warehouse_id'], null, (int) $stock['location_id'], null, (string) $stock['status'], $newStatus);
                $this->insertLedgerEntry($documentId, $lineId, (int) $stock['id'], (int) $stock['lot_id'], MovementType::SaleDispatch, -$quantity, $isReserved ? -$quantity : 0, (int) $stock['location_id'], null, $newOnHand, $newReserved, $actorUserId, $now);
                $this->updateStockProjection($stock, $newOnHand, $newReserved, (int) $stock['warehouse_id'], (int) $stock['location_id'], $newStatus, $now);
                $this->db->table('sales_order_lines')->where('id', $saleLine['id'])->update(['status' => 'dispatched', 'updated_at' => $now]);
                $results[] = ['stock_unit_id' => (int) $stock['id'], 'quantity_kg' => $quantity, 'remaining_kg' => $newOnHand, 'reservation_consumed' => $isReserved];
            }

            $this->db->table('sales_orders')->where('id', $salesOrderId)->update(['status' => 'dispatched', 'dispatched_by' => $actorUserId, 'dispatched_at' => $now, 'lock_version' => (int) $order['lock_version'] + 1, 'updated_at' => $now]);
            $this->audit->record('sale.dispatched', 'sales_order', $salesOrderId, $actorUserId, (int) $order['company_id'], (int) $order['site_id'], $originWarehouseId, ['inventory_document_id' => $documentId, 'origin_warehouse_id' => $originWarehouseId, 'lines' => $results]);
            $this->db->transCommit();
            return ['sales_order_id' => $salesOrderId, 'status' => 'dispatched', 'inventory_document_id' => $documentId, 'lines' => $results];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function reverse(int $documentId, string $reason, int $actorUserId): array
    {
        if (trim($reason) === '') {
            throw new DomainException('El reverso requiere motivo.', 'REVERSAL_REASON_REQUIRED');
        }

        $this->db->transException(true)->transBegin();

        try {
            $original = $this->inventory->findDocumentForUpdate($documentId);

            if (str_starts_with((string) ($original['source_document_type'] ?? ''), 'warehouse_request_')) {
                throw new DomainException('Usa el flujo de solicitud para liberar o devolver estas tinas.', 'SPECIALIZED_REVERSAL_REQUIRED');
            }
            if ($original['status'] !== 'confirmed') {
                throw new DomainException('Sólo se reversan movimientos confirmados.', 'DOCUMENT_NOT_CONFIRMED');
            }
            if (! in_array((string) $original['movement_type'], self::DIRECTLY_REVERSIBLE_TYPES, true)) {
                throw new DomainException(
                    'Este documento pertenece a un flujo especializado; usa liberación, devolución o conciliación según corresponda.',
                    'SPECIALIZED_REVERSAL_REQUIRED',
                    ['movement_type' => $original['movement_type']]
                );
            }

            if ($this->db->table('inventory_documents')->where('reversal_of_id', $documentId)->countAllResults() > 0) {
                throw new DomainException('El movimiento ya tiene reverso.', 'DOCUMENT_ALREADY_REVERSED');
            }

            $lines = $this->inventory->documentLines($documentId);
            $reversalId = $this->insertDocument(
                MovementType::Reversal,
                (int) $original['company_id'],
                (int) $original['site_id'],
                $original['destination_warehouse_id'] === null ? null : (int) $original['destination_warehouse_id'],
                $original['origin_warehouse_id'] === null ? null : (int) $original['origin_warehouse_id'],
                'inventory_reversal',
                $documentId,
                $actorUserId,
                $reason,
                reversalOfId: $documentId,
                reasonCode: 'reversal',
            );
            $now = date('Y-m-d H:i:s');
            $results = [];

            foreach ($lines as $index => $line) {
                $stock = $this->inventory->findStockUnitForUpdate((int) $line['stock_unit_id']);
                $this->assertWarehouseRequestCommitment($stock, $original['movement_type'] === MovementType::Relocation->value);
                $ledger = $this->db->table('inventory_movements')->where('inventory_document_line_id', $line['id'])->get()->getRowArray();
                $latestId = (int) $this->db->table('inventory_movements')->selectMax('id')->where('stock_unit_id', $stock['id'])->get()->getRow('id');

                if ($ledger === null || $latestId !== (int) $ledger['id']) {
                    throw new DomainException(
                        'La unidad tuvo movimientos posteriores; usa un ajuste compensatorio trazable.',
                        'REVERSAL_HAS_LATER_MOVEMENTS',
                        ['stock_unit_id' => $stock['id']]
                    );
                }

                $newOnHand = round((float) $stock['on_hand_weight_kg'] - (float) $ledger['quantity_delta_kg'], 3);
                $newReserved = round((float) $stock['reserved_weight_kg'] - (float) $ledger['reserved_delta_kg'], 3);

                if ($newOnHand < 0 || $newReserved < 0 || $newReserved > $newOnHand) {
                    throw new DomainException('El reverso produciría un saldo inválido.', 'INVALID_REVERSAL_BALANCE');
                }

                $targetWarehouse = $line['from_warehouse_id'] === null ? (int) $stock['warehouse_id'] : (int) $line['from_warehouse_id'];
                $targetLocation = $line['from_location_id'] === null ? (int) $stock['location_id'] : (int) $line['from_location_id'];
                $targetStatus = $line['stock_status_before'] ?: ($newOnHand === 0.0 ? 'exhausted' : 'available');
                $this->assertStockPlacement($stock, $targetLocation, $targetWarehouse, $newOnHand, (string) $targetStatus);
                $reversalLineId = $this->insertDocumentLine(
                    $reversalId,
                    $index + 1,
                    (int) $stock['id'],
                    (int) $stock['lot_id'],
                    (float) $line['quantity_kg'],
                    (int) $stock['warehouse_id'],
                    $targetWarehouse,
                    (int) $stock['location_id'],
                    $targetLocation,
                    (string) $stock['status'],
                    (string) $targetStatus,
                );
                $movementId = $this->insertLedgerEntry(
                    $reversalId,
                    $reversalLineId,
                    (int) $stock['id'],
                    (int) $stock['lot_id'],
                    MovementType::Reversal,
                    -(float) $ledger['quantity_delta_kg'],
                    -(float) $ledger['reserved_delta_kg'],
                    (int) $stock['location_id'],
                    $targetLocation,
                    $newOnHand,
                    $newReserved,
                    $actorUserId,
                    $now,
                );
                $this->updateStockProjection($stock, $newOnHand, $newReserved, $targetWarehouse, $targetLocation, (string) $targetStatus, $now);

                if ($targetLocation !== (int) $stock['location_id']) {
                    $this->insertLocationHistory((int) $stock['id'], $movementId, (int) $stock['location_id'], $targetLocation, $actorUserId, $now);
                }

                $results[] = ['stock_unit_id' => (int) $stock['id'], 'on_hand_weight_kg' => $newOnHand, 'reserved_weight_kg' => $newReserved];
            }

            if ((string) $original['movement_type'] === MovementType::Receipt->value
                && (string) $original['source_document_type'] === 'reception'
                && $original['source_document_id'] !== null) {
                $receptionId = (int) $original['source_document_id'];
                $this->db->table('receptions')->where(['id' => $receptionId, 'status' => 'confirmed'])->update([
                    'status' => 'reversed',
                    'lock_version' => $this->db->query('SELECT lock_version FROM receptions WHERE id = ?', [$receptionId])->getRow('lock_version') + 1,
                    'updated_at' => $now,
                ]);
                if ($this->db->affectedRows() !== 1) {
                    throw new DomainException('La recepción vinculada ya no está confirmada.', 'RECEPTION_NOT_REVERSIBLE');
                }
                $this->db->table('reception_lines')->where('reception_id', $receptionId)->update(['status' => 'reversed']);
            }

            $this->audit->record('inventory.movement_reversed', 'inventory_document', $reversalId, $actorUserId, (int) $original['company_id'], (int) $original['site_id'], null, ['reversal_of_id' => $documentId, 'lines' => $results], $reason);
            $this->db->transCommit();

            return ['document_id' => $reversalId, 'reversal_of_id' => $documentId, 'lines' => $results];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function reconcileCount(int $countId, string $reason, int $actorUserId): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException('La conciliación requiere un motivo de al menos cinco caracteres.', 'RECONCILIATION_REASON_REQUIRED');
        }

        $this->db->transException(true)->transBegin();
        try {
            $count = $this->db->query(<<<'SQL'
SELECT ic.*, w.company_id, w.site_id
FROM inventory_counts ic
INNER JOIN warehouses w ON w.id = ic.warehouse_id
WHERE ic.id = ?
FOR UPDATE
SQL, [$countId])->getRowArray();
            if ($count === null || $count['status'] !== 'counted') {
                throw new DomainException('El conteo debe estar terminado antes de conciliarse.', 'INVENTORY_COUNT_NOT_RECONCILABLE');
            }

            $lines = $this->db->table('inventory_count_lines')->where('inventory_count_id', $countId)->orderBy('id')->get()->getResultArray();
            $relocations = [];
            $relocatedLineIds = [];
            $missing = 0;

            // El peso ya no forma parte del conteo físico. Se conserva la fotografía
            // original únicamente para verificar que el inventario no cambió mientras se contaba.
            foreach ($lines as $line) {
                if ((string) $line['result'] === 'pending') {
                    throw new DomainException('El conteo contiene tinas pendientes.', 'INVENTORY_COUNT_INCOMPLETE');
                }

                $stock = $this->inventory->findStockUnitForUpdate((int) $line['stock_unit_id']);
                if ((int) $stock['lock_version'] !== (int) $line['expected_lock_version']
                    || abs((float) $stock['on_hand_weight_kg'] - (float) $line['expected_weight_kg']) > 0.001
                    || (int) $stock['location_id'] !== (int) $line['expected_location_id']) {
                    throw new DomainException(
                        'El inventario cambió después de congelar el conteo; revisa la tina indicada e inicia un nuevo conteo si corresponde.',
                        'INVENTORY_COUNT_SNAPSHOT_STALE',
                        ['stock_unit_id' => (int) $stock['id']]
                    );
                }

                if ((string) $line['result'] === 'relocated') {
                    if ($line['counted_location_id'] === null) {
                        throw new DomainException('Una reubicación detectada no tiene fila física.', 'COUNT_RELOCATION_WITHOUT_ROW');
                    }
                    $relocations[] = new MovementLineData(
                        (int) $stock['id'],
                        (float) $stock['on_hand_weight_kg'],
                        (int) $line['expected_location_id'],
                        (int) $line['counted_location_id'],
                        (int) $count['warehouse_id'],
                        (int) $count['warehouse_id'],
                    );
                    $relocatedLineIds[] = (int) $line['id'];
                } elseif ((string) $line['result'] === 'missing') {
                    // Una tina no encontrada NO disminuye inventario automáticamente.
                    $missing++;
                }
            }

            $documents = [];
            if ($relocations !== []) {
                $data = new CreateMovementData(
                    MovementType::Relocation,
                    (int) $count['company_id'],
                    (int) $count['site_id'],
                    (int) $count['warehouse_id'],
                    (int) $count['warehouse_id'],
                    'inventory_count',
                    $countId,
                    null,
                    null,
                    'physical_count',
                    $reason,
                    $actorUserId,
                    $relocations,
                );
                $documentId = $this->insertDocument(
                    MovementType::Relocation,
                    $data->companyId,
                    $data->siteId,
                    $data->originWarehouseId,
                    $data->destinationWarehouseId,
                    $data->sourceDocumentType,
                    $data->sourceDocumentId,
                    $data->actorUserId,
                    $data->observations,
                    reasonCode: $data->reasonCode,
                );
                foreach ($relocations as $index => $movementLine) {
                    $this->applyExistingLine($documentId, $index + 1, $data, $movementLine);
                }
                $documents[] = ['id' => $documentId, 'movement_type' => MovementType::Relocation->value];
                if ($relocatedLineIds !== []) {
                    $this->db->table('inventory_count_lines')->whereIn('id', $relocatedLineIds)->update(['result' => 'relocation_applied']);
                }
            }

            $unexpected = (int) $this->db->table('inventory_count_unexpected')->where('inventory_count_id', $countId)->countAllResults();
            $now = date('Y-m-d H:i:s');
            $this->db->table('inventory_counts')->where('id', $countId)->update([
                'status' => 'reconciled', 'reconciled_by' => $actorUserId, 'reconciled_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record(
                'inventory_count.reconciled', 'inventory_count', $countId, $actorUserId,
                (int) $count['company_id'], (int) $count['site_id'], null,
                ['documents' => $documents, 'relocations' => count($relocations), 'missing' => $missing, 'unexpected' => $unexpected], $reason,
            );
            $this->db->transCommit();

            return [
                'id' => $countId,
                'status' => 'reconciled',
                'documents' => $documents,
                'relocations_applied' => count($relocations),
                'missing_pending_review' => $missing,
                'unexpected_pending_review' => $unexpected,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function applyExistingLine(int $documentId, int $lineNumber, CreateMovementData $data, MovementLineData $line): array
    {
        $stock = $this->inventory->findStockUnitForUpdate($line->stockUnitId);
        $this->assertWarehouseRequestCommitment($stock, $data->type === MovementType::Relocation);

        if ((int) $stock['company_id'] !== $data->companyId || (int) $stock['site_id'] !== $data->siteId) {
            throw new DomainException('La unidad está fuera del alcance operativo.', 'STOCK_SCOPE_MISMATCH');
        }

        if (in_array($stock['status'], self::TERMINAL_STATES, true) && ! in_array($data->type, [MovementType::Return, MovementType::AdjustmentIncrease], true)) {
            throw new DomainException('La unidad no admite más movimientos.', 'STOCK_UNIT_TERMINAL', ['status' => $stock['status']]);
        }

        if ($line->quantityKg <= 0) {
            throw new DomainException('La cantidad debe ser positiva.', 'INVALID_MOVEMENT_QUANTITY');
        }

        if ($line->fromLocationId !== null && $line->fromLocationId !== (int) $stock['location_id']) {
            throw new DomainException('La ubicación origen cambió; recarga la operación.', 'STALE_STOCK_LOCATION');
        }
        if ($line->fromWarehouseId !== null && $line->fromWarehouseId !== (int) $stock['warehouse_id']) {
            throw new DomainException('El almacén origen cambió; recarga la operación.', 'STALE_STOCK_WAREHOUSE');
        }
        if ($data->originWarehouseId !== null && $data->originWarehouseId !== (int) $stock['warehouse_id']) {
            throw new DomainException('El encabezado no corresponde al almacén de la unidad.', 'MOVEMENT_WAREHOUSE_MISMATCH');
        }

        $onHand = (float) $stock['on_hand_weight_kg'];
        $reserved = (float) $stock['reserved_weight_kg'];
        $available = round($onHand - $reserved, 3);
        $quantityDelta = 0.0;
        $reservedDelta = 0.0;
        $newWarehouse = (int) $stock['warehouse_id'];
        $newLocation = (int) $stock['location_id'];
        $newStatus = (string) $stock['status'];

        switch ($data->type) {
            case MovementType::Relocation:
                if (abs($line->quantityKg - $onHand) > 0.001 || $line->toLocationId === null) {
                    throw new DomainException('La reubicación mueve la unidad completa y requiere destino.', 'FULL_UNIT_RELOCATION_REQUIRED');
                }
                $targetWarehouse = $line->toWarehouseId ?? $newWarehouse;
                if ($targetWarehouse !== $newWarehouse) {
                    throw new DomainException('Los cambios de almacén deben ejecutarse mediante un traspaso.', 'TRANSFER_REQUIRED');
                }

                $targetLocationId = $this->resolveRelocationTargetForUpdate(
                    $stock,
                    $line->toLocationId,
                    $targetWarehouse,
                    $onHand,
                    $newStatus,
                );

                if ($targetLocationId === $newLocation && $targetWarehouse === $newWarehouse) {
                    throw new DomainException('El destino debe ser distinto del origen.', 'SAME_LOCATION_MOVEMENT');
                }
                $this->assertStockPlacement($stock, $targetLocationId, $targetWarehouse, $onHand, $newStatus);
                $newWarehouse = $targetWarehouse;
                $newLocation = $targetLocationId;
                break;

            case MovementType::ProductionIssue:
            case MovementType::SaleDispatch:
            case MovementType::Loss:
            case MovementType::AdjustmentDecrease:
                if ($line->quantityKg > $available) {
                    throw new DomainException('Saldo disponible insuficiente.', 'INSUFFICIENT_AVAILABLE_STOCK', ['available_kg' => $available]);
                }
                $quantityDelta = -$line->quantityKg;
                $remaining = round($onHand + $quantityDelta, 3);
                $newStatus = match (true) {
                    $remaining <= 0 => 'exhausted',
                    $reserved > 0 => 'reserved',
                    $stock['status'] === 'held' => 'held',
                    $stock['status'] === 'rejected' => 'rejected',
                    default => 'available',
                };
                break;

            case MovementType::Return:
            case MovementType::AdjustmentIncrease:
                $quantityDelta = $line->quantityKg;
                $newStatus = in_array($stock['status'], ['held', 'rejected'], true) ? (string) $stock['status'] : 'available';
                $this->assertStockPlacement($stock, (int) $stock['location_id'], (int) $stock['warehouse_id'], $onHand + $quantityDelta, $newStatus);
                break;

            default:
                throw new DomainException('Tipo no soportado por el movimiento general.', 'UNSUPPORTED_MOVEMENT_TYPE');
        }

        $newOnHand = round($onHand + $quantityDelta, 3);
        $newReserved = round($reserved + $reservedDelta, 3);
        $now = date('Y-m-d H:i:s');
        $lineId = $this->insertDocumentLine(
            $documentId,
            $lineNumber,
            (int) $stock['id'],
            (int) $stock['lot_id'],
            $line->quantityKg,
            (int) $stock['warehouse_id'],
            $newWarehouse,
            (int) $stock['location_id'],
            $newLocation,
            (string) $stock['status'],
            $newStatus,
        );
        $movementId = $this->insertLedgerEntry(
            $documentId,
            $lineId,
            (int) $stock['id'],
            (int) $stock['lot_id'],
            $data->type,
            $quantityDelta,
            $reservedDelta,
            (int) $stock['location_id'],
            $newLocation,
            $newOnHand,
            $newReserved,
            $data->actorUserId,
            $now,
        );
        $this->updateStockProjection($stock, $newOnHand, $newReserved, $newWarehouse, $newLocation, $newStatus, $now);

        if ($newLocation !== (int) $stock['location_id']) {
            $this->insertLocationHistory((int) $stock['id'], $movementId, (int) $stock['location_id'], $newLocation, $data->actorUserId, $now);
        }

        return ['stock_unit_id' => (int) $stock['id'], 'on_hand_weight_kg' => $newOnHand, 'reserved_weight_kg' => $newReserved, 'status' => $newStatus];
    }

    /** Called only from the transaction of the warehouse-request service. */
    public function cancelWarehouseRequestShipment(
        array $request,
        int $stockId,
        string $reason,
        int $actorUserId
    ): array {
        $reason=trim($reason);
        if (mb_strlen($reason)<5 || mb_strlen($reason)>1000) {
            throw new DomainException(
                'Cancelar una salida requiere un motivo de 5 a 1000 caracteres.',
                'WAREHOUSE_SHIPMENT_CANCEL_REASON_REQUIRED'
            );
        }

        $stock=$this->inventory->findStockUnitForUpdate($stockId);

        if (
            (string) $stock['status']!=='in_transit'
            || (int) $stock['company_id']!==(int) $request['provider_company_id']
            || (int) $stock['warehouse_id']!==(int) $request['source_warehouse_id']
        ) {
            throw new DomainException(
                'La tina ya cambió de estado o almacén y no puede cancelarse desde Salidas.',
                'WAREHOUSE_SHIPMENT_STOCK_NOT_CANCELABLE'
            );
        }

        $original=$this->db->query(
            <<<'SQL'
SELECT
    idoc.*,
    idl.id AS document_line_id,
    idl.from_warehouse_id,
    idl.to_warehouse_id,
    idl.from_location_id,
    idl.to_location_id,
    idl.stock_status_before,
    idl.stock_status_after,
    idl.quantity_kg,
    im.id AS movement_id,
    im.quantity_delta_kg,
    im.reserved_delta_kg
FROM inventory_documents idoc
INNER JOIN inventory_document_lines idl
    ON idl.inventory_document_id=idoc.id
INNER JOIN inventory_movements im
    ON im.inventory_document_line_id=idl.id
LEFT JOIN inventory_documents reversal
    ON reversal.reversal_of_id=idoc.id
WHERE idoc.source_document_type='warehouse_request_ship'
  AND idoc.source_document_id=?
  AND idoc.movement_type=?
  AND idoc.status='confirmed'
  AND idl.stock_unit_id=?
  AND reversal.id IS NULL
ORDER BY idoc.id DESC
LIMIT 1
FOR UPDATE
SQL,
            [(int) $request['id'],MovementType::TransferShipment->value,$stockId]
        )->getRowArray();

        if ($original===null) {
            throw new DomainException(
                'No se encontró el movimiento vigente de embarque de la tina.',
                'WAREHOUSE_SHIPMENT_DOCUMENT_NOT_FOUND'
            );
        }

        $latestMovementId=(int) $this->db->table('inventory_movements')
            ->selectMax('id')
            ->where('stock_unit_id',$stockId)
            ->get()
            ->getRow('id');

        if ($latestMovementId!==(int) $original['movement_id']) {
            throw new DomainException(
                'La tina ya tuvo un movimiento posterior o fue recibida; la salida ya no puede cancelarse.',
                'WAREHOUSE_SHIPMENT_HAS_LATER_MOVEMENT'
            );
        }

        $restoredOnHand=round(
            (float) $stock['on_hand_weight_kg']-(float) $original['quantity_delta_kg'],
            3
        );
        $restoredReserved=round(
            (float) $stock['reserved_weight_kg']-(float) $original['reserved_delta_kg'],
            3
        );

        if (
            $restoredOnHand<=0
            || $restoredReserved<0
            || abs($restoredReserved-$restoredOnHand)>0.001
        ) {
            throw new DomainException(
                'El movimiento de embarque no puede restaurarse de forma segura.',
                'WAREHOUSE_SHIPMENT_REVERSAL_BALANCE_INVALID'
            );
        }

        $targetWarehouse=(int) ($original['from_warehouse_id']??0);
        $targetLocation=(int) ($original['from_location_id']??0);

        if (
            $targetWarehouse!==(int) $request['source_warehouse_id']
            || $targetLocation<=0
        ) {
            throw new DomainException(
                'El origen histórico del embarque no es válido.',
                'WAREHOUSE_SHIPMENT_REVERSAL_ORIGIN_INVALID'
            );
        }

        $now=date('Y-m-d H:i:s');

        $reversalDocumentId=$this->insertDocument(
            MovementType::Reversal,
            (int) $original['company_id'],
            (int) $original['site_id'],
            $original['destination_warehouse_id']===null?null:(int) $original['destination_warehouse_id'],
            $targetWarehouse,
            'warehouse_request_ship_cancel',
            (int) $request['id'],
            $actorUserId,
            $reason,
            reversalOfId:(int) $original['id'],
            reasonCode:'warehouse_request_ship_cancel'
        );

        $reversalLineId=$this->insertDocumentLine(
            $reversalDocumentId,
            1,
            $stockId,
            (int) $stock['lot_id'],
            $restoredOnHand,
            $original['destination_warehouse_id']===null?null:(int) $original['destination_warehouse_id'],
            $targetWarehouse,
            null,
            $targetLocation,
            (string) $stock['status'],
            'reserved'
        );

        $reversalMovementId=$this->insertLedgerEntry(
            $reversalDocumentId,
            $reversalLineId,
            $stockId,
            (int) $stock['lot_id'],
            MovementType::Reversal,
            -(float) $original['quantity_delta_kg'],
            -(float) $original['reserved_delta_kg'],
            null,
            $targetLocation,
            $restoredOnHand,
            $restoredReserved,
            $actorUserId,
            $now
        );

        $this->updateStockProjection(
            $stock,
            $restoredOnHand,
            $restoredReserved,
            $targetWarehouse,
            $targetLocation,
            'reserved',
            $now
        );

        $this->audit->record(
            'inventory.warehouse_request_shipment_reversed',
            'inventory_document',
            $reversalDocumentId,
            $actorUserId,
            (int) $original['company_id'],
            (int) $original['site_id'],
            null,
            [
                'reversal_of_id'=>(int) $original['id'],
                'stock_unit_id'=>$stockId,
                'inventory_movement_id'=>$reversalMovementId,
                'restored_weight_kg'=>$restoredOnHand,
                'restored_reserved_kg'=>$restoredReserved,
            ],
            $reason
        );

        return [
            'reversal_document_id'=>$reversalDocumentId,
            'reversal_movement_id'=>$reversalMovementId,
            'restored_weight_kg'=>$restoredOnHand,
            'restored_reserved_kg'=>$restoredReserved,
            'release_document_id'=>null,
        ];
    }

    /** Called only from the transaction of the warehouse-request service. */
    public function cancelWarehouseRequestReceipt(
        array $request,
        int $stockId,
        string $reason,
        int $actorUserId
    ): array {
        $reason=trim($reason);
        if (mb_strlen($reason)<5 || mb_strlen($reason)>1000) {
            throw new DomainException(
                'Anular una recepción requiere un motivo de 5 a 1000 caracteres.',
                'WAREHOUSE_RECEIPT_CANCEL_REASON_REQUIRED'
            );
        }

        $stock=$this->inventory->findStockUnitForUpdate($stockId);
        if (
            (string) $stock['status']!=='available'
            || (int) $stock['company_id']!==(int) $request['company_id']
            || (int) $stock['warehouse_id']!==(int) $request['destination_warehouse_id']
            || (float) $stock['reserved_weight_kg']>0.0001
        ) {
            throw new DomainException(
                'La tina ya cambió de estado, almacén o tiene una reserva; la recepción no puede anularse.',
                'WAREHOUSE_RECEIPT_STOCK_NOT_CANCELABLE'
            );
        }

        $original=$this->db->query(
            <<<'SQL'
SELECT
    idoc.*,
    idl.id AS document_line_id,
    idl.from_warehouse_id,
    idl.to_warehouse_id,
    idl.from_location_id,
    idl.to_location_id,
    idl.stock_status_before,
    idl.stock_status_after,
    idl.quantity_kg,
    im.id AS movement_id,
    im.quantity_delta_kg,
    im.reserved_delta_kg
FROM inventory_documents idoc
INNER JOIN inventory_document_lines idl
    ON idl.inventory_document_id=idoc.id
INNER JOIN inventory_movements im
    ON im.inventory_document_line_id=idl.id
LEFT JOIN inventory_documents reversal
    ON reversal.reversal_of_id=idoc.id
WHERE idoc.source_document_type='warehouse_request_accept'
  AND idoc.source_document_id=?
  AND idoc.movement_type=?
  AND idoc.status='confirmed'
  AND idl.stock_unit_id=?
  AND reversal.id IS NULL
ORDER BY idoc.id DESC
LIMIT 1
FOR UPDATE
SQL,
            [(int) $request['id'],MovementType::TransferReceipt->value,$stockId]
        )->getRowArray();

        if ($original===null) {
            throw new DomainException(
                'No se encontró el movimiento vigente de recepción de la tina.',
                'WAREHOUSE_RECEIPT_DOCUMENT_NOT_FOUND'
            );
        }

        $latestMovementId=(int) $this->db->table('inventory_movements')
            ->selectMax('id')
            ->where('stock_unit_id',$stockId)
            ->get()
            ->getRow('id');
        if ($latestMovementId!==(int) $original['movement_id']) {
            throw new DomainException(
                'La tina ya tuvo un movimiento posterior; la recepción no puede anularse.',
                'WAREHOUSE_RECEIPT_HAS_LATER_MOVEMENT'
            );
        }

        $restoredOnHand=round((float) $stock['on_hand_weight_kg']-(float) $original['quantity_delta_kg'],3);
        $restoredReserved=round((float) $stock['reserved_weight_kg']-(float) $original['reserved_delta_kg'],3);
        if ($restoredOnHand<=0 || abs($restoredReserved)>0.0001) {
            throw new DomainException(
                'El movimiento de recepción no puede restaurarse de forma segura.',
                'WAREHOUSE_RECEIPT_REVERSAL_BALANCE_INVALID'
            );
        }

        $targetWarehouse=(int) ($original['from_warehouse_id']??0);
        $targetLocation=(int) ($original['from_location_id']??0);
        if ($targetWarehouse!==(int) $request['source_warehouse_id'] || $targetLocation<=0) {
            throw new DomainException(
                'El origen histórico de la recepción no es válido.',
                'WAREHOUSE_RECEIPT_REVERSAL_ORIGIN_INVALID'
            );
        }

        $now=date('Y-m-d H:i:s');
        $reversalDocumentId=$this->insertDocument(
            MovementType::Reversal,
            (int) $request['company_id'],
            (int) $stock['site_id'],
            (int) $request['destination_warehouse_id'],
            $targetWarehouse,
            'warehouse_request_receipt_cancel',
            (int) $request['id'],
            $actorUserId,
            $reason,
            reversalOfId:(int) $original['id'],
            reasonCode:'warehouse_request_receipt_cancel'
        );

        $reversalLineId=$this->insertDocumentLine(
            $reversalDocumentId,
            1,
            $stockId,
            (int) $stock['lot_id'],
            $restoredOnHand,
            (int) $request['destination_warehouse_id'],
            $targetWarehouse,
            (int) $stock['location_id'],
            $targetLocation,
            (string) $stock['status'],
            'in_transit'
        );

        $reversalMovementId=$this->insertLedgerEntry(
            $reversalDocumentId,
            $reversalLineId,
            $stockId,
            (int) $stock['lot_id'],
            MovementType::Reversal,
            -(float) $original['quantity_delta_kg'],
            -(float) $original['reserved_delta_kg'],
            (int) $stock['location_id'],
            $targetLocation,
            $restoredOnHand,
            $restoredReserved,
            $actorUserId,
            $now
        );

        $originWarehouse=$this->db->table('warehouses')->where('id',$targetWarehouse)->get()->getRowArray();
        if ($originWarehouse===null) {
            throw new DomainException('El almacén origen ya no existe.', 'WAREHOUSE_NOT_FOUND');
        }

        $this->updateStockProjection(
            $stock,
            $restoredOnHand,
            0.0,
            $targetWarehouse,
            $targetLocation,
            'in_transit',
            $now,
            (int) $originWarehouse['site_id'],
            (int) $originWarehouse['company_id']
        );
        $this->insertLocationHistory($stockId,$reversalMovementId,(int) $stock['location_id'],$targetLocation,$actorUserId,$now);

        $this->audit->record(
            'inventory.warehouse_request_receipt_reversed',
            'inventory_document',
            $reversalDocumentId,
            $actorUserId,
            (int) $request['company_id'],
            (int) $stock['site_id'],
            null,
            [
                'reversal_of_id'=>(int) $original['id'],
                'stock_unit_id'=>$stockId,
                'reversal_movement_id'=>$reversalMovementId,
                'restored_weight_kg'=>$restoredOnHand,
                'restored_warehouse_id'=>$targetWarehouse,
                'restored_location_id'=>$targetLocation,
            ],
            $reason
        );

        return [
            'reversal_document_id'=>$reversalDocumentId,
            'reversal_movement_id'=>$reversalMovementId,
            'stock_unit_id'=>$stockId,
            'restored_weight_kg'=>$restoredOnHand,
            'status'=>'in_transit',
        ];
    }


    /**
     * Anula de forma atómica una recepción de Materia prima que pudo haber
     * generado ya su salida terminal a Producción.
     *
     * Cadena válida:
     * TransferReceipt -> ProductionIssue -> (reversal ProductionIssue)
     * -> (reversal TransferReceipt)
     *
     * Se bloquea si la tina física fue reutilizada o si el stock tuvo un
     * movimiento posterior ajeno a esta cadena.
     */
    public function cancelWarehouseRequestProductionReceipt(
        array $request,
        int $unitId,
        int $stockId,
        string $reason,
        int $actorUserId
    ): array {
        $reason=trim($reason);
        if (mb_strlen($reason)<5 || mb_strlen($reason)>1000) {
            throw new DomainException(
                'Anular una recepción requiere un motivo de 5 a 1000 caracteres.',
                'WAREHOUSE_RECEIPT_CANCEL_REASON_REQUIRED'
            );
        }
        if ((string) ($request['request_type']??'')!=='production') {
            throw new DomainException(
                'La solicitud no corresponde a Materia prima para Producción.',
                'PRODUCTION_RECEIPT_CANCEL_REQUEST_TYPE_INVALID'
            );
        }

        $stock=$this->inventory->findStockUnitForUpdate($stockId);
        $physicalTub=$this->db->query(
            'SELECT * FROM tubs WHERE id=? FOR UPDATE',
            [(int) $stock['tub_id']]
        )->getRowArray();

        if ($physicalTub===null || ! (bool) $physicalTub['active']) {
            throw new DomainException(
                'La tina física ya no está disponible para validar el reverso.',
                'PRODUCTION_RECEIPT_TUB_NOT_AVAILABLE'
            );
        }

        $production=$this->db->query(
            <<<'SQL'
SELECT
    idoc.*,
    idl.id AS document_line_id,
    idl.from_warehouse_id,
    idl.to_warehouse_id,
    idl.from_location_id,
    idl.to_location_id,
    idl.stock_status_before,
    idl.stock_status_after,
    idl.quantity_kg,
    im.id AS movement_id,
    im.quantity_delta_kg,
    im.reserved_delta_kg
FROM inventory_documents idoc
INNER JOIN inventory_document_lines idl
    ON idl.inventory_document_id=idoc.id
INNER JOIN inventory_movements im
    ON im.inventory_document_line_id=idl.id
LEFT JOIN inventory_documents reversal
    ON reversal.reversal_of_id=idoc.id
WHERE idoc.source_document_type='warehouse_request_production'
  AND idoc.source_document_id=?
  AND idoc.movement_type=?
  AND idoc.status='confirmed'
  AND idl.stock_unit_id=?
  AND reversal.id IS NULL
ORDER BY idoc.id DESC
LIMIT 1
FOR UPDATE
SQL,
            [$unitId,MovementType::ProductionIssue->value,$stockId]
        )->getRowArray();

        $productionReversalDocumentId=null;
        $productionReversalMovementId=null;

        if ($production!==null) {
            if (
                (string) $physicalTub['status']!=='available'
                || (string) $stock['status']!=='exhausted'
                || (int) $stock['warehouse_id']!==(int) $request['destination_warehouse_id']
                || (int) $stock['company_id']!==(int) $request['company_id']
                || abs((float) $stock['on_hand_weight_kg'])>0.0001
                || abs((float) $stock['reserved_weight_kg'])>0.0001
            ) {
                throw new DomainException(
                    'La salida a Producción ya no está en un estado reversible.',
                    'PRODUCTION_ISSUE_NOT_REVERSIBLE'
                );
            }

            $laterStock=$this->db->query(
                'SELECT id,created_at FROM stock_units WHERE tub_id=? AND id<>? AND created_at>? ORDER BY id ASC LIMIT 1',
                [(int) $stock['tub_id'],$stockId,(string) $production['created_at']]
            )->getRowArray();
            if ($laterStock!==null) {
                throw new DomainException(
                    'La tina ya fue reutilizada después de la salida a Producción; la recepción histórica no puede anularse.',
                    'PRODUCTION_RECEIPT_TUB_REUSED_AFTER_ISSUE',
                    [
                        'stock_unit_id'=>$stockId,
                        'tub_id'=>(int) $stock['tub_id'],
                        'later_stock_unit_id'=>(int) $laterStock['id'],
                    ]
                );
            }

            $latestMovementId=(int) $this->db->table('inventory_movements')
                ->selectMax('id')
                ->where('stock_unit_id',$stockId)
                ->get()
                ->getRow('id');
            if ($latestMovementId!==(int) $production['movement_id']) {
                throw new DomainException(
                    'La tina ya tuvo otro movimiento después de Producción; la recepción no puede anularse.',
                    'PRODUCTION_RECEIPT_HAS_LATER_MOVEMENT'
                );
            }

            $restoredWeight=round(
                (float) $stock['on_hand_weight_kg']-(float) $production['quantity_delta_kg'],
                3
            );
            if ($restoredWeight<=0 || (int) ($production['from_location_id']??0)<=0) {
                throw new DomainException(
                    'La salida a Producción no puede restaurarse de forma segura.',
                    'PRODUCTION_ISSUE_REVERSAL_BALANCE_INVALID'
                );
            }

            $now=date('Y-m-d H:i:s');
            $productionReversalDocumentId=$this->insertDocument(
                MovementType::Reversal,
                (int) $request['company_id'],
                (int) $stock['site_id'],
                null,
                (int) $request['destination_warehouse_id'],
                'warehouse_request_production_cancel',
                $unitId,
                $actorUserId,
                $reason,
                reversalOfId:(int) $production['id'],
                reasonCode:'production_issue_cancel'
            );

            $productionReversalLineId=$this->insertDocumentLine(
                $productionReversalDocumentId,
                1,
                $stockId,
                (int) $stock['lot_id'],
                $restoredWeight,
                null,
                (int) $request['destination_warehouse_id'],
                null,
                (int) $production['from_location_id'],
                (string) $stock['status'],
                'available'
            );

            $productionReversalMovementId=$this->insertLedgerEntry(
                $productionReversalDocumentId,
                $productionReversalLineId,
                $stockId,
                (int) $stock['lot_id'],
                MovementType::Reversal,
                -(float) $production['quantity_delta_kg'],
                -(float) $production['reserved_delta_kg'],
                null,
                (int) $production['from_location_id'],
                $restoredWeight,
                0.0,
                $actorUserId,
                $now
            );

            $this->updateStockProjection(
                $stock,
                $restoredWeight,
                0.0,
                (int) $request['destination_warehouse_id'],
                (int) $production['from_location_id'],
                'available',
                $now
            );

            $this->db->table('tubs')
                ->where([
                    'id'=>(int) $physicalTub['id'],
                    'lock_version'=>(int) $physicalTub['lock_version'],
                ])
                ->update([
                    'status'=>'received',
                    'lock_version'=>(int) $physicalTub['lock_version']+1,
                    'updated_at'=>$now,
                ]);
            if ($this->db->affectedRows()!==1) {
                throw new DomainException(
                    'La tina física cambió durante el reverso de Producción.',
                    'PRODUCTION_RECEIPT_TUB_CONCURRENT_MODIFICATION'
                );
            }

            $this->audit->record(
                'inventory.production_issue_reversed',
                'inventory_document',
                $productionReversalDocumentId,
                $actorUserId,
                (int) $request['company_id'],
                (int) $stock['site_id'],
                after:[
                    'reversal_of_id'=>(int) $production['id'],
                    'warehouse_request_id'=>(int) $request['id'],
                    'warehouse_request_unit_id'=>$unitId,
                    'stock_unit_id'=>$stockId,
                    'restored_weight_kg'=>$restoredWeight,
                    'physical_tub_id'=>(int) $physicalTub['id'],
                    'physical_tub_status_after'=>'received',
                ],
                reason:$reason
            );

            // Actualiza la proyección local para el segundo reverso dentro de
            // esta misma transacción.
            $stock=$this->inventory->findStockUnitForUpdate($stockId);
            $physicalTub=$this->db->query(
                'SELECT * FROM tubs WHERE id=? FOR UPDATE',
                [(int) $stock['tub_id']]
            )->getRowArray();
        }

        if (
            (string) $stock['status']!=='available'
            || (int) $stock['company_id']!==(int) $request['company_id']
            || (int) $stock['warehouse_id']!==(int) $request['destination_warehouse_id']
            || (float) $stock['on_hand_weight_kg']<=0
            || (float) $stock['reserved_weight_kg']>0.0001
            || $physicalTub===null
            || (string) $physicalTub['status']!=='received'
        ) {
            throw new DomainException(
                'La recepción ya no está en un estado reversible.',
                'WAREHOUSE_RECEIPT_STOCK_NOT_CANCELABLE'
            );
        }

        $receipt=$this->db->query(
            <<<'SQL'
SELECT
    idoc.*,
    idl.id AS document_line_id,
    idl.from_warehouse_id,
    idl.to_warehouse_id,
    idl.from_location_id,
    idl.to_location_id,
    idl.stock_status_before,
    idl.stock_status_after,
    idl.quantity_kg,
    im.id AS movement_id,
    im.quantity_delta_kg,
    im.reserved_delta_kg
FROM inventory_documents idoc
INNER JOIN inventory_document_lines idl
    ON idl.inventory_document_id=idoc.id
INNER JOIN inventory_movements im
    ON im.inventory_document_line_id=idl.id
LEFT JOIN inventory_documents reversal
    ON reversal.reversal_of_id=idoc.id
WHERE idoc.source_document_type='warehouse_request_accept'
  AND idoc.source_document_id=?
  AND idoc.movement_type=?
  AND idoc.status='confirmed'
  AND idl.stock_unit_id=?
  AND reversal.id IS NULL
ORDER BY idoc.id DESC
LIMIT 1
FOR UPDATE
SQL,
            [(int) $request['id'],MovementType::TransferReceipt->value,$stockId]
        )->getRowArray();

        if ($receipt===null) {
            throw new DomainException(
                'No se encontró el movimiento vigente de recepción de la tina.',
                'WAREHOUSE_RECEIPT_DOCUMENT_NOT_FOUND'
            );
        }

        if ($production===null) {
            $latestMovementId=(int) $this->db->table('inventory_movements')
                ->selectMax('id')
                ->where('stock_unit_id',$stockId)
                ->get()
                ->getRow('id');
            if ($latestMovementId!==(int) $receipt['movement_id']) {
                throw new DomainException(
                    'La tina ya tuvo un movimiento posterior; la recepción no puede anularse.',
                    'WAREHOUSE_RECEIPT_HAS_LATER_MOVEMENT'
                );
            }
        } else {
            $latestMovementId=(int) $this->db->table('inventory_movements')
                ->selectMax('id')
                ->where('stock_unit_id',$stockId)
                ->get()
                ->getRow('id');
            if ($latestMovementId!==(int) $productionReversalMovementId) {
                throw new DomainException(
                    'La cadena de reverso cambió durante la operación.',
                    'PRODUCTION_RECEIPT_REVERSAL_CHAIN_CHANGED'
                );
            }
        }

        $restoredOnHand=round(
            (float) $stock['on_hand_weight_kg']-(float) $receipt['quantity_delta_kg'],
            3
        );
        $restoredReserved=round(
            (float) $stock['reserved_weight_kg']-(float) $receipt['reserved_delta_kg'],
            3
        );

        if ($restoredOnHand<=0 || abs($restoredReserved)>0.0001) {
            throw new DomainException(
                'El movimiento de recepción no puede restaurarse de forma segura.',
                'WAREHOUSE_RECEIPT_REVERSAL_BALANCE_INVALID'
            );
        }

        $targetWarehouse=(int) ($receipt['from_warehouse_id']??0);
        $targetLocation=(int) ($receipt['from_location_id']??0);
        if ($targetWarehouse!==(int) $request['source_warehouse_id'] || $targetLocation<=0) {
            throw new DomainException(
                'El origen histórico de la recepción no es válido.',
                'WAREHOUSE_RECEIPT_REVERSAL_ORIGIN_INVALID'
            );
        }

        $now=date('Y-m-d H:i:s');
        $receiptReversalDocumentId=$this->insertDocument(
            MovementType::Reversal,
            (int) $request['company_id'],
            (int) $stock['site_id'],
            (int) $request['destination_warehouse_id'],
            $targetWarehouse,
            'warehouse_request_receipt_cancel',
            (int) $request['id'],
            $actorUserId,
            $reason,
            reversalOfId:(int) $receipt['id'],
            reasonCode:'warehouse_request_receipt_cancel'
        );

        $receiptReversalLineId=$this->insertDocumentLine(
            $receiptReversalDocumentId,
            1,
            $stockId,
            (int) $stock['lot_id'],
            $restoredOnHand,
            (int) $request['destination_warehouse_id'],
            $targetWarehouse,
            (int) $stock['location_id'],
            $targetLocation,
            (string) $stock['status'],
            'in_transit'
        );

        $receiptReversalMovementId=$this->insertLedgerEntry(
            $receiptReversalDocumentId,
            $receiptReversalLineId,
            $stockId,
            (int) $stock['lot_id'],
            MovementType::Reversal,
            -(float) $receipt['quantity_delta_kg'],
            -(float) $receipt['reserved_delta_kg'],
            (int) $stock['location_id'],
            $targetLocation,
            $restoredOnHand,
            $restoredReserved,
            $actorUserId,
            $now
        );

        $originWarehouse=$this->db->table('warehouses')->where('id',$targetWarehouse)->get()->getRowArray();
        if ($originWarehouse===null) {
            throw new DomainException('El almacén origen ya no existe.', 'WAREHOUSE_NOT_FOUND');
        }

        $this->updateStockProjection(
            $stock,
            $restoredOnHand,
            0.0,
            $targetWarehouse,
            $targetLocation,
            'in_transit',
            $now,
            (int) $originWarehouse['site_id'],
            (int) $originWarehouse['company_id']
        );
        $this->insertLocationHistory(
            $stockId,
            $receiptReversalMovementId,
            (int) $stock['location_id'],
            $targetLocation,
            $actorUserId,
            $now
        );

        $this->audit->record(
            'inventory.warehouse_request_receipt_reversed',
            'inventory_document',
            $receiptReversalDocumentId,
            $actorUserId,
            (int) $request['company_id'],
            (int) $stock['site_id'],
            after:[
                'reversal_of_id'=>(int) $receipt['id'],
                'stock_unit_id'=>$stockId,
                'reversal_movement_id'=>$receiptReversalMovementId,
                'restored_weight_kg'=>$restoredOnHand,
                'restored_warehouse_id'=>$targetWarehouse,
                'restored_location_id'=>$targetLocation,
                'production_reversal_document_id'=>$productionReversalDocumentId,
            ],
            reason:$reason
        );

        return [
            'production_reversal_document_id'=>$productionReversalDocumentId,
            'production_reversal_movement_id'=>$productionReversalMovementId,
            'reversal_document_id'=>$receiptReversalDocumentId,
            'reversal_movement_id'=>$receiptReversalMovementId,
            'stock_unit_id'=>$stockId,
            'restored_weight_kg'=>$restoredOnHand,
            'status'=>'in_transit',
        ];
    }


    public function cancelSaleRequestUnitDispatch(
        int $salesOrderId,
        int $salesOrderLineId,
        string $reason,
        int $actorUserId
    ): array {
        $reason=trim($reason);
        if (mb_strlen($reason)<5 || mb_strlen($reason)>1000) {
            throw new DomainException(
                'Cancelar un despacho requiere un motivo de 5 a 1000 caracteres.',
                'SALE_SHIPMENT_CANCEL_REASON_REQUIRED'
            );
        }

        $order=$this->db->query(
            'SELECT * FROM sales_orders WHERE id=? FOR UPDATE',
            [$salesOrderId]
        )->getRowArray();

        $saleLine=$this->db->query(
            'SELECT * FROM sales_order_lines WHERE id=? AND sales_order_id=? FOR UPDATE',
            [$salesOrderLineId,$salesOrderId]
        )->getRowArray();

        if ($order===null || $saleLine===null || (string) $saleLine['status']!=='dispatched') {
            throw new DomainException(
                'El despacho de venta ya no está disponible para cancelación.',
                'SALE_SHIPMENT_NOT_CANCELABLE'
            );
        }

        $stockId=(int) $saleLine['stock_unit_id'];
        $stock=$this->inventory->findStockUnitForUpdate($stockId);

        // La salida de venta libera físicamente la tina. Para permitir un
        // reverso posterior debemos comprobar que esa misma tina NO haya sido
        // reutilizada desde entonces. Si ya inició otra descarga, el despacho
        // anterior deja de ser reversible desde esta estación.
        $physicalTub=$this->db->query(
            'SELECT * FROM tubs WHERE id=? FOR UPDATE',
            [(int) $stock['tub_id']]
        )->getRowArray();

        if (
            $physicalTub===null
            || ! (bool) $physicalTub['active']
            || (string) $physicalTub['status']!=='available'
        ) {
            throw new DomainException(
                'La tina física ya no está libre; el despacho no puede cancelarse porque pudo haber sido reutilizada.',
                'SALE_SHIPMENT_TUB_ALREADY_REUSED',
                [
                    'stock_unit_id'=>$stockId,
                    'tub_id'=>(int) $stock['tub_id'],
                    'tub_status'=>$physicalTub['status'] ?? null,
                ]
            );
        }

        if (
            (int) $stock['company_id']!==(int) $order['company_id']
            || (int) $stock['site_id']!==(int) $order['site_id']
            || (int) $stock['warehouse_id']!==(int) $order['source_warehouse_id']
            || (string) $stock['status']!=='dispatched'
        ) {
            throw new DomainException(
                'La tina ya tuvo otro movimiento y el despacho no puede cancelarse desde la venta.',
                'SALE_SHIPMENT_STOCK_NOT_CANCELABLE'
            );
        }

        $original=$this->db->query(
            <<<'SQL'
SELECT
    idoc.*,
    idl.id AS document_line_id,
    idl.from_warehouse_id,
    idl.to_warehouse_id,
    idl.from_location_id,
    idl.to_location_id,
    idl.stock_status_before,
    idl.stock_status_after,
    idl.quantity_kg,
    im.id AS movement_id,
    im.quantity_delta_kg,
    im.reserved_delta_kg
FROM inventory_documents idoc
INNER JOIN inventory_document_lines idl
    ON idl.inventory_document_id=idoc.id
INNER JOIN inventory_movements im
    ON im.inventory_document_line_id=idl.id
LEFT JOIN inventory_documents reversal
    ON reversal.reversal_of_id=idoc.id
WHERE idoc.source_document_type='sales_order'
  AND idoc.source_document_id=?
  AND idoc.movement_type=?
  AND idoc.status='confirmed'
  AND idl.stock_unit_id=?
  AND reversal.id IS NULL
ORDER BY idoc.id DESC
LIMIT 1
FOR UPDATE
SQL,
            [$salesOrderId,MovementType::SaleDispatch->value,$stockId]
        )->getRowArray();

        if ($original===null) {
            throw new DomainException(
                'No se encontró el movimiento vigente de salida de venta.',
                'SALE_SHIPMENT_DOCUMENT_NOT_FOUND'
            );
        }

        // Aunque la tina esté hoy libre, no permitimos restaurar producto viejo
        // si la tina fue utilizada en otra descarga entre el despacho original
        // y este intento de cancelación.
        $laterStock=$this->db->query(
            'SELECT id,created_at FROM stock_units WHERE tub_id=? AND id<>? AND created_at>? ORDER BY id ASC LIMIT 1',
            [(int) $stock['tub_id'],$stockId,(string) $original['created_at']]
        )->getRowArray();

        if ($laterStock!==null) {
            throw new DomainException(
                'La tina ya fue reutilizada después de esta venta. El despacho histórico ya no puede cancelarse.',
                'SALE_SHIPMENT_TUB_REUSED_AFTER_DISPATCH',
                [
                    'stock_unit_id'=>$stockId,
                    'tub_id'=>(int) $stock['tub_id'],
                    'later_stock_unit_id'=>(int) $laterStock['id'],
                ]
            );
        }

        $latestMovementId=(int) $this->db->table('inventory_movements')
            ->selectMax('id')
            ->where('stock_unit_id',$stockId)
            ->get()
            ->getRow('id');

        if ($latestMovementId!==(int) $original['movement_id']) {
            throw new DomainException(
                'La tina ya tuvo un movimiento posterior; el despacho no puede cancelarse.',
                'SALE_SHIPMENT_HAS_LATER_MOVEMENT'
            );
        }

        $restoredOnHand=round(
            (float) $stock['on_hand_weight_kg']-(float) $original['quantity_delta_kg'],
            3
        );
        $restoredReserved=round(
            (float) $stock['reserved_weight_kg']-(float) $original['reserved_delta_kg'],
            3
        );

        if (
            $restoredOnHand<=0
            || $restoredReserved<0
            || $restoredReserved>$restoredOnHand+0.001
        ) {
            throw new DomainException(
                'El despacho no puede restaurarse de forma segura.',
                'SALE_SHIPMENT_REVERSAL_BALANCE_INVALID'
            );
        }

        $targetWarehouse=(int) ($original['from_warehouse_id']??0);
        $targetLocation=(int) ($original['from_location_id']??0);

        if (
            $targetWarehouse!==(int) $order['source_warehouse_id']
            || $targetLocation<=0
        ) {
            throw new DomainException(
                'El origen histórico de la venta no es válido.',
                'SALE_SHIPMENT_REVERSAL_ORIGIN_INVALID'
            );
        }

        $now=date('Y-m-d H:i:s');
        $restoredStatus=$restoredReserved>0.0001?'reserved':'available';

        $reversalDocumentId=$this->insertDocument(
            MovementType::Reversal,
            (int) $order['company_id'],
            (int) $order['site_id'],
            null,
            $targetWarehouse,
            'sales_order_dispatch_cancel',
            $salesOrderId,
            $actorUserId,
            $reason,
            reversalOfId:(int) $original['id'],
            reasonCode:'sale_dispatch_cancel'
        );

        $reversalLineId=$this->insertDocumentLine(
            $reversalDocumentId,
            1,
            $stockId,
            (int) $stock['lot_id'],
            $restoredOnHand,
            null,
            $targetWarehouse,
            null,
            $targetLocation,
            (string) $stock['status'],
            $restoredStatus
        );

        $reversalMovementId=$this->insertLedgerEntry(
            $reversalDocumentId,
            $reversalLineId,
            $stockId,
            (int) $stock['lot_id'],
            MovementType::Reversal,
            -(float) $original['quantity_delta_kg'],
            -(float) $original['reserved_delta_kg'],
            null,
            $targetLocation,
            $restoredOnHand,
            $restoredReserved,
            $actorUserId,
            $now
        );

        $this->updateStockProjection(
            $stock,
            $restoredOnHand,
            $restoredReserved,
            $targetWarehouse,
            $targetLocation,
            $restoredStatus,
            $now
        );

        $releaseDocumentId=null;
        $releaseMovementId=null;

        if ($restoredReserved>0.0001) {
            $restoredStock=$this->inventory->findStockUnitForUpdate($stockId);

            $releaseDocumentId=$this->insertDocument(
                MovementType::Release,
                (int) $order['company_id'],
                (int) $order['site_id'],
                $targetWarehouse,
                $targetWarehouse,
                'sales_order_dispatch_cancel_release',
                $salesOrderId,
                $actorUserId,
                $reason,
                reasonCode:'sale_dispatch_cancel_release'
            );

            $releaseLineId=$this->insertDocumentLine(
                $releaseDocumentId,
                1,
                $stockId,
                (int) $restoredStock['lot_id'],
                $restoredReserved,
                $targetWarehouse,
                $targetWarehouse,
                $targetLocation,
                $targetLocation,
                (string) $restoredStock['status'],
                'available'
            );

            $releaseMovementId=$this->insertLedgerEntry(
                $releaseDocumentId,
                $releaseLineId,
                $stockId,
                (int) $restoredStock['lot_id'],
                MovementType::Release,
                0.0,
                -$restoredReserved,
                $targetLocation,
                $targetLocation,
                $restoredOnHand,
                0.0,
                $actorUserId,
                $now
            );

            $this->updateStockProjection(
                $restoredStock,
                $restoredOnHand,
                0.0,
                $targetWarehouse,
                $targetLocation,
                'available',
                $now
            );
        }

        // El reverso devuelve el producto al inventario; por lo tanto la tina
        // vuelve a estar ocupada/recibida y deja de estar disponible para
        // una nueva descarga.
        $currentPhysicalTub=$this->db->query(
            'SELECT * FROM tubs WHERE id=? FOR UPDATE',
            [(int) $physicalTub['id']]
        )->getRowArray();

        if ($currentPhysicalTub===null || (string) $currentPhysicalTub['status']!=='available') {
            throw new DomainException(
                'La tina física cambió durante la cancelación; no se restauró el despacho.',
                'SALE_SHIPMENT_TUB_CONCURRENT_MODIFICATION'
            );
        }

        $this->db->table('tubs')
            ->where([
                'id'=>(int) $currentPhysicalTub['id'],
                'lock_version'=>(int) $currentPhysicalTub['lock_version'],
            ])
            ->update([
                'status'=>'received',
                'lock_version'=>(int) $currentPhysicalTub['lock_version'] + 1,
                'updated_at'=>$now,
            ]);

        if ($this->db->affectedRows() !== 1) {
            throw new DomainException(
                'La tina física cambió durante la cancelación; no se restauró el despacho.',
                'SALE_SHIPMENT_TUB_CONCURRENT_MODIFICATION'
            );
        }

        $this->audit->record(
            'inventory.sale_dispatch_reversed',
            'inventory_document',
            $reversalDocumentId,
            $actorUserId,
            (int) $order['company_id'],
            (int) $order['site_id'],
            null,
            [
                'reversal_of_id'=>(int) $original['id'],
                'sales_order_id'=>$salesOrderId,
                'sales_order_line_id'=>$salesOrderLineId,
                'stock_unit_id'=>$stockId,
                'reversal_movement_id'=>$reversalMovementId,
                'release_document_id'=>$releaseDocumentId,
                'release_movement_id'=>$releaseMovementId,
                'restored_weight_kg'=>$restoredOnHand,
                'physical_tub_id'=>(int) $physicalTub['id'],
                'physical_tub_status_before'=>'available',
                'physical_tub_status_after'=>'received',
            ],
            $reason
        );

        return [
            'reversal_document_id'=>$reversalDocumentId,
            'reversal_movement_id'=>$reversalMovementId,
            'release_document_id'=>$releaseDocumentId,
            'release_movement_id'=>$releaseMovementId,
            'stock_unit_id'=>$stockId,
            'restored_weight_kg'=>$restoredOnHand,
            'status'=>'available',
            'physical_tub_id'=>(int) $physicalTub['id'],
            'physical_tub_status'=>'received',
        ];
    }


    /**
     * Salida terminal a Producción de una tina ya recibida en destino.
     *
     * Esta operación es deliberadamente independiente de la transacción de
     * recepción. Si falla, la recepción válida permanece confirmada y puede
     * reintentarse. Es idempotente por warehouse_request_unit.
     */
    public function issueWarehouseRequestProductionUnit(
        int $requestId,
        int $unitId,
        string $productionArea,
        int $actorUserId
    ): array {
        $productionArea=trim($productionArea);
        if ($requestId<=0 || $unitId<=0 || $productionArea==='' || mb_strlen($productionArea)>120) {
            throw new DomainException(
                'No fue posible identificar la solicitud, la tina o el área de producción.',
                'PRODUCTION_ISSUE_DATA_INVALID'
            );
        }

        $this->db->transException(true)->transBegin();
        try {
            $row=$this->db->query(
                <<<'SQL'
SELECT
    wr.id AS request_id,
    wr.folio,
    wr.company_id,
    wr.destination_warehouse_id,
    wr.request_type,
    wru.id AS unit_id,
    wru.stock_unit_id,
    wru.status AS unit_status
FROM warehouse_requests wr
INNER JOIN warehouse_request_units wru
    ON wru.request_id=wr.id
WHERE wr.id=? AND wru.id=?
FOR UPDATE
SQL,
                [$requestId,$unitId]
            )->getRowArray();

            if ($row===null) {
                throw new DomainException(
                    'La tina no pertenece a la solicitud indicada.',
                    'PRODUCTION_REQUEST_UNIT_NOT_FOUND'
                );
            }
            if ((string) $row['request_type']!=='production') {
                throw new DomainException(
                    'La solicitud no es de Materia prima para Producción.',
                    'PRODUCTION_ISSUE_REQUEST_TYPE_INVALID'
                );
            }
            if ((string) $row['unit_status']!=='accepted') {
                throw new DomainException(
                    'La tina todavía no tiene una recepción aceptada.',
                    'PRODUCTION_ISSUE_RECEIPT_REQUIRED'
                );
            }

            // Idempotencia: una misma warehouse_request_unit nunca debe producir
            // dos ProductionIssue confirmados.
            $existing=$this->db->table('inventory_documents')
                ->select('id')
                ->where([
                    'movement_type'=>MovementType::ProductionIssue->value,
                    'status'=>'confirmed',
                    'source_document_type'=>'warehouse_request_production',
                    'source_document_id'=>$unitId,
                ])
                ->orderBy('id','DESC')
                ->get()
                ->getRowArray();

            if ($existing!==null) {
                $this->db->transCommit();
                return [
                    'already_completed'=>true,
                    'inventory_document_id'=>(int) $existing['id'],
                    'request_id'=>$requestId,
                    'unit_id'=>$unitId,
                    'stock_unit_id'=>(int) $row['stock_unit_id'],
                    'production_area'=>$productionArea,
                    'status'=>'completed',
                ];
            }

            $stock=$this->inventory->findStockUnitForUpdate((int) $row['stock_unit_id']);
            if (
                (string) $stock['status']!=='available'
                || (int) $stock['warehouse_id']!==(int) $row['destination_warehouse_id']
                || (int) $stock['company_id']!==(int) $row['company_id']
                || (float) $stock['on_hand_weight_kg']<=0
                || (float) $stock['reserved_weight_kg']>0.0001
            ) {
                throw new DomainException(
                    'La tina recibida cambió de estado, almacén, compañía o saldo; no puede enviarse automáticamente a Producción.',
                    'PRODUCTION_ISSUE_STOCK_NOT_AVAILABLE',
                    ['stock_unit_id'=>(int) $stock['id']]
                );
            }

            $physicalTub=$this->db->query(
                'SELECT * FROM tubs WHERE id=? FOR UPDATE',
                [(int) $stock['tub_id']]
            )->getRowArray();

            if (
                $physicalTub===null
                || ! (bool) $physicalTub['active']
                || (string) $physicalTub['status']!=='received'
            ) {
                throw new DomainException(
                    'La tina física no está en estado recibido y no puede liberarse hacia Producción.',
                    'PRODUCTION_PHYSICAL_TUB_NOT_RELEASABLE',
                    [
                        'stock_unit_id'=>(int) $stock['id'],
                        'tub_id'=>(int) $stock['tub_id'],
                        'tub_status'=>$physicalTub['status']??null,
                    ]
                );
            }

            $quantity=round((float) $stock['on_hand_weight_kg'],3);
            $now=date('Y-m-d H:i:s');

            $documentId=$this->insertDocument(
                MovementType::ProductionIssue,
                (int) $stock['company_id'],
                (int) $stock['site_id'],
                (int) $stock['warehouse_id'],
                null,
                'warehouse_request_production',
                $unitId,
                $actorUserId,
                'Salida a Producción · '.$row['folio'].' · Área: '.$productionArea,
                'warehouse_request',
                $requestId,
                'production_issue'
            );

            $lineId=$this->insertDocumentLine(
                $documentId,
                1,
                (int) $stock['id'],
                (int) $stock['lot_id'],
                $quantity,
                (int) $stock['warehouse_id'],
                null,
                (int) $stock['location_id'],
                null,
                (string) $stock['status'],
                'exhausted'
            );

            $movementId=$this->insertLedgerEntry(
                $documentId,
                $lineId,
                (int) $stock['id'],
                (int) $stock['lot_id'],
                MovementType::ProductionIssue,
                -$quantity,
                0.0,
                (int) $stock['location_id'],
                null,
                0.0,
                0.0,
                $actorUserId,
                $now
            );

            $this->updateStockProjection(
                $stock,
                0.0,
                0.0,
                (int) $stock['warehouse_id'],
                (int) $stock['location_id'],
                'exhausted',
                $now
            );

            // Producción es salida terminal: el producto deja inventario y la
            // tina física vuelve al pool disponible.
            $this->db->table('tubs')
                ->where([
                    'id'=>(int) $physicalTub['id'],
                    'lock_version'=>(int) $physicalTub['lock_version'],
                ])
                ->update([
                    'status'=>'available',
                    'lock_version'=>(int) $physicalTub['lock_version']+1,
                    'updated_at'=>$now,
                ]);

            if ($this->db->affectedRows()!==1) {
                throw new DomainException(
                    'La tina física cambió durante la salida a Producción; no se confirmó el movimiento.',
                    'PRODUCTION_PHYSICAL_TUB_CONCURRENT_MODIFICATION'
                );
            }

            $this->audit->record(
                'inventory.production_issue_from_warehouse_request',
                'inventory_document',
                $documentId,
                $actorUserId,
                (int) $stock['company_id'],
                (int) $stock['site_id'],
                after: [
                    'warehouse_id'=>(int) $stock['warehouse_id'],
                    'warehouse_request_id'=>$requestId,
                    'warehouse_request_unit_id'=>$unitId,
                    'stock_unit_id'=>(int) $stock['id'],
                    'production_area'=>$productionArea,
                    'quantity_kg'=>$quantity,
                    'inventory_movement_id'=>$movementId,
                    'physical_tub_id'=>(int) $physicalTub['id'],
                    'physical_tub_status_after'=>'available',
                ]
            );

            $this->db->transCommit();

            return [
                'already_completed'=>false,
                'inventory_document_id'=>$documentId,
                'inventory_movement_id'=>$movementId,
                'request_id'=>$requestId,
                'unit_id'=>$unitId,
                'stock_unit_id'=>(int) $stock['id'],
                'quantity_kg'=>$quantity,
                'production_area'=>$productionArea,
                'physical_tub_id'=>(int) $physicalTub['id'],
                'physical_tub_status'=>'available',
                'status'=>'completed',
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }


    public function warehouseRequestMovement(array $request, int $stockId, string $action, float $weight, ?int $rowId, int $actor): array
    {
        $allowed = ['reserve','release','ship','accept','return_start','return_entry'];
        if (! in_array($action,$allowed,true) || ! is_finite($weight) || $weight<=0) {
            throw new DomainException('Movimiento de solicitud inválido.', 'WAREHOUSE_MOVEMENT_INVALID');
        }
        $stock = $this->inventory->findStockUnitForUpdate($stockId);
        $expected = match ($action) { 'reserve'=>'available', 'release','ship'=>'reserved', default=>'in_transit' };
        $releasingHeld = $action==='release' && in_array($stock['status'],['held','rejected'],true);
        if ((!$releasingHeld && $stock['status']!==$expected) || (int) $stock['company_id']!==(int) $request['provider_company_id']
            || (int) $stock['warehouse_id']!==(int) $request['source_warehouse_id']) {
            throw new DomainException('La tina cambió de estado o almacén.', 'CONCURRENT_STOCK_MODIFICATION');
        }
        if (($action==='reserve' && (float) $stock['reserved_weight_kg']!==0.0)
            || (in_array($action,['release','ship'],true) && abs((float) $stock['reserved_weight_kg']-(float) $stock['on_hand_weight_kg'])>0.0001)) {
            throw new DomainException('La reserva de la tina no concilia.', 'WAREHOUSE_RESERVATION_MISMATCH');
        }
        $newStatus = match($action) { 'reserve'=>'reserved', 'ship','return_start'=>'in_transit', default=>'available' };
        if ($releasingHeld) { $newStatus=(string) $stock['status']; }
        $type = match($action) {
            'reserve'=>MovementType::Reservation, 'release'=>MovementType::Release,
            'ship'=>MovementType::TransferShipment, 'accept'=>MovementType::TransferReceipt,
            default=>MovementType::Return,
        };
        $newWeight = in_array($action,['reserve','release'],true) ? (float) $stock['on_hand_weight_kg'] : round($weight,3);
        $newReserved = $action==='reserve' ? $newWeight : 0.0;
        $newWarehouse = $action==='accept' ? (int) $request['destination_warehouse_id'] : (int) $request['source_warehouse_id'];
        $warehouse = $this->db->table('warehouses')->where(['id'=>$newWarehouse,'company_id'=>$action==='accept'?$request['company_id']:$request['provider_company_id'],'active'=>1])->get()->getRowArray();
        if ($warehouse===null) { throw new DomainException('El almacén ya no está disponible.', 'WAREHOUSE_NOT_FOUND'); }
        $targetLocation = (int) $stock['location_id'];
        if (in_array($action,['accept','return_entry'],true)) {
            $row = $this->db->query('SELECT * FROM locations WHERE id=? FOR UPDATE',[$rowId ?? 0])->getRowArray();
            if ($row===null || ! (bool) $row['active'] || (int) $row['warehouse_id']!==$newWarehouse
                || ! in_array($row['location_type'],['row','aisle'],true)) {
                throw new DomainException('Selecciona una fila activa del almacén que recibe.', 'INVALID_RECEIPT_ROW');
            }
            $targetLocation = $this->resolveRelocationTargetForUpdate($stock,(int) $row['id'],$newWarehouse,$newWeight,'available');
            $this->assertStockPlacement($stock,$targetLocation,$newWarehouse,$newWeight,'available');
        }
        $now = date('Y-m-d H:i:s');
        $origin = in_array($action,['return_start','return_entry'],true) ? (int) $request['destination_warehouse_id'] : (int) $stock['warehouse_id'];
        $destination = $action==='ship' ? (int) $request['destination_warehouse_id'] : $newWarehouse;
        $fromLocation = in_array($action,['return_start','return_entry'],true) ? null : (int) $stock['location_id'];
        $documentSite = $action==='return_start'
            ? (int) $this->db->table('warehouses')->where('id',$request['destination_warehouse_id'])->get()->getRow('site_id')
            : (int) $warehouse['site_id'];
        $documentCompany=$action==='return_start'?(int) $request['company_id']:(int) $warehouse['company_id'];
        $doc = $this->insertDocument($type,$documentCompany,$documentSite,$origin,$destination,
            'warehouse_request_'.$action,(int) $request['id'],$actor,'Solicitud '.$request['folio'].' · '.$action);
        $docLine = $this->insertDocumentLine($doc,1,$stockId,(int) $stock['lot_id'],$newWeight,$origin,$destination,
            $fromLocation,in_array($action,['ship','return_start'],true)?null:$targetLocation,(string) $stock['status'],$newStatus);
        $movement = $this->insertLedgerEntry($doc,$docLine,$stockId,(int) $stock['lot_id'],$type,
            round($newWeight-(float) $stock['on_hand_weight_kg'],3),round($newReserved-(float) $stock['reserved_weight_kg'],3),
            $fromLocation,in_array($action,['ship','return_start'],true)?null:$targetLocation,
            $newWeight,$newReserved,$actor,$now);
        $this->updateStockProjection($stock,$newWeight,$newReserved,$newWarehouse,$targetLocation,$newStatus,$now,(int) $warehouse['site_id'],(int) $warehouse['company_id']);
        if (in_array($action,['accept','return_entry'],true)) {
            $this->insertLocationHistory($stockId,$movement,$fromLocation,$targetLocation,$actor,$now);
        }
        return ['inventory_document_id'=>$doc,'inventory_movement_id'=>$movement,'stock_unit_id'=>$stockId,'status'=>$newStatus];
    }

    private function snapshotInitialOwner(int $stockId): void
    {
        $row = $this->db->query('SELECT v.owner_company_id FROM stock_units su JOIN lots l ON l.id=su.lot_id JOIN voyages vg ON vg.id=l.voyage_id JOIN vessels v ON v.id=vg.vessel_id WHERE su.id=?',[$stockId])->getRowArray();
        if ($row===null || empty($row['owner_company_id'])) { return; }
        $this->db->table('stock_units')->where('id',$stockId)->update([
            'original_owner_company_id'=>(int) $row['owner_company_id'],'owner_company_id'=>(int) $row['owner_company_id']]);
        $this->db->table('stock_unit_owner_history')->insert(['stock_unit_id'=>$stockId,
            'owner_company_id'=>(int) $row['owner_company_id'],'source_document_type'=>'initial_reception','occurred_at'=>date('Y-m-d H:i:s')]);
    }

    private function assertWarehouseRequestCommitment(array $stock, bool $allowPreselected = false): void
    {
        $unit=$this->db->table('warehouse_request_units')->select('status')->where('active_stock_id',(int) $stock['id'])->get()->getRowArray();
        if ($unit!==null && !($allowPreselected && $unit['status']==='selected')) {
            throw new DomainException('La tina esta comprometida con una solicitud entre almacenes. Libera la preseleccion o completa su recepcion/devolucion antes de modificarla.', 'WAREHOUSE_REQUEST_COMMITMENT_ACTIVE');
        }
    }
    private function insertDocument(
        MovementType $type,
        int $companyId,
        int $siteId,
        ?int $originWarehouseId,
        ?int $destinationWarehouseId,
        ?string $sourceType,
        ?int $sourceId,
        int $actorUserId,
        ?string $observations,
        ?string $destinationType = null,
        ?int $destinationId = null,
        ?string $reasonCode = null,
        ?int $reversalOfId = null,
    ): int {
        $now = date('Y-m-d H:i:s');
        $this->db->table('inventory_documents')->insert([
            'uuid' => Uuid::uuid7()->toString(),
            'folio' => $this->folios->next('INV'),
            'movement_type' => $type->value,
            'status' => 'confirmed',
            'company_id' => $companyId,
            'site_id' => $siteId,
            'origin_warehouse_id' => $originWarehouseId,
            'destination_warehouse_id' => $destinationWarehouseId,
            'source_document_type' => $sourceType,
            'source_document_id' => $sourceId,
            'destination_document_type' => $destinationType,
            'destination_document_id' => $destinationId,
            'reversal_of_id' => $reversalOfId,
            'reason_code' => $reasonCode,
            'observations' => $observations,
            'confirmed_by' => $actorUserId,
            'confirmed_at' => $now,
            'created_by' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->insertID();
    }

    private function insertDocumentLine(
        int $documentId,
        int $lineNumber,
        int $stockUnitId,
        int $lotId,
        float $quantity,
        ?int $fromWarehouseId,
        ?int $toWarehouseId,
        ?int $fromLocationId,
        ?int $toLocationId,
        ?string $statusBefore,
        string $statusAfter,
    ): int {
        $this->db->table('inventory_document_lines')->insert([
            'inventory_document_id' => $documentId,
            'line_number' => $lineNumber,
            'stock_unit_id' => $stockUnitId,
            'lot_id' => $lotId,
            'from_warehouse_id' => $fromWarehouseId,
            'to_warehouse_id' => $toWarehouseId,
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'quantity_kg' => round($quantity, 3),
            'stock_status_before' => $statusBefore,
            'stock_status_after' => $statusAfter,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    private function insertLedgerEntry(
        int $documentId,
        int $lineId,
        int $stockUnitId,
        int $lotId,
        MovementType $type,
        float $quantityDelta,
        float $reservedDelta,
        ?int $fromLocationId,
        ?int $toLocationId,
        float $resultingOnHand,
        float $resultingReserved,
        int $actorUserId,
        string $occurredAt,
    ): int {
        $this->db->table('inventory_movements')->insert([
            'uuid' => Uuid::uuid7()->toString(),
            'inventory_document_id' => $documentId,
            'inventory_document_line_id' => $lineId,
            'stock_unit_id' => $stockUnitId,
            'lot_id' => $lotId,
            'movement_type' => $type->value,
            'quantity_delta_kg' => round($quantityDelta, 3),
            'reserved_delta_kg' => round($reservedDelta, 3),
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'resulting_on_hand_kg' => round($resultingOnHand, 3),
            'resulting_reserved_kg' => round($resultingReserved, 3),
            'occurred_at' => $occurredAt,
            'actor_user_id' => $actorUserId,
            'created_at' => $occurredAt,
        ]);

        return (int) $this->db->insertID();
    }

    private function updateStockProjection(array $stock, float $onHand, float $reserved, int $warehouseId, int $locationId, string $status, string $now, ?int $siteId = null, ?int $companyId = null): void
    {
        $projection = [
            'on_hand_weight_kg' => round($onHand, 3),
            'reserved_weight_kg' => round($reserved, 3),
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'status' => $status,
            'lock_version' => (int) $stock['lock_version'] + 1,
            'updated_at' => $now,
        ];
        if ($siteId !== null) {
            $projection['site_id'] = $siteId;
        }
        // Sólo el flujo entre empresas proporciona companyId; dueño y antigüedad se conservan.
        if ($companyId !== null) { $projection['company_id'] = $companyId; }
        $this->db->table('stock_units')->where(['id' => $stock['id'], 'lock_version' => $stock['lock_version']])->update($projection);

        if ($this->db->affectedRows() !== 1) {
            throw new DomainException('La unidad cambió durante la operación.', 'CONCURRENT_STOCK_MODIFICATION', ['stock_unit_id' => $stock['id']]);
        }
    }

    private function lockWeighingForReception(int $weighingId): array
    {
        $row = $this->db->query(<<<'SQL'
SELECT
    w.*,
    w.lock_version AS weighing_lock_version,
    tf.species_id,
    tf.size_grade_id,
    dp.voyage_id,
    v.company_id,
    v.site_id,
    t.lock_version AS tub_lock_version,
    t.status AS tub_status,
    l.id AS label_id,
    l.code AS label_code,
    qi.quality_grade,
    qi.disposition
FROM weighings w
INNER JOIN tub_fillings tf ON tf.id = w.tub_filling_id
INNER JOIN discharge_plans dp ON dp.id = tf.discharge_plan_id
INNER JOIN voyages v ON v.id = dp.voyage_id
INNER JOIN tubs t ON t.id = w.tub_id
INNER JOIN labels l ON l.weighing_id = w.id AND l.status <> 'canceled'
LEFT JOIN quality_inspections qi ON qi.weighing_id = w.id AND qi.status = 'approved'
WHERE w.id = ?
FOR UPDATE
SQL, [$weighingId])->getRowArray();

        if ($row === null) {
            throw new DomainException('Pesaje o etiqueta vigente no encontrados.', 'WEIGHING_NOT_RECEIVABLE');
        }

        if (! in_array($row['status'], ['confirmed', 'pending_inspection'], true)) {
            throw new DomainException('El pesaje no está disponible para recepción.', 'WEIGHING_NOT_RECEIVABLE', ['status' => $row['status']]);
        }

        if ($this->db->table('stock_units')->where('weighing_id', $weighingId)->countAllResults() > 0) {
            throw new DomainException('El pesaje ya fue recibido.', 'DUPLICATE_RECEPTION');
        }

        // La recepción es el segundo candado de la tina física. El JOIN con
        // tubs forma parte del SELECT ... FOR UPDATE anterior, por lo que el
        // estado se valida dentro de la misma transacción antes de crear stock.
        if ((string) $row['tub_status'] !== 'weighed') {
            throw new DomainException(
                'La tina no está lista para recepción.',
                'TUB_NOT_READY_FOR_RECEPTION',
                [
                    'tub_id' => (int) $row['tub_id'],
                    'tub_status' => (string) $row['tub_status'],
                ]
            );
        }

        $activeStock = $this->db->query(
            <<<'SQL'
SELECT id, unit_code, status, on_hand_weight_kg
FROM stock_units
WHERE tub_id = ?
  AND on_hand_weight_kg > 0
ORDER BY id ASC
LIMIT 1
FOR UPDATE
SQL,
            [(int) $row['tub_id']]
        )->getRowArray();

        if ($activeStock !== null) {
            throw new DomainException(
                'La tina ya tiene producto activo en inventario y no puede recibirse nuevamente.',
                'TUB_HAS_ACTIVE_STOCK',
                [
                    'tub_id' => (int) $row['tub_id'],
                    'stock_unit_id' => (int) $activeStock['id'],
                    'unit_code' => (string) $activeStock['unit_code'],
                ]
            );
        }

        return $row;
    }

    private function assertReceptionContext(array $context, int $companyId, int $siteId): void
    {
        if ((int) $context['company_id'] !== $companyId || (int) $context['site_id'] !== $siteId) {
            throw new DomainException('El pesaje no pertenece al alcance de recepción.', 'RECEPTION_SCOPE_MISMATCH');
        }
    }

    private function initialStockStatus(array $context): string
    {
        // La recepción normal entra disponible. Calidad sólo bloquea inventario
        // cuando existe una disposición explícita de retención o rechazo.
        return match ((string) ($context['disposition'] ?? 'pending')) {
            'rejected' => 'rejected',
            'hold' => 'held',
            default => 'available',
        };
    }

    private function lockAndValidateLocation(int $locationId, int $warehouseId): array
    {
        $location = $this->db->query('SELECT * FROM locations WHERE id = ? FOR UPDATE', [$locationId])->getRowArray();

        if ($location === null || (int) $location['warehouse_id'] !== $warehouseId || ! (bool) $location['active']) {
            throw new DomainException('Ubicación destino inválida.', 'INVALID_DESTINATION_LOCATION');
        }

        return $location;
    }

    /**
     * La UI puede enviar una Fila/Pasillo como destino. En ese caso se resuelve
     * aquí una posición interna libre, dentro de la misma transacción.
     */
    private function resolveRelocationTargetForUpdate(
        array $stock,
        int $destinationId,
        int $warehouseId,
        float $onHand,
        string $status
    ): int {
        $destination = $this->db->query(
            'SELECT * FROM locations WHERE id = ? FOR UPDATE',
            [$destinationId]
        )->getRowArray();

        if ($destination === null || ! (bool) $destination['active'] || (int) $destination['warehouse_id'] !== $warehouseId) {
            throw new DomainException('La ubicación destino no está disponible.', 'INVALID_RELOCATION_DESTINATION');
        }

        if (! in_array((string) $destination['location_type'], ['row', 'aisle'], true)) {
            return $destinationId;
        }

        $candidates = $this->db->query(
            <<<'SQL'
SELECT s.id
FROM locations s
INNER JOIN locations p ON p.id = s.parent_id
INNER JOIN locations sector ON sector.id = p.parent_id
WHERE sector.id = ?
  AND sector.warehouse_id = ?
  AND sector.active = 1
  AND p.active = 1
  AND s.active = 1
  AND s.location_type IN ('stack_level', 'stack')
ORDER BY p.code DESC, s.code ASC, s.id ASC
FOR UPDATE
SQL,
            [$destinationId, $warehouseId]
        )->getResultArray();

        foreach ($candidates as $candidate) {
            $candidateId = (int) $candidate['id'];
            if ($candidateId === (int) $stock['location_id']) {
                continue;
            }

            try {
                $this->assertStockPlacement($stock, $candidateId, $warehouseId, $onHand, $status);
                return $candidateId;
            } catch (DomainException) {
                continue;
            }
        }

        throw new DomainException(
            'La fila seleccionada no tiene una posición interna disponible para esta tina.',
            'RELOCATION_SECTOR_WITHOUT_CAPACITY'
        );
    }

    private function assertStockPlacement(array $stock, int $locationId, int $warehouseId, float $newOnHand, string $newStatus): void
    {
        $location = $this->lockAndValidateLocation($locationId, $warehouseId);
        $currentCounts = (int) $stock['location_id'] === $locationId
            && ! in_array((string) $stock['status'], self::NON_CAPACITY_STATES, true)
            && (float) $stock['on_hand_weight_kg'] > 0;
        $newCounts = ! in_array($newStatus, self::NON_CAPACITY_STATES, true) && $newOnHand > 0;
        $projectedUnits = $this->locationUnitCount($locationId) - ($currentCounts ? 1 : 0) + ($newCounts ? 1 : 0);
        $projectedWeight = $this->locationWeight($locationId)
            - ($currentCounts ? (float) $stock['on_hand_weight_kg'] : 0)
            + ($newCounts ? $newOnHand : 0);
        $this->assertCapacity($location, $projectedUnits, $projectedWeight);

        if ($newCounts) {
            $this->assertLocationSegregation($locationId, (int) $stock['species_id'], (int) $stock['lot_id']);
        }
    }

    private function assertLocationSegregation(int $locationId, int $speciesId, int $lotId): void
    {
        $location = $this->db->table('locations')->select('segregation_rule')->where('id', $locationId)->get()->getRowArray();
        $rule = (string) ($location['segregation_rule'] ?? '');

        if ($rule === 'same_species') {
            $different = $this->db->table('stock_units')->where('location_id', $locationId)->where('on_hand_weight_kg >', 0)->whereNotIn('status', self::NON_CAPACITY_STATES)->where('species_id !=', $speciesId)->countAllResults();
            if ($different > 0) {
                throw new DomainException('La ubicación exige una sola especie.', 'LOCATION_SEGREGATION_VIOLATION');
            }
        }

        if ($rule === 'same_lot') {
            $different = $this->db->table('stock_units')->where('location_id', $locationId)->where('on_hand_weight_kg >', 0)->whereNotIn('status', self::NON_CAPACITY_STATES)->where('lot_id !=', $lotId)->countAllResults();
            if ($different > 0) {
                throw new DomainException('La ubicación exige un solo lote.', 'LOCATION_SEGREGATION_VIOLATION');
            }
        }
    }

    private function assertCapacity(array $location, int $projectedUnits, float $projectedWeight): void
    {
        if ($location['max_units'] !== null && $projectedUnits > (int) $location['max_units']) {
            throw new DomainException('La ubicación excedería su capacidad de unidades.', 'LOCATION_UNIT_CAPACITY_EXCEEDED');
        }

        if ($location['max_weight_kg'] !== null && $projectedWeight > (float) $location['max_weight_kg']) {
            throw new DomainException('La ubicación excedería su capacidad de peso.', 'LOCATION_WEIGHT_CAPACITY_EXCEEDED');
        }
    }

    private function locationUnitCount(int $locationId): int
    {
        return $this->db->table('stock_units')->where('location_id', $locationId)->where('on_hand_weight_kg >', 0)->whereNotIn('status', self::NON_CAPACITY_STATES)->countAllResults();
    }

    private function locationWeight(int $locationId): float
    {
        return (float) ($this->db->table('stock_units')->selectSum('on_hand_weight_kg', 'weight')->where('location_id', $locationId)->whereNotIn('status', self::NON_CAPACITY_STATES)->get()->getRow('weight') ?? 0);
    }

    private function insertLocationHistory(int $stockUnitId, int $movementId, ?int $fromLocationId, int $toLocationId, int $actorUserId, string $now): void
    {
        $this->db->table('stock_unit_location_history')->insert([
            'stock_unit_id' => $stockUnitId,
            'inventory_movement_id' => $movementId,
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'moved_by' => $actorUserId,
            'moved_at' => $now,
        ]);
    }

    private function assertStockMatchesRequest(array $stock, array $requestLine): void
    {
        if (! in_array($stock['status'], ['available', 'reserved'], true)
            || (int) $stock['species_id'] !== (int) $requestLine['species_id']
            || (int) $stock['size_grade_id'] !== (int) $requestLine['size_grade_id']
            || ($requestLine['lot_id'] !== null && (int) $stock['lot_id'] !== (int) $requestLine['lot_id'])
            || ($requestLine['quality_grade'] !== null && (string) $stock['quality_grade'] !== (string) $requestLine['quality_grade'])) {
            throw new DomainException('La unidad no cumple los criterios de la solicitud.', 'STOCK_CRITERIA_MISMATCH', ['stock_unit_id' => $stock['id']]);
        }
        if ($requestLine['owner_company_id'] !== null) {
            $ownerId = (int) ($this->db->table('lots l')->select('v.owner_company_id')
                ->join('voyages vg', 'vg.id = l.voyage_id')->join('vessels v', 'v.id = vg.vessel_id')
                ->where('l.id', (int) $stock['lot_id'])->get()->getRow('owner_company_id') ?? 0);
            if ($ownerId !== (int) $requestLine['owner_company_id']) {
                throw new DomainException('La unidad no pertenece al propietario solicitado.', 'STOCK_OWNER_MISMATCH', ['stock_unit_id' => $stock['id']]);
            }
        }
    }
}
