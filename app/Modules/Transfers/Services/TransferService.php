<?php

namespace App\Modules\Transfers\Services;

use App\Common\Domain\WorkflowTransitions;
use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Common\Services\FolioService;
use App\Modules\Inventory\Repositories\InventoryRepository;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class TransferService
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
        if (! empty($payload['reservation_id'])) {
            return $this->createFromReservation((int) $payload['reservation_id'], $actorUserId);
        }

        return $this->createAdministrative($payload, $actorUserId);
    }

    private function createAdministrative(array $payload, int $actorUserId): array
    {
        $rawUnitIds = array_values($payload['stock_unit_ids'] ?? []);
        $unitIds = array_map('intval', $rawUnitIds);
        if ($unitIds === []) {
            throw new DomainException('El traspaso requiere unidades específicas.', 'TRANSFER_UNITS_REQUIRED');
        }
        if (in_array(0, $unitIds, true) || count($unitIds) !== count(array_unique($unitIds))) {
            throw new DomainException('El traspaso contiene unidades inválidas o repetidas.', 'INVALID_TRANSFER_UNITS');
        }
        if ((int) $payload['origin_warehouse_id'] === (int) $payload['destination_warehouse_id']) {
            throw new DomainException('Los almacenes origen y destino deben ser distintos.', 'TRANSFER_SAME_WAREHOUSE');
        }

        $transferType = trim((string) ($payload['transfer_type'] ?? 'legacy')) ?: 'legacy';
        if ($transferType === 'warehouse_request') {
            throw new DomainException('Registra este movimiento desde Solicitudes entre almacenes.', 'WAREHOUSE_REQUEST_WORKFLOW_REQUIRED');
        }
        $observations = trim((string) ($payload['observations'] ?? ''));
        if ($transferType === 'administrative' && mb_strlen($observations) < 5) {
            throw new DomainException('El traspaso administrativo requiere un motivo de al menos 5 caracteres.', 'TRANSFER_ADMIN_REASON_REQUIRED');
        }

        $this->db->transException(true)->transBegin();
        try {
            $warehouses = $this->db->table('warehouses')
                ->whereIn('id', [(int) $payload['origin_warehouse_id'], (int) $payload['destination_warehouse_id']])
                ->where(['company_id' => (int) $payload['company_id'], 'active' => 1])
                ->get()->getResultArray();
            if (count($warehouses) !== 2) {
                throw new DomainException('Origen y destino deben ser almacenes activos de la empresa.', 'TRANSFER_WAREHOUSE_MISMATCH');
            }

            $warehouseById = [];
            foreach ($warehouses as $warehouse) {
                $warehouseById[(int) $warehouse['id']] = $warehouse;
            }

            $now = date('Y-m-d H:i:s');
            $folio = $this->folios->next('TRP');
            $this->db->table('transfers')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'folio' => $folio,
                'company_id' => (int) $payload['company_id'],
                'origin_warehouse_id' => (int) $payload['origin_warehouse_id'],
                'destination_warehouse_id' => (int) $payload['destination_warehouse_id'],
                'transfer_type' => $transferType === 'legacy' ? 'administrative' : $transferType,
                'request_id' => null,
                'reservation_id' => null,
                'status' => 'requested',
                'requested_by' => $actorUserId,
                'requested_at' => $now,
                'carrier_reference' => $payload['carrier_reference'] ?? null,
                'observations' => $payload['observations'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $transferId = (int) $this->db->insertID();

            foreach ($unitIds as $index => $stockUnitId) {
                $stock = $this->inventory->findStockUnitForUpdate($stockUnitId);
                if ((int) $stock['company_id'] !== (int) $payload['company_id']
                    || (int) $stock['warehouse_id'] !== (int) $payload['origin_warehouse_id']
                    || $stock['status'] !== 'available'
                    || (float) $stock['reserved_weight_kg'] > 0) {
                    throw new DomainException('Una unidad no está disponible para traspaso.', 'TRANSFER_STOCK_NOT_AVAILABLE', ['stock_unit_id' => $stockUnitId]);
                }
                $this->db->table('transfer_lines')->insert([
                    'transfer_id' => $transferId,
                    'line_number' => $index + 1,
                    'stock_unit_id' => $stockUnitId,
                    'shipped_weight_kg' => (float) $stock['on_hand_weight_kg'],
                    'status' => 'prepared',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->audit->record(
                'transfer.created',
                'transfer',
                $transferId,
                $actorUserId,
                (int) $payload['company_id'],
                (int) $warehouseById[(int) $payload['origin_warehouse_id']]['site_id'],
                null,
                [
                    'folio' => $folio,
                    'transfer_type' => 'administrative',
                    'stock_unit_ids' => $unitIds,
                    'destination_site_id' => (int) $warehouseById[(int) $payload['destination_warehouse_id']]['site_id'],
                ]
            );

            $this->db->transCommit();
            return [
                'id' => $transferId,
                'folio' => $folio,
                'status' => 'requested',
                'transfer_type' => 'administrative',
                'unit_count' => count($unitIds),
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function createFromReservation(int $reservationId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $reservation = $this->db->query(<<<'SQL'
SELECT rs.*, req.company_id, req.site_id, req.folio AS request_folio, req.status AS request_status,
       req.request_type, req.destination_warehouse_id, req.authorized_by
FROM reservations rs
INNER JOIN raw_material_requests req ON req.id = rs.request_id
WHERE rs.id = ?
FOR UPDATE
SQL, [$reservationId])->getRowArray();

            if ($reservation === null) {
                throw new DomainException('Reserva no encontrada.', 'RESERVATION_NOT_FOUND');
            }
            if (($reservation['request_type'] ?? 'production') !== 'warehouse_transfer') {
                throw new DomainException('La reserva no pertenece a una solicitud de traspaso.', 'RESERVATION_NOT_TRANSFER_REQUEST');
            }
            if ($reservation['status'] !== 'active') {
                throw new DomainException('La reserva no está activa para generar un traspaso.', 'RESERVATION_NOT_ACTIVE');
            }
            if ($reservation['expires_at'] < date('Y-m-d H:i:s')) {
                throw new DomainException('La reserva venció; libérala y selecciona las tinas nuevamente.', 'RESERVATION_EXPIRED');
            }
            if (! in_array((string) $reservation['request_status'], ['reserved', 'authorized'], true)) {
                throw new DomainException('La solicitud no está lista para generar el traspaso.', 'REQUEST_NOT_READY_FOR_TRANSFER');
            }

            $destinationWarehouseId = (int) ($reservation['destination_warehouse_id'] ?? 0);
            $destination = $this->db->table('warehouses')->where([
                'id' => $destinationWarehouseId,
                'company_id' => (int) $reservation['company_id'],
                'active' => 1,
            ])->get()->getRowArray();
            if ($destination === null) {
                throw new DomainException('El almacén destino ya no está disponible.', 'DESTINATION_WAREHOUSE_NOT_FOUND');
            }

            $existing = $this->db->table('transfers')
                ->where('reservation_id', $reservationId)
                ->where('status !=', 'canceled')
                ->countAllResults();
            if ($existing > 0) {
                throw new DomainException('La reserva ya tiene traspaso(s) generado(s).', 'RESERVATION_TRANSFER_ALREADY_EXISTS');
            }

            $lines = $this->db->table('reservation_lines rl')
                ->select('rl.*, su.company_id, su.site_id, su.warehouse_id, su.on_hand_weight_kg, su.reserved_weight_kg AS stock_reserved_weight_kg, su.status AS stock_status')
                ->join('stock_units su', 'su.id = rl.stock_unit_id')
                ->where(['rl.reservation_id' => $reservationId, 'rl.status' => 'active'])
                ->orderBy('rl.id')
                ->get()->getResultArray();

            if ($lines === []) {
                throw new DomainException('La reserva no contiene tinas activas.', 'RESERVATION_LINES_REQUIRED');
            }

            $groups = [];
            foreach ($lines as $line) {
                $stockUnitId = (int) $line['stock_unit_id'];
                $warehouseId = (int) $line['warehouse_id'];
                $reservedWeight = round((float) $line['reserved_weight_kg'], 3);
                $onHand = round((float) $line['on_hand_weight_kg'], 3);
                $stockReserved = round((float) $line['stock_reserved_weight_kg'], 3);

                if ((int) $line['company_id'] !== (int) $reservation['company_id']) {
                    throw new DomainException('Una tina reservada pertenece a otra empresa.', 'TRANSFER_RESERVATION_SCOPE_MISMATCH', ['stock_unit_id' => $stockUnitId]);
                }
                if ($warehouseId === $destinationWarehouseId) {
                    throw new DomainException('Una tina reservada ya pertenece al almacén destino.', 'TRANSFER_SAME_WAREHOUSE', ['stock_unit_id' => $stockUnitId]);
                }
                if ($line['stock_status'] !== 'reserved'
                    || abs($reservedWeight - $onHand) > 0.001
                    || abs($stockReserved - $onHand) > 0.001) {
                    throw new DomainException(
                        'Las solicitudes de traspaso requieren tinas completas y reservadas exclusivamente.',
                        'TRANSFER_FULL_RESERVED_UNIT_REQUIRED',
                        ['stock_unit_id' => $stockUnitId]
                    );
                }

                $groups[$warehouseId][] = $line;
            }

            $now = date('Y-m-d H:i:s');
            $created = [];

            foreach ($groups as $originWarehouseId => $groupLines) {
                $origin = $this->db->table('warehouses')->where([
                    'id' => (int) $originWarehouseId,
                    'company_id' => (int) $reservation['company_id'],
                    'active' => 1,
                ])->get()->getRowArray();
                if ($origin === null) {
                    throw new DomainException('El almacén origen ya no está disponible.', 'TRANSFER_ORIGIN_WAREHOUSE_INVALID');
                }

                $folio = $this->folios->next('TRP');
                $this->db->table('transfers')->insert([
                    'uuid' => Uuid::uuid7()->toString(),
                    'folio' => $folio,
                    'company_id' => (int) $reservation['company_id'],
                    'origin_warehouse_id' => (int) $originWarehouseId,
                    'destination_warehouse_id' => $destinationWarehouseId,
                    'transfer_type' => 'request',
                    'request_id' => (int) $reservation['request_id'],
                    'reservation_id' => $reservationId,
                    // La solicitud ya fue autorizada; el traspaso ligado nace autorizado.
                    'status' => 'authorized',
                    'requested_by' => $actorUserId,
                    'authorized_by' => $reservation['authorized_by'] !== null ? (int) $reservation['authorized_by'] : $actorUserId,
                    'requested_at' => $now,
                    'carrier_reference' => null,
                    'observations' => 'Generado desde ' . $reservation['request_folio'] . ' / ' . $reservation['folio'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $transferId = (int) $this->db->insertID();

                foreach (array_values($groupLines) as $index => $line) {
                    $this->db->table('transfer_lines')->insert([
                        'transfer_id' => $transferId,
                        'line_number' => $index + 1,
                        'stock_unit_id' => (int) $line['stock_unit_id'],
                        'shipped_weight_kg' => round((float) $line['reserved_weight_kg'], 3),
                        'status' => 'prepared',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $created[] = [
                    'id' => $transferId,
                    'folio' => $folio,
                    'origin_warehouse_id' => (int) $originWarehouseId,
                    'destination_warehouse_id' => $destinationWarehouseId,
                    'unit_count' => count($groupLines),
                    'status' => 'authorized',
                ];

                $this->audit->record(
                    'transfer.created_from_request',
                    'transfer',
                    $transferId,
                    $actorUserId,
                    (int) $reservation['company_id'],
                    (int) $reservation['site_id'],
                    null,
                    [
                        'folio' => $folio,
                        'request_id' => (int) $reservation['request_id'],
                        'reservation_id' => $reservationId,
                        'destination_site_id' => (int) $destination['site_id'],
                        'stock_unit_ids' => array_map('intval', array_column($groupLines, 'stock_unit_id')),
                    ]
                );
            }

            $this->db->table('reservations')->where('id', $reservationId)->update([
                'status' => 'in_transfer',
                'lock_version' => (int) $reservation['lock_version'] + 1,
                'updated_at' => $now,
            ]);
            $this->db->table('raw_material_requests')->where('id', (int) $reservation['request_id'])
                ->set('status', 'in_transfer')
                ->set('lock_version', 'lock_version + 1', false)
                ->set('updated_at', $now)
                ->update();

            $this->db->transCommit();
            return [
                'reservation_id' => $reservationId,
                'request_id' => (int) $reservation['request_id'],
                'status' => 'in_transfer',
                'transfer_count' => count($created),
                'transfers' => $created,
                'folios' => array_column($created, 'folio'),
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }


    public function recordWeight(int $transferId, string $direction, array $payload, int $actorUserId, bool $fromWarehouseRequest = false): array
    {
        if (! in_array($direction, ['outbound', 'inbound'], true)) {
            throw new DomainException('Tipo de pesaje de traspaso inválido.', 'TRANSFER_WEIGHT_DIRECTION_INVALID');
        }

        $stockUnitId = (int) ($payload['stock_unit_id'] ?? 0);
        $scaleReadingId = (int) ($payload['scale_reading_id'] ?? 0);
        $varianceReason = trim((string) ($payload['variance_reason'] ?? ''));

        if ($stockUnitId <= 0 || $scaleReadingId <= 0) {
            throw new DomainException('La tina y la lectura estable son obligatorias.', 'TRANSFER_WEIGHT_DATA_REQUIRED');
        }

        $this->db->transException(true)->transBegin();

        try {
            $transfer = $this->db->query('SELECT * FROM transfers WHERE id = ? FOR UPDATE', [$transferId])->getRowArray();
            if ($transfer === null) {
                throw new DomainException('Traspaso no encontrado.', 'TRANSFER_NOT_FOUND');
            }

            if (($transfer['transfer_type'] ?? '') === 'warehouse_request' && ! $fromWarehouseRequest) {
                throw new DomainException('Registra el pesaje desde la solicitud entre almacenes.', 'WAREHOUSE_REQUEST_WORKFLOW_REQUIRED');
            }

            $warehouseFlow = $fromWarehouseRequest && ($transfer['transfer_type'] ?? '') === 'warehouse_request';
            $allowedStatuses = $direction === 'outbound' ? ['authorized', 'prepared'] : ['in_transit', 'discrepancy'];
            if ($warehouseFlow && $direction === 'outbound') { $allowedStatuses[] = 'in_transit'; }
            if (! in_array((string) $transfer['status'], $allowedStatuses, true)) {
                throw new DomainException('El traspaso no está listo para este pesaje.', 'TRANSFER_NOT_READY_FOR_WEIGHT');
            }

            $line = $this->db->query(
                'SELECT * FROM transfer_lines WHERE transfer_id = ? AND stock_unit_id = ? FOR UPDATE',
                [$transferId, $stockUnitId]
            )->getRowArray();

            if ($line === null) {
                throw new DomainException('La tina no pertenece al traspaso.', 'TRANSFER_WEIGHT_UNIT_MISMATCH');
            }

            if ($direction === 'outbound' && (string) $line['status'] !== 'prepared') {
                throw new DomainException('La tina ya no está disponible para pesaje de salida.', 'TRANSFER_LINE_NOT_PREPARED');
            }

            if ($direction === 'inbound' && ! in_array((string) $line['status'], ['shipped', 'missing'], true)) {
                throw new DomainException('La tina no está pendiente de recepción.', 'TRANSFER_LINE_NOT_RECEIVABLE');
            }

            $stock = $this->db->query('SELECT * FROM stock_units WHERE id = ? FOR UPDATE', [$stockUnitId])->getRowArray();
            if ($stock === null) {
                throw new DomainException('No se encontró la unidad de inventario.', 'STOCK_UNIT_NOT_FOUND');
            }

            $warehouseId = $direction === 'outbound'
                ? (int) $transfer['origin_warehouse_id']
                : (int) $transfer['destination_warehouse_id'];

            $warehouseQuery = $this->db->table('warehouses')->where(['id'=>$warehouseId,'active'=>1]);
            if (! $warehouseFlow) { $warehouseQuery->where('company_id',(int) $transfer['company_id']); }
            $warehouse = $warehouseQuery->get()->getRowArray();

            if ($warehouse === null) {
                throw new DomainException('El almacén del pesaje ya no está disponible.', 'TRANSFER_WEIGHT_WAREHOUSE_INVALID');
            }

            if ($direction === 'outbound') {
                if ((int) $stock['warehouse_id'] !== (int) $transfer['origin_warehouse_id']
                    || ! in_array((string) $stock['status'], ['available', 'reserved'], true)) {
                    throw new DomainException('La tina ya no está físicamente en el almacén origen.', 'TRANSFER_WEIGHT_STOCK_ORIGIN_MISMATCH');
                }
            } else {
                if ((int) $stock['warehouse_id'] !== (int) $transfer['origin_warehouse_id']
                    || (string) $stock['status'] !== 'in_transit') {
                    throw new DomainException('La tina ya no está en tránsito.', 'TRANSFER_WEIGHT_STOCK_TRANSIT_MISMATCH');
                }
            }

            $reading = $this->db->table('scale_readings sr')
                ->select('sr.*, sc.site_id AS scale_site_id, sc.active AS scale_active')
                ->join('scales sc', 'sc.id = sr.scale_id')
                ->where('sr.id', $scaleReadingId)
                ->get()->getRowArray();

            if ($warehouseFlow) {
                $reading=$this->db->query('SELECT sr.*,sc.site_id AS scale_site_id,sc.active AS scale_active FROM scale_readings sr JOIN scales sc ON sc.id=sr.scale_id WHERE sr.id=? FOR UPDATE',[$scaleReadingId])->getRowArray();
            }
            if ($reading === null || ! (bool) $reading['stable']) {
                throw new DomainException('Se requiere una lectura estable de báscula.', 'TRANSFER_STABLE_SCALE_READING_REQUIRED');
            }

            if ((int) $reading['scale_site_id'] !== (int) $warehouse['site_id']) {
                throw new DomainException('La lectura proviene de una báscula de otra instalación.', 'TRANSFER_SCALE_SITE_MISMATCH');
            }

            $this->db->query('SELECT id FROM scale_readings WHERE id = ? FOR UPDATE', [$scaleReadingId]);
            $returnReadingUsed = $this->db->table('warehouse_request_units')->where('return_reading_id', $scaleReadingId)->countAllResults();
            $readingAlreadyUsed = $this->db->table('transfer_weighings')
                ->where(['scale_reading_id' => $scaleReadingId, 'status' => 'confirmed'])
                ->countAllResults();

            if ($warehouseFlow) {
                \App\Modules\WarehouseRequests\Domain\RequestPolicy::reading($reading,$actorUserId,(int) $warehouse['company_id'],(int) $warehouse['site_id']);
                $readingAlreadyUsed += $this->db->table('transfer_weighings')->where('scale_reading_id',$scaleReadingId)->countAllResults();
                $readingAlreadyUsed += $this->db->table('weighings')->where('scale_reading_id',$scaleReadingId)->countAllResults();
                if (!(bool) $reading['scale_active'] || $reading['unit']!=='kg' || $reading['weighing_id']!==null) {
                    throw new DomainException('La lectura no es válida para esta operación.','TRANSFER_STABLE_SCALE_READING_REQUIRED');
                }
            }
            if ($readingAlreadyUsed > 0 || $returnReadingUsed > 0) {
                throw new DomainException('La lectura de báscula ya fue utilizada en otro pesaje de traspaso.', 'TRANSFER_SCALE_READING_ALREADY_USED');
            }

            $weight = round((float) $reading['weight'], 3);
            $grossWeight = $weight;
            $tareWeight = 0.0;
            if ($warehouseFlow) {
                $tub=$this->db->query('SELECT current_tare_kg FROM tubs WHERE id=? FOR UPDATE',[$stock['tub_id']])->getRowArray();
                $tareWeight=(float) $tub['current_tare_kg'];
                if (!is_finite($tareWeight) || $tareWeight<0) {
                    throw new DomainException('La tara de la tina no es válida.','TRANSFER_WEIGHT_INVALID');
                }
                $weight=round($grossWeight-$tareWeight,3);
            }
            $baseline = $direction === 'outbound'
                ? round((float) $stock['on_hand_weight_kg'], 3)
                : round((float) $line['shipped_weight_kg'], 3);

            if ($weight <= 0 || $baseline <= 0) {
                throw new DomainException('El peso leído o su referencia no son válidos.', 'TRANSFER_WEIGHT_INVALID');
            }

            $difference = round($weight - $baseline, 3);
            $differencePercent = round(($difference / $baseline) * 100, 4);
            $toleranceKg = round((float) env('inventory.transfer_weight_tolerance_kg', 2.000), 3);
            $tolerancePercent = round((float) env('inventory.transfer_weight_tolerance_percent', 0.2500), 4);
            $allowedDifference = max($toleranceKg, round($baseline * ($tolerancePercent / 100), 3));
            $withinTolerance = abs($difference) <= $allowedDifference + 0.0001;

            if (! $withinTolerance && mb_strlen($varianceReason) < 5) {
                throw new DomainException(
                    'La diferencia de peso está fuera de tolerancia. Captura el motivo antes de confirmar.',
                    'TRANSFER_WEIGHT_VARIANCE_REASON_REQUIRED',
                    [
                        'baseline_weight_kg' => $baseline,
                        'gross_weight_kg' => $grossWeight,
                        'tare_weight_kg' => $tareWeight,
                        'weight_kg' => $weight,
                        'difference_kg' => $difference,
                        'difference_percent' => $differencePercent,
                        'allowed_difference_kg' => $allowedDifference,
                    ]
                );
            }

            $now = date('Y-m-d H:i:s');

            $previous = $this->db->table('transfer_weighings')->where([
                'transfer_line_id' => (int) $line['id'],
                'direction' => $direction,
                'is_current' => 1,
            ])->get()->getRowArray();

            if ($previous !== null) {
                $this->db->table('transfer_weighings')->where('id', (int) $previous['id'])->update([
                    'status' => 'reversed',
                    'is_current' => null,
                    'reversed_by' => $actorUserId,
                    'reversed_at' => $now,
                    'reversal_reason' => 'Reemplazado por una nueva lectura estable.',
                    'updated_at' => $now,
                ]);
            }

            $this->db->table('transfer_weighings')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'transfer_id' => $transferId,
                'transfer_line_id' => (int) $line['id'],
                'stock_unit_id' => $stockUnitId,
                'direction' => $direction,
                'company_id' => (int) ($warehouseFlow ? $warehouse['company_id'] : $transfer['company_id']),
                'site_id' => (int) $warehouse['site_id'],
                'warehouse_id' => $warehouseId,
                'scale_id' => empty($reading['scale_id']) ? null : (int) $reading['scale_id'],
                'scale_reading_id' => $scaleReadingId,
                'weight_kg' => $weight,
                'baseline_weight_kg' => $baseline,
                'difference_kg' => $difference,
                'difference_percent' => $differencePercent,
                'tolerance_kg' => $toleranceKg,
                'tolerance_percent' => $tolerancePercent,
                'within_tolerance' => $withinTolerance ? 1 : 0,
                'variance_reason' => $varianceReason !== '' ? $varianceReason : null,
                'status' => 'confirmed',
                'is_current' => 1,
                'confirmed_by' => $actorUserId,
                'confirmed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $weighingId = (int) $this->db->insertID();

            $lineUpdate = ['updated_at' => $now];
            if ($direction === 'outbound') {
                $lineUpdate['shipped_weight_kg'] = $weight;
            } else {
                $lineUpdate['received_weight_kg'] = $weight;
                $lineUpdate['difference_kg'] = $difference;
            }
            $this->db->table('transfer_lines')->where('id', (int) $line['id'])->update($lineUpdate);

            $this->audit->record(
                'transfer.weight_recorded',
                'transfer',
                $transferId,
                $actorUserId,
                (int) ($warehouseFlow ? $warehouse['company_id'] : $transfer['company_id']),
                (int) $warehouse['site_id'],
                null,
                [
                    'transfer_weighing_id' => $weighingId,
                    'stock_unit_id' => $stockUnitId,
                    'direction' => $direction,
                    'weight_kg' => $weight,
                    'baseline_weight_kg' => $baseline,
                'gross_weight_kg' => $grossWeight,
                'tare_weight_kg' => $tareWeight,
                    'difference_kg' => $difference,
                    'difference_percent' => $differencePercent,
                    'within_tolerance' => $withinTolerance,
                    'scale_id' => empty($reading['scale_id']) ? null : (int) $reading['scale_id'],
                    'scale_reading_id' => $scaleReadingId,
                ],
                $varianceReason !== '' ? $varianceReason : null
            );

            $this->db->transCommit();

            return [
                'id' => $weighingId,
                'transfer_id' => $transferId,
                'transfer_line_id' => (int) $line['id'],
                'stock_unit_id' => $stockUnitId,
                'direction' => $direction,
                'weight_kg' => $weight,
                'baseline_weight_kg' => $baseline,
                'gross_weight_kg' => $grossWeight,
                'tare_weight_kg' => $tareWeight,
                'difference_kg' => $difference,
                'difference_percent' => $differencePercent,
                'within_tolerance' => $withinTolerance,
                'allowed_difference_kg' => $allowedDifference,
                'scale_id' => empty($reading['scale_id']) ? null : (int) $reading['scale_id'],
                'scale_reading_id' => $scaleReadingId,
                'confirmed_at' => $now,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function currentWeights(int $transferId): array
    {
        $rows = $this->db->table('transfer_weighings')
            ->where(['transfer_id' => $transferId, 'status' => 'confirmed', 'is_current' => 1])
            ->orderBy('id')
            ->get()->getResultArray();

        $byLine = [];
        foreach ($rows as $row) {
            $byLine[(int) $row['transfer_line_id']][(string) $row['direction']] = $row;
        }

        return $byLine;
    }

    public function authorize(int $transferId, bool $approved, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $transfer = $this->db->query(<<<'SQL'
SELECT t.*, w.site_id AS origin_site_id
FROM transfers t
INNER JOIN warehouses w ON w.id = t.origin_warehouse_id
WHERE t.id = ?
FOR UPDATE
SQL, [$transferId])->getRowArray();
            if ($transfer === null) {
                throw new DomainException('Traspaso no encontrado.', 'TRANSFER_NOT_FOUND');
            }
            if (($transfer['transfer_type'] ?? '') === 'warehouse_request') {
                throw new DomainException('Finaliza o rechaza desde la solicitud entre almacenes.', 'WAREHOUSE_REQUEST_WORKFLOW_REQUIRED');
            }
            if (($transfer['transfer_type'] ?? 'administrative') === 'request') {
                throw new DomainException('Los traspasos ligados a solicitud ya nacen autorizados.', 'REQUEST_TRANSFER_ALREADY_AUTHORIZED');
            }
            $target = $approved ? 'authorized' : 'canceled';
            WorkflowTransitions::transfer()->assertCanTransition((string) $transfer['status'], $target);
            $now = date('Y-m-d H:i:s');
            $this->db->table('transfers')->where('id', $transferId)->update([
                'status' => $target,
                'authorized_by' => $approved ? $actorUserId : null,
                'lock_version' => (int) $transfer['lock_version'] + 1,
                'updated_at' => $now,
            ]);
            $this->audit->record(
                'transfer.' . $target,
                'transfer',
                $transferId,
                $actorUserId,
                (int) $transfer['company_id'],
                (int) $transfer['origin_site_id'],
                ['status' => $transfer['status']],
                ['status' => $target]
            );
            $this->db->transCommit();
            return ['id' => $transferId, 'status' => $target];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
