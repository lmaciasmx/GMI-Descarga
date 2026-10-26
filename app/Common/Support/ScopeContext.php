<?php

namespace App\Common\Support;

use App\Common\Exceptions\DomainException;
use CodeIgniter\Database\BaseConnection;

final class ScopeContext
{
    private bool $configured = false;
    private ?int $companyId = null;
    private ?int $siteId = null;
    private ?int $warehouseId = null;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function configure(?int $companyId, ?int $siteId, ?int $warehouseId = null): void
    {
        $this->configured = true;
        $this->companyId = $companyId;
        $this->siteId = $siteId;
        $this->warehouseId = $warehouseId;
    }

    public function clear(): void
    {
        $this->configured = false;
        $this->companyId = null;
        $this->siteId = null;
        $this->warehouseId = null;
    }

    /** @return array{configured: bool, company_id: ?int, site_id: ?int, warehouse_id: ?int} */
    public function restrictions(): array
    {
        return [
            'configured' => $this->configured,
            'company_id' => $this->companyId,
            'site_id' => $this->siteId,
            'warehouse_id' => $this->warehouseId,
        ];
    }

    public function assertPayload(array $payload): void
    {
        if (! $this->configured) {
            return;
        }

        /*
         * company_id representa la empresa responsable de la operación.
         *
         * owner_company_id NO forma parte del alcance operativo. Ese campo
         * identifica al propietario de un activo maestro (por ejemplo una
         * embarcación) y puede ser una pesquera distinta de la compañía que
         * está operando el frigorífico.
         */
        $companyId = isset($payload['company_id'])
            ? (int) $payload['company_id']
            : null;

        $siteId = isset($payload['site_id'])
            ? (int) $payload['site_id']
            : null;

        $this->assertCompanySite($companyId, $siteId);

        foreach (['warehouse_id', 'origin_warehouse_id'] as $field) {
            if (! empty($payload[$field])) {
                $this->assertWarehouse((int) $payload[$field]);
            }
        }

        if (! empty($payload['destination_warehouse_id'])) {
            $destination = $this->db->table('warehouses')
                ->select('company_id, site_id')
                ->where('id', (int) $payload['destination_warehouse_id'])
                ->get()
                ->getRowArray();

            if ($destination !== null) {
                // A transfer may legitimately target another site. Creation is
                // authorized from the origin scope; the destination is limited
                // to the same company and is validated again when received.
                $this->assertCompanySite((int) $destination['company_id'], null);
            }
        }

        $aggregateFields = [
            'voyage_id' => 'voyage',
            'plan_id' => 'plan',
            'filling_id' => 'filling',
            'weighing_id' => 'weighing',
            'stock_unit_id' => 'stock_unit',
            'request_id' => 'request',
            'sales_order_id' => 'sales_order',
            'location_id' => 'location',
            'parent_id' => 'location',
            'chamber_id' => 'chamber',
            'vessel_id' => 'vessel',
            'tub_id' => 'tub',
            'scale_id' => 'scale',
            'scale_reading_id' => 'scale_reading',
            'printer_id' => 'printer',
        ];

        foreach ($aggregateFields as $field => $type) {
            if (! empty($payload[$field])) {
                $this->assertAggregate($type, (int) $payload[$field]);
            }
        }

        foreach ((array) ($payload['weighing_ids'] ?? []) as $id) {
            $this->assertAggregate('weighing', (int) $id);
        }

        foreach ((array) ($payload['stock_unit_ids'] ?? []) as $id) {
            $this->assertAggregate('stock_unit', (int) $id);
        }

        // Las unidades recibidas aún pertenecen físicamente al alcance origen.
        // Su pertenencia al traspaso y al destino autorizado se valida bajo
        // bloqueo en InventoryService::receiveTransfer().
        foreach ((array) ($payload['lines'] ?? []) as $line) {
            if (is_array($line) && ! empty($line['stock_unit_id'])) {
                $this->assertAggregate('stock_unit', (int) $line['stock_unit_id']);
            }
        }

        $transferRequestCompanyId = null;
        if (! empty($payload['request_id'])) {
            $requestScope = $this->db->table('raw_material_requests')
                ->select('company_id, request_type')
                ->where('id', (int) $payload['request_id'])
                ->get()
                ->getRowArray();

            if ($requestScope !== null && (string) ($requestScope['request_type'] ?? 'production') === 'warehouse_transfer') {
                $transferRequestCompanyId = (int) $requestScope['company_id'];
            }
        }

        foreach ((array) ($payload['selections'] ?? []) as $selection) {
            if (! is_array($selection) || empty($selection['stock_unit_id'])) {
                continue;
            }

            $stockUnitId = (int) $selection['stock_unit_id'];

            if ($transferRequestCompanyId === null) {
                $this->assertAggregate('stock_unit', $stockUnitId);
                continue;
            }

            $stockScope = $this->db->table('stock_units')
                ->select('company_id')
                ->where('id', $stockUnitId)
                ->get()
                ->getRowArray();

            if ($stockScope === null || (int) $stockScope['company_id'] !== $transferRequestCompanyId) {
                throw new DomainException('La tina seleccionada está fuera de la empresa de la solicitud.', 'SCOPE_MISMATCH');
            }
        }
    }

