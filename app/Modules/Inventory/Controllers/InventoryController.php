<?php

namespace App\Modules\Inventory\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final class InventoryController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();

        $viewMode = in_array((string) $this->request->getGet('vista'), ['resumen', 'tinas'], true)
            ? (string) $this->request->getGet('vista')
            : 'resumen';
        $groupBy = in_array((string) $this->request->getGet('agrupar'), ['producto', 'lote', 'camara', 'marea'], true)
            ? (string) $this->request->getGet('agrupar')
            : 'producto';
        $page = max(1, (int) ($this->request->getGet('pagina') ?? 1));
        $perPage = (int) ($this->request->getGet('por_pagina') ?? 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $filters = [
            'q' => trim((string) $this->request->getGet('q')),
            'warehouse_id' => (int) ($this->request->getGet('warehouse_id') ?? 0),
            'chamber_id' => (int) ($this->request->getGet('chamber_id') ?? 0),
            'sector_id' => (int) ($this->request->getGet('sector_id') ?? 0),
            'species_id' => (int) ($this->request->getGet('species_id') ?? 0),
            'size_grade_id' => (int) ($this->request->getGet('size_grade_id') ?? 0),
            'lot_id' => (int) ($this->request->getGet('lot_id') ?? 0),
            'status' => trim((string) $this->request->getGet('status')),
            'quality' => trim((string) $this->request->getGet('quality')),
            'voyage_id' => (int) ($this->request->getGet('voyage_id') ?? 0),
            'date_from' => trim((string) $this->request->getGet('date_from')),
            'date_to' => trim((string) $this->request->getGet('date_to')),
        ];

        if (! empty($scope['warehouse_id'])) {
            $filters['warehouse_id'] = (int) $scope['warehouse_id'];
        }

        $kpis = $this->loadKpis($db, $scope, $filters);
        $filterOptions = $this->loadFilterOptions($db, $scope, $filters);

        $rows = [];
        $totalRows = 0;

        if ($viewMode === 'resumen') {
            [$rows, $totalRows] = $this->loadSummary($db, $scope, $filters, $groupBy, $page, $perPage);
        } else {
            [$rows, $totalRows] = $this->loadUnits($db, $scope, $filters, $page, $perPage);
        }

        return view('App\\Modules\\Inventory\\Views\\index', [
            'title' => 'Existencias de inventario',
            'subtitle' => 'Consulta operativa por resumen o por tina/marbete, con filtros y totales calculados sobre la vista actual.',
            'viewMode' => $viewMode,
            'groupBy' => $groupBy,
            'filters' => $filters,
            'filterOptions' => $filterOptions,
            'kpis' => $kpis,
            'rows' => $rows,
            'page' => $page,
            'perPage' => $perPage,
            'totalRows' => $totalRows,
            'totalPages' => max(1, (int) ceil($totalRows / $perPage)),
            'scope' => $scope,
        ]);
    }

    public function movements()
    {
        $scope = session('operational_scope');
        $db = db_connect();

        $page = max(1, (int) ($this->request->getGet('pagina') ?? 1));
        $perPage = (int) ($this->request->getGet('por_pagina') ?? 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $filters = [
            'q' => trim((string) $this->request->getGet('q')),
            'movement_type' => trim((string) $this->request->getGet('movement_type')),
            'warehouse_id' => (int) ($this->request->getGet('warehouse_id') ?? 0),
            'date_from' => trim((string) $this->request->getGet('date_from')),
            'date_to' => trim((string) $this->request->getGet('date_to')),
        ];

        if (! empty($scope['warehouse_id'])) {
            $filters['warehouse_id'] = (int) $scope['warehouse_id'];
        }

        $applyDocumentFilters = static function (BaseBuilder $builder) use ($scope, $filters): BaseBuilder {
            $builder->where(['d.company_id' => $scope['company_id'], 'd.site_id' => $scope['site_id']]);

            if ($filters['warehouse_id'] > 0) {
                $builder->groupStart()
                    ->where('d.origin_warehouse_id', $filters['warehouse_id'])
                    ->orWhere('d.destination_warehouse_id', $filters['warehouse_id'])
                ->groupEnd();
            }
            if ($filters['movement_type'] !== '') {
                $builder->where('d.movement_type', $filters['movement_type']);
            }
            if ($filters['date_from'] !== '') {
                $builder->where('d.confirmed_at >=', $filters['date_from'] . ' 00:00:00');
            }
            if ($filters['date_to'] !== '') {
                $builder->where('d.confirmed_at <=', $filters['date_to'] . ' 23:59:59');
            }
            if ($filters['q'] !== '') {
                $builder->groupStart()
                    ->like('d.folio', $filters['q'])
                    ->orLike('su.unit_code', $filters['q'])
                    ->orLike('lo.code', $filters['q'])
                    ->orLike('d.reason_code', $filters['q'])
                ->groupEnd();
            }

            return $builder;
        };

        $countBuilder = $db->table('inventory_documents d')
            ->select('COUNT(DISTINCT d.id) AS total', false)
            ->join('inventory_document_lines dl', 'dl.inventory_document_id = d.id')
            ->join('stock_units su', 'su.id = dl.stock_unit_id')
            ->join('lots lo', 'lo.id = dl.lot_id');
        $applyDocumentFilters($countBuilder);
        $totalRows = (int) ($countBuilder->get()->getRow('total') ?? 0);

        $documentsBuilder = $db->table('inventory_documents d')
            ->select("d.id, d.folio, d.movement_type, d.status, d.reason_code, d.observations, d.confirmed_by, d.confirmed_at, d.source_document_type, d.source_document_id, d.origin_warehouse_id, d.destination_warehouse_id, COUNT(DISTINCT dl.id) AS line_count, ROUND(SUM(dl.quantity_kg), 3) AS total_kg, GROUP_CONCAT(DISTINCT su.unit_code ORDER BY su.unit_code SEPARATOR ', ') AS unit_codes, GROUP_CONCAT(DISTINCT lo.code ORDER BY lo.code SEPARATOR ', ') AS lot_codes, MAX(rev.id) AS reversal_document_id", false)
            ->join('inventory_document_lines dl', 'dl.inventory_document_id = d.id')
            ->join('stock_units su', 'su.id = dl.stock_unit_id')
            ->join('lots lo', 'lo.id = dl.lot_id')
            ->join('inventory_documents rev', 'rev.reversal_of_id = d.id', 'left');
        $applyDocumentFilters($documentsBuilder);
        $documents = $documentsBuilder
            ->groupBy('d.id')
            ->orderBy('d.confirmed_at', 'DESC')
            ->orderBy('d.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        // Tinas y ubicaciones se consultan bajo demanda desde la modal.
        $units = [];
        $locations = [];

        $warehouseBuilder = $db->table('warehouses')
            ->where(['company_id' => $scope['company_id'], 'site_id' => $scope['site_id'], 'active' => 1]);
        if (! empty($scope['warehouse_id'])) {
            $warehouseBuilder->where('id', $scope['warehouse_id']);
        }
        $warehouses = $warehouseBuilder->orderBy('name')->get()->getResultArray();

        return view('App\\Modules\\Inventory\\Views\\movements', [
            'title' => 'Movimientos de inventario',
            'subtitle' => 'Histórico trazable y movimientos operativos controlados.',
            'documents' => $documents,
            'units' => $units,
            'locations' => $locations,
            'warehouses' => $warehouses,
            'filters' => $filters,
            'scope' => $scope,
            'page' => $page,
            'perPage' => $perPage,
            'totalRows' => $totalRows,
            'totalPages' => max(1, (int) ceil($totalRows / $perPage)),
            'canMove' => auth()->user()->can('inventory.move'),
            'canAdjust' => auth()->user()->can('inventory.adjust'),
            'canReverse' => auth()->user()->can('inventory.reverse'),
        ]);
    }

    private function baseStockQuery(BaseConnection $db, array $scope, array $filters): BaseBuilder
    {
        $builder = $db->table('stock_units su')
            ->join('lots lo', 'lo.id = su.lot_id')
            ->join('species s', 's.id = su.species_id')
            ->join('size_grades sg', 'sg.id = su.size_grade_id')
            ->join('warehouses w', 'w.id = su.warehouse_id')
            ->join('locations loc', 'loc.id = su.location_id')
            ->join('locations pos', 'pos.id = loc.parent_id', 'left')
            ->join('locations sec', 'sec.id = pos.parent_id', 'left')
            ->join('chambers c', 'c.id = loc.chamber_id', 'left')
            ->join('tubs t', 't.id = su.tub_id')
            ->join('voyages v', 'v.id = lo.voyage_id')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->join('quality_inspections qi', "qi.weighing_id = su.weighing_id AND qi.status = 'approved'", 'left', false)
            ->where(['su.company_id' => $scope['company_id'], 'su.site_id' => $scope['site_id']])
            ->where('su.on_hand_weight_kg >', 0)
            ->whereNotIn('su.status', ['dispatched', 'exhausted']);

        if (! empty($scope['warehouse_id'])) {
            $builder->where('su.warehouse_id', (int) $scope['warehouse_id']);
        } elseif ($filters['warehouse_id'] > 0) {
            $builder->where('su.warehouse_id', $filters['warehouse_id']);
        }

        if ($filters['chamber_id'] > 0) {
            $builder->where('loc.chamber_id', $filters['chamber_id']);
        }
        if ($filters['sector_id'] > 0) {
            $builder->where('sec.id', $filters['sector_id']);
        }
        if ($filters['species_id'] > 0) {
            $builder->where('su.species_id', $filters['species_id']);
        }
        if ($filters['size_grade_id'] > 0) {
            $builder->where('su.size_grade_id', $filters['size_grade_id']);
        }
        if ($filters['lot_id'] > 0) {
            $builder->where('su.lot_id', $filters['lot_id']);
        }
        if ($filters['voyage_id'] > 0) {
            $builder->where('lo.voyage_id', $filters['voyage_id']);
        }
        if ($filters['status'] !== '') {
            $builder->where('su.status', $filters['status']);
        }

        if ($filters['quality'] !== '') {
            match ($filters['quality']) {
                'sin_incidencia' => $builder->where('qi.id', null),
                'observado' => $builder->where('qi.disposition', 'hold'),
                'rechazado' => $builder->where('qi.disposition', 'rejected'),
                'liberado' => $builder->whereIn('qi.disposition', ['accepted', 'released']),
                default => null,
            };
        }

        if ($filters['date_from'] !== '') {
            $builder->where('su.received_at >=', $filters['date_from'] . ' 00:00:00');
        }
        if ($filters['date_to'] !== '') {
            $builder->where('su.received_at <=', $filters['date_to'] . ' 23:59:59');
        }

        if ($filters['q'] !== '') {
            $q = $filters['q'];
            $builder->groupStart()
                ->like('su.unit_code', $q)
                ->orLike('t.code', $q)
                ->orLike('t.barcode', $q)
                ->orLike('lo.code', $q)
                ->orLike('s.name', $q)
                ->orLike('sg.name', $q)
                ->orLike('ve.code', $q)
                ->orLike('ve.name', $q)
                ->orLike('loc.path', $q)
            ->groupEnd();
        }

        return $builder;
    }

    private function loadKpis(BaseConnection $db, array $scope, array $filters): array
    {
        $builder = $this->baseStockQuery($db, $scope, $filters);
        $builder->select(
            "COUNT(su.id) AS units, " .
            "COUNT(DISTINCT su.lot_id) AS lots, " .
            "COALESCE(SUM(su.on_hand_weight_kg),0) AS total_kg, " .
            "COALESCE(SUM(su.reserved_weight_kg),0) AS reserved_kg, " .
            "COALESCE(SUM(CASE WHEN su.status IN ('available','reserved') THEN GREATEST(su.on_hand_weight_kg - su.reserved_weight_kg,0) ELSE 0 END),0) AS available_kg, " .
            "COALESCE(SUM(CASE WHEN su.status = 'held' THEN su.on_hand_weight_kg ELSE 0 END),0) AS held_kg, " .
            "COALESCE(SUM(CASE WHEN su.status = 'rejected' THEN su.on_hand_weight_kg ELSE 0 END),0) AS rejected_kg",
            false
        );
        return $builder->get()->getRowArray() ?? [
            'units' => 0, 'lots' => 0, 'total_kg' => 0, 'reserved_kg' => 0,
            'available_kg' => 0, 'held_kg' => 0, 'rejected_kg' => 0,
        ];
    }

    private function loadUnits(BaseConnection $db, array $scope, array $filters, int $page, int $perPage): array
    {
        $countBuilder = $this->baseStockQuery($db, $scope, $filters);
        $total = $countBuilder->countAllResults();

        $builder = $this->baseStockQuery($db, $scope, $filters);
        $builder->select(
            "su.id, su.unit_code, su.status, su.quality_grade, su.hold_reason, su.on_hand_weight_kg, su.reserved_weight_kg, su.received_at, " .
            "lo.id AS lot_id, lo.code AS lot_code, s.id AS species_id, s.name AS species_name, sg.id AS size_grade_id, sg.name AS size_name, " .
            "w.name AS warehouse_name, loc.path AS location_path, loc.code AS leaf_code, pos.code AS position_code, " .
            "sec.id AS sector_id, sec.code AS sector_code, sec.name AS sector_name, sec.location_type AS sector_type, " .
            "c.id AS chamber_id, c.code AS chamber_code, c.name AS chamber_name, t.code AS tub_code, " .
            "v.id AS voyage_id, v.annual_number, v.year, ve.code AS vessel_code, ve.name AS vessel_name, " .
            "qi.disposition AS quality_disposition, qi.quality_grade AS inspection_quality_grade, " .
            "CASE " .
            "WHEN qi.id IS NULL THEN 'sin_incidencia' " .
            "WHEN qi.disposition = 'hold' THEN 'observado' " .
            "WHEN qi.disposition = 'rejected' THEN 'rechazado' " .
            "WHEN qi.disposition IN ('accepted','released') THEN 'liberado' " .
            "ELSE 'revision' END AS quality_state",
            false
        )
        ->orderBy('su.received_at', 'DESC')
        ->orderBy('su.id', 'DESC')
        ->limit($perPage, ($page - 1) * $perPage);

        return [$builder->get()->getResultArray(), $total];
    }

    private function loadSummary(BaseConnection $db, array $scope, array $filters, string $groupBy, int $page, int $perPage): array
    {
        $config = match ($groupBy) {
            'lote' => [
                'select' => "lo.id AS group_id, lo.code AS group_label, CONCAT(s.name, ' · ', sg.name) AS group_subtitle, lo.id AS lot_id, NULL AS chamber_id, lo.voyage_id AS voyage_id",
                'group' => ['lo.id', 'lo.code', 's.name', 'sg.name', 'lo.voyage_id'],
            ],
            'camara' => [
                'select' => "c.id AS group_id, COALESCE(c.name,c.code,'Sin cámara') AS group_label, w.name AS group_subtitle, NULL AS lot_id, c.id AS chamber_id, NULL AS voyage_id",
                'group' => ['c.id', 'c.name', 'c.code', 'w.name'],
            ],
            'marea' => [
                'select' => "v.id AS group_id, CONCAT(ve.code, ' · Marea ', v.annual_number, '/', v.year) AS group_label, ve.name AS group_subtitle, NULL AS lot_id, NULL AS chamber_id, v.id AS voyage_id",
                'group' => ['v.id', 've.code', 've.name', 'v.annual_number', 'v.year'],
            ],
            default => [
                'select' => "CONCAT(su.species_id, '-', su.size_grade_id) AS group_id, CONCAT(s.name, ' · ', sg.name) AS group_label, 'Especie / talla' AS group_subtitle, NULL AS lot_id, NULL AS chamber_id, NULL AS voyage_id, su.species_id AS species_id, su.size_grade_id AS size_grade_id",
                'group' => ['su.species_id', 'su.size_grade_id', 's.name', 'sg.name'],
            ],
        };

        $countBuilder = $this->baseStockQuery($db, $scope, $filters);
        $countBuilder->select($config['select'], false);
        foreach ($config['group'] as $field) {
            $countBuilder->groupBy($field);
        }
        $total = $countBuilder->get()->getNumRows();

        $builder = $this->baseStockQuery($db, $scope, $filters);
        $builder->select(
            $config['select'] . ", " .
            "COUNT(su.id) AS unit_count, COUNT(DISTINCT su.lot_id) AS lot_count, " .
            "COALESCE(SUM(su.on_hand_weight_kg),0) AS total_kg, " .
            "COALESCE(SUM(su.reserved_weight_kg),0) AS reserved_kg, " .
            "COALESCE(SUM(CASE WHEN su.status IN ('available','reserved') THEN GREATEST(su.on_hand_weight_kg - su.reserved_weight_kg,0) ELSE 0 END),0) AS available_kg, " .
            "COALESCE(SUM(CASE WHEN su.status = 'held' THEN su.on_hand_weight_kg ELSE 0 END),0) AS held_kg, " .
            "COALESCE(SUM(CASE WHEN su.status = 'rejected' THEN su.on_hand_weight_kg ELSE 0 END),0) AS rejected_kg, " .
            "MAX(su.received_at) AS last_received_at",
            false
        );
        foreach ($config['group'] as $field) {
            $builder->groupBy($field);
        }
        $builder->orderBy('total_kg', 'DESC', false)
            ->limit($perPage, ($page - 1) * $perPage);

        return [$builder->get()->getResultArray(), $total];
    }

    private function loadFilterOptions(BaseConnection $db, array $scope, array $filters): array
    {
        $warehouseBuilder = $db->table('warehouses')
            ->select('id, code, name')
            ->where(['company_id' => $scope['company_id'], 'site_id' => $scope['site_id'], 'active' => 1])
            ->orderBy('name');
        if (! empty($scope['warehouse_id'])) {
            $warehouseBuilder->where('id', (int) $scope['warehouse_id']);
        }
        $warehouses = $warehouseBuilder->get()->getResultArray();
        $warehouseIds = array_map('intval', array_column($warehouses, 'id')) ?: [0];

        $chambers = $db->table('chambers')
            ->select('id, warehouse_id, code, name')
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('active', 1)
            ->orderBy('code')
            ->get()->getResultArray();

        $sectorBuilder = $db->table('locations')
            ->select('id, warehouse_id, chamber_id, code, name, location_type, path')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('location_type', ['row', 'aisle'])
            ->where('active', 1)
            ->orderBy('path');
        if ($filters['chamber_id'] > 0) {
            $sectorBuilder->where('chamber_id', $filters['chamber_id']);
        }

        $voyages = $db->table('voyages v')
            ->select('v.id, v.annual_number, v.year, ve.code AS vessel_code, ve.name AS vessel_name')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->where(['v.company_id' => $scope['company_id'], 'v.site_id' => $scope['site_id']])
            ->orderBy('v.year', 'DESC')->orderBy('v.annual_number', 'DESC')
            ->limit(250)->get()->getResultArray();

        return [
            'warehouses' => $warehouses,
            'chambers' => $chambers,
            'sectors' => $sectorBuilder->get()->getResultArray(),
            'species' => $db->table('species')->select('id, code, name')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'sizes' => $db->table('size_grades')->select('id, species_id, code, name')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'lots' => $db->table('lots')->select('id, code, voyage_id')->whereIn('voyage_id', array_map('intval', array_column($voyages, 'id')) ?: [0])->orderBy('id', 'DESC')->limit(500)->get()->getResultArray(),
            'voyages' => $voyages,
            'statuses' => [
                'available' => 'Disponible',
                'reserved' => 'Reservado',
                'held' => 'Retenido',
                'rejected' => 'Rechazado',
                'in_transit' => 'En tránsito',
                'received' => 'Recibido',
            ],
            'qualities' => [
                'sin_incidencia' => 'Sin incidencia',
                'observado' => 'Observado / retenido',
                'liberado' => 'Revisado y liberado',
                'rechazado' => 'Rechazado por Calidad',
            ],
        ];
    }
}
