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
        $this->db->transException(true)->transBegin();
        try {
            $warehouses = $this->db->table('warehouses')->whereIn('id', [(int) $payload['origin_warehouse_id'], (int) $payload['destination_warehouse_id']])->where(['company_id' => (int) $payload['company_id'], 'active' => 1])->get()->getResultArray();
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
                if ((int) $stock['company_id'] !== (int) $payload['company_id'] || (int) $stock['warehouse_id'] !== (int) $payload['origin_warehouse_id'] || $stock['status'] !== 'available' || (float) $stock['reserved_weight_kg'] > 0) {
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
            $this->audit->record('transfer.created', 'transfer', $transferId, $actorUserId, (int) $payload['company_id'], (int) $warehouseById[(int) $payload['origin_warehouse_id']]['site_id'], null, ['folio' => $folio, 'stock_unit_ids' => $unitIds, 'destination_site_id' => (int) $warehouseById[(int) $payload['destination_warehouse_id']]['site_id']]);
            $this->db->transCommit();
            return ['id' => $transferId, 'folio' => $folio, 'status' => 'requested', 'unit_count' => count($unitIds)];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
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
            $target = $approved ? 'authorized' : 'canceled';
            WorkflowTransitions::transfer()->assertCanTransition((string) $transfer['status'], $target);
            $now = date('Y-m-d H:i:s');
            $this->db->table('transfers')->where('id', $transferId)->update(['status' => $target, 'authorized_by' => $approved ? $actorUserId : null, 'lock_version' => (int) $transfer['lock_version'] + 1, 'updated_at' => $now]);
            $this->audit->record('transfer.' . $target, 'transfer', $transferId, $actorUserId, (int) $transfer['company_id'], (int) $transfer['origin_site_id'], ['status' => $transfer['status']], ['status' => $target]);
            $this->db->transCommit();
            return ['id' => $transferId, 'status' => $target];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
