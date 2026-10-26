<?php

namespace App\Modules\Sales\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Common\Services\FolioService;
use App\Modules\Inventory\Repositories\InventoryRepository;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class SalesService
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
            throw new DomainException('La venta requiere unidades específicas.', 'SALE_LINES_REQUIRED');
        }
        $this->db->transException(true)->transBegin();
        try {
            if ($this->db->table('sites')->where(['id' => (int) $payload['site_id'], 'company_id' => (int) $payload['company_id'], 'active' => 1])->countAllResults() !== 1) {
                throw new DomainException('La instalación no pertenece a la empresa.', 'SITE_COMPANY_MISMATCH');
            }
            $customer = $this->db->table('companies')->where(['id' => (int) $payload['customer_company_id'], 'active' => 1])->whereIn('company_type', ['customer', 'owner'])->get()->getRowArray();
            if ($customer === null) {
                throw new DomainException('El cliente no existe o no está activo.', 'CUSTOMER_NOT_FOUND');
            }
            $now = date('Y-m-d H:i:s');
            $folio = $this->folios->next('VTA');
            $this->db->table('sales_orders')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'folio' => $folio,
                'company_id' => (int) $payload['company_id'],
                'site_id' => (int) $payload['site_id'],
                'customer_company_id' => (int) $payload['customer_company_id'],
                'status' => 'draft',
                'required_at' => $payload['required_at'] ?? null,
                'requested_by' => $actorUserId,
                'observations' => $payload['observations'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $orderId = (int) $this->db->insertID();
            $seenStock = [];
            foreach ($lines as $index => $line) {
                $stockUnitId = (int) ($line['stock_unit_id'] ?? 0);
                if ($stockUnitId <= 0 || isset($seenStock[$stockUnitId])) {
                    throw new DomainException('La venta contiene unidades inválidas o repetidas.', 'INVALID_SALE_LINES');
                }
                $seenStock[$stockUnitId] = true;
                $stock = $this->inventory->findStockUnitForUpdate($stockUnitId);
                $quantity = round((float) ($line['quantity_kg'] ?? $stock['on_hand_weight_kg']), 3);
                $available = round((float) $stock['on_hand_weight_kg'] - (float) $stock['reserved_weight_kg'], 3);
                if ((int) $stock['company_id'] !== (int) $payload['company_id'] || (int) $stock['site_id'] !== (int) $payload['site_id'] || $stock['status'] !== 'available' || $quantity <= 0 || $quantity > $available) {
                    throw new DomainException('Una unidad no está disponible para venta.', 'SALE_STOCK_NOT_AVAILABLE', ['stock_unit_id' => $stock['id'], 'available_kg' => $available]);
                }
                $this->db->table('sales_order_lines')->insert([
                    'sales_order_id' => $orderId,
                    'line_number' => $index + 1,
                    'stock_unit_id' => (int) $stock['id'],
                    'quantity_kg' => $quantity,
                    'unit_price' => $line['unit_price'] ?? null,
                    'currency' => $line['currency'] ?? null,
                    'status' => 'selected',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->audit->record('sale.created', 'sales_order', $orderId, $actorUserId, (int) $payload['company_id'], (int) $payload['site_id'], null, ['folio' => $folio, 'line_count' => count($lines)]);
            $this->db->transCommit();
            return ['id' => $orderId, 'folio' => $folio, 'status' => 'draft', 'stock_reserved' => false];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
