<?php

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Common\Services\FolioService;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class InventoryCountService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly FolioService $folios,
        private readonly AuditService $audit,
    ) {
    }

    public function start(int $warehouseId, ?int $chamberId, int $actorUserId, ?string $observations = null): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $warehouse = $this->db->query('SELECT * FROM warehouses WHERE id = ? FOR UPDATE', [$warehouseId])->getRowArray();
            if ($warehouse === null || ! (bool) $warehouse['active']) {
                throw new DomainException('Almacén no encontrado.', 'WAREHOUSE_NOT_FOUND');
            }
            if ($chamberId !== null && $this->db->table('chambers')->where(['id' => $chamberId, 'warehouse_id' => $warehouseId, 'active' => 1])->countAllResults() !== 1) {
                throw new DomainException('La cámara no pertenece al almacén seleccionado.', 'COUNT_CHAMBER_OUT_OF_SCOPE');
            }

            // Conservamos una sola fotografía abierta por almacén para evitar alcances traslapados.
            if ($this->db->table('inventory_counts')->where('warehouse_id', $warehouseId)->whereIn('status', ['planned', 'counting', 'counted'])->countAllResults() > 0) {
                throw new DomainException('Ya existe un conteo abierto para el almacén.', 'OPEN_INVENTORY_COUNT_EXISTS');
            }

            $now = date('Y-m-d H:i:s');
            $folio = $this->folios->next('CON');
            $this->db->table('inventory_counts')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'folio' => $folio,
                'warehouse_id' => $warehouseId,
                'chamber_id' => $chamberId,
                'status' => 'counting',
                'frozen_at' => $now,
                'created_by' => $actorUserId,
                'observations' => $observations,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $countId = (int) $this->db->insertID();

            $builder = $this->db->table('stock_units su')
                ->select('su.id, su.location_id, su.on_hand_weight_kg, su.lock_version')
                ->join('locations l', 'l.id = su.location_id')
                ->where('su.warehouse_id', $warehouseId)
                ->where('su.on_hand_weight_kg >', 0)
                ->whereNotIn('su.status', ['in_transit', 'dispatched', 'exhausted']);
            if ($chamberId !== null) {
                $builder->where('l.chamber_id', $chamberId);
            }

            $units = $builder->get()->getResultArray();
            foreach ($units as $unit) {
                $this->db->table('inventory_count_lines')->insert([
                    'inventory_count_id' => $countId,
                    'stock_unit_id' => (int) $unit['id'],
                    'expected_location_id' => (int) $unit['location_id'],
                    // Se conserva el peso exclusivamente como parte de la fotografía de concurrencia.
                    // Ya no se captura ni se concilia peso físicamente.
                    'expected_weight_kg' => (float) $unit['on_hand_weight_kg'],
                    'expected_lock_version' => (int) $unit['lock_version'],
                    'counted_weight_kg' => null,
                    'result' => 'pending',
                ]);
            }

            $this->audit->record('inventory_count.started', 'inventory_count', $countId, $actorUserId, (int) $warehouse['company_id'], (int) $warehouse['site_id'], null, ['folio' => $folio, 'unit_count' => count($units)]);
            $this->db->transCommit();
            return ['id' => $countId, 'folio' => $folio, 'status' => 'counting', 'unit_count' => count($units)];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function lookup(int $countId, string $code): array
    {
        $count = $this->count($countId);
        $code = trim($code);
        if ($code === '') {
            throw new DomainException('Captura o escanea una tina.', 'COUNT_UNIT_CODE_REQUIRED');
        }
        if (str_starts_with(strtoupper($code), 'GMAR|LABEL=')) {
            $code = substr($code, strlen('GMAR|LABEL='));
        }

        $stock = $this->findStockByCode($count, $code);
        if ($stock === null) {
            throw new DomainException('No se encontró una tina con ese código dentro del almacén.', 'COUNT_STOCK_UNIT_NOT_FOUND');
        }

        $line = $this->db->table('inventory_count_lines cl')
            ->select('cl.id, cl.expected_location_id, cl.counted_location_id, cl.result, cl.counted_at')
            ->where(['cl.inventory_count_id' => $countId, 'cl.stock_unit_id' => (int) $stock['id']])
            ->get()->getRowArray();

        $expectedRow = $line !== null ? $this->rowForLocation((int) $line['expected_location_id']) : null;
        $countedRow = $line !== null && $line['counted_location_id'] !== null ? $this->rowLocation((int) $line['counted_location_id']) : null;
        $unexpected = $this->db->table('inventory_count_unexpected')->where([
            'inventory_count_id' => $countId,
            'stock_unit_id' => (int) $stock['id'],
        ])->get()->getRowArray();

        return [
            'stock_unit_id' => (int) $stock['id'],
            'unit_code' => (string) $stock['unit_code'],
            'tub_code' => (string) ($stock['tub_code'] ?? ''),
            'label_code' => (string) ($stock['label_code'] ?? ''),
            'lot_code' => (string) ($stock['lot_code'] ?? ''),
            'species' => (string) ($stock['species_name'] ?? ''),
            'size' => (string) ($stock['size_name'] ?? ''),
            'status' => (string) $stock['status'],
            'expected' => $line !== null,
            'result' => $line['result'] ?? ($unexpected !== null ? 'unexpected' : 'not_recorded'),
            'expected_row' => $expectedRow,
            'counted_row' => $countedRow,
            'unexpected_recorded' => $unexpected !== null,
        ];
    }

    public function record(int $countId, int $stockUnitId, int $rowLocationId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $count = $this->db->query('SELECT * FROM inventory_counts WHERE id = ? FOR UPDATE', [$countId])->getRowArray();
            if ($count === null || $count['status'] !== 'counting') {
                throw new DomainException('El conteo no está activo.', 'INVENTORY_COUNT_NOT_ACTIVE');
            }
            $row = $this->validateRowScope($count, $rowLocationId, true);
            $line = $this->db->query('SELECT * FROM inventory_count_lines WHERE inventory_count_id = ? AND stock_unit_id = ? FOR UPDATE', [$countId, $stockUnitId])->getRowArray();
            $now = date('Y-m-d H:i:s');

            if ($line !== null) {
                $expectedRow = $this->rowForLocation((int) $line['expected_location_id']);
                $result = $expectedRow !== null && (int) $expectedRow['id'] === $rowLocationId ? 'match' : 'relocated';
                $this->db->table('inventory_count_lines')->where('id', (int) $line['id'])->update([
                    'counted_location_id' => $rowLocationId,
                    'counted_weight_kg' => null,
                    'result' => $result,
                    'counted_by' => $actorUserId,
                    'counted_at' => $now,
                ]);
                $this->db->transCommit();
                return ['stock_unit_id' => $stockUnitId, 'result' => $result, 'expected' => true, 'row' => $this->publicRow($row)];
            }

            $stock = $this->db->query('SELECT * FROM stock_units WHERE id = ? FOR UPDATE', [$stockUnitId])->getRowArray();
            if ($stock === null || (int) $stock['warehouse_id'] !== (int) $count['warehouse_id'] || (float) $stock['on_hand_weight_kg'] <= 0) {
                throw new DomainException('La tina no está disponible dentro del almacén de este conteo.', 'COUNT_UNEXPECTED_UNIT_OUT_OF_SCOPE');
            }

            $existing = $this->db->table('inventory_count_unexpected')->where([
                'inventory_count_id' => $countId,
                'stock_unit_id' => $stockUnitId,
            ])->get()->getRowArray();
            $data = [
                'counted_location_id' => $rowLocationId,
                'result' => 'unexpected',
                'counted_by' => $actorUserId,
                'counted_at' => $now,
                'updated_at' => $now,
            ];
            if ($existing === null) {
                $this->db->table('inventory_count_unexpected')->insert($data + [
                    'inventory_count_id' => $countId,
                    'stock_unit_id' => $stockUnitId,
                    'created_at' => $now,
                ]);
            } else {
                $this->db->table('inventory_count_unexpected')->where('id', (int) $existing['id'])->update($data);
            }

            $this->db->transCommit();
            return ['stock_unit_id' => $stockUnitId, 'result' => 'unexpected', 'expected' => false, 'row' => $this->publicRow($row)];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function markMissing(int $countId, int $stockUnitId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $count = $this->db->query('SELECT * FROM inventory_counts WHERE id = ? FOR UPDATE', [$countId])->getRowArray();
            if ($count === null || $count['status'] !== 'counting') {
                throw new DomainException('El conteo no está activo.', 'INVENTORY_COUNT_NOT_ACTIVE');
            }
            $line = $this->db->query('SELECT * FROM inventory_count_lines WHERE inventory_count_id = ? AND stock_unit_id = ? FOR UPDATE', [$countId, $stockUnitId])->getRowArray();
            if ($line === null) {
                throw new DomainException('Sólo una tina esperada puede marcarse como no encontrada.', 'COUNT_MISSING_REQUIRES_EXPECTED_UNIT');
            }
            $now = date('Y-m-d H:i:s');
            $this->db->table('inventory_count_lines')->where('id', (int) $line['id'])->update([
                'counted_location_id' => null,
                'counted_weight_kg' => null,
                'result' => 'missing',
                'counted_by' => $actorUserId,
                'counted_at' => $now,
            ]);
            $this->db->transCommit();
            return ['stock_unit_id' => $stockUnitId, 'result' => 'missing'];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function finish(int $countId, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $count = $this->db->query('SELECT * FROM inventory_counts WHERE id = ? FOR UPDATE', [$countId])->getRowArray();
            if ($count === null || $count['status'] !== 'counting') {
                throw new DomainException('El conteo no está activo.', 'INVENTORY_COUNT_NOT_ACTIVE');
            }
            $pending = $this->db->table('inventory_count_lines')->where('inventory_count_id', $countId)->where('result', 'pending')->countAllResults();
            if ($pending > 0) {
                throw new DomainException('Aún hay tinas pendientes por confirmar o marcar como no encontradas.', 'INVENTORY_COUNT_INCOMPLETE', ['pending_lines' => $pending]);
            }
            $now = date('Y-m-d H:i:s');
            $this->db->table('inventory_counts')->where('id', $countId)->update(['status' => 'counted', 'counted_at' => $now, 'updated_at' => $now]);
            $warehouse = $this->db->table('warehouses')->where('id', $count['warehouse_id'])->get()->getRowArray();
            $this->audit->record('inventory_count.finished', 'inventory_count', $countId, $actorUserId, (int) $warehouse['company_id'], (int) $warehouse['site_id'], ['status' => 'counting'], ['status' => 'counted']);
            $this->db->transCommit();
            return ['id' => $countId, 'status' => 'counted'];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function listItems(int $countId, string $result, string $search = '', int $page = 1, int $perPage = 25): array
    {
        $count = $this->count($countId);
        $allowed = ['expected', 'pending', 'match', 'relocated', 'missing', 'unexpected'];
        if (! in_array($result, $allowed, true)) {
            throw new DomainException('El filtro solicitado no es válido.', 'COUNT_RESULT_FILTER_INVALID');
        }

        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $search = trim($search);

        if ($result === 'unexpected') {
            $builder = $this->db->table('inventory_count_unexpected u')
                ->select("u.stock_unit_id, su.unit_code, t.code AS tub_code, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name, 'unexpected' AS result, u.counted_at, NULL AS expected_row, NULL AS expected_chamber, COALESCE(crow.name, crow.code, '') AS counted_row, cc.name AS counted_chamber")
                ->join('stock_units su', 'su.id = u.stock_unit_id')
                ->join('tubs t', 't.id = su.tub_id', 'left')
                ->join('lots lo', 'lo.id = su.lot_id', 'left')
                ->join('species s', 's.id = su.species_id', 'left')
                ->join('size_grades sg', 'sg.id = su.size_grade_id', 'left')
                ->join('locations crow', 'crow.id = u.counted_location_id', 'left')
                ->join('chambers cc', 'cc.id = crow.chamber_id', 'left')
                ->where('u.inventory_count_id', $countId);

            if ($search !== '') {
                $builder->groupStart()
                    ->like('su.unit_code', $search)
                    ->orLike('t.code', $search)
                    ->orLike('lo.code', $search)
                    ->orLike('s.name', $search)
                    ->orLike('sg.name', $search)
                    ->groupEnd();
            }

            $totalBuilder = clone $builder;
            $total = (int) $totalBuilder->countAllResults();
            $items = $builder->orderBy('u.counted_at', 'DESC')->orderBy('u.id', 'DESC')->limit($perPage, $offset)->get()->getResultArray();
        } else {
            $builder = $this->db->table('inventory_count_lines cl')
                ->select("cl.stock_unit_id, su.unit_code, t.code AS tub_code, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name, cl.result, cl.counted_at, COALESCE(erow.name, erow.code, 'Fila no definida') AS expected_row, ec.name AS expected_chamber, COALESCE(crow.name, crow.code, '') AS counted_row, cc.name AS counted_chamber")
                ->join('stock_units su', 'su.id = cl.stock_unit_id')
                ->join('tubs t', 't.id = su.tub_id', 'left')
                ->join('lots lo', 'lo.id = su.lot_id', 'left')
                ->join('species s', 's.id = su.species_id', 'left')
                ->join('size_grades sg', 'sg.id = su.size_grade_id', 'left')
                ->join('locations el', 'el.id = cl.expected_location_id')
                ->join('locations ep', 'ep.id = el.parent_id', 'left')
                ->join('locations erow', "erow.id = CASE WHEN el.location_type IN ('row','aisle') THEN el.id WHEN ep.location_type IN ('row','aisle') THEN ep.id ELSE ep.parent_id END", 'left', false)
                ->join('chambers ec', 'ec.id = el.chamber_id', 'left')
                ->join('locations crow', 'crow.id = cl.counted_location_id', 'left')
                ->join('chambers cc', 'cc.id = crow.chamber_id', 'left')
                ->where('cl.inventory_count_id', $countId);

            if ($result !== 'expected') {
                $builder->where('cl.result', $result);
            }
            if ($search !== '') {
                $builder->groupStart()
                    ->like('su.unit_code', $search)
                    ->orLike('t.code', $search)
                    ->orLike('lo.code', $search)
                    ->orLike('s.name', $search)
                    ->orLike('sg.name', $search)
                    ->groupEnd();
            }

            $totalBuilder = clone $builder;
            $total = (int) $totalBuilder->countAllResults();
            $items = $builder->orderBy('cl.id', 'ASC')->limit($perPage, $offset)->get()->getResultArray();
        }

        $items = array_map(static function (array $row): array {
            return [
                'stock_unit_id' => (int) $row['stock_unit_id'],
                'unit_code' => (string) ($row['unit_code'] ?? ''),
                'tub_code' => (string) ($row['tub_code'] ?? ''),
                'lot_code' => (string) ($row['lot_code'] ?? ''),
                'species' => (string) ($row['species_name'] ?? ''),
                'size' => (string) ($row['size_name'] ?? ''),
                'result' => (string) ($row['result'] ?? 'pending'),
                'counted_at' => $row['counted_at'] ?? null,
                'expected' => trim((string) ($row['expected_chamber'] ?? '') . ' · ' . (string) ($row['expected_row'] ?? ''), ' ·'),
                'counted' => trim((string) ($row['counted_chamber'] ?? '') . ' · ' . (string) ($row['counted_row'] ?? ''), ' ·'),
                'lookup_code' => (string) (($row['tub_code'] ?? '') ?: ($row['unit_code'] ?? '')),
            ];
        }, $items);

        return [
            'count_id' => $countId,
            'filter' => $result,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'items' => $items,
        ];
    }

    private function count(int $countId): array
    {
        $count = $this->db->table('inventory_counts')->where('id', $countId)->get()->getRowArray();
        if ($count === null) {
            throw new DomainException('Conteo no encontrado.', 'INVENTORY_COUNT_NOT_FOUND');
        }
        return $count;
    }

    private function findStockByCode(array $count, string $code): ?array
    {
        return $this->db->table('stock_units su')
            ->select('su.id, su.unit_code, su.status, su.warehouse_id, su.location_id, t.code AS tub_code, lb.code AS label_code, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name')
            ->join('tubs t', 't.id = su.tub_id', 'left')
            ->join('labels lb', 'lb.id = su.label_id', 'left')
            ->join('lots lo', 'lo.id = su.lot_id', 'left')
            ->join('species s', 's.id = su.species_id', 'left')
            ->join('size_grades sg', 'sg.id = su.size_grade_id', 'left')
            ->where('su.warehouse_id', (int) $count['warehouse_id'])
            ->groupStart()
                ->where('su.unit_code', $code)
                ->orWhere('t.code', $code)
                ->orWhere('lb.code', $code)
            ->groupEnd()
            ->get()->getRowArray();
    }

    private function validateRowScope(array $count, int $rowLocationId, bool $lock = false): array
    {
        $sql = 'SELECT l.*, c.code AS chamber_code, c.name AS chamber_name FROM locations l INNER JOIN chambers c ON c.id = l.chamber_id WHERE l.id = ?' . ($lock ? ' FOR UPDATE' : '');
        $row = $this->db->query($sql, [$rowLocationId])->getRowArray();
        if ($row === null || ! (bool) $row['active'] || (int) $row['warehouse_id'] !== (int) $count['warehouse_id'] || ! in_array((string) $row['location_type'], ['row', 'aisle'], true)) {
            throw new DomainException('La fila seleccionada no pertenece al almacén del conteo.', 'COUNT_ROW_OUT_OF_SCOPE');
        }
        if ($count['chamber_id'] !== null && (int) $row['chamber_id'] !== (int) $count['chamber_id']) {
            throw new DomainException('La fila seleccionada no pertenece a la cámara del conteo.', 'COUNT_ROW_OUT_OF_SCOPE');
        }
        return $row;
    }

    private function rowLocation(int $locationId): ?array
    {
        $row = $this->db->table('locations l')->select('l.*, c.code AS chamber_code, c.name AS chamber_name')->join('chambers c', 'c.id = l.chamber_id')->where('l.id', $locationId)->get()->getRowArray();
        return $row !== null && in_array((string) $row['location_type'], ['row', 'aisle'], true) ? $this->publicRow($row) : null;
    }

    private function rowForLocation(int $locationId): ?array
    {
        $location = $this->db->query(<<<'SQL'
SELECT
    l.id AS location_id,
    CASE
        WHEN l.location_type IN ('row','aisle') THEN l.id
        WHEN p.location_type IN ('row','aisle') THEN p.id
        WHEN sector.location_type IN ('row','aisle') THEN sector.id
        ELSE NULL
    END AS row_id
FROM locations l
LEFT JOIN locations p ON p.id = l.parent_id
LEFT JOIN locations sector ON sector.id = p.parent_id
WHERE l.id = ?
SQL, [$locationId])->getRowArray();
        if ($location === null || $location['row_id'] === null) {
            return null;
        }
        return $this->rowLocation((int) $location['row_id']);
    }

    private function publicRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'chamber_id' => (int) $row['chamber_id'],
            'chamber' => (string) ($row['chamber_name'] ?? $row['chamber_code'] ?? ''),
            'code' => (string) $row['code'],
            'name' => (string) ($row['name'] ?? $row['code']),
        ];
    }
}