    public function assertCompanySite(?int $companyId, ?int $siteId): void
    {
        if (! $this->configured) {
            return;
        }

        if ($this->companyId !== null && $companyId !== null && $companyId !== $this->companyId) {
            throw new DomainException('La empresa está fuera del alcance autorizado.', 'SCOPE_MISMATCH');
        }

        if ($this->siteId !== null && $siteId !== null && $siteId !== $this->siteId) {
            throw new DomainException('La instalación está fuera del alcance autorizado.', 'SCOPE_MISMATCH');
        }
    }

    public function assertWarehouse(int $warehouseId): void
    {
        if (! $this->configured) {
            return;
        }

        if ($this->warehouseId !== null && $warehouseId !== $this->warehouseId) {
            throw new DomainException('El almacén está fuera del alcance autorizado.', 'SCOPE_MISMATCH');
        }

        $warehouse = $this->db->table('warehouses')
            ->select('company_id, site_id')
            ->where('id', $warehouseId)
            ->get()
            ->getRowArray();

        if ($warehouse !== null) {
            $this->assertCompanySite((int) $warehouse['company_id'], (int) $warehouse['site_id']);
        }
    }

    public function assertAggregate(string $type, int $id): void
    {
        if (! $this->configured || $id <= 0) {
            return;
        }

        [$sql, $parameters] = match ($type) {
            'voyage' => [
                'SELECT company_id, site_id, NULL AS warehouse_id FROM voyages WHERE id = ?',
                [$id],
            ],
            'plan' => [
                'SELECT v.company_id, v.site_id, NULL AS warehouse_id
                 FROM discharge_plans p
                 INNER JOIN voyages v ON v.id = p.voyage_id
                 WHERE p.id = ?',
                [$id],
            ],
            'filling' => [
                'SELECT v.company_id, v.site_id, NULL AS warehouse_id
                 FROM tub_fillings f
                 INNER JOIN discharge_plans p ON p.id = f.discharge_plan_id
                 INNER JOIN voyages v ON v.id = p.voyage_id
                 WHERE f.id = ?',
                [$id],
            ],
            'weighing' => [
                'SELECT v.company_id, v.site_id, NULL AS warehouse_id
                 FROM weighings w
                 INNER JOIN tub_fillings f ON f.id = w.tub_filling_id
                 INNER JOIN discharge_plans p ON p.id = f.discharge_plan_id
                 INNER JOIN voyages v ON v.id = p.voyage_id
                 WHERE w.id = ?',
                [$id],
            ],
            'label' => [
                'SELECT v.company_id, v.site_id, NULL AS warehouse_id
                 FROM labels l
                 INNER JOIN weighings w ON w.id = l.weighing_id
                 INNER JOIN tub_fillings f ON f.id = w.tub_filling_id
                 INNER JOIN discharge_plans p ON p.id = f.discharge_plan_id
                 INNER JOIN voyages v ON v.id = p.voyage_id
                 WHERE l.id = ?',
                [$id],
            ],
            'quality_inspection' => [
                'SELECT v.company_id, v.site_id, NULL AS warehouse_id
                 FROM quality_inspections q
                 INNER JOIN weighings w ON w.id = q.weighing_id
                 INNER JOIN tub_fillings f ON f.id = w.tub_filling_id
                 INNER JOIN discharge_plans p ON p.id = f.discharge_plan_id
                 INNER JOIN voyages v ON v.id = p.voyage_id
                 WHERE q.id = ?',
                [$id],
            ],
            'location' => [
                'SELECT w.company_id, w.site_id, l.warehouse_id
                 FROM locations l
                 INNER JOIN warehouses w ON w.id = l.warehouse_id
                 WHERE l.id = ?',
                [$id],
            ],
            'chamber' => [
                'SELECT w.company_id, w.site_id, c.warehouse_id
                 FROM chambers c
                 INNER JOIN warehouses w ON w.id = c.warehouse_id
                 WHERE c.id = ?',
                [$id],
            ],

            /*
             * Las embarcaciones son activos maestros.
             * owner_company_id identifica a su propietario, pero no determina
             * el alcance operativo del frigorífico que trabaja con ellas.
             */
            'vessel' => [
                'SELECT NULL AS company_id, NULL AS site_id, NULL AS warehouse_id
                 FROM vessels
                 WHERE id = ?',
                [$id],
            ],

            /*
             * Las tinas son activos globales y pueden trasladarse entre
             * compañías/almacenes. Su ubicación y pertenencia operativa se
             * determinan mediante los movimientos e inventario, no desde tubs.
             */
            'tub' => [
                'SELECT NULL AS company_id, NULL AS site_id, NULL AS warehouse_id
                 FROM tubs
                 WHERE id = ?',
                [$id],
            ],

            'scale' => [
                'SELECT s.company_id, sc.site_id, NULL AS warehouse_id
                 FROM scales sc
                 INNER JOIN sites s ON s.id = sc.site_id
                 WHERE sc.id = ?',
                [$id],
            ],
            'scale_reading' => [
                'SELECT s.company_id, sc.site_id, NULL AS warehouse_id
                 FROM scale_readings sr
                 INNER JOIN scales sc ON sc.id = sr.scale_id
                 INNER JOIN sites s ON s.id = sc.site_id
                 WHERE sr.id = ?',
                [$id],
            ],
            'printer' => [
                'SELECT s.company_id, p.site_id, NULL AS warehouse_id
                 FROM printers p
                 INNER JOIN sites s ON s.id = p.site_id
                 WHERE p.id = ?',
                [$id],
            ],
            'stock_unit' => [
                'SELECT company_id, site_id, warehouse_id
                 FROM stock_units
                 WHERE id = ?',
                [$id],
            ],
            'request' => [
                'SELECT company_id, site_id, NULL AS warehouse_id
                 FROM raw_material_requests
                 WHERE id = ?',
                [$id],
            ],
            'reservation' => [
                'SELECT r.company_id, r.site_id, NULL AS warehouse_id
                 FROM reservations rs
                 INNER JOIN raw_material_requests r ON r.id = rs.request_id
                 WHERE rs.id = ?',
                [$id],
            ],
            'transfer' => [
                'SELECT t.company_id, ow.site_id, t.origin_warehouse_id AS warehouse_id, t.destination_warehouse_id
                 FROM transfers t
                 INNER JOIN warehouses ow ON ow.id = t.origin_warehouse_id
                 WHERE t.id = ?',
                [$id],
            ],
            'transfer_company' => [
                'SELECT company_id, NULL AS site_id, NULL AS warehouse_id
                 FROM transfers
                 WHERE id = ?',
                [$id],
            ],
            'transfer_origin' => [
                'SELECT t.company_id, w.site_id, t.origin_warehouse_id AS warehouse_id
                 FROM transfers t
                 INNER JOIN warehouses w ON w.id = t.origin_warehouse_id
                 WHERE t.id = ?',
                [$id],
            ],
            'transfer_destination' => [
                'SELECT t.company_id, w.site_id, t.destination_warehouse_id AS warehouse_id
                 FROM transfers t
                 INNER JOIN warehouses w ON w.id = t.destination_warehouse_id
                 WHERE t.id = ?',
                [$id],
            ],
            'sales_order' => [
                'SELECT so.company_id, so.site_id, su.warehouse_id
                 FROM sales_orders so
                 LEFT JOIN sales_order_lines sl ON sl.sales_order_id = so.id
                 LEFT JOIN stock_units su ON su.id = sl.stock_unit_id
                 WHERE so.id = ?
                 LIMIT 1',
                [$id],
            ],
            'inventory_document' => [
                'SELECT company_id, site_id, COALESCE(origin_warehouse_id, destination_warehouse_id) AS warehouse_id
                 FROM inventory_documents
                 WHERE id = ?',
                [$id],
            ],
            'inventory_count' => [
                'SELECT w.company_id, w.site_id, ic.warehouse_id
                 FROM inventory_counts ic
                 INNER JOIN warehouses w ON w.id = ic.warehouse_id
                 WHERE ic.id = ?',
                [$id],
            ],
            default => throw new \InvalidArgumentException('Tipo de agregado no soportado para alcance.'),
        };

        $row = $this->db->query($sql, $parameters)->getRowArray();

        if ($row === null) {
            return;
        }

        $this->assertCompanySite(
            isset($row['company_id']) ? (int) $row['company_id'] : null,
            isset($row['site_id']) ? (int) $row['site_id'] : null,
        );

        if (isset($row['warehouse_id']) && $row['warehouse_id'] !== null) {
            $this->assertWarehouse((int) $row['warehouse_id']);
        }

        if ($type === 'transfer' && isset($row['destination_warehouse_id'])) {
            $this->assertWarehouse((int) $row['destination_warehouse_id']);
        }
    }
}
