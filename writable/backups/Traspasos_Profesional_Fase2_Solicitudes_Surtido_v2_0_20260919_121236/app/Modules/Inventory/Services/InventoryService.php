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
                }

                if ($quantity <= 0 || $quantity > $available) {
                    throw new DomainException('Cantidad de reserva no disponible.', 'INSUFFICIENT_AVAILABLE_STOCK', ['stock_unit_id' => $stockUnitId, 'available_kg' => $available]);
                }
                if ($quantity > $requestRemaining) {
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
            foreach ($lines as $saleLine) {
                $stock = $this->inventory->findStockUnitForUpdate((int) $saleLine['stock_unit_id']);
                $quantity = (float) $saleLine['quantity_kg'];
                $available = round((float) $stock['on_hand_weight_kg'] - (float) $stock['reserved_weight_kg'], 3);
                if ((int) $stock['company_id'] !== (int) $order['company_id'] || (int) $stock['site_id'] !== (int) $order['site_id'] || $stock['status'] !== 'available' || $quantity <= 0 || $quantity > $available) {
                    throw new DomainException('Una unidad ya no está disponible para despacho.', 'SALE_STOCK_NOT_AVAILABLE', ['stock_unit_id' => $stock['id']]);
                }
                $lockedStocks[(int) $stock['id']] = $stock;
                $originWarehouses[(int) $stock['warehouse_id']] = true;
            }
            $originWarehouseId = count($originWarehouses) === 1 ? (int) array_key_first($originWarehouses) : null;
            $documentId = $this->insertDocument(MovementType::SaleDispatch, (int) $order['company_id'], (int) $order['site_id'], $originWarehouseId, null, 'sales_order', $salesOrderId, $actorUserId, $order['observations']);
            $now = date('Y-m-d H:i:s');
            $results = [];
            foreach ($lines as $index => $saleLine) {
                $stock = $lockedStocks[(int) $saleLine['stock_unit_id']];
                $quantity = (float) $saleLine['quantity_kg'];
                $newOnHand = round((float) $stock['on_hand_weight_kg'] - $quantity, 3);
                $newStatus = $newOnHand <= 0 ? 'dispatched' : 'available';
                $lineId = $this->insertDocumentLine($documentId, $index + 1, (int) $stock['id'], (int) $stock['lot_id'], $quantity, (int) $stock['warehouse_id'], null, (int) $stock['location_id'], null, (string) $stock['status'], $newStatus);
                $this->insertLedgerEntry($documentId, $lineId, (int) $stock['id'], (int) $stock['lot_id'], MovementType::SaleDispatch, -$quantity, 0, (int) $stock['location_id'], null, $newOnHand, (float) $stock['reserved_weight_kg'], $actorUserId, $now);
                $this->updateStockProjection($stock, $newOnHand, (float) $stock['reserved_weight_kg'], (int) $stock['warehouse_id'], (int) $stock['location_id'], $newStatus, $now);
                $this->db->table('sales_order_lines')->where('id', $saleLine['id'])->update(['status' => 'dispatched', 'updated_at' => $now]);
                $results[] = ['stock_unit_id' => (int) $stock['id'], 'quantity_kg' => $quantity, 'remaining_kg' => $newOnHand];
            }
            $this->db->table('sales_orders')->where('id', $salesOrderId)->update(['status' => 'dispatched', 'dispatched_by' => $actorUserId, 'dispatched_at' => $now, 'lock_version' => (int) $order['lock_version'] + 1, 'updated_at' => $now]);
            $this->audit->record('sale.dispatched', 'sales_order', $salesOrderId, $actorUserId, (int) $order['company_id'], (int) $order['site_id'], null, ['inventory_document_id' => $documentId, 'origin_warehouse_id' => $originWarehouseId, 'lines' => $results]);
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

    private function updateStockProjection(array $stock, float $onHand, float $reserved, int $warehouseId, int $locationId, string $status, string $now, ?int $siteId = null): void
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
            || ($requestLine['size_grade_id'] !== null && (int) $stock['size_grade_id'] !== (int) $requestLine['size_grade_id'])
            || ($requestLine['quality_grade'] !== null && (string) $stock['quality_grade'] !== (string) $requestLine['quality_grade'])) {
            throw new DomainException('La unidad no cumple los criterios de la solicitud.', 'STOCK_CRITERIA_MISMATCH', ['stock_unit_id' => $stock['id']]);
        }
    }
}
