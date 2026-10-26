<?php

namespace App\Modules\Sales\Services;

use App\Common\Exceptions\DomainException;
use App\Modules\WarehouseRequests\Domain\RequestPolicy;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class SalesRequestService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function create(array $payload, int $actorUserId): array
    {
        $scope = service('scopeContext')->restrictions();
        RequestPolicy::require(
            ! empty($scope['configured']) && ! empty($scope['company_id']) && ! empty($scope['site_id']) && ! empty($scope['warehouse_id']),
            'Selecciona empresa, instalación y almacén en tu alcance operativo.',
            'OPERATIONAL_SCOPE_REQUIRED'
        );

        $warehouseId = (int) $scope['warehouse_id'];
        $warehouse = $this->db->query(
            'SELECT w.id,w.company_id,w.site_id,w.code,w.name,c.trade_name AS company_name,s.name AS site_name
             FROM warehouses w
             INNER JOIN companies c ON c.id=w.company_id
             INNER JOIN sites s ON s.id=w.site_id AND s.company_id=w.company_id
             WHERE w.id=? AND w.company_id=? AND w.site_id=? AND w.active=1 AND c.active=1 AND s.active=1',
            [$warehouseId, (int) $scope['company_id'], (int) $scope['site_id']]
        )->getRowArray();
        RequestPolicy::require($warehouse !== null, 'El almacén actual no está disponible.', 'SALE_WAREHOUSE_NOT_AVAILABLE');

        $requestTypeConfig = config(\Config\WarehouseRequestTypes::class);
        RequestPolicy::require(
            $requestTypeConfig->allows($warehouse, 'sale'),
            'El almacén actual no está configurado para Venta a cliente.',
            'SALE_WAREHOUSE_NOT_ALLOWED',
            [
                'warehouse_id' => (int) $warehouse['id'],
                'warehouse_code' => (string) ($warehouse['code'] ?? ''),
            ]
        );

        $customerId = (int) ($payload['customer_company_id'] ?? 0);
        $customer = $this->db->table('companies')
            ->where(['id' => $customerId, 'active' => 1, 'company_type' => 'customer'])
            ->get()
            ->getRowArray();
        RequestPolicy::require($customer !== null, 'Selecciona un cliente activo del catálogo.', 'SALE_CUSTOMER_REQUIRED');

        $required = str_replace('T', ' ', trim((string) ($payload['required_at'] ?? '')));
        $requiredDate = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $required);
        RequestPolicy::require(
            $requiredDate !== false && $requiredDate->format('Y-m-d H:i') === $required,
            'Indica una fecha y hora requeridas válidas.',
            'SALE_REQUIRED_AT_INVALID'
        );

        $observations = trim((string) ($payload['observations'] ?? ''));
        RequestPolicy::require(mb_strlen($observations) <= 1000, 'Las observaciones admiten hasta 1000 caracteres.');

        $rawLines = $payload['lines'] ?? null;
        RequestPolicy::require(is_array($rawLines) && count($rawLines) > 0 && count($rawLines) <= 200, 'Captura de una a 200 líneas.');

        $normalized = [];
        $scopeKeys = [];
        $proposedIds = [];

        foreach (array_values($rawLines) as $index => $line) {
            RequestPolicy::require(is_array($line), 'Detalle de venta inválido.', 'SALE_REQUEST_LINE_INVALID');

            $speciesId = (int) ($line['species_id'] ?? 0);
            $sizeId = (int) ($line['size_grade_id'] ?? 0);
            RequestPolicy::require(
                $this->db->table('species')->where(['id' => $speciesId, 'active' => 1])->countAllResults() === 1,
                'Selecciona una especie activa en cada línea.'
            );

            $size = $this->db->table('size_grades')->where(['id' => $sizeId, 'active' => 1])->get()->getRowArray();
            RequestPolicy::require(
                $size !== null && (empty($size['species_id']) || (int) $size['species_id'] === $speciesId),
                'La talla es obligatoria y debe corresponder a la especie.'
            );

            $lotId = ! empty($line['lot_id']) ? (int) $line['lot_id'] : null;
            $ownerId = ! empty($line['owner_company_id']) ? (int) $line['owner_company_id'] : null;

            if ($lotId !== null) {
                $lot = $this->db->table('lots')->where(['id' => $lotId, 'species_id' => $speciesId])->get()->getRowArray();
                RequestPolicy::require($lot !== null, 'El lote no corresponde a la especie seleccionada.');
            }

            if ($ownerId !== null) {
                RequestPolicy::require(
                    $this->db->table('companies')->where(['id' => $ownerId, 'active' => 1])->countAllResults() === 1,
                    'El propietario no está activo.'
                );
            }

            $key = $speciesId . ':' . $sizeId . ':' . ($lotId ?? 0) . ':' . ($ownerId ?? 0);
            RequestPolicy::require(
                ! isset($scopeKeys[$key]),
                'Hay dos líneas con la misma Especie, Talla, Lote y Propietario.',
                'SALE_DUPLICATE_LINE_SCOPE'
            );
            $scopeKeys[$key] = true;

            $grams = RequestPolicy::grams($line['requested_weight_kg'] ?? '');

            $stocks = array_values(array_unique(array_map(
                'intval',
                is_array($line['stock_unit_ids'] ?? null) ? $line['stock_unit_ids'] : []
            )));
            $stocks = array_values(array_filter($stocks, static fn (int $id): bool => $id > 0));

            foreach ($stocks as $stockId) {
                RequestPolicy::require(
                    ! isset($proposedIds[$stockId]),
                    'Una tina no puede proponerse en más de una línea.',
                    'SALE_DUPLICATE_PROPOSAL_UNIT'
                );
                $proposedIds[$stockId] = $index + 1;
            }

            $normalized[] = [
                'line_number' => $index + 1,
                'species_id' => $speciesId,
                'size_grade_id' => $sizeId,
                'lot_id' => $lotId,
                'owner_company_id' => $ownerId,
                'requested_weight_kg' => $grams / 1000,
                'requested_unit_count' => null,
                'stock_unit_ids' => $stocks,
            ];
        }

        $this->db->transException(true)->transBegin();

        try {
            $proposalStocks = [];
            $stockIds = array_keys($proposedIds);

            if ($stockIds !== []) {
                sort($stockIds, SORT_NUMERIC);
                $marks = implode(',', array_fill(0, count($stockIds), '?'));
                $proposalStocks = array_column(
                    $this->db->query(
                        'SELECT * FROM stock_units WHERE id IN (' . $marks . ') ORDER BY id',
                        $stockIds
                    )->getResultArray(),
                    null,
                    'id'
                );

                RequestPolicy::require(
                    count($proposalStocks) === count($stockIds),
                    'Una o más tinas propuestas ya no existen.',
                    'SALE_PROPOSAL_STOCK_NOT_FOUND'
                );

                foreach ($normalized as $line) {
                    foreach ($line['stock_unit_ids'] as $stockId) {
                        $stock = $proposalStocks[$stockId] ?? null;
                        RequestPolicy::require($stock !== null, 'Tina propuesta no encontrada.', 'SALE_PROPOSAL_STOCK_NOT_FOUND');
                        RequestPolicy::require(
                            (int) $stock['company_id'] === (int) $scope['company_id']
                            && (int) $stock['site_id'] === (int) $scope['site_id']
                            && (int) $stock['warehouse_id'] === $warehouseId,
                            'La tina propuesta ya no pertenece al almacén que surtirá.',
                            'SALE_PROPOSAL_SCOPE_MISMATCH'
                        );
                        RequestPolicy::require(
                            $this->preferredLineNumber($stock, $normalized) === (int) $line['line_number'],
                            'La tina propuesta corresponde a otra línea más específica o a un cruce ambiguo de lote/propietario.',
                            'SALE_PROPOSAL_LINE_SCOPE_CONFLICT'
                        );
                    }
                }
            }

            $now = date('Y-m-d H:i:s');
            $folio = service('folioService')->next('VTA');

            $this->db->table('sales_orders')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'folio' => $folio,
                'company_id' => (int) $scope['company_id'],
                'site_id' => (int) $scope['site_id'],
                'source_warehouse_id' => $warehouseId,
                'customer_company_id' => $customerId,
                'status' => 'draft',
                'required_at' => $requiredDate->format('Y-m-d H:i:s'),
                'requested_by' => $actorUserId,
                'observations' => $observations !== '' ? $observations : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $orderId = (int) $this->db->insertID();

            $requestedKg = 0.0;
            $proposedKg = 0.0;
            $proposedUnits = 0;

            foreach ($normalized as $line) {
                $requestedKg += (float) $line['requested_weight_kg'];

                $this->db->table('sales_request_lines')->insert([
                    'sales_order_id' => $orderId,
                    'line_number' => $line['line_number'],
                    'species_id' => $line['species_id'],
                    'size_grade_id' => $line['size_grade_id'],
                    'lot_id' => $line['lot_id'],
                    'owner_company_id' => $line['owner_company_id'],
                    'requested_weight_kg' => $line['requested_weight_kg'],
                    'requested_unit_count' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $requestLineId = (int) $this->db->insertID();

                foreach ($line['stock_unit_ids'] as $stockId) {
                    $stock = $proposalStocks[$stockId];
                    $weight = round(max(0, (float) $stock['on_hand_weight_kg']), 3);

                    $this->db->table('sales_request_proposals')->insert([
                        'sales_order_id' => $orderId,
                        'request_line_id' => $requestLineId,
                        'stock_unit_id' => $stockId,
                        'proposed_weight_kg' => $weight,
                        'proposed_by' => $actorUserId,
                        'created_at' => $now,
                    ]);

                    $proposedKg += $weight;
                    $proposedUnits++;
                }
            }

            service('auditService')->record(
                'sale.request_created',
                'sales_order',
                $orderId,
                $actorUserId,
                (int) $scope['company_id'],
                (int) $scope['site_id'],
                after: [
                    'folio' => $folio,
                    'source_warehouse_id' => $warehouseId,
                    'customer_company_id' => $customerId,
                    'request_lines' => count($normalized),
                    'requested_kg' => round($requestedKg, 3),
                    'proposed_kg' => round($proposedKg, 3),
                    'proposed_units' => $proposedUnits,
                    'inventory_reserved' => false,
                ]
            );

            RequestPolicy::require(
                $this->db->transStatus() !== false,
                'No fue posible guardar la solicitud de venta.',
                'SALE_REQUEST_TRANSACTION_FAILED'
            );

            $this->db->transCommit();

            return [
                'id' => $orderId,
                'folio' => $folio,
                'status' => 'draft',
                'customer' => ['id' => $customerId, 'trade_name' => $customer['trade_name']],
                'requested_kg' => round($requestedKg, 3),
                'proposed_kg' => round($proposedKg, 3),
                'proposed_units' => $proposedUnits,
                'inventory_reserved' => false,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function history(array $filters = []): array
    {
        $scope = service('scopeContext')->restrictions();

        RequestPolicy::require(
            ! empty($scope['configured'])
            && ! empty($scope['company_id'])
            && ! empty($scope['site_id'])
            && ! empty($scope['warehouse_id']),
            'Selecciona empresa, instalación y almacén en tu alcance operativo.',
            'OPERATIONAL_SCOPE_REQUIRED'
        );

        $warehouseId = (int) $scope['warehouse_id'];
        $companyId = (int) $scope['company_id'];
        $siteId = (int) $scope['site_id'];

        $warehouseRows = $this->db->query(
            <<<'SQL'
SELECT
    r.id,
    r.folio,
    r.request_type,
    r.status,
    r.required_at,
    r.created_at,
    r.observations,
    r.source_warehouse_id,
    r.destination_warehouse_id,
    sw.name AS source_name,
    dw.name AS destination_name,
    (
        SELECT COALESCE(SUM(rl.requested_weight_kg), 0)
        FROM warehouse_request_lines rl
        WHERE rl.request_id = r.id
    ) AS requested_kg,
    (
        SELECT COALESCE(SUM(
            CASE
                WHEN u.status = 'selected' THEN COALESCE(u.preselected_weight_kg, 0)
                ELSE COALESCE(tl.shipped_weight_kg, 0)
            END
        ), 0)
        FROM warehouse_request_units u
        LEFT JOIN transfer_lines tl ON tl.id = u.transfer_line_id
        WHERE u.request_id = r.id
          AND u.status <> 'released'
    ) AS covered_kg,
    (
        SELECT COUNT(*)
        FROM warehouse_request_units u
        WHERE u.request_id = r.id
          AND u.status <> 'released'
    ) AS unit_count,
    (
        SELECT COUNT(*)
        FROM warehouse_request_lines rl
        WHERE rl.request_id = r.id
    ) AS line_count
FROM warehouse_requests r
INNER JOIN warehouses sw ON sw.id = r.source_warehouse_id
INNER JOIN warehouses dw ON dw.id = r.destination_warehouse_id
WHERE
    (
        (r.provider_company_id = ? AND r.source_warehouse_id = ?)
        OR
        (r.company_id = ? AND r.destination_warehouse_id = ?)
    )
ORDER BY r.created_at DESC, r.id DESC
LIMIT 1000
SQL,
            [$companyId, $warehouseId, $companyId, $warehouseId]
        )->getResultArray();

        $saleRows = [];

        if (
            $this->db->tableExists('sales_request_lines')
            && $this->db->fieldExists('source_warehouse_id', 'sales_orders')
        ) {
            $saleRows = $this->db->query(
                <<<'SQL'
SELECT
    so.id,
    so.folio,
    'sale' AS request_type,
    so.status,
    so.required_at,
    so.created_at,
    so.observations,
    so.source_warehouse_id,
    NULL AS destination_warehouse_id,
    w.name AS source_name,
    COALESCE(NULLIF(c.trade_name, ''), c.legal_name, 'Cliente') AS destination_name,
    (
        SELECT COALESCE(SUM(srl.requested_weight_kg), 0)
        FROM sales_request_lines srl
        WHERE srl.sales_order_id = so.id
    ) AS requested_kg,
    (
        SELECT COALESCE(SUM(idl.quantity_kg), 0)
        FROM sales_order_lines sol
        INNER JOIN inventory_document_lines idl ON idl.stock_unit_id = sol.stock_unit_id
        INNER JOIN inventory_documents idoc ON idoc.id = idl.inventory_document_id
        WHERE sol.sales_order_id = so.id
          AND sol.status = 'dispatched'
          AND idoc.status = 'confirmed'
          AND idoc.movement_type = 'sale_dispatch'
          AND idoc.source_document_type = 'sales_order'
          AND idoc.source_document_id = so.id
          AND NOT EXISTS (
              SELECT 1
              FROM inventory_documents reversal
              WHERE reversal.reversal_of_id = idoc.id
          )
    ) AS covered_kg,
    (
        SELECT COUNT(*)
        FROM sales_order_lines sol
        WHERE sol.sales_order_id = so.id
          AND sol.status = 'dispatched'
    ) AS unit_count,
    (
        SELECT COUNT(*)
        FROM sales_request_proposals srp
        WHERE srp.sales_order_id = so.id
    ) AS proposed_units,
    (
        SELECT COUNT(*)
        FROM sales_request_lines srl
        WHERE srl.sales_order_id = so.id
    ) AS line_count
FROM sales_orders so
INNER JOIN warehouses w ON w.id = so.source_warehouse_id
INNER JOIN companies c ON c.id = so.customer_company_id
WHERE so.company_id = ?
  AND so.site_id = ?
  AND so.source_warehouse_id = ?
  AND EXISTS (
      SELECT 1
      FROM sales_request_lines sx
      WHERE sx.sales_order_id = so.id
  )
ORDER BY so.created_at DESC, so.id DESC
LIMIT 1000
SQL,
                [$companyId, $siteId, $warehouseId]
            )->getResultArray();
        }

        $rows = [];

        foreach ($warehouseRows as $row) {
            $status = (string) $row['status'];
            [$statusGroup, $statusLabel] = match ($status) {
                'open' => ['pending', 'Pendiente de aceptación'],
                'accepted' => ['accepted', 'Aceptada'],
                'preparing' => ['in_supply', 'En preparación'],
                'dispatching' => ['in_supply', 'Despacho abierto'],
                'dispatched' => ['in_supply', 'Despacho finalizado'],
                'closed' => ['closed', 'Cerrada'],
                'rejected' => ['rejected', 'Rechazada'],
                default => ['pending', $status],
            };

            $type = (string) $row['request_type'];
            $typeLabel = $type === 'production' ? 'Materia prima' : 'Traspaso interno';
            $concept = $type === 'production' ? 'Materia prima para producción' : 'Traspaso entre almacenes';
            $role = (int) $row['source_warehouse_id'] === $warehouseId ? 'provider' : 'receiver';
            $requested = round((float) $row['requested_kg'], 3);
            $covered = round((float) $row['covered_kg'], 3);

            $rows[] = [
                'source' => 'warehouse',
                'id' => (int) $row['id'],
                'folio' => (string) $row['folio'],
                'request_type' => $type,
                'type_label' => $typeLabel,
                'concept' => $concept,
                'status' => $status,
                'status_group' => $statusGroup,
                'status_label' => $statusLabel,
                'role' => $role,
                'source_name' => (string) $row['source_name'],
                'destination_name' => (string) $row['destination_name'],
                'required_at' => (string) $row['required_at'],
                'created_at' => (string) $row['created_at'],
                'observations' => (string) ($row['observations'] ?? ''),
                'requested_kg' => $requested,
                'covered_kg' => $covered,
                'coverage_pct' => $requested > 0 ? round(($covered / $requested) * 100, 1) : 0.0,
                'unit_count' => (int) $row['unit_count'],
                'proposed_units' => 0,
                'line_count' => (int) $row['line_count'],
            ];
        }

        foreach ($saleRows as $row) {
            $status = (string) $row['status'];
            [$statusGroup, $statusLabel] = match ($status) {
                'draft' => ['pending', 'Pendiente de surtido'],
                'allocated' => ['in_supply', 'En surtido'],
                'dispatched' => ['closed', 'Despachada'],
                'canceled' => ['rejected', 'Cancelada'],
                default => ['pending', $status],
            };

            $requested = round((float) $row['requested_kg'], 3);
            $covered = round((float) $row['covered_kg'], 3);

            $rows[] = [
                'source' => 'sale',
                'id' => (int) $row['id'],
                'folio' => (string) $row['folio'],
                'request_type' => 'sale',
                'type_label' => 'Venta a cliente',
                'concept' => 'Venta a cliente',
                'status' => $status,
                'status_group' => $statusGroup,
                'status_label' => $statusLabel,
                'role' => 'provider',
                'source_name' => (string) $row['source_name'],
                'destination_name' => 'Cliente: ' . (string) $row['destination_name'],
                'required_at' => (string) $row['required_at'],
                'created_at' => (string) $row['created_at'],
                'observations' => (string) ($row['observations'] ?? ''),
                'requested_kg' => $requested,
                'covered_kg' => $covered,
                'coverage_pct' => $requested > 0 ? round(($covered / $requested) * 100, 1) : 0.0,
                'unit_count' => (int) $row['unit_count'],
                'proposed_units' => (int) ($row['proposed_units'] ?? 0),
                'line_count' => (int) $row['line_count'],
            ];
        }

        $search = mb_strtolower(mb_substr(trim((string) ($filters['search'] ?? '')), 0, 100));
        $statusFilter = trim((string) ($filters['status'] ?? ''));
        $roleFilter = trim((string) ($filters['role'] ?? ''));
        $typeFilter = trim((string) ($filters['type'] ?? ''));
        $from = trim((string) ($filters['date_from'] ?? ''));
        $to = trim((string) ($filters['date_to'] ?? ''));

        $validDate = static fn (string $value): bool =>
            $value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        RequestPolicy::require($validDate($from) && $validDate($to), 'El rango de fechas no es válido.', 'INVALID_HISTORY_DATE_RANGE');

        $rows = array_values(array_filter($rows, static function (array $row) use (
            $search,
            $statusFilter,
            $roleFilter,
            $typeFilter,
            $from,
            $to
        ): bool {
            if ($statusFilter !== '' && $row['status_group'] !== $statusFilter) {
                return false;
            }
            if ($roleFilter !== '' && $row['role'] !== $roleFilter) {
                return false;
            }
            if ($typeFilter !== '' && $row['request_type'] !== $typeFilter) {
                return false;
            }

            $requiredDate = substr((string) $row['required_at'], 0, 10);
            if ($from !== '' && $requiredDate < $from) {
                return false;
            }
            if ($to !== '' && $requiredDate > $to) {
                return false;
            }

            if ($search !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $row['folio'],
                    $row['type_label'],
                    $row['concept'],
                    $row['source_name'],
                    $row['destination_name'],
                    $row['observations'],
                    $row['status_label'],
                ]));

                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        }));

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp((string) $b['created_at'], (string) $a['created_at']);
            return $cmp !== 0 ? $cmp : ((int) $b['id'] <=> (int) $a['id']);
        });

        $summary = [
            'total' => count($rows),
            'pending' => 0,
            'accepted' => 0,
            'in_supply' => 0,
            'closed' => 0,
            'rejected' => 0,
        ];

        foreach ($rows as $row) {
            if (isset($summary[$row['status_group']])) {
                $summary[$row['status_group']]++;
            }
        }

        $perPage = (int) ($filters['per_page'] ?? 10);
        if (! in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 10;
        }

        $pages = max(1, (int) ceil(max(1, $summary['total']) / $perPage));
        $page = max(1, min($pages, (int) ($filters['page'] ?? 1)));
        $offset = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($rows, $offset, $perPage),
            'summary' => $summary,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $summary['total'],
                'pages' => $pages,
            ],
        ];
    }

    public function detail(int $salesOrderId): array
    {
        $scope = service('scopeContext')->restrictions();
        $order = $this->db->table('sales_orders so')
            ->select('so.*, c.trade_name AS customer_name, w.name AS source_warehouse_name')
            ->join('companies c', 'c.id=so.customer_company_id')
            ->join('warehouses w', 'w.id=so.source_warehouse_id', 'left')
            ->where('so.id', $salesOrderId)
            ->get()
            ->getRowArray();

        RequestPolicy::require($order !== null, 'Solicitud de venta no encontrada.', 'SALE_REQUEST_NOT_FOUND');
        $this->assertScope($order, $scope, false);

        $lines = $this->db->table('sales_request_lines srl')
            ->select('srl.*, s.name AS species_name, sg.name AS size_name, l.code AS lot_code, o.trade_name AS owner_name')
            ->join('species s', 's.id=srl.species_id')
            ->join('size_grades sg', 'sg.id=srl.size_grade_id')
            ->join('lots l', 'l.id=srl.lot_id', 'left')
            ->join('companies o', 'o.id=srl.owner_company_id', 'left')
            ->where('srl.sales_order_id', $salesOrderId)
            ->orderBy('srl.line_number')
            ->get()
            ->getResultArray();

        RequestPolicy::require($lines !== [], 'La orden no corresponde a una solicitud integrada de venta.', 'SALE_REQUEST_NOT_INTEGRATED');

        $proposals = $this->db->table('sales_request_proposals p')
            ->select(
                'p.id,p.sales_order_id,p.request_line_id,p.stock_unit_id,p.proposed_weight_kg,p.created_at,' .
                'su.unit_code,su.on_hand_weight_kg,su.reserved_weight_kg,su.status,' .
                'tb.code AS tub_code,l.code AS lot_code,o.trade_name AS owner_name,loc.path AS location_path'
            )
            ->join('stock_units su', 'su.id=p.stock_unit_id')
            ->join('tubs tb', 'tb.id=su.tub_id')
            ->join('lots l', 'l.id=su.lot_id')
            ->join('companies o', 'o.id=su.owner_company_id', 'left')
            ->join('locations loc', 'loc.id=su.location_id', 'left')
            ->where('p.sales_order_id', $salesOrderId)
            ->orderBy('p.request_line_id')
            ->orderBy('p.id')
            ->get()
            ->getResultArray();

        foreach ($proposals as &$proposal) {
            $proposal['available_now'] = $proposal['status'] === 'available'
                && (float) $proposal['reserved_weight_kg'] <= 0.0001
                && (float) $proposal['on_hand_weight_kg'] > 0;
        }
        unset($proposal);

        $allocations = $this->db->table('sales_order_lines sol')
            ->select(
                'sol.*,su.unit_code,su.on_hand_weight_kg,su.reserved_weight_kg,su.status AS stock_status,' .
                'tb.code AS tub_code,l.code AS lot_code,o.trade_name AS owner_name,loc.path AS location_path,' .
                '(SELECT idl.quantity_kg FROM inventory_documents idoc ' .
                ' JOIN inventory_document_lines idl ON idl.inventory_document_id=idoc.id ' .
                ' WHERE idoc.status=\'confirmed\' AND idoc.movement_type=\'sale_dispatch\' ' .
                '   AND idoc.source_document_type=\'sales_order\' AND idoc.source_document_id=sol.sales_order_id ' .
                '   AND idl.stock_unit_id=sol.stock_unit_id ORDER BY idoc.id DESC LIMIT 1) AS outbound_weight_kg'
            )
            ->join('stock_units su', 'su.id=sol.stock_unit_id')
            ->join('tubs tb', 'tb.id=su.tub_id')
            ->join('lots l', 'l.id=su.lot_id')
            ->join('companies o', 'o.id=su.owner_company_id', 'left')
            ->join('locations loc', 'loc.id=su.location_id', 'left')
            ->where('sol.sales_order_id', $salesOrderId)
            ->orderBy('sol.line_number')
            ->get()
            ->getResultArray();

        return [
            'order' => $order,
            'lines' => $lines,
            'proposals' => $proposals,
            'allocations' => $allocations,
            'closure' => $this->saleClosureState($salesOrderId, $order, $lines),
        ];
    }

    /**
     * Determina si una venta puede cerrarse físicamente aunque los kg reales
     * no sean exactamente iguales a los solicitados.
     *
     * Reglas:
     * - con tinas seleccionadas/reservadas pendientes: no se cierra;
     * - si la línea ya alcanzó/superó los kg: puede cerrarse;
     * - si hubo propuesta de tinas: puede cerrarse cuando ya salió al menos
     *   el número de tinas propuesto para esa línea;
     * - sin propuesta: puede cerrarse cuando el peso actual ya es la opción
     *   más cercana al solicitado usando tinas completas, o cuando ya no
     *   existe una tina compatible disponible.
     *
     * No se fracciona una tina para completar diferencias pequeñas.
     */
    private function saleClosureState(int $salesOrderId, ?array $order = null, ?array $requestLines = null): array
    {
        $order ??= $this->db->table('sales_orders')->where('id', $salesOrderId)->get()->getRowArray();

        if ($order === null) {
            return [
                'can_finalize' => false,
                'requires_reason' => false,
                'reason_code' => 'sale_not_found',
                'message' => 'Solicitud de venta no encontrada.',
                'requested_kg' => 0.0,
                'dispatched_kg' => 0.0,
                'difference_kg' => 0.0,
                'coverage_pct' => 0.0,
                'dispatched_units' => 0,
                'proposed_units' => 0,
                'pending_units' => 0,
                'lines' => [],
            ];
        }

        $requestLines ??= $this->db->table('sales_request_lines')
            ->where('sales_order_id', $salesOrderId)
            ->orderBy('line_number')
            ->get()
            ->getResultArray();

        $dispatchRows = $this->db->query(<<<'SQL'
SELECT
    sol.request_line_id,
    COUNT(DISTINCT sol.id) AS dispatched_units,
    COALESCE(SUM(idl.quantity_kg),0) AS dispatched_kg
FROM sales_order_lines sol
INNER JOIN inventory_document_lines idl ON idl.stock_unit_id=sol.stock_unit_id
INNER JOIN inventory_documents idoc ON idoc.id=idl.inventory_document_id
WHERE sol.sales_order_id=?
  AND sol.status='dispatched'
  AND idoc.status='confirmed'
  AND idoc.movement_type='sale_dispatch'
  AND idoc.source_document_type='sales_order'
  AND idoc.source_document_id=?
  AND NOT EXISTS (
      SELECT 1
      FROM inventory_documents reversal
      WHERE reversal.reversal_of_id=idoc.id
  )
GROUP BY sol.request_line_id
SQL, [$salesOrderId, $salesOrderId])->getResultArray();

        $dispatchByLine = [];
        foreach ($dispatchRows as $row) {
            $dispatchByLine[(int) $row['request_line_id']] = [
                'units' => (int) $row['dispatched_units'],
                'kg' => round((float) $row['dispatched_kg'], 3),
            ];
        }

        $proposalRows = $this->db->query(
            'SELECT request_line_id,COUNT(*) AS units
             FROM sales_request_proposals
             WHERE sales_order_id=?
             GROUP BY request_line_id',
            [$salesOrderId]
        )->getResultArray();
        $proposalsByLine = [];
        foreach ($proposalRows as $row) {
            $proposalsByLine[(int) $row['request_line_id']] = (int) $row['units'];
        }

        $pendingRows = $this->db->query(
            "SELECT request_line_id,COUNT(*) AS units
             FROM sales_order_lines
             WHERE sales_order_id=?
               AND status IN ('selected','reserved')
             GROUP BY request_line_id",
            [$salesOrderId]
        )->getResultArray();
        $pendingByLine = [];
        foreach ($pendingRows as $row) {
            $pendingByLine[(int) $row['request_line_id']] = (int) $row['units'];
        }

        $canFinalize = in_array((string) $order['status'], ['draft','allocated'], true);
        $requiresReason = false;
        $requestedTotal = 0.0;
        $dispatchedTotal = 0.0;
        $dispatchedUnitsTotal = 0;
        $proposedUnitsTotal = 0;
        $pendingUnitsTotal = 0;
        $lineStates = [];

        foreach ($requestLines as $line) {
            $lineId = (int) $line['id'];
            $requested = round((float) $line['requested_weight_kg'], 3);
            $requestedUnits = $line['requested_unit_count'] === null
                ? 0
                : max(0, (int) $line['requested_unit_count']);
            $actual = round((float) ($dispatchByLine[$lineId]['kg'] ?? 0.0), 3);
            $dispatchedUnits = (int) ($dispatchByLine[$lineId]['units'] ?? 0);
            $proposedUnits = (int) ($proposalsByLine[$lineId] ?? 0);
            $pendingUnits = (int) ($pendingByLine[$lineId] ?? 0);
            $shortage = round(max(0.0, $requested - $actual), 3);

            $requestedTotal += $requested;
            $dispatchedTotal += $actual;
            $dispatchedUnitsTotal += $dispatchedUnits;
            $proposedUnitsTotal += $proposedUnits;
            $pendingUnitsTotal += $pendingUnits;

            $lineCanFinalize = true;
            $lineRule = 'target_reached';
            $bestNextWeight = null;
            $bestNextDifference = null;

            if ($pendingUnits > 0) {
                $lineCanFinalize = false;
                $lineRule = 'pending_selected_units';
            } elseif ($dispatchedUnits === 0 || $actual <= 0.0001) {
                $lineCanFinalize = false;
                $lineRule = 'no_dispatched_units';
            } elseif ($requestedUnits > 0) {
                // Cuando la solicitud especifica número de tinas, ese número es
                // el criterio operativo de cierre. El peso real puede variar
                // porque las tinas son indivisibles.
                if ($dispatchedUnits >= $requestedUnits) {
                    $lineRule = 'requested_unit_count_reached';
                    if ($actual + 0.0001 < $requested) {
                        $requiresReason = true;
                    }
                } else {
                    $lineCanFinalize = false;
                    $lineRule = 'requested_units_pending';
                }
            } elseif ($actual + 0.0001 >= $requested) {
                $lineRule = 'target_reached';
            } elseif ($proposedUnits > 0) {
                if ($dispatchedUnits >= $proposedUnits) {
                    $lineRule = 'all_proposed_units_dispatched';
                    $requiresReason = true;
                } else {
                    $lineCanFinalize = false;
                    $lineRule = 'proposed_units_pending';
                }
            } else {
                $candidate = $this->db->query(
                    <<<'SQL'
SELECT
    su.on_hand_weight_kg,
    ABS((? + su.on_hand_weight_kg) - ?) AS resulting_difference
FROM stock_units su
WHERE su.company_id=?
  AND su.site_id=?
  AND su.warehouse_id=?
  AND su.status='available'
  AND su.reserved_weight_kg <= 0.0001
  AND su.on_hand_weight_kg > 0
  AND su.species_id=?
  AND su.size_grade_id=?
  AND (? IS NULL OR su.lot_id=?)
  AND (? IS NULL OR su.owner_company_id=?)
ORDER BY resulting_difference ASC, su.received_at ASC, su.id ASC
LIMIT 1
SQL,
                    [
                        $actual,
                        $requested,
                        (int) $order['company_id'],
                        (int) $order['site_id'],
                        (int) $order['source_warehouse_id'],
                        (int) $line['species_id'],
                        (int) $line['size_grade_id'],
                        empty($line['lot_id']) ? null : (int) $line['lot_id'],
                        empty($line['lot_id']) ? null : (int) $line['lot_id'],
                        empty($line['owner_company_id']) ? null : (int) $line['owner_company_id'],
                        empty($line['owner_company_id']) ? null : (int) $line['owner_company_id'],
                    ]
                )->getRowArray();

                if ($candidate === null) {
                    // No queda otra tina compatible disponible: permite documentar
                    // el faltante y concluir la salida.
                    $lineRule = 'no_compatible_stock_available';
                    $requiresReason = true;
                } else {
                    $bestNextWeight = round((float) $candidate['on_hand_weight_kg'], 3);
                    $bestNextDifference = round((float) $candidate['resulting_difference'], 3);

                    // "Acercarse al peso" significa que detenerse ahora deja una
                    // diferencia igual o menor que agregar la mejor tina completa
                    // disponible. Así evitamos porcentajes arbitrarios.
                    if ($shortage <= $bestNextDifference + 0.0005) {
                        $lineRule = 'closest_whole_tub_result';
                        $requiresReason = true;
                    } else {
                        $lineCanFinalize = false;
                        $lineRule = 'better_compatible_tub_available';
                    }
                }
            }

            if ($shortage > 0.0005 && $lineCanFinalize) {
                $requiresReason = true;
            }
            if (! $lineCanFinalize) {
                $canFinalize = false;
            }

            $lineStates[] = [
                'request_line_id' => $lineId,
                'line_number' => (int) $line['line_number'],
                'requested_kg' => $requested,
                'dispatched_kg' => $actual,
                'difference_kg' => round($actual - $requested, 3),
                'coverage_pct' => $requested > 0 ? round(($actual / $requested) * 100, 1) : 0.0,
                'requested_units' => $requestedUnits,
                'dispatched_units' => $dispatchedUnits,
                'proposed_units' => $proposedUnits,
                'pending_units' => $pendingUnits,
                'can_finalize' => $lineCanFinalize,
                'rule' => $lineRule,
                'best_next_weight_kg' => $bestNextWeight,
                'best_next_difference_kg' => $bestNextDifference,
            ];
        }

        if ((string) $order['status'] === 'dispatched') {
            $canFinalize = false;
            $reasonCode = 'already_dispatched';
            $message = 'La venta ya está finalizada.';
        } elseif ($requestLines === []) {
            $canFinalize = false;
            $reasonCode = 'no_request_lines';
            $message = 'La venta no contiene líneas para finalizar.';
        } elseif ($canFinalize) {
            $reasonCode = $requiresReason ? 'finalizable_with_difference' : 'target_reached';

            $hasUnitTarget = false;
            foreach ($lineStates as $lineState) {
                if ((int) ($lineState['requested_units'] ?? 0) > 0) {
                    $hasUnitTarget = true;
                    break;
                }
            }

            if ($hasUnitTarget) {
                $message = $requiresReason
                    ? 'Ya se despachó el número de tinas solicitado. Puedes finalizar documentando la diferencia de peso.'
                    : 'Ya se despachó el número de tinas solicitado. Puedes finalizar la salida.';
            } else {
                $message = $requiresReason
                    ? 'La salida puede finalizarse con diferencia documentada porque las tinas son indivisibles.'
                    : 'La salida alcanzó el peso solicitado y puede finalizarse.';
            }
        } else {
            $reasonCode = 'supply_still_pending';
            $message = 'La salida aún no cumple el criterio de cierre: faltan tinas comprometidas o existe una tina completa compatible que acerca más el peso solicitado.';
        }

        $requestedTotal = round($requestedTotal, 3);
        $dispatchedTotal = round($dispatchedTotal, 3);

        return [
            'can_finalize' => $canFinalize,
            'requires_reason' => $requiresReason,
            'reason_code' => $reasonCode,
            'message' => $message,
            'requested_kg' => $requestedTotal,
            'dispatched_kg' => $dispatchedTotal,
            'difference_kg' => round($dispatchedTotal - $requestedTotal, 3),
            'coverage_pct' => $requestedTotal > 0 ? round(($dispatchedTotal / $requestedTotal) * 100, 1) : 0.0,
            'dispatched_units' => $dispatchedUnitsTotal,
            'proposed_units' => $proposedUnitsTotal,
            'pending_units' => $pendingUnitsTotal,
            'lines' => $lineStates,
        ];
    }

    public function availability(int $salesOrderId, int $requestLineId, array $filters = []): array
    {
        $detail = $this->detail($salesOrderId);
        $order = $detail['order'];
        RequestPolicy::require(
            $order['status'] === 'draft',
            'La solicitud ya no está pendiente de surtido.',
            'SALE_REQUEST_NOT_PREPARABLE'
        );

        $line = null;
        foreach ($detail['lines'] as $candidate) {
            if ((int) $candidate['id'] === $requestLineId) {
                $line = $candidate;
                break;
            }
        }
        RequestPolicy::require($line !== null, 'Línea de solicitud no encontrada.', 'SALE_REQUEST_LINE_NOT_FOUND');

        $warehouseId = (int) ($order['source_warehouse_id'] ?? 0);
        RequestPolicy::require($warehouseId > 0, 'La solicitud no tiene almacén de surtido definido.', 'SALE_SOURCE_WAREHOUSE_REQUIRED');

        $builder = $this->db->table('stock_units su')
            ->select(
                'su.id,su.unit_code,su.on_hand_weight_kg,su.received_at,su.lot_id,su.owner_company_id,' .
                'tb.code AS tub_code,l.code AS lot_code,o.trade_name AS owner_name,loc.path AS location_path'
            )
            ->join('tubs tb', 'tb.id=su.tub_id')
            ->join('lots l', 'l.id=su.lot_id')
            ->join('companies o', 'o.id=su.owner_company_id', 'left')
            ->join('locations loc', 'loc.id=su.location_id', 'left')
            ->where([
                'su.company_id' => (int) $order['company_id'],
                'su.site_id' => (int) $order['site_id'],
                'su.warehouse_id' => $warehouseId,
                'su.species_id' => (int) $line['species_id'],
                'su.size_grade_id' => (int) $line['size_grade_id'],
                'su.status' => 'available',
            ])
            ->where('su.reserved_weight_kg <=', 0.0001)
            ->where('su.on_hand_weight_kg >', 0);

        if (! empty($line['lot_id'])) {
            $builder->where('su.lot_id', (int) $line['lot_id']);
        }
        if (! empty($line['owner_company_id'])) {
            $builder->where('su.owner_company_id', (int) $line['owner_company_id']);
        }

        $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 100);
        if ($search !== '') {
            $builder->groupStart()
                ->like('su.unit_code', $search)
                ->orLike('tb.code', $search)
                ->orLike('l.code', $search)
                ->orLike('o.trade_name', $search)
                ->groupEnd();
        }

        $rows = $builder
            ->orderBy('su.received_at', 'ASC')
            ->orderBy('su.id', 'ASC')
            ->limit(300)
            ->get()
            ->getResultArray();

        $proposalIds = [];
        foreach ($detail['proposals'] as $proposal) {
            if ((int) $proposal['request_line_id'] === $requestLineId) {
                $proposalIds[(int) $proposal['stock_unit_id']] = true;
            }
        }

        $totalKg = 0.0;
        foreach ($rows as &$row) {
            $row['is_proposed'] = isset($proposalIds[(int) $row['id']]);
            $totalKg += (float) $row['on_hand_weight_kg'];
        }
        unset($row);

        return [
            'line' => $line,
            'rows' => $rows,
            'totals' => ['units' => count($rows), 'kg' => round($totalKg, 3)],
        ];
    }

    public function prepare(int $salesOrderId, array $payload, int $actorUserId): array
    {
        $scope = service('scopeContext')->restrictions();
        RequestPolicy::require(
            ! empty($scope['configured']) && ! empty($scope['warehouse_id']),
            'Selecciona tu almacén en el alcance operativo.',
            'OPERATIONAL_SCOPE_REQUIRED'
        );

        $rawSelections = $payload['lines'] ?? null;
        RequestPolicy::require(is_array($rawSelections), 'Captura el surtido definitivo.', 'SALE_PREPARE_LINES_REQUIRED');

        $this->db->transException(true)->transBegin();

        try {
            $order = $this->db->query('SELECT * FROM sales_orders WHERE id=? FOR UPDATE', [$salesOrderId])->getRowArray();
            RequestPolicy::require($order !== null, 'Solicitud de venta no encontrada.', 'SALE_REQUEST_NOT_FOUND');
            $this->assertScope($order, $scope, true);
            RequestPolicy::require($order['status'] === 'draft', 'La solicitud ya fue preparada o despachada.', 'SALE_REQUEST_NOT_PREPARABLE');
            RequestPolicy::require(
                $this->db->table('sales_order_lines')->where('sales_order_id', $salesOrderId)->countAllResults() === 0,
                'La solicitud ya contiene un surtido definitivo.',
                'SALE_REQUEST_ALREADY_PREPARED'
            );

            $requestLines = $this->db->table('sales_request_lines')
                ->where('sales_order_id', $salesOrderId)
                ->orderBy('line_number')
                ->get()
                ->getResultArray();
            RequestPolicy::require($requestLines !== [], 'La orden no corresponde a una solicitud integrada.', 'SALE_REQUEST_NOT_INTEGRATED');

            $selectionMap = [];
            $seenStocks = [];

            foreach ($rawSelections as $selection) {
                RequestPolicy::require(is_array($selection), 'Detalle de surtido inválido.', 'SALE_PREPARE_LINE_INVALID');
                $lineId = (int) ($selection['request_line_id'] ?? 0);
                RequestPolicy::require($lineId > 0 && ! isset($selectionMap[$lineId]), 'Línea de surtido inválida o repetida.', 'SALE_PREPARE_LINE_DUPLICATE');

                $ids = array_values(array_unique(array_map(
                    'intval',
                    is_array($selection['stock_unit_ids'] ?? null) ? $selection['stock_unit_ids'] : []
                )));
                $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
                RequestPolicy::require($ids !== [], 'Selecciona al menos una tina definitiva para cada línea.', 'SALE_PREPARE_SELECTION_REQUIRED');

                foreach ($ids as $stockId) {
                    RequestPolicy::require(! isset($seenStocks[$stockId]), 'Una tina no puede surtir dos líneas.', 'SALE_PREPARE_DUPLICATE_STOCK');
                    $seenStocks[$stockId] = true;
                }

                $selectionMap[$lineId] = $ids;
            }

            foreach ($requestLines as $line) {
                RequestPolicy::require(
                    isset($selectionMap[(int) $line['id']]),
                    'Falta definir el surtido de la línea ' . $line['line_number'] . '.',
                    'SALE_PREPARE_LINE_MISSING'
                );
            }

            RequestPolicy::require(
                count($selectionMap) === count($requestLines),
                'El surtido contiene líneas que no pertenecen a la solicitud.',
                'SALE_PREPARE_UNKNOWN_LINE'
            );

            $stockIds = array_keys($seenStocks);
            sort($stockIds, SORT_NUMERIC);
            $marks = implode(',', array_fill(0, count($stockIds), '?'));
            $stocks = array_column(
                $this->db->query(
                    'SELECT * FROM stock_units WHERE id IN (' . $marks . ') ORDER BY id FOR UPDATE',
                    $stockIds
                )->getResultArray(),
                null,
                'id'
            );

            RequestPolicy::require(count($stocks) === count($stockIds), 'Una o más tinas ya no existen.', 'SALE_PREPARE_STOCK_NOT_FOUND');

            $warehouseId = (int) $order['source_warehouse_id'];
            $normalizedLines = array_map(static fn (array $line): array => [
                'id' => (int) $line['id'],
                'line_number' => (int) $line['line_number'],
                'species_id' => (int) $line['species_id'],
                'size_grade_id' => (int) $line['size_grade_id'],
                'lot_id' => empty($line['lot_id']) ? null : (int) $line['lot_id'],
                'owner_company_id' => empty($line['owner_company_id']) ? null : (int) $line['owner_company_id'],
                'requested_weight_kg' => (float) $line['requested_weight_kg'],
            ], $requestLines);

            $saleLineNumber = 0;
            $reservedKg = 0.0;
            $reservedUnits = 0;
            $requestedKg = 0.0;
            $now = date('Y-m-d H:i:s');

            foreach ($normalizedLines as $line) {
                $requestedKg += (float) $line['requested_weight_kg'];

                foreach ($selectionMap[$line['id']] as $stockId) {
                    $stock = $stocks[$stockId] ?? null;
                    RequestPolicy::require($stock !== null, 'Tina no encontrada.', 'SALE_PREPARE_STOCK_NOT_FOUND');
                    RequestPolicy::require(
                        (int) $stock['company_id'] === (int) $order['company_id']
                        && (int) $stock['site_id'] === (int) $order['site_id']
                        && (int) $stock['warehouse_id'] === $warehouseId,
                        'La tina no pertenece al almacén que debe surtir.',
                        'SALE_PREPARE_SCOPE_MISMATCH'
                    );
                    RequestPolicy::require(
                        $stock['status'] === 'available'
                        && (float) $stock['reserved_weight_kg'] <= 0.0001
                        && (float) $stock['on_hand_weight_kg'] > 0,
                        'Una tina ya no está disponible. Actualiza el surtido.',
                        'SALE_PREPARE_STOCK_NOT_AVAILABLE'
                    );
                    RequestPolicy::require(
                        $this->preferredRequestLineId($stock, $normalizedLines) === (int) $line['id'],
                        'La tina corresponde a otra línea más específica o a un cruce ambiguo de lote/propietario.',
                        'SALE_PREPARE_LINE_SCOPE_CONFLICT'
                    );

                    $quantity = round((float) $stock['on_hand_weight_kg'], 3);
                    $saleLineNumber++;
                    $reservedKg += $quantity;
                    $reservedUnits++;

                    $this->db->table('sales_order_lines')->insert([
                        'sales_order_id' => $salesOrderId,
                        'request_line_id' => $line['id'],
                        'line_number' => $saleLineNumber,
                        'stock_unit_id' => $stockId,
                        'quantity_kg' => $quantity,
                        'unit_price' => null,
                        'currency' => null,
                        'status' => 'selected',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $reservation = service('inventoryService')->reserveSaleOrder($salesOrderId, $warehouseId, $actorUserId);

            service('auditService')->record(
                'sale.request_prepared',
                'sales_order',
                $salesOrderId,
                $actorUserId,
                (int) $order['company_id'],
                (int) $order['site_id'],
                after: [
                    'source_warehouse_id' => $warehouseId,
                    'requested_kg' => round($requestedKg, 3),
                    'reserved_kg' => round($reservedKg, 3),
                    'reserved_units' => $reservedUnits,
                    'difference_kg' => round($reservedKg - $requestedKg, 3),
                ]
            );

            RequestPolicy::require(
                $this->db->transStatus() !== false,
                'No fue posible confirmar el surtido.',
                'SALE_PREPARE_TRANSACTION_FAILED'
            );

            $this->db->transCommit();

            return [
                'sales_order_id' => $salesOrderId,
                'status' => 'allocated',
                'requested_kg' => round($requestedKg, 3),
                'reserved_kg' => round($reservedKg, 3),
                'reserved_units' => $reservedUnits,
                'difference_kg' => round($reservedKg - $requestedKg, 3),
                'inventory_document_id' => $reservation['inventory_document_id'] ?? null,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function assertScope(array $order, array $scope, bool $requireWarehouse): void
    {
        RequestPolicy::require(
            (int) $order['company_id'] === (int) ($scope['company_id'] ?? 0)
            && (int) $order['site_id'] === (int) ($scope['site_id'] ?? 0),
            'La solicitud de venta no corresponde al alcance actual.',
            'SALE_REQUEST_SCOPE_MISMATCH'
        );

        if ($requireWarehouse || ! empty($order['source_warehouse_id'])) {
            RequestPolicy::require(
                ! empty($scope['warehouse_id'])
                && (int) $order['source_warehouse_id'] === (int) $scope['warehouse_id'],
                'La solicitud corresponde a otro almacén de surtido.',
                'SALE_REQUEST_WAREHOUSE_MISMATCH'
            );
        }
    }

    private function lineMatchesStock(array $stock, array $line): bool
    {
        return (int) $stock['species_id'] === (int) $line['species_id']
            && (int) $stock['size_grade_id'] === (int) $line['size_grade_id']
            && (empty($line['lot_id']) || (int) $stock['lot_id'] === (int) $line['lot_id'])
            && (empty($line['owner_company_id']) || (int) $stock['owner_company_id'] === (int) $line['owner_company_id']);
    }

    private function lineSpecificity(array $line): int
    {
        return (! empty($line['lot_id']) ? 1 : 0) + (! empty($line['owner_company_id']) ? 1 : 0);
    }

    private function preferredLineNumber(array $stock, array $lines): ?int
    {
        $matches = array_values(array_filter($lines, fn (array $line): bool => $this->lineMatchesStock($stock, $line)));
        if ($matches === []) {
            return null;
        }

        $max = max(array_map(fn (array $line): int => $this->lineSpecificity($line), $matches));
        $preferred = array_values(array_filter($matches, fn (array $line): bool => $this->lineSpecificity($line) === $max));

        return count($preferred) === 1 ? (int) $preferred[0]['line_number'] : null;
    }

    private function preferredRequestLineId(array $stock, array $lines): ?int
    {
        $matches = array_values(array_filter($lines, fn (array $line): bool => $this->lineMatchesStock($stock, $line)));
        if ($matches === []) {
            return null;
        }

        $max = max(array_map(fn (array $line): int => $this->lineSpecificity($line), $matches));
        $preferred = array_values(array_filter($matches, fn (array $line): bool => $this->lineSpecificity($line) === $max));

        return count($preferred) === 1 ? (int) $preferred[0]['id'] : null;
    }


    public function shipUnit(int $salesOrderId, array $payload, int $actorUserId): array
    {
        $scope = service('scopeContext')->restrictions();
        RequestPolicy::require(
            ! empty($scope['configured']) && ! empty($scope['company_id']) && ! empty($scope['site_id']) && ! empty($scope['warehouse_id']),
            'Selecciona empresa, instalación y almacén en tu alcance operativo.',
            'OPERATIONAL_SCOPE_REQUIRED'
        );

        $requestLineId = (int) ($payload['request_line_id'] ?? 0);
        $stockId = (int) ($payload['stock_unit_id'] ?? 0);
        RequestPolicy::require($requestLineId > 0 && $stockId > 0, 'La tina y la línea de venta son obligatorias.', 'SALE_OUTGOING_SELECTION_REQUIRED');

        $this->db->transException(true)->transBegin();

        try {
            $order = $this->db->query('SELECT * FROM sales_orders WHERE id=? FOR UPDATE', [$salesOrderId])->getRowArray();
            RequestPolicy::require($order !== null, 'Solicitud de venta no encontrada.', 'SALE_REQUEST_NOT_FOUND');
            $this->assertScope($order, $scope, true);
            RequestPolicy::require(
                in_array((string) $order['status'], ['draft','allocated'], true),
                'La solicitud de venta ya no está disponible para salida.',
                'SALE_REQUEST_NOT_DISPATCHABLE'
            );

            $requestLines = $this->db->table('sales_request_lines')
                ->where('sales_order_id', $salesOrderId)
                ->orderBy('line_number')
                ->get()
                ->getResultArray();
            RequestPolicy::require($requestLines !== [], 'La venta no contiene líneas integradas.', 'SALE_REQUEST_NOT_INTEGRATED');

            $requestLine = null;
            foreach ($requestLines as $candidate) {
                if ((int) $candidate['id'] === $requestLineId) {
                    $requestLine = $candidate;
                    break;
                }
            }
            RequestPolicy::require($requestLine !== null, 'La línea no pertenece a esta venta.', 'SALE_REQUEST_LINE_NOT_FOUND');

            $stock = $this->db->query('SELECT * FROM stock_units WHERE id=? FOR UPDATE', [$stockId])->getRowArray();
            RequestPolicy::require($stock !== null, 'Tina no encontrada.', 'SALE_OUTGOING_STOCK_NOT_FOUND');
            RequestPolicy::require(
                (int) $stock['company_id'] === (int) $order['company_id']
                && (int) $stock['site_id'] === (int) $order['site_id']
                && (int) $stock['warehouse_id'] === (int) $order['source_warehouse_id'],
                'La tina no pertenece al almacén que surte la venta.',
                'SALE_OUTGOING_SCOPE_MISMATCH'
            );
            RequestPolicy::require(
                $this->preferredRequestLineId($stock, $requestLines) === $requestLineId,
                'La tina corresponde a otra línea más específica o a un cruce ambiguo de lote/propietario.',
                'SALE_OUTGOING_LINE_SCOPE_CONFLICT'
            );

            $this->verifyOutgoingCode($stockId, (string) ($payload['code'] ?? ''));
            $placement = $this->validateOutgoingLocation(
                $stock,
                (int) ($payload['chamber_id'] ?? 0),
                (int) ($payload['row_location_id'] ?? 0),
                (int) $order['source_warehouse_id']
            );

            $saleLine = $this->db->query(
                'SELECT * FROM sales_order_lines WHERE sales_order_id=? AND stock_unit_id=? FOR UPDATE',
                [$salesOrderId, $stockId]
            )->getRowArray();

            if ($saleLine !== null) {
                if ((string) $saleLine['status'] === 'released') {
                    // La tina ya había salido en esta misma venta y posteriormente
                    // se canceló ese despacho. La línea se conserva por auditoría
                    // y por la restricción única (venta + stock_unit), por lo que
                    // NO debemos crear otra línea: reactivamos la existente.
                    RequestPolicy::require(
                        (string) $stock['status'] === 'available'
                        && (float) $stock['reserved_weight_kg'] <= 0.0001
                        && (float) $stock['on_hand_weight_kg'] > 0,
                        'La tina cancelada todavía no está disponible para reprocesarse.',
                        'SALE_OUTGOING_RELEASED_UNIT_NOT_AVAILABLE'
                    );

                    $otherWarehouse = $this->db->query(<<<'SQL'
SELECT r.folio
FROM warehouse_request_units u
JOIN warehouse_requests r ON r.id=u.request_id
WHERE u.active_stock_id=?
  AND u.status='selected'
  AND r.supply_finalized_at IS NULL
  AND r.status IN ('accepted','preparing','dispatching')
LIMIT 1
SQL,[$stockId])->getRowArray();

                    if ($otherWarehouse !== null) {
                        RequestPolicy::require(
                            false,
                            'La tina fue asignada a '.$otherWarehouse['folio'].' después de cancelar el despacho.',
                            'SALE_OUTGOING_ASSIGNMENT_CONFLICT'
                        );
                    }

                    $otherSale = $this->db->query(<<<'SQL'
SELECT so.folio
FROM sales_order_lines sol
JOIN sales_orders so ON so.id=sol.sales_order_id
WHERE sol.stock_unit_id=?
  AND sol.sales_order_id<>?
  AND sol.status IN ('selected','reserved')
  AND so.status IN ('draft','allocated')
LIMIT 1
SQL,[$stockId,$salesOrderId])->getRowArray();

                    if ($otherSale !== null) {
                        RequestPolicy::require(
                            false,
                            'La tina fue asignada a '.$otherSale['folio'].' después de cancelar el despacho.',
                            'SALE_OUTGOING_ASSIGNMENT_CONFLICT'
                        );
                    }

                    $now = date('Y-m-d H:i:s');

                    $this->db->table('sales_order_lines')
                        ->where('id', (int) $saleLine['id'])
                        ->where('status', 'released')
                        ->update([
                            'request_line_id' => $requestLineId,
                            'quantity_kg' => round((float) $stock['on_hand_weight_kg'], 3),
                            'status' => 'selected',
                            'updated_at' => $now,
                        ]);

                    RequestPolicy::require(
                        $this->db->affectedRows() === 1,
                        'La tina cambió mientras se intentaba reprocesar. Recarga la estación.',
                        'SALE_OUTGOING_RESELECT_CONCURRENT_MODIFICATION'
                    );

                    service('auditService')->record(
                        'sale.request_unit_reselected_after_cancel',
                        'sales_order',
                        $salesOrderId,
                        $actorUserId,
                        (int) $order['company_id'],
                        (int) $order['site_id'],
                        after: [
                            'sales_order_line_id' => (int) $saleLine['id'],
                            'request_line_id' => $requestLineId,
                            'stock_unit_id' => $stockId,
                            'previous_status' => 'released',
                            'new_status' => 'selected',
                            'restored_inventory_kg' => round((float) $stock['on_hand_weight_kg'], 3),
                        ]
                    );

                    $saleLine = $this->db->query(
                        'SELECT * FROM sales_order_lines WHERE id=? FOR UPDATE',
                        [(int) $saleLine['id']]
                    )->getRowArray();
                } else {
                    RequestPolicy::require(
                        in_array((string) $saleLine['status'], ['selected','reserved'], true),
                        'La tina ya fue procesada en esta venta.',
                        'SALE_OUTGOING_UNIT_ALREADY_PROCESSED'
                    );
                    RequestPolicy::require(
                        (int) $saleLine['request_line_id'] === $requestLineId,
                        'La tina está seleccionada para otra línea de esta venta.',
                        'SALE_OUTGOING_LINE_MISMATCH'
                    );
                }
            } else {
                RequestPolicy::require(
                    (string) $stock['status'] === 'available'
                    && (float) $stock['reserved_weight_kg'] <= 0.0001
                    && (float) $stock['on_hand_weight_kg'] > 0,
                    'La tina cambió de estado o ya fue comprometida.',
                    'SALE_OUTGOING_STOCK_NOT_AVAILABLE'
                );

                $otherWarehouse = $this->db->query(<<<'SQL'
SELECT r.folio
FROM warehouse_request_units u
JOIN warehouse_requests r ON r.id=u.request_id
WHERE u.active_stock_id=?
  AND u.status='selected'
  AND r.supply_finalized_at IS NULL
  AND r.status IN ('accepted','preparing','dispatching')
LIMIT 1
SQL,[$stockId])->getRowArray();
                if ($otherWarehouse !== null) {
                    RequestPolicy::require(
                        false,
                        'La tina fue asignada a '.$otherWarehouse['folio'].' mientras se procesaba la salida.',
                        'SALE_OUTGOING_ASSIGNMENT_CONFLICT'
                    );
                }

                $otherSale = $this->db->query(<<<'SQL'
SELECT so.folio
FROM sales_order_lines sol
JOIN sales_orders so ON so.id=sol.sales_order_id
WHERE sol.stock_unit_id=?
  AND sol.sales_order_id<>?
  AND sol.status IN ('selected','reserved')
  AND so.status IN ('draft','allocated')
LIMIT 1
SQL,[$stockId,$salesOrderId])->getRowArray();
                if ($otherSale !== null) {
                    RequestPolicy::require(
                        false,
                        'La tina fue asignada a '.$otherSale['folio'].' mientras se procesaba la salida.',
                        'SALE_OUTGOING_ASSIGNMENT_CONFLICT'
                    );
                }

                $lineNumber=(int) $this->db->query(
                    'SELECT COALESCE(MAX(line_number),0)+1 AS n FROM sales_order_lines WHERE sales_order_id=?',
                    [$salesOrderId]
                )->getRow('n');

                $this->db->table('sales_order_lines')->insert([
                    'sales_order_id'=>$salesOrderId,
                    'request_line_id'=>$requestLineId,
                    'line_number'=>$lineNumber,
                    'stock_unit_id'=>$stockId,
                    'quantity_kg'=>round((float) $stock['on_hand_weight_kg'],3),
                    'unit_price'=>null,
                    'currency'=>null,
                    'status'=>'selected',
                    'created_at'=>date('Y-m-d H:i:s'),
                    'updated_at'=>date('Y-m-d H:i:s'),
                ]);

                $saleLine=$this->db->query(
                    'SELECT * FROM sales_order_lines WHERE id=? FOR UPDATE',
                    [(int) $this->db->insertID()]
                )->getRowArray();
            }

            $weight = $this->outgoingWeight($order, $stock, $payload, $actorUserId);

            $movement = service('inventoryService')->dispatchSaleRequestUnit(
                $salesOrderId,
                (int) $saleLine['id'],
                (float) $weight['weight_kg'],
                (int) $weight['scale_reading_id'],
                $actorUserId
            );

            $allComplete = true;
            foreach ($requestLines as $line) {
                $actual = (float) $this->db->query(<<<'SQL'
SELECT COALESCE(SUM(idl.quantity_kg),0) AS kg
FROM sales_order_lines sol
JOIN inventory_document_lines idl ON idl.stock_unit_id=sol.stock_unit_id
JOIN inventory_documents idoc ON idoc.id=idl.inventory_document_id
WHERE sol.sales_order_id=?
  AND sol.request_line_id=?
  AND sol.status='dispatched'
  AND idoc.status='confirmed'
  AND idoc.movement_type='sale_dispatch'
  AND idoc.source_document_type='sales_order'
  AND idoc.source_document_id=?
  AND NOT EXISTS (
      SELECT 1
      FROM inventory_documents reversal
      WHERE reversal.reversal_of_id=idoc.id
  )
SQL,[$salesOrderId,(int) $line['id'],$salesOrderId])->getRow('kg');

                if ($actual + 0.0001 < (float) $line['requested_weight_kg']) {
                    $allComplete = false;
                    break;
                }
            }

            $now = date('Y-m-d H:i:s');
            $orderUpdate = [
                'status'=>$allComplete?'dispatched':'allocated',
                'lock_version'=>(int) $order['lock_version']+1,
                'updated_at'=>$now,
            ];
            if ($allComplete) {
                $orderUpdate['dispatched_by']=$actorUserId;
                $orderUpdate['dispatched_at']=$now;
            }
            $this->db->table('sales_orders')->where('id',$salesOrderId)->update($orderUpdate);

            service('auditService')->record(
                'sale.request_unit_shipped',
                'sales_order',
                $salesOrderId,
                $actorUserId,
                (int) $order['company_id'],
                (int) $order['site_id'],
                after: [
                    'source_warehouse_id'=>(int) $order['source_warehouse_id'],
                    'sales_order_line_id'=>(int) $saleLine['id'],
                    'request_line_id'=>$requestLineId,
                    'stock_unit_id'=>$stockId,
                    'scale_reading_id'=>(int) $weight['scale_reading_id'],
                    'gross_weight_kg'=>(float) $weight['gross_weight_kg'],
                    'tare_weight_kg'=>(float) $weight['tare_weight_kg'],
                    'outbound_weight_kg'=>(float) $weight['weight_kg'],
                    'source_chamber_id'=>(int) $placement['chamber_id'],
                    'source_row_location_id'=>(int) $placement['row_location_id'],
                    'status'=>$allComplete?'dispatched':'allocated',
                ]
            );

            RequestPolicy::require(
                $this->db->transStatus() !== false,
                'No fue posible registrar la salida de venta.',
                'SALE_OUTGOING_TRANSACTION_FAILED'
            );

            $this->db->transCommit();

            return [
                'shipment'=>$movement+[
                    'request_line_id'=>$requestLineId,
                    'source_chamber_id'=>(int) $placement['chamber_id'],
                    'source_row_location_id'=>(int) $placement['row_location_id'],
                    'source_location_path'=>(string) $placement['path'],
                    'gross_weight_kg'=>(float) $weight['gross_weight_kg'],
                    'tare_weight_kg'=>(float) $weight['tare_weight_kg'],
                    'outbound_weight_kg'=>(float) $weight['weight_kg'],
                ],
                'detail'=>$this->detail($salesOrderId),
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function finalizeOutgoing(int $salesOrderId, array $payload, int $actorUserId): array
    {
        $scope = service('scopeContext')->restrictions();
        RequestPolicy::require(
            ! empty($scope['configured']) && ! empty($scope['company_id']) && ! empty($scope['site_id']) && ! empty($scope['warehouse_id']),
            'Selecciona empresa, instalación y almacén en tu alcance operativo.',
            'OPERATIONAL_SCOPE_REQUIRED'
        );

        $reason = trim((string) ($payload['reason'] ?? ''));
        $administrative = filter_var(
            $payload['administrative'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        if ($administrative) {
            RequestPolicy::require(
                function_exists('auth')
                && auth()->loggedIn()
                && auth()->user() !== null
                && auth()->user()->can('sales.manage'),
                'No tienes permiso para finalizar administrativamente una venta.',
                'SALE_ADMIN_FINALIZE_FORBIDDEN'
            );
        }

        $this->db->transException(true)->transBegin();

        try {
            $order = $this->db->query(
                'SELECT * FROM sales_orders WHERE id=? FOR UPDATE',
                [$salesOrderId]
            )->getRowArray();

            RequestPolicy::require($order !== null, 'Solicitud de venta no encontrada.', 'SALE_REQUEST_NOT_FOUND');
            $this->assertScope($order, $scope, true);
            RequestPolicy::require(
                in_array((string) $order['status'], ['draft','allocated'], true),
                'La salida de esta venta ya fue finalizada o cancelada.',
                'SALE_OUTGOING_NOT_FINALIZABLE'
            );

            $requestLines = $this->db->table('sales_request_lines')
                ->where('sales_order_id', $salesOrderId)
                ->orderBy('line_number')
                ->get()
                ->getResultArray();

            $closure = $this->saleClosureState($salesOrderId, $order, $requestLines);

            $releasedPendingUnits = 0;
            $releaseInventoryDocumentId = null;

            if ($administrative) {
                RequestPolicy::require(
                    (string) $order['status'] === 'allocated',
                    'La venta no está en un estado disponible para cierre administrativo.',
                    'SALE_ADMIN_FINALIZE_STATUS_INVALID'
                );
                RequestPolicy::require(
                    (int) ($closure['dispatched_units'] ?? 0) > 0
                    && (float) ($closure['dispatched_kg'] ?? 0) > 0.0001,
                    'Para finalizar administrativamente debe existir al menos una tina con peso de salida registrado.',
                    'SALE_ADMIN_FINALIZE_REQUIRES_DISPATCH'
                );
                RequestPolicy::require(
                    mb_strlen($reason) >= 5 && mb_strlen($reason) <= 1000,
                    'Finalizar administrativamente requiere un motivo de 5 a 1000 caracteres.',
                    'SALE_ADMIN_FINALIZE_REASON_REQUIRED'
                );

                // En estado normal, las selecciones preparadas ya están reservadas.
                // Si existiera una línea "selected" pendiente, se bloquea el cierre
                // para no alterar inventario en una condición inconsistente.
                $selectedPending = (int) $this->db->table('sales_order_lines')
                    ->where([
                        'sales_order_id' => $salesOrderId,
                        'status' => 'selected',
                    ])
                    ->countAllResults();

                RequestPolicy::require(
                    $selectedPending === 0,
                    'La venta contiene una selección pendiente sin reserva. Actualiza el surtido antes de finalizar.',
                    'SALE_ADMIN_FINALIZE_SELECTION_INCONSISTENT'
                );

                $reservedPending = (int) $this->db->table('sales_order_lines')
                    ->where([
                        'sales_order_id' => $salesOrderId,
                        'status' => 'reserved',
                    ])
                    ->countAllResults();

                if ($reservedPending > 0) {
                    // Finalizar significa que ya no habrá más salidas.
                    // Libera exclusivamente las reservas aún no despachadas;
                    // las tinas ya despachadas permanecen intactas.
                    $release = service('inventoryService')->releaseSaleReservation(
                        $salesOrderId,
                        (int) $order['source_warehouse_id'],
                        'Cierre administrativo: '.$reason,
                        $actorUserId
                    );

                    $releasedPendingUnits = count((array) ($release['lines'] ?? []));
                    $releaseInventoryDocumentId = $release['inventory_document_id'] ?? null;

                    // releaseSaleReservation marca temporalmente el encabezado como
                    // canceled; seguimos dentro de la misma transacción y lo
                    // convertimos en el cierre administrativo definitivo.
                    $order = $this->db->query(
                        'SELECT * FROM sales_orders WHERE id=? FOR UPDATE',
                        [$salesOrderId]
                    )->getRowArray();

                    RequestPolicy::require(
                        $order !== null && (string) $order['status'] === 'canceled',
                        'No fue posible conciliar la liberación de reservas antes de finalizar.',
                        'SALE_ADMIN_FINALIZE_RELEASE_FAILED'
                    );
                }
            } else {
                // Flujo operativo existente: SIN CAMBIOS.
                RequestPolicy::require(
                    ! empty($closure['can_finalize']),
                    (string) ($closure['message'] ?? 'La venta todavía tiene surtido pendiente.'),
                    'SALE_OUTGOING_FINALIZATION_NOT_READY',
                    ['closure' => $closure]
                );

                if (! empty($closure['requires_reason'])) {
                    RequestPolicy::require(
                        mb_strlen($reason) >= 5 && mb_strlen($reason) <= 1000,
                        'Finalizar con diferencia requiere un motivo de 5 a 1000 caracteres.',
                        'SALE_OUTGOING_FINALIZATION_REASON_REQUIRED'
                    );
                } elseif ($reason !== '') {
                    RequestPolicy::require(
                        mb_strlen($reason) <= 1000,
                        'El motivo no puede exceder 1000 caracteres.',
                        'SALE_OUTGOING_FINALIZATION_REASON_TOO_LONG'
                    );
                }
            }

            $now = date('Y-m-d H:i:s');
            $this->db->table('sales_orders')
                ->where([
                    'id' => $salesOrderId,
                    'lock_version' => (int) $order['lock_version'],
                ])
                ->update([
                    'status' => 'dispatched',
                    'dispatched_by' => $actorUserId,
                    'dispatched_at' => $now,
                    'lock_version' => (int) $order['lock_version'] + 1,
                    'updated_at' => $now,
                ]);

            RequestPolicy::require(
                $this->db->affectedRows() === 1,
                'La venta cambió mientras se finalizaba. Recarga la estación.',
                'SALE_OUTGOING_FINALIZATION_CONCURRENT_MODIFICATION'
            );

            service('auditService')->record(
                $administrative
                    ? 'sale.request_admin_finalized'
                    : 'sale.request_outgoing_finalized',
                'sales_order',
                $salesOrderId,
                $actorUserId,
                (int) $order['company_id'],
                (int) $order['site_id'],
                after: [
                    'source_warehouse_id' => (int) $order['source_warehouse_id'],
                    'requested_kg' => (float) $closure['requested_kg'],
                    'dispatched_kg' => (float) $closure['dispatched_kg'],
                    'difference_kg' => (float) $closure['difference_kg'],
                    'coverage_pct' => (float) $closure['coverage_pct'],
                    'dispatched_units' => (int) $closure['dispatched_units'],
                    'proposed_units' => (int) $closure['proposed_units'],
                    'finalization_reason_code' => $administrative
                        ? 'administrative_manual_close'
                        : (string) $closure['reason_code'],
                    'administrative' => $administrative,
                    'released_pending_units' => $releasedPendingUnits,
                    'release_inventory_document_id' => $releaseInventoryDocumentId,
                    'lines' => $closure['lines'],
                ],
                reason: $reason === '' ? null : $reason
            );

            RequestPolicy::require(
                $this->db->transStatus() !== false,
                'No fue posible finalizar la salida de venta.',
                'SALE_OUTGOING_FINALIZATION_FAILED'
            );

            $this->db->transCommit();

            return [
                'sales_order_id' => $salesOrderId,
                'status' => 'dispatched',
                'closure' => $closure,
                'detail' => $this->detail($salesOrderId),
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function verifyOutgoingCode(int $stockId, string $rawCode): void
    {
        $code = RequestPolicy::qr($rawCode);
        $row = $this->db->query(
            'SELECT su.id
             FROM stock_units su
             JOIN tubs tb ON tb.id=su.tub_id
             LEFT JOIN labels lb ON lb.id=su.label_id
             WHERE su.id=?
               AND (su.unit_code=? OR su.uuid=? OR tb.code=? OR tb.barcode=? OR lb.code=?)',
            [$stockId,$code,$code,$code,$code,$code]
        )->getRowArray();

        RequestPolicy::require(
            $row !== null,
            'Vuelve a leer el QR de la tina que vas a registrar.',
            'SALE_OUTGOING_QR_MISMATCH'
        );
    }

    private function validateOutgoingLocation(array $stock, int $chamberId, int $rowLocationId, int $warehouseId): array
    {
        RequestPolicy::require(
            $chamberId>0 && $rowLocationId>0,
            'Selecciona cámara y fila antes de registrar la salida.',
            'SALE_OUTGOING_LOCATION_REQUIRED'
        );

        $chamber=$this->db->table('chambers')->select('id,name,code')
            ->where(['id'=>$chamberId,'warehouse_id'=>$warehouseId,'active'=>1])
            ->get()->getRowArray();
        RequestPolicy::require(
            $chamber!==null,
            'La cámara seleccionada no pertenece al almacén origen.',
            'SALE_OUTGOING_CHAMBER_MISMATCH'
        );

        $row=$this->db->table('locations')->select('id,chamber_id,path,location_type')
            ->where([
                'id'=>$rowLocationId,
                'warehouse_id'=>$warehouseId,
                'chamber_id'=>$chamberId,
                'active'=>1,
            ])
            ->whereIn('location_type',['row','aisle'])
            ->get()->getRowArray();
        RequestPolicy::require(
            $row!==null,
            'La fila seleccionada no pertenece a la cámara indicada.',
            'SALE_OUTGOING_ROW_MISMATCH'
        );

        $currentLocationId=(int) ($stock['location_id']??0);
        if ($currentLocationId>0) {
            $belongs=$this->db->query(<<<'SQL'
WITH RECURSIVE location_chain AS (
    SELECT id,parent_id,warehouse_id,0 AS depth FROM locations WHERE id=?
    UNION ALL
    SELECT p.id,p.parent_id,p.warehouse_id,c.depth+1
    FROM locations p JOIN location_chain c ON c.parent_id=p.id
    WHERE c.depth<20
)
SELECT id FROM location_chain WHERE id=? AND warehouse_id=? LIMIT 1
SQL,[$currentLocationId,$rowLocationId,$warehouseId])->getRowArray();

            if ($belongs===null) {
                $current=$this->db->table('locations')->select('path')->where('id',$currentLocationId)->get()->getRowArray();
                RequestPolicy::require(
                    false,
                    'La tina está registrada en '.($current['path']??'otra ubicación').'. Corrige Cámara/Fila o actualiza primero la ubicación física.',
                    'SALE_OUTGOING_LOCATION_MISMATCH'
                );
            }
        }

        return [
            'chamber_id'=>$chamberId,
            'row_location_id'=>$rowLocationId,
            'path'=>(string) $row['path'],
        ];
    }

    private function outgoingWeight(array $order, array $stock, array $payload, int $actorUserId): array
    {
        $readingId=(int) ($payload['scale_reading_id']??0);
        $reading=$this->db->query(
            'SELECT sr.*,sc.site_id AS scale_site_id,sc.active AS scale_active
             FROM scale_readings sr
             JOIN scales sc ON sc.id=sr.scale_id
             WHERE sr.id=? FOR UPDATE',
            [$readingId]
        )->getRowArray();

        RequestPolicy::require(
            $reading!==null
            && (bool) $reading['stable']
            && (bool) $reading['scale_active']
            && $reading['unit']==='kg'
            && $reading['weighing_id']===null
            && (int) $reading['scale_site_id']===(int) $order['site_id'],
            'Se requiere una lectura estable de la báscula del almacén origen.',
            'SALE_OUTGOING_SCALE_REQUIRED'
        );

        RequestPolicy::require(
            $this->db->table('transfer_weighings')->where('scale_reading_id',$readingId)->countAllResults()===0
            && $this->db->table('weighings')->where('scale_reading_id',$readingId)->countAllResults()===0
            && $this->db->table('warehouse_request_units')->where('return_reading_id',$readingId)->countAllResults()===0
            && $this->db->table('inventory_documents')
                ->where(['destination_document_type'=>'scale_reading','destination_document_id'=>$readingId,'status'=>'confirmed'])
                ->countAllResults()===0,
            'La lectura ya fue utilizada.',
            'SCALE_READING_ALREADY_USED'
        );

        RequestPolicy::reading(
            $reading,
            $actorUserId,
            (int) $order['company_id'],
            (int) $order['site_id']
        );

        $tub=$this->db->query(
            'SELECT tb.current_tare_kg FROM tubs tb JOIN stock_units su ON su.tub_id=tb.id WHERE su.id=? FOR UPDATE',
            [(int) $stock['id']]
        )->getRowArray();

        $tare=(float) ($tub['current_tare_kg']??0);
        RequestPolicy::require($tare>=0 && is_finite($tare),'La tara de la tina no es válida.');

        $gross=round(RequestPolicy::grams($reading['weight'])/1000,3);
        $net=round($gross-$tare,3);
        RequestPolicy::require($net>0,'El peso bruto debe ser mayor que la tara.');

        return [
            'scale_reading_id'=>$readingId,
            'gross_weight_kg'=>$gross,
            'tare_weight_kg'=>$tare,
            'weight_kg'=>$net,
        ];
    }

    public function createCustomer(array $payload, int $actorUserId): array
    {
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $tradeName = trim((string) ($payload['trade_name'] ?? ''));
        $legalName = trim((string) ($payload['legal_name'] ?? ''));
        $taxId = strtoupper(trim((string) ($payload['tax_id'] ?? '')));

        RequestPolicy::require($code !== '' && mb_strlen($code) <= 20, 'Captura un código de cliente de hasta 20 caracteres.', 'CUSTOMER_CODE_REQUIRED');
        RequestPolicy::require($tradeName !== '' && mb_strlen($tradeName) <= 120, 'Captura el nombre comercial del cliente.', 'CUSTOMER_TRADE_NAME_REQUIRED');
        RequestPolicy::require($legalName !== '' && mb_strlen($legalName) <= 180, 'Captura la razón social del cliente.', 'CUSTOMER_LEGAL_NAME_REQUIRED');
        RequestPolicy::require(mb_strlen($taxId) <= 20, 'El RFC / identificación fiscal admite hasta 20 caracteres.', 'CUSTOMER_TAX_ID_INVALID');

        $existingCode = $this->db->table('companies')->where('code', $code)->get()->getRowArray();
        RequestPolicy::require($existingCode === null, 'Ya existe una empresa con ese código.', 'CUSTOMER_CODE_EXISTS');

        if ($taxId !== '') {
            $existingTax = $this->db->table('companies')->where('tax_id', $taxId)->get()->getRowArray();
            RequestPolicy::require($existingTax === null, 'Ya existe una empresa con ese RFC / identificación fiscal.', 'CUSTOMER_TAX_ID_EXISTS');
        }

        service('catalogService')->create('companies', [
            'code' => $code,
            'legal_name' => $legalName,
            'trade_name' => $tradeName,
            'tax_id' => $taxId !== '' ? $taxId : null,
            'company_type' => 'customer',
            'active' => 1,
        ], $actorUserId);

        $customer = $this->db->table('companies')
            ->select('id,code,trade_name,legal_name,tax_id,company_type,active')
            ->where(['code' => $code, 'company_type' => 'customer', 'active' => 1])
            ->get()
            ->getRowArray();

        RequestPolicy::require($customer !== null, 'El cliente se guardó pero no pudo recuperarse.', 'CUSTOMER_CREATE_READBACK_FAILED');

        return [
            'customer' => [
                'id' => (int) $customer['id'],
                'code' => $customer['code'],
                'trade_name' => $customer['trade_name'],
                'legal_name' => $customer['legal_name'],
                'tax_id' => $customer['tax_id'],
            ],
        ];
    }

    public function cancelShipment(int $salesOrderId, array $payload, int $actorUserId): array
    {
        $scope = service('scopeContext')->restrictions();
        RequestPolicy::require(
            ! empty($scope['configured']) && ! empty($scope['company_id']) && ! empty($scope['site_id']) && ! empty($scope['warehouse_id']),
            'Selecciona empresa, instalación y almacén en tu alcance operativo.',
            'OPERATIONAL_SCOPE_REQUIRED'
        );

        $saleLineId=(int) ($payload['sales_order_line_id']??0);
        $reason=trim((string) ($payload['reason']??''));

        RequestPolicy::require($saleLineId>0,'Selecciona la tina cuyo despacho deseas cancelar.','SALE_SHIPMENT_LINE_REQUIRED');
        RequestPolicy::require(
            mb_strlen($reason)>=5 && mb_strlen($reason)<=1000,
            'Cancelar un despacho requiere un motivo de 5 a 1000 caracteres.',
            'SALE_SHIPMENT_CANCEL_REASON_REQUIRED'
        );

        $this->db->transException(true)->transBegin();

        try {
            $order=$this->db->query(
                'SELECT * FROM sales_orders WHERE id=? FOR UPDATE',
                [$salesOrderId]
            )->getRowArray();

            RequestPolicy::require($order!==null,'Solicitud de venta no encontrada.','SALE_REQUEST_NOT_FOUND');
            $this->assertScope($order,$scope,true);
            RequestPolicy::require(
                in_array((string) $order['status'],['allocated','dispatched'],true),
                'La venta no tiene despachos cancelables.',
                'SALE_SHIPMENT_NOT_CANCELABLE'
            );

            $saleLine=$this->db->query(
                'SELECT * FROM sales_order_lines WHERE id=? AND sales_order_id=? FOR UPDATE',
                [$saleLineId,$salesOrderId]
            )->getRowArray();

            RequestPolicy::require(
                $saleLine!==null && (string) $saleLine['status']==='dispatched',
                'La tina ya fue cancelada o no tiene un despacho vigente.',
                'SALE_SHIPMENT_ALREADY_RESOLVED'
            );

            $movement=service('inventoryService')->cancelSaleRequestUnitDispatch(
                $salesOrderId,
                $saleLineId,
                $reason,
                $actorUserId
            );

            $now=date('Y-m-d H:i:s');

            $this->db->table('sales_order_lines')
                ->where('id',$saleLineId)
                ->update([
                    'status'=>'released',
                    'updated_at'=>$now,
                ]);

            $requestLines=$this->db->table('sales_request_lines')
                ->where('sales_order_id',$salesOrderId)
                ->orderBy('line_number')
                ->get()
                ->getResultArray();

            $allComplete=true;
            foreach ($requestLines as $line) {
                $actual=(float) $this->db->query(<<<'SQL'
SELECT COALESCE(SUM(idl.quantity_kg),0) AS kg
FROM sales_order_lines sol
JOIN inventory_document_lines idl ON idl.stock_unit_id=sol.stock_unit_id
JOIN inventory_documents idoc ON idoc.id=idl.inventory_document_id
WHERE sol.sales_order_id=?
  AND sol.request_line_id=?
  AND sol.status='dispatched'
  AND idoc.status='confirmed'
  AND idoc.movement_type='sale_dispatch'
  AND idoc.source_document_type='sales_order'
  AND idoc.source_document_id=?
  AND NOT EXISTS (
      SELECT 1
      FROM inventory_documents reversal
      WHERE reversal.reversal_of_id=idoc.id
  )
SQL,[$salesOrderId,(int) $line['id'],$salesOrderId])->getRow('kg');

                if ($actual+0.0001<(float) $line['requested_weight_kg']) {
                    $allComplete=false;
                    break;
                }
            }

            $orderUpdate=[
                'status'=>$allComplete?'dispatched':'allocated',
                'lock_version'=>(int) $order['lock_version']+1,
                'updated_at'=>$now,
            ];

            if ($allComplete) {
                // Si aún queda completamente cubierta, conserva el cierre previo.
                $orderUpdate['dispatched_by']=$order['dispatched_by']??$actorUserId;
                $orderUpdate['dispatched_at']=$order['dispatched_at']??$now;
            } else {
                $orderUpdate['dispatched_by']=null;
                $orderUpdate['dispatched_at']=null;
            }

            $this->db->table('sales_orders')->where('id',$salesOrderId)->update($orderUpdate);

            service('auditService')->record(
                'sale.request_unit_shipment_canceled',
                'sales_order',
                $salesOrderId,
                $actorUserId,
                (int) $order['company_id'],
                (int) $order['site_id'],
                after: [
                    'sales_order_line_id'=>$saleLineId,
                    'stock_unit_id'=>(int) $saleLine['stock_unit_id'],
                    'status'=>$orderUpdate['status'],
                    'inventory_reversal_document_id'=>$movement['reversal_document_id']??null,
                    'inventory_release_document_id'=>$movement['release_document_id']??null,
                    'restored_weight_kg'=>$movement['restored_weight_kg']??null,
                ],
                reason: $reason
            );

            RequestPolicy::require(
                $this->db->transStatus()!==false,
                'No fue posible cancelar el despacho de venta.',
                'SALE_SHIPMENT_CANCEL_TRANSACTION_FAILED'
            );

            $this->db->transCommit();

            return [
                'canceled'=>true,
                'sales_order_id'=>$salesOrderId,
                'sales_order_line_id'=>$saleLineId,
                'status'=>$orderUpdate['status'],
                'detail'=>$this->detail($salesOrderId),
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function release(int $salesOrderId, array $payload, int $actorUserId): array
    {
        $scope = service('scopeContext')->restrictions();
        RequestPolicy::require(
            ! empty($scope['configured']) && ! empty($scope['warehouse_id']),
            'Selecciona tu almacén en el alcance operativo.',
            'OPERATIONAL_SCOPE_REQUIRED'
        );

        $reason = trim((string) ($payload['reason'] ?? ''));
        RequestPolicy::require(mb_strlen($reason) >= 5 && mb_strlen($reason) <= 1000, 'Indica un motivo de liberación de al menos cinco caracteres.');

        $this->db->transException(true)->transBegin();

        try {
            $order = $this->db->query('SELECT * FROM sales_orders WHERE id=? FOR UPDATE', [$salesOrderId])->getRowArray();
            RequestPolicy::require($order !== null, 'Solicitud de venta no encontrada.', 'SALE_REQUEST_NOT_FOUND');
            RequestPolicy::require(
                (int) $order['company_id'] === (int) $scope['company_id']
                && (int) $order['site_id'] === (int) $scope['site_id'],
                'La venta no corresponde al alcance actual.',
                'SALE_REQUEST_SCOPE_MISMATCH'
            );
            RequestPolicy::require(
                $this->db->table('sales_request_lines')->where('sales_order_id', $salesOrderId)->countAllResults() > 0,
                'La orden corresponde al flujo de venta anterior y no usa esta liberación.',
                'SALE_REQUEST_NOT_INTEGRATED'
            );

            $warehouses = $this->db->query(
                'SELECT DISTINCT su.warehouse_id
                 FROM sales_order_lines sol
                 INNER JOIN stock_units su ON su.id=sol.stock_unit_id
                 WHERE sol.sales_order_id=?',
                [$salesOrderId]
            )->getResultArray();
            RequestPolicy::require(
                count($warehouses) === 1 && (int) $warehouses[0]['warehouse_id'] === (int) $scope['warehouse_id'],
                'La reserva corresponde a otro almacén.',
                'SALE_REQUEST_WAREHOUSE_MISMATCH'
            );

            $result = service('inventoryService')->releaseSaleReservation(
                $salesOrderId,
                (int) $scope['warehouse_id'],
                $reason,
                $actorUserId
            );
            RequestPolicy::require($this->db->transStatus() !== false, 'No fue posible liberar la reserva.', 'SALE_RELEASE_TRANSACTION_FAILED');
            $this->db->transCommit();

            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
