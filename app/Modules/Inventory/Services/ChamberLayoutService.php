<?php

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\DomainException;
use CodeIgniter\Database\BaseConnection;

final class ChamberLayoutService
{
    public function __construct(
        private readonly BaseConnection $db,
    ) {
    }

    public function chambers(): array
    {
        $builder = $this->db->table('chambers c')
            ->select('c.id, c.warehouse_id, c.code, c.name, w.code AS warehouse_code, w.name AS warehouse_name')
            ->join('warehouses w', 'w.id = c.warehouse_id')
            ->where('c.active', 1)
            ->where('w.active', 1);

        $scope = service('scopeContext')->restrictions();

        if (($scope['configured'] ?? false) === true) {
            if (($scope['warehouse_id'] ?? null) !== null) {
                $builder->where('c.warehouse_id', (int) $scope['warehouse_id']);
            } else {
                if (($scope['company_id'] ?? null) !== null) {
                    $builder->where('w.company_id', (int) $scope['company_id']);
                }

                if (($scope['site_id'] ?? null) !== null) {
                    $builder->where('w.site_id', (int) $scope['site_id']);
                }
            }
        }

        return $builder
            ->orderBy('w.name')
            ->orderBy('c.code')
            ->get()
            ->getResultArray();
    }

    public function layout(int $chamberId): array
    {
        $chamber = $this->findChamber($chamberId);
        service('scopeContext')->assertWarehouse((int) $chamber['warehouse_id']);

        $rows = $this->db->table('locations s')
            ->select([
                's.id',
                's.code',
                's.name',
                's.path',
                's.active',
                'p.id AS position_id',
                'p.code AS position_code',
                'p.name AS position_name',
                'p.path AS position_path',
                'sector.id AS sector_id',
                'sector.code AS sector_code',
                'sector.name AS sector_name',
                'sector.location_type AS sector_type',
            ])
            ->join('locations p', 'p.id = s.parent_id')
            ->join('locations sector', 'sector.id = p.parent_id')
            ->where('s.chamber_id', $chamberId)
            ->whereIn('s.location_type', ['stack_level', 'stack'])
            ->where('s.active', 1)
            ->where('p.active', 1)
            ->where('sector.active', 1)
            ->orderBy('sector.code')
            ->orderBy('p.code')
            ->orderBy('s.code')
            ->get()
            ->getResultArray();

        $stackIds = array_map('intval', array_column($rows, 'id'));

        $restrictions = [];
        $stock = [];

        if ($stackIds !== []) {
            $restrictionRows = $this->db->table('location_restrictions')
                ->select('id, location_id, restriction_type, reason, notes, created_at')
                ->whereIn('location_id', $stackIds)
                ->where('active', 1)
                ->orderBy('id', 'DESC')
                ->get()
                ->getResultArray();

            foreach ($restrictionRows as $restriction) {
                $locationId = (int) $restriction['location_id'];
                if (! isset($restrictions[$locationId])) {
                    $restrictions[$locationId] = $restriction;
                }
            }

            $stockRows = $this->db->table('stock_units su')
                ->select([
                    'su.id',
                    'su.location_id',
                    'su.unit_code',
                    'su.tub_id',
                    'su.on_hand_weight_kg',
                    'su.status',
                    't.code AS tub_code',
                    'sp.code AS species_code',
                    'sp.name AS species_name',
                    'sg.code AS size_code',
                    'sg.name AS size_name',
                ])
                ->join('tubs t', 't.id = su.tub_id')
                ->join('species sp', 'sp.id = su.species_id')
                ->join('size_grades sg', 'sg.id = su.size_grade_id')
                ->whereIn('su.location_id', $stackIds)
                ->where('su.on_hand_weight_kg >', 0)
                ->whereNotIn('su.status', ['in_transit', 'dispatched', 'exhausted'])
                ->orderBy('su.id', 'DESC')
                ->get()
                ->getResultArray();

            foreach ($stockRows as $unit) {
                $locationId = (int) $unit['location_id'];
                $stock[$locationId] ??= [];
                $stock[$locationId][] = $unit;
            }
        }

        $sectors = [];

        foreach ($rows as $row) {
            $sectorCode = (string) $row['sector_code'];
            $positionCode = (string) $row['position_code'];
            $stackCode = (string) $row['code'];

            $sectorNumber = $this->extractSectorNumber($sectorCode);
            $positionNumber = $this->extractPositionNumber($positionCode);
            $stackNumber = $this->extractStackNumber($stackCode);

            $sectorKey = (string) $row['sector_id'];

            if (! isset($sectors[$sectorKey])) {
                $sectors[$sectorKey] = [
                    'id' => (int) $row['sector_id'],
                    'code' => $sectorCode,
                    'name' => (string) $row['sector_name'],
                    'type' => (string) $row['sector_type'],
                    'number' => $sectorNumber,
                    'positions' => [],
                ];
            }

            $positionKey = (string) $row['position_id'];

            if (! isset($sectors[$sectorKey]['positions'][$positionKey])) {
                $sectors[$sectorKey]['positions'][$positionKey] = [
                    'id' => (int) $row['position_id'],
                    'code' => $positionCode,
                    'name' => (string) $row['position_name'],
                    'path' => (string) $row['position_path'],
                    'number' => $positionNumber,
                    'stacks' => [],
                ];
            }

            $locationId = (int) $row['id'];
            $restriction = $restrictions[$locationId] ?? null;
            $units = $stock[$locationId] ?? [];

            $state = 'free';

            if ($restriction !== null) {
                $state = 'blocked';
            } elseif ($units !== []) {
                $state = 'occupied';
            }

            $sectors[$sectorKey]['positions'][$positionKey]['stacks'][] = [
                'id' => $locationId,
                'code' => $stackCode,
                'name' => (string) $row['name'],
                'path' => (string) $row['path'],
                'number' => $stackNumber,
                'state' => $state,
                'restriction' => $restriction,
                'stock' => $units,
            ];
        }

        foreach ($sectors as &$sector) {
            foreach ($sector['positions'] as &$position) {
                usort(
                    $position['stacks'],
                    static fn (array $a, array $b): int => $a['number'] <=> $b['number']
                );
            }
            unset($position);

            $sector['positions'] = array_values($sector['positions']);

            usort(
                $sector['positions'],
                static fn (array $a, array $b): int => $b['number'] <=> $a['number']
            );
        }
        unset($sector);

        $sectors = array_values($sectors);

        usort($sectors, static function (array $a, array $b): int {
            $typeOrder = ['row' => 0, 'aisle' => 1];

            $typeA = $typeOrder[$a['type']] ?? 9;
            $typeB = $typeOrder[$b['type']] ?? 9;

            if ($typeA !== $typeB) {
                return $typeA <=> $typeB;
            }

            if ($a['type'] === 'row') {
                return $b['number'] <=> $a['number'];
            }

            return $a['number'] <=> $b['number'];
        });

        $summary = [
            'total' => 0,
            'free' => 0,
            'occupied' => 0,
            'blocked' => 0,
        ];

        foreach ($sectors as $sector) {
            foreach ($sector['positions'] as $position) {
                foreach ($position['stacks'] as $stack) {
                    $summary['total']++;
                    $summary[$stack['state']]++;
                }
            }
        }

        return [
            'chamber' => [
                'id' => (int) $chamber['id'],
                'warehouse_id' => (int) $chamber['warehouse_id'],
                'code' => (string) $chamber['code'],
                'name' => (string) $chamber['name'],
                'warehouse_code' => (string) $chamber['warehouse_code'],
                'warehouse_name' => (string) $chamber['warehouse_name'],
            ],
            'summary' => $summary,
            'sectors' => $sectors,
        ];
    }

