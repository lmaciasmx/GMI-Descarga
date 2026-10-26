<?php

namespace App\Modules\Inventory\Controllers;

use App\Controllers\BaseController;

final class InventoryCountPageController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();
        $warehousesBuilder = $db->table('warehouses')->where([
            'company_id' => $scope['company_id'], 'site_id' => $scope['site_id'], 'active' => 1,
        ])->orderBy('name');
        if (! empty($scope['warehouse_id'])) {
            $warehousesBuilder->where('id', $scope['warehouse_id']);
        }
        $warehouses = $warehousesBuilder->get()->getResultArray();
        $warehouseIds = array_map('intval', array_column($warehouses, 'id')) ?: [0];

        $counts = $db->table('inventory_counts ic')
            ->select('ic.*, w.code AS warehouse_code, w.name AS warehouse_name, c.code AS chamber_code, c.name AS chamber_name')
            ->join('warehouses w', 'w.id = ic.warehouse_id')
            ->join('chambers c', 'c.id = ic.chamber_id', 'left')
            ->whereIn('ic.warehouse_id', $warehouseIds)
            ->orderBy('ic.created_at', 'DESC')->limit(100)->get()->getResultArray();

        $selectedId = (int) ($this->request->getGet('count') ?? 0);
        if ($selectedId === 0) {
            foreach ($counts as $candidate) {
                if (in_array($candidate['status'], ['counting', 'counted'], true)) { $selectedId = (int) $candidate['id']; break; }
            }
        }
        $selected = null;
        foreach ($counts as $candidate) {
            if ((int) $candidate['id'] === $selectedId) { $selected = $candidate; break; }
        }

        $summary = ['expected' => 0, 'pending' => 0, 'match' => 0, 'relocated' => 0, 'missing' => 0, 'unexpected' => 0];
        $observations = [];
        $rows = [];
        if ($selected !== null) {
            $summary['expected'] = (int) $db->table('inventory_count_lines')->where('inventory_count_id', $selectedId)->countAllResults();
            foreach (['pending', 'match', 'relocated', 'missing'] as $result) {
                $summary[$result] = (int) $db->table('inventory_count_lines')->where(['inventory_count_id' => $selectedId, 'result' => $result])->countAllResults();
            }
            $summary['unexpected'] = (int) $db->table('inventory_count_unexpected')->where('inventory_count_id', $selectedId)->countAllResults();

            $expectedRows = $db->query(<<<'SQL'
SELECT cl.stock_unit_id, su.unit_code, t.code AS tub_code, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name,
       cl.result, cl.counted_at,
       COALESCE(erow.name, erow.code, 'Fila no definida') AS expected_row,
       COALESCE(crow.name, crow.code, '') AS counted_row
FROM inventory_count_lines cl
INNER JOIN stock_units su ON su.id = cl.stock_unit_id
LEFT JOIN tubs t ON t.id = su.tub_id
LEFT JOIN lots lo ON lo.id = su.lot_id
LEFT JOIN species s ON s.id = su.species_id
LEFT JOIN size_grades sg ON sg.id = su.size_grade_id
INNER JOIN locations el ON el.id = cl.expected_location_id
LEFT JOIN locations ep ON ep.id = el.parent_id
LEFT JOIN locations erow ON erow.id = CASE WHEN el.location_type IN ('row','aisle') THEN el.id WHEN ep.location_type IN ('row','aisle') THEN ep.id ELSE ep.parent_id END
LEFT JOIN locations crow ON crow.id = cl.counted_location_id
WHERE cl.inventory_count_id = ? AND cl.result <> 'pending'
ORDER BY cl.counted_at DESC, cl.id DESC
LIMIT 100
SQL, [$selectedId])->getResultArray();
            foreach ($expectedRows as $row) { $row['source'] = 'expected'; $observations[] = $row; }

            $unexpectedRows = $db->query(<<<'SQL'
SELECT u.stock_unit_id, su.unit_code, t.code AS tub_code, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name,
       'unexpected' AS result, u.counted_at, '' AS expected_row, COALESCE(l.name, l.code, '') AS counted_row
FROM inventory_count_unexpected u
INNER JOIN stock_units su ON su.id = u.stock_unit_id
LEFT JOIN tubs t ON t.id = su.tub_id
LEFT JOIN lots lo ON lo.id = su.lot_id
LEFT JOIN species s ON s.id = su.species_id
LEFT JOIN size_grades sg ON sg.id = su.size_grade_id
LEFT JOIN locations l ON l.id = u.counted_location_id
WHERE u.inventory_count_id = ?
ORDER BY u.counted_at DESC, u.id DESC
LIMIT 100
SQL, [$selectedId])->getResultArray();
            foreach ($unexpectedRows as $row) { $row['source'] = 'unexpected'; $observations[] = $row; }
            usort($observations, static fn(array $a, array $b): int => strcmp((string) ($b['counted_at'] ?? ''), (string) ($a['counted_at'] ?? '')));
            $observations = array_slice($observations, 0, 100);

            $rowBuilder = $db->table('locations l')
                ->select('l.id, l.warehouse_id, l.chamber_id, l.code, l.name, c.code AS chamber_code, c.name AS chamber_name')
                ->join('chambers c', 'c.id = l.chamber_id')
                ->where('l.warehouse_id', (int) $selected['warehouse_id'])
                ->where('l.active', 1)->whereIn('l.location_type', ['row', 'aisle'])
                ->orderBy('c.name')->orderBy('l.code');
            if ($selected['chamber_id'] !== null) { $rowBuilder->where('l.chamber_id', (int) $selected['chamber_id']); }
            $rows = $rowBuilder->get()->getResultArray();
        }

        return view('App\\Modules\\Inventory\\Views\\counts', [
            'title' => 'Conteos',
            'subtitle' => 'Inventario físico por identificación y ubicación, sin volver a pesar las tinas.',
            'warehouses' => $warehouses,
            'chambers' => $db->table('chambers')->whereIn('warehouse_id', $warehouseIds)->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'counts' => $counts,
            'selected' => $selected,
            'summary' => $summary,
            'observations' => $observations,
            'rows' => $rows,
        ]);
    }
}
