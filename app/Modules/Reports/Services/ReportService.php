<?php

namespace App\Modules\Reports\Services;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

final class ReportService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    public function catalog(): array
    {
        return [
            'general.summary' => [
                'title' => 'Resumen general de operación',
                'category' => 'Ejecutivos',
                'icon' => 'bi-speedometer2',
                'description' => 'Indicadores de inventario, reservas, solicitudes y movimientos del alcance actual.',
            ],
            'inventory.current' => [
                'title' => 'Existencias actuales',
                'category' => 'Inventario',
                'icon' => 'bi-boxes',
                'description' => 'Inventario disponible por especie, talla, lote, propietario y ubicación.',
            ],
            'inventory.movements' => [
                'title' => 'Movimientos de inventario',
                'category' => 'Inventario',
                'icon' => 'bi-arrow-left-right',
                'description' => 'Entradas, salidas, reservas y ajustes registrados en el libro mayor.',
            ],
            'requests.warehouse' => [
                'title' => 'Solicitudes entre almacenes',
                'category' => 'Solicitudes y movimientos',
                'icon' => 'bi-clipboard-check',
                'description' => 'Solicitudes, tipo, origen, destino, estado y avance de surtido.',
            ],
            'transfers.summary' => [
                'title' => 'Traspasos',
                'category' => 'Solicitudes y movimientos',
                'icon' => 'bi-truck',
                'description' => 'Movimientos entre almacenes con pesos de salida, recepción y diferencias.',
            ],
            'production.raw-material' => [
                'title' => 'Materia Prima / Producción',
                'category' => 'Producción y venta',
                'icon' => 'bi-gear',
                'description' => 'Solicitudes de materia prima, destino productivo y cumplimiento de peso.',
            ],
            'sales.dispatches' => [
                'title' => 'Ventas',
                'category' => 'Producción y venta',
                'icon' => 'bi-receipt',
                'description' => 'Solicitudes y despachos de venta por producto, cliente y peso.',
            ],
            'traceability.unit' => [
                'title' => 'Trazabilidad por tina',
                'category' => 'Trazabilidad',
                'icon' => 'bi-qr-code-scan',
                'description' => 'Relación de unidad, lote, barco, marea, pesaje, ubicación y estado.',
            ],
            'discharge.summary' => [
                'title' => 'Descargas',
                'category' => 'Operación',
                'icon' => 'bi-water',
                'description' => 'Descargas por barco, marea y plan con peso planeado y fechas operativas.',
            ],
            'warehouse.entries' => [
                'title' => 'Entradas a frigorífico',
                'category' => 'Operación',
                'icon' => 'bi-box-arrow-in-down',
                'description' => 'Recepciones confirmadas, almacén, ubicación y peso aceptado.',
            ],
        ];
    }

    public function overview(array $scope, array $filters = []): array
    {
        $companyId = (int) ($scope['company_id'] ?? 0);
        $siteId = (int) ($scope['site_id'] ?? 0);
        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);

        $stock = $this->db->table('stock_units su')
            ->select('COUNT(*) AS units, COALESCE(SUM(su.on_hand_weight_kg),0) AS kg, COUNT(DISTINCT su.species_id) AS species')
            ->where(['su.company_id' => $companyId, 'su.site_id' => $siteId])
            ->whereNotIn('su.status', ['dispatched', 'exhausted']);
        $this->applyWarehouse($stock, 'su.warehouse_id', $scope);
        $stockRow = $stock->get()->getRowArray() ?? [];

        $movements = $this->db->table('inventory_movements im')
            ->select('COUNT(*) AS total')
            ->join('inventory_documents d', 'd.id = im.inventory_document_id')
            ->where(['d.company_id' => $companyId, 'd.site_id' => $siteId]);
        if ($warehouseId > 0) {
            $movements->groupStart()
                ->where('d.origin_warehouse_id', $warehouseId)
                ->orWhere('d.destination_warehouse_id', $warehouseId)
                ->groupEnd();
        }
        $this->applyDateRange($movements, 'im.occurred_at', $filters);
        $movementTotal = (int) ($movements->get()->getRowArray()['total'] ?? 0);

        $requests = $this->db->table('warehouse_requests wr')
            ->select('COUNT(*) AS total')
            ->join('warehouses sw', 'sw.id = wr.source_warehouse_id', 'left')
            ->join('warehouses dw', 'dw.id = wr.destination_warehouse_id', 'left')
            ->where('wr.company_id', $companyId)
            ->groupStart()
                ->where('sw.site_id', $siteId)
                ->orWhere('dw.site_id', $siteId)
            ->groupEnd();
        if ($warehouseId > 0) {
            $requests->groupStart()
                ->where('wr.source_warehouse_id', $warehouseId)
                ->orWhere('wr.destination_warehouse_id', $warehouseId)
                ->groupEnd();
        }
        $this->applyDateRange($requests, 'wr.created_at', $filters);
        $requestTotal = (int) ($requests->get()->getRowArray()['total'] ?? 0);

        $trace = $this->db->table('stock_units su')
            ->select('COUNT(*) AS total')
            ->where(['su.company_id' => $companyId, 'su.site_id' => $siteId]);
        $this->applyWarehouse($trace, 'su.warehouse_id', $scope);
        $this->applyDateRange($trace, 'su.received_at', $filters);
        $traceTotal = (int) ($trace->get()->getRowArray()['total'] ?? 0);

        return [
            'stock_units' => (int) ($stockRow['units'] ?? 0),
            'stock_kg' => (float) ($stockRow['kg'] ?? 0),
            'species_count' => (int) ($stockRow['species'] ?? 0),
            'movements' => $movementTotal,
            'requests' => $requestTotal,
            'traceability' => $traceTotal,
        ];
    }

    public function get(string $key, array $scope, array $filters = []): array
    {
        if (! isset($this->catalog()[$key])) {
            throw new InvalidArgumentException('Reporte no reconocido.');
        }

        return match ($key) {
            'general.summary' => $this->generalSummary($scope, $filters),
            'inventory.current' => $this->inventoryCurrent($scope, $filters),
            'inventory.movements' => $this->inventoryMovements($scope, $filters),
            'requests.warehouse' => $this->warehouseRequests($scope, $filters),
            'transfers.summary' => $this->transfers($scope, $filters),
            'production.raw-material' => $this->rawMaterial($scope, $filters),
            'sales.dispatches' => $this->sales($scope, $filters),
            'traceability.unit' => $this->traceability($scope, $filters),
            'discharge.summary' => $this->discharges($scope, $filters),
            'warehouse.entries' => $this->warehouseEntries($scope, $filters),
        };
    }

    private function generalSummary(array $scope, array $filters): array
    {
        $companyId = (int) ($scope['company_id'] ?? 0);
        $siteId = (int) ($scope['site_id'] ?? 0);
        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);

        $stock = $this->db->table('stock_units su')
            ->select('COUNT(*) AS units, ROUND(SUM(su.on_hand_weight_kg), 3) AS on_hand_kg, ROUND(SUM(su.reserved_weight_kg), 3) AS reserved_kg')
            ->where(['su.company_id' => $companyId, 'su.site_id' => $siteId])
            ->whereNotIn('su.status', ['dispatched', 'exhausted']);
        if ($warehouseId > 0) {
            $stock->where('su.warehouse_id', $warehouseId);
        }
        $stockTotals = $stock->get()->getRowArray() ?? [];

        $requests = $this->db->table('warehouse_requests wr')
            ->select('COUNT(*) AS total')
            ->join('warehouses sw', 'sw.id = wr.source_warehouse_id', 'left')
            ->join('warehouses dw', 'dw.id = wr.destination_warehouse_id', 'left')
            ->where('wr.company_id', $companyId)
            ->groupStart()
                ->where('sw.site_id', $siteId)
                ->orWhere('dw.site_id', $siteId)
            ->groupEnd()
            ->whereNotIn('wr.status', ['closed', 'rejected', 'cancelled']);
        if ($warehouseId > 0) {
            $requests->groupStart()
                ->where('wr.source_warehouse_id', $warehouseId)
                ->orWhere('wr.destination_warehouse_id', $warehouseId)
                ->groupEnd();
        }
        $requestTotal = (int) (($requests->get()->getRowArray()['total'] ?? 0));

        $movements = $this->db->table('inventory_movements im')
            ->select('COUNT(*) AS total')
            ->join('inventory_documents d', 'd.id = im.inventory_document_id')
            ->where(['d.company_id' => $companyId, 'd.site_id' => $siteId]);
        if ($warehouseId > 0) {
            $movements->groupStart()
                ->where('d.origin_warehouse_id', $warehouseId)
                ->orWhere('d.destination_warehouse_id', $warehouseId)
                ->groupEnd();
        }
        $this->applyDateRange($movements, 'im.occurred_at', $filters);
        $movementTotal = (int) (($movements->get()->getRowArray()['total'] ?? 0));

        $species = $this->db->table('stock_units su')
            ->select('s.name AS species, COUNT(*) AS units, ROUND(SUM(su.on_hand_weight_kg), 3) AS on_hand_kg, ROUND(SUM(su.reserved_weight_kg), 3) AS reserved_kg')
            ->join('species s', 's.id = su.species_id')
            ->where(['su.company_id' => $companyId, 'su.site_id' => $siteId])
            ->whereNotIn('su.status', ['dispatched', 'exhausted']);
        if ($warehouseId > 0) {
            $species->where('su.warehouse_id', $warehouseId);
        }

        return [
            'kpis' => [
                ['label' => 'Tinas/unidades', 'value' => number_format((int) ($stockTotals['units'] ?? 0))],
                ['label' => 'Existencia', 'value' => number_format((float) ($stockTotals['on_hand_kg'] ?? 0), 3) . ' kg'],
                ['label' => 'Reservado', 'value' => number_format((float) ($stockTotals['reserved_kg'] ?? 0), 3) . ' kg'],
                ['label' => 'Solicitudes abiertas', 'value' => number_format($requestTotal)],
                ['label' => 'Movimientos periodo', 'value' => number_format($movementTotal)],
            ],
            'columns' => [
                'species' => 'Especie', 'units' => 'Tinas', 'on_hand_kg' => 'Existencia kg', 'reserved_kg' => 'Reservado kg',
            ],
            'rows' => $species->groupBy('s.id')->orderBy('s.name')->get()->getResultArray(),
            'chart' => 'species',
        ];
    }

    private function inventoryCurrent(array $scope, array $filters): array
    {
        $b = $this->db->table('stock_units su')
            ->select('su.unit_code, s.name AS species, sg.name AS size_grade, lo.code AS lot_code, c.trade_name AS owner, w.name AS warehouse, l.path AS location, su.on_hand_weight_kg, su.reserved_weight_kg, su.status, su.received_at')
            ->join('species s', 's.id = su.species_id')
            ->join('size_grades sg', 'sg.id = su.size_grade_id', 'left')
            ->join('lots lo', 'lo.id = su.lot_id', 'left')
            ->join('companies c', 'c.id = su.owner_company_id', 'left')
            ->join('warehouses w', 'w.id = su.warehouse_id', 'left')
            ->join('locations l', 'l.id = su.location_id', 'left')
            ->where(['su.company_id' => (int) $scope['company_id'], 'su.site_id' => (int) $scope['site_id']])
            ->whereNotIn('su.status', ['dispatched', 'exhausted']);
        $this->applyWarehouse($b, 'su.warehouse_id', $scope);
        $b->orderBy('su.received_at', 'DESC');
        if (empty($filters['export_full'])) {
            $b->limit(2000);
        }
        $rows = $b->get()->getResultArray();
        $kg = array_sum(array_map(static fn (array $r): float => (float) $r['on_hand_weight_kg'], $rows));
        $reserved = array_sum(array_map(static fn (array $r): float => (float) $r['reserved_weight_kg'], $rows));

        return [
            'kpis' => [
                ['label' => 'Tinas', 'value' => number_format(count($rows))],
                ['label' => 'Peso neto', 'value' => number_format($kg, 3) . ' kg'],
                ['label' => 'Reservado', 'value' => number_format($reserved, 3) . ' kg'],
            ],
            'columns' => [
                'unit_code' => 'Tina/Unidad', 'species' => 'Especie', 'size_grade' => 'Talla', 'lot_code' => 'Lote', 'owner' => 'Propietario',
                'warehouse' => 'Almacén', 'location' => 'Ubicación', 'on_hand_weight_kg' => 'Kg', 'reserved_weight_kg' => 'Reservado', 'status' => 'Estado', 'received_at' => 'Recibido',
            ],
            'rows' => $rows,
        ];
    }

    private function inventoryMovements(array $scope, array $filters): array
    {
        $b = $this->db->table('inventory_movements im')
            ->select('im.occurred_at, d.folio AS document_folio, d.movement_type, su.unit_code, lo.code AS lot_code, im.quantity_delta_kg, im.reserved_delta_kg, fl.path AS from_location, tl.path AS to_location, im.resulting_on_hand_kg, im.resulting_reserved_kg')
            ->join('inventory_documents d', 'd.id = im.inventory_document_id')
            ->join('stock_units su', 'su.id = im.stock_unit_id', 'left')
            ->join('lots lo', 'lo.id = im.lot_id', 'left')
            ->join('locations fl', 'fl.id = im.from_location_id', 'left')
            ->join('locations tl', 'tl.id = im.to_location_id', 'left')
            ->where(['d.company_id' => (int) $scope['company_id'], 'd.site_id' => (int) $scope['site_id']]);
        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $b->groupStart()->where('d.origin_warehouse_id', $warehouseId)->orWhere('d.destination_warehouse_id', $warehouseId)->groupEnd();
        }
        $this->applyDateRange($b, 'im.occurred_at', $filters);
        $b->orderBy('im.occurred_at', 'DESC');
        if (empty($filters['export_full'])) {
            $b->limit(2000);
        }
        $rows = $b->get()->getResultArray();

        return [
            'kpis' => [['label' => 'Movimientos', 'value' => number_format(count($rows))]],
            'columns' => [
                'occurred_at' => 'Fecha', 'document_folio' => 'Documento', 'movement_type' => 'Movimiento', 'unit_code' => 'Tina', 'lot_code' => 'Lote',
                'quantity_delta_kg' => 'Δ Kg', 'reserved_delta_kg' => 'Δ Reserva', 'from_location' => 'Origen', 'to_location' => 'Destino', 'resulting_on_hand_kg' => 'Saldo kg',
            ],
            'rows' => $rows,
        ];
    }

    private function warehouseRequests(array $scope, array $filters): array
    {
        $b = $this->db->table('warehouse_requests wr')
            ->select('wr.folio, wr.request_type, sw.name AS source_warehouse, dw.name AS destination_warehouse, wr.status, wr.required_at, wr.accepted_at, wr.supply_finalized_at, wr.receiver_validated_at, wr.closed_at, COUNT(DISTINCT wrl.id) AS line_count, COUNT(DISTINCT wru.id) AS units')
            ->join('warehouses sw', 'sw.id = wr.source_warehouse_id', 'left')
            ->join('warehouses dw', 'dw.id = wr.destination_warehouse_id', 'left')
            ->join('warehouse_request_lines wrl', 'wrl.request_id = wr.id', 'left')
            ->join('warehouse_request_units wru', 'wru.request_id = wr.id', 'left')
            ->where('wr.company_id', (int) $scope['company_id'])
            ->groupStart()
                ->where('sw.site_id', (int) $scope['site_id'])
                ->orWhere('dw.site_id', (int) $scope['site_id'])
            ->groupEnd();
        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $b->groupStart()->where('wr.source_warehouse_id', $warehouseId)->orWhere('wr.destination_warehouse_id', $warehouseId)->groupEnd();
        }
        $this->applyDateRange($b, 'wr.created_at', $filters);
        $b->groupBy('wr.id')->orderBy('wr.created_at', 'DESC');
        if (empty($filters['export_full'])) {
            $b->limit(1000);
        }
        $rows = $b->get()->getResultArray();

        return [
            'kpis' => [['label' => 'Solicitudes', 'value' => number_format(count($rows))]],
            'columns' => [
                'folio' => 'Folio', 'request_type' => 'Tipo', 'source_warehouse' => 'Origen', 'destination_warehouse' => 'Destino', 'status' => 'Estado',
                'required_at' => 'Requerida', 'line_count' => 'Líneas', 'units' => 'Tinas', 'supply_finalized_at' => 'Surtido finalizado', 'receiver_validated_at' => 'Recepción validada', 'closed_at' => 'Cierre',
            ],
            'rows' => $rows,
        ];
    }

    private function transfers(array $scope, array $filters): array
    {
        $b = $this->db->table('transfers tr')
            ->select('tr.folio, ow.name AS origin_warehouse, dw.name AS destination_warehouse, tr.transfer_type, tr.status, tr.requested_at, tr.shipped_at, tr.received_at, COUNT(tl.id) AS units, ROUND(SUM(tl.shipped_weight_kg),3) AS shipped_kg, ROUND(SUM(tl.received_weight_kg),3) AS received_kg, ROUND(SUM(tl.difference_kg),3) AS difference_kg')
            ->join('warehouses ow', 'ow.id = tr.origin_warehouse_id', 'left')
            ->join('warehouses dw', 'dw.id = tr.destination_warehouse_id', 'left')
            ->join('transfer_lines tl', 'tl.transfer_id = tr.id', 'left')
            ->where('tr.company_id', (int) $scope['company_id'])
            ->groupStart()
                ->where('ow.site_id', (int) $scope['site_id'])
                ->orWhere('dw.site_id', (int) $scope['site_id'])
            ->groupEnd();
        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $b->groupStart()->where('tr.origin_warehouse_id', $warehouseId)->orWhere('tr.destination_warehouse_id', $warehouseId)->groupEnd();
        }
        $this->applyDateRange($b, 'tr.created_at', $filters);
        $b->groupBy('tr.id')->orderBy('tr.created_at', 'DESC');
        if (empty($filters['export_full'])) {
            $b->limit(1000);
        }
        $rows = $b->get()->getResultArray();

        return [
            'kpis' => [['label' => 'Traspasos', 'value' => number_format(count($rows))]],
            'columns' => [
                'folio' => 'Folio', 'origin_warehouse' => 'Origen', 'destination_warehouse' => 'Destino', 'transfer_type' => 'Tipo', 'status' => 'Estado',
                'requested_at' => 'Solicitado', 'shipped_at' => 'Salida', 'received_at' => 'Recepción', 'units' => 'Tinas', 'shipped_kg' => 'Kg salida', 'received_kg' => 'Kg entrada', 'difference_kg' => 'Diferencia',
            ],
            'rows' => $rows,
        ];
    }

    private function rawMaterial(array $scope, array $filters): array
    {
        $b = $this->db->table('raw_material_requests rmr')
            ->select('rmr.folio, w.name AS source_warehouse, rmr.destination, rmr.priority, rmr.status, rmr.required_at, rmr.requested_at, COUNT(rml.id) AS line_count, ROUND(SUM(rml.requested_weight_kg),3) AS requested_kg, ROUND(SUM(rml.reserved_weight_kg),3) AS reserved_kg, ROUND(SUM(rml.fulfilled_weight_kg),3) AS fulfilled_kg')
            ->join('warehouses w', 'w.id = rmr.source_warehouse_id', 'left')
            ->join('raw_material_request_lines rml', 'rml.request_id = rmr.id', 'left')
            ->where(['rmr.company_id' => (int) $scope['company_id'], 'rmr.site_id' => (int) $scope['site_id']]);
        $this->applyWarehouse($b, 'rmr.source_warehouse_id', $scope);
        $this->applyDateRange($b, 'rmr.created_at', $filters);
        $b->groupBy('rmr.id')->orderBy('rmr.created_at', 'DESC');
        if (empty($filters['export_full'])) {
            $b->limit(1000);
        }
        $rows = $b->get()->getResultArray();

        return [
            'kpis' => [['label' => 'Solicitudes MP', 'value' => number_format(count($rows))]],
            'columns' => [
                'folio' => 'Folio', 'source_warehouse' => 'Almacén', 'destination' => 'Destino', 'priority' => 'Prioridad', 'status' => 'Estado', 'required_at' => 'Requerida',
                'line_count' => 'Líneas', 'requested_kg' => 'Solicitado kg', 'reserved_kg' => 'Reservado kg', 'fulfilled_kg' => 'Cumplido kg',
            ],
            'rows' => $rows,
        ];
    }

    private function sales(array $scope, array $filters): array
    {
        $b = $this->db->table('sales_orders so')
            ->select('so.folio, w.name AS source_warehouse, c.trade_name AS customer, so.status, so.required_at, so.dispatched_at, COUNT(sol.id) AS units, ROUND(SUM(sol.quantity_kg),3) AS dispatched_kg')
            ->join('warehouses w', 'w.id = so.source_warehouse_id', 'left')
            ->join('companies c', 'c.id = so.customer_company_id', 'left')
            ->join('sales_order_lines sol', 'sol.sales_order_id = so.id', 'left')
            ->where(['so.company_id' => (int) $scope['company_id'], 'so.site_id' => (int) $scope['site_id']]);
        $this->applyWarehouse($b, 'so.source_warehouse_id', $scope);
        $this->applyDateRange($b, 'so.created_at', $filters);
        $b->groupBy('so.id')->orderBy('so.created_at', 'DESC');
        if (empty($filters['export_full'])) {
            $b->limit(1000);
        }
        $rows = $b->get()->getResultArray();

        return [
            'kpis' => [['label' => 'Ventas', 'value' => number_format(count($rows))]],
            'columns' => [
                'folio' => 'Folio', 'source_warehouse' => 'Almacén', 'customer' => 'Cliente', 'status' => 'Estado', 'required_at' => 'Requerida', 'dispatched_at' => 'Despachada', 'units' => 'Tinas', 'dispatched_kg' => 'Kg',
            ],
            'rows' => $rows,
        ];
    }

    private function traceability(array $scope, array $filters): array
    {
        $b = $this->db->table('stock_units su')
            ->select('su.unit_code, su.status, su.on_hand_weight_kg, lo.code AS lot_code, w.folio AS weighing_folio, ve.name AS vessel_name, v.annual_number, t.code AS tub_code, l.path AS location_path, su.received_at')
            ->join('lots lo', 'lo.id = su.lot_id', 'left')
            ->join('voyages v', 'v.id = lo.voyage_id', 'left')
            ->join('vessels ve', 've.id = v.vessel_id', 'left')
            ->join('weighings w', 'w.id = su.weighing_id', 'left')
            ->join('tubs t', 't.id = su.tub_id', 'left')
            ->join('locations l', 'l.id = su.location_id', 'left')
            ->where(['su.company_id' => (int) $scope['company_id'], 'su.site_id' => (int) $scope['site_id']]);
        $this->applyWarehouse($b, 'su.warehouse_id', $scope);
        $this->applyDateRange($b, 'su.received_at', $filters);
        $b->orderBy('su.received_at', 'DESC');
        if (empty($filters['export_full'])) {
            $b->limit(2000);
        }
        $rows = $b->get()->getResultArray();

        return [
            'kpis' => [['label' => 'Unidades', 'value' => number_format(count($rows))]],
            'columns' => [
                'unit_code' => 'Unidad', 'lot_code' => 'Lote', 'vessel_name' => 'Embarcación', 'annual_number' => 'Marea', 'tub_code' => 'Tina', 'weighing_folio' => 'Pesaje',
                'location_path' => 'Ubicación', 'on_hand_weight_kg' => 'Peso kg', 'status' => 'Estado', 'received_at' => 'Recibido',
            ],
            'rows' => $rows,
        ];
    }

    private function discharges(array $scope, array $filters): array
    {
        $b = $this->db->table('discharge_plans dp')
            ->select('dp.folio, ve.name AS vessel_name, v.annual_number, v.year, dp.status, dp.planned_start_at, dp.planned_end_at, ROUND(SUM(dpt.planned_weight_kg),3) AS planned_kg, COUNT(DISTINCT dpt.id) AS plan_lines')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->join('discharge_plan_tanks dpt', 'dpt.discharge_plan_id = dp.id', 'left')
            ->where(['v.company_id' => (int) $scope['company_id'], 'v.site_id' => (int) $scope['site_id']]);
        $this->applyWarehouse($b, 'v.receiving_warehouse_id', $scope);
        $this->applyDateRange($b, 'dp.created_at', $filters);
        $b->groupBy('dp.id')->orderBy('dp.created_at', 'DESC');
        if (empty($filters['export_full'])) {
            $b->limit(1000);
        }
        $rows = $b->get()->getResultArray();

        return [
            'kpis' => [['label' => 'Planes/descargas', 'value' => number_format(count($rows))]],
            'columns' => [
                'folio' => 'Plan', 'vessel_name' => 'Barco', 'annual_number' => 'Marea', 'year' => 'Año', 'status' => 'Estado', 'planned_start_at' => 'Inicio plan', 'planned_end_at' => 'Fin plan', 'planned_kg' => 'Planeado kg', 'plan_lines' => 'Líneas',
            ],
            'rows' => $rows,
        ];
    }

    private function warehouseEntries(array $scope, array $filters): array
    {
        $b = $this->db->table('receptions r')
            ->select('r.folio, w.name AS warehouse, r.status, r.received_at, COUNT(rl.id) AS line_count, ROUND(SUM(rl.accepted_weight_kg),3) AS accepted_kg')
            ->join('warehouses w', 'w.id = r.warehouse_id', 'left')
            ->join('reception_lines rl', 'rl.reception_id = r.id', 'left')
            ->where(['r.company_id' => (int) $scope['company_id'], 'r.site_id' => (int) $scope['site_id']]);
        $this->applyWarehouse($b, 'r.warehouse_id', $scope);
        $this->applyDateRange($b, 'r.received_at', $filters);
        $b->groupBy('r.id')->orderBy('r.received_at', 'DESC');
        if (empty($filters['export_full'])) {
            $b->limit(1000);
        }
        $rows = $b->get()->getResultArray();

        return [
            'kpis' => [['label' => 'Recepciones', 'value' => number_format(count($rows))]],
            'columns' => [
                'folio' => 'Folio', 'warehouse' => 'Almacén', 'status' => 'Estado', 'received_at' => 'Recibida', 'line_count' => 'Líneas', 'accepted_kg' => 'Aceptado kg',
            ],
            'rows' => $rows,
        ];
    }

    private function applyWarehouse($builder, string $field, array $scope): void
    {
        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $builder->where($field, $warehouseId);
        }
    }

    private function applyDateRange($builder, string $field, array $filters): void
    {
        if (! empty($filters['from'])) {
            $builder->where($field . ' >=', $filters['from'] . ' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $builder->where($field . ' <=', $filters['to'] . ' 23:59:59');
        }
    }
}