    public function restrict(int $locationId, array $payload, int $actorUserId): array
    {
        $location = $this->findStack($locationId);
        service('scopeContext')->assertWarehouse((int) $location['warehouse_id']);

        $type = trim((string) ($payload['restriction_type'] ?? ''));
        $reason = trim((string) ($payload['reason'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));

        $allowedTypes = ['humidifier', 'maintenance', 'obstacle', 'reserved', 'other'];

        if (! in_array($type, $allowedTypes, true)) {
            throw new DomainException(
                'Selecciona un tipo de restricción válido.',
                'INVALID_LOCATION_RESTRICTION_TYPE'
            );
        }

        if ($reason === '') {
            throw new DomainException(
                'Indica el motivo de la inhabilitación.',
                'LOCATION_RESTRICTION_REASON_REQUIRED'
            );
        }

        if (mb_strlen($reason) > 150) {
            throw new DomainException(
                'El motivo no puede superar 150 caracteres.',
                'LOCATION_RESTRICTION_REASON_TOO_LONG'
            );
        }

        $activeStock = $this->activeStockCount($locationId);

        if ($activeStock > 0) {
            throw new DomainException(
                'No se puede inhabilitar una estiba que actualmente contiene inventario.',
                'LOCATION_RESTRICTION_HAS_STOCK'
            );
        }

        $alreadyBlocked = $this->db->table('location_restrictions')
            ->where('location_id', $locationId)
            ->where('active', 1)
            ->countAllResults();

        if ($alreadyBlocked > 0) {
            throw new DomainException(
                'La ubicación ya tiene una restricción activa.',
                'LOCATION_RESTRICTION_ALREADY_ACTIVE'
            );
        }

        $this->db->table('location_restrictions')->insert([
            'location_id' => $locationId,
            'restriction_type' => $type,
            'reason' => $reason,
            'notes' => $notes === '' ? null : $notes,
            'active' => 1,
            'created_by' => $actorUserId > 0 ? $actorUserId : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'id' => (int) $this->db->insertID(),
            'location_id' => $locationId,
            'status' => 'blocked',
        ];
    }

    public function release(int $locationId, int $actorUserId): array
    {
        $location = $this->findStack($locationId);
        service('scopeContext')->assertWarehouse((int) $location['warehouse_id']);

        $active = $this->db->table('location_restrictions')
            ->where('location_id', $locationId)
            ->where('active', 1)
            ->get()
            ->getResultArray();

        if ($active === []) {
            throw new DomainException(
                'La ubicación no tiene una restricción activa.',
                'LOCATION_RESTRICTION_NOT_FOUND'
            );
        }

        $this->db->table('location_restrictions')
            ->where('location_id', $locationId)
            ->where('active', 1)
            ->update([
                'active' => 0,
                'released_by' => $actorUserId > 0 ? $actorUserId : null,
                'released_at' => date('Y-m-d H:i:s'),
            ]);

        return [
            'location_id' => $locationId,
            'status' => 'released',
        ];
    }

    private function findChamber(int $chamberId): array
    {
        $row = $this->db->table('chambers c')
            ->select('c.id, c.warehouse_id, c.code, c.name, w.code AS warehouse_code, w.name AS warehouse_name')
            ->join('warehouses w', 'w.id = c.warehouse_id')
            ->where('c.id', $chamberId)
            ->where('c.active', 1)
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw new DomainException(
                'Cámara no encontrada o inactiva.',
                'CHAMBER_NOT_FOUND'
            );
        }

        return $row;
    }

    private function findStack(int $locationId): array
    {
        $row = $this->db->table('locations')
            ->select('id, warehouse_id, chamber_id, code, name, location_type, active')
            ->where('id', $locationId)
            ->get()
            ->getRowArray();

        if (
            $row === null
            || (int) $row['active'] !== 1
            || ! in_array((string) $row['location_type'], ['stack_level', 'stack'], true)
        ) {
            throw new DomainException(
                'La ubicación seleccionada no es una estiba activa.',
                'STACK_LOCATION_NOT_FOUND'
            );
        }

        return $row;
    }

    private function activeStockCount(int $locationId): int
    {
        return $this->db->table('stock_units')
            ->where('location_id', $locationId)
            ->where('on_hand_weight_kg >', 0)
            ->whereNotIn('status', ['in_transit', 'dispatched', 'exhausted'])
            ->countAllResults();
    }

    private function extractSectorNumber(string $code): int
    {
        if (preg_match('/(?:^|-)(?:F|P)0*(\d+)(?:-|$)/i', $code, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d+)(?!.*\d)/', $code, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function extractPositionNumber(string $code): int
    {
        if (preg_match('/(?:POS|POSICION)[-_]?0*(\d+)/i', $code, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/-P0*(\d+)(?:-|$)/i', $code, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d+)(?!.*\d)/', $code, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function extractStackNumber(string $code): int
    {
        if (preg_match('/(?:^|-)E0*(\d+)(?:-|$)/i', $code, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d+)(?!.*\d)/', $code, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
