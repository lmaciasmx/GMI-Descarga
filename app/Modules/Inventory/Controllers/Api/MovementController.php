<?php

namespace App\Modules\Inventory\Controllers\Api;

use App\Common\Controllers\ApiController;
use App\Common\Exceptions\DomainException;
use App\Modules\Inventory\DTO\CreateMovementData;

final class MovementController extends ApiController
{
    private const STATUS_LABELS = [
        'available' => 'Disponible',
        'reserved' => 'Reservado',
        'held' => 'Retenido',
        'rejected' => 'Rechazado',
        'in_transit' => 'En tránsito',
        'received' => 'Recibido',
        'dispatched' => 'Despachado',
        'exhausted' => 'Agotado',
    ];

    public function show(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('inventory_document', $id);
            $db = db_connect();

            $document = $db->table('inventory_documents d')
                ->select('d.id, d.folio, d.movement_type, d.status, d.reason_code, d.observations, d.confirmed_by, d.confirmed_at, d.source_document_type, d.source_document_id, d.destination_document_type, d.destination_document_id, d.reversal_of_id, ow.name AS origin_warehouse_name, dw.name AS destination_warehouse_name')
                ->join('warehouses ow', 'ow.id = d.origin_warehouse_id', 'left')
                ->join('warehouses dw', 'dw.id = d.destination_warehouse_id', 'left')
                ->where('d.id', $id)
                ->get()->getRowArray();

            if ($document === null) {
                throw new DomainException('Movimiento no encontrado.', 'INVENTORY_MOVEMENT_NOT_FOUND');
            }

            $lines = $db->table('inventory_document_lines dl')
                ->select("dl.id, dl.line_number, dl.quantity_kg, dl.stock_status_before, dl.stock_status_after, su.unit_code, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name, fw.name AS from_warehouse_name, tw.name AS to_warehouse_name, fl.path AS from_location_path, tl.path AS to_location_path, im.quantity_delta_kg, im.resulting_on_hand_kg, im.resulting_reserved_kg, im.actor_user_id, im.occurred_at", false)
                ->join('stock_units su', 'su.id = dl.stock_unit_id')
                ->join('lots lo', 'lo.id = dl.lot_id')
                ->join('species s', 's.id = su.species_id')
                ->join('size_grades sg', 'sg.id = su.size_grade_id')
                ->join('warehouses fw', 'fw.id = dl.from_warehouse_id', 'left')
                ->join('warehouses tw', 'tw.id = dl.to_warehouse_id', 'left')
                ->join('locations fl', 'fl.id = dl.from_location_id', 'left')
                ->join('locations tl', 'tl.id = dl.to_location_id', 'left')
                ->join('inventory_movements im', 'im.inventory_document_line_id = dl.id', 'left')
                ->where('dl.inventory_document_id', $id)
                ->orderBy('dl.line_number')
                ->get()->getResultArray();

            foreach ($lines as &$line) {
                $line['stock_status_before_label'] = self::STATUS_LABELS[$line['stock_status_before']] ?? $line['stock_status_before'];
                $line['stock_status_after_label'] = self::STATUS_LABELS[$line['stock_status_after']] ?? $line['stock_status_after'];
            }
            unset($line);

            return ['document' => $document, 'lines' => $lines];
        });
    }

    public function units()
    {
        return $this->execute(function (): array {
            $q = trim((string) $this->request->getGet('q'));
            if (mb_strlen($q) < 2) {
                return ['items' => []];
            }

            $scope = session('operational_scope') ?: [];
            $companyId = (int) ($scope['company_id'] ?? 0);
            $siteId = (int) ($scope['site_id'] ?? 0);
            if ($companyId <= 0 || $siteId <= 0) {
                throw new DomainException('No hay alcance operativo activo.', 'OPERATIONAL_SCOPE_REQUIRED');
            }

            $db = db_connect();
            $builder = $db->table('stock_units su')
                ->select("su.id, su.unit_code, su.warehouse_id, su.location_id, su.on_hand_weight_kg, su.reserved_weight_kg, su.status, su.quality_grade, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name, w.name AS warehouse_name, t.code AS tub_code, lb.code AS label_code, loc.path AS location_path, sec.id AS sector_id, sec.code AS sector_code, sec.name AS sector_name, c.id AS chamber_id, c.code AS chamber_code, c.name AS chamber_name", false)
                ->join('lots lo', 'lo.id = su.lot_id')
                ->join('species s', 's.id = su.species_id')
                ->join('size_grades sg', 'sg.id = su.size_grade_id')
                ->join('warehouses w', 'w.id = su.warehouse_id')
                ->join('locations loc', 'loc.id = su.location_id')
                ->join('locations pos', 'pos.id = loc.parent_id', 'left')
                ->join('locations sec', 'sec.id = pos.parent_id', 'left')
                ->join('chambers c', 'c.id = loc.chamber_id', 'left')
                ->join('tubs t', 't.id = su.tub_id', 'left')
                ->join('labels lb', "lb.weighing_id = su.weighing_id AND lb.status != 'canceled'", 'left', false)
                ->where(['su.company_id' => $companyId, 'su.site_id' => $siteId])
                ->where('su.on_hand_weight_kg >', 0)
                ->whereNotIn('su.status', ['dispatched', 'exhausted']);

            if (! empty($scope['warehouse_id'])) {
                $builder->where('su.warehouse_id', (int) $scope['warehouse_id']);
            }

            $builder->groupStart()
                ->like('t.code', $q)
                ->orLike('su.unit_code', $q)
                ->orLike('lb.code', $q)
                ->orLike('lo.code', $q)
            ->groupEnd();

            $rows = $builder->orderBy('su.received_at', 'DESC')->limit(25)->get()->getResultArray();
            $items = [];
            foreach ($rows as $row) {
                $tina = trim((string) ($row['tub_code'] ?? ''));
                $unit = trim((string) ($row['unit_code'] ?? ''));
                $label = trim((string) ($row['label_code'] ?? ''));
                $main = $tina !== '' ? $tina : ($label !== '' ? $label : $unit);
                $items[] = $row + [
                    'status_label' => self::STATUS_LABELS[$row['status']] ?? $row['status'],
                    'text' => $main . ' · ' . (string) $row['lot_code'] . ' · ' . number_format((float) $row['on_hand_weight_kg'], 3) . ' kg',
                    'location_display' => $this->sectorDisplay((string) ($row['sector_code'] ?? ''), (string) ($row['sector_name'] ?? '')),
                ];
            }

            return ['items' => $items];
        });
    }

    public function chambers(int $warehouseId)
    {
        return $this->execute(function () use ($warehouseId): array {
            return ['items' => service('warehouseEntryService')->chambersForWarehouse($warehouseId)];
        });
    }

    public function sectors(int $chamberId)
    {
        return $this->execute(function () use ($chamberId): array {
            $result = service('warehouseEntryService')->sectorAvailability($chamberId);
            $items = [];
            foreach ($result['sectors'] ?? [] as $row) {
                $items[] = $row + [
                    'display_name' => $this->sectorDisplay((string) ($row['code'] ?? ''), (string) ($row['name'] ?? '')),
                ];
            }
            return ['items' => $items, 'layout_configured' => (bool) ($result['layout_configured'] ?? false)];
        });
    }

    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'movement_type' => 'required|in_list[relocation,return,loss,adjustment_increase,adjustment_decrease]',
                'company_id' => 'required|is_natural_no_zero',
                'site_id' => 'required|is_natural_no_zero',
                'lines' => 'required|is_array',
                'origin_warehouse_id' => 'permit_empty|is_natural_no_zero',
                'destination_warehouse_id' => 'permit_empty|is_natural_no_zero',
                'source_document_type' => 'permit_empty|max_length[60]',
                'source_document_id' => 'permit_empty|is_natural_no_zero',
                'destination_document_type' => 'permit_empty|max_length[60]',
                'destination_document_id' => 'permit_empty|is_natural_no_zero',
                'reason_code' => 'permit_empty|max_length[50]',
                'observations' => 'permit_empty|max_length[1000]',
                'lines.*.stock_unit_id' => 'required|is_natural_no_zero',
                'lines.*.quantity_kg' => 'required|decimal|greater_than[0]',
                'lines.*.from_location_id' => 'permit_empty|is_natural_no_zero',
                'lines.*.to_location_id' => 'permit_empty|is_natural_no_zero',
                'lines.*.from_warehouse_id' => 'permit_empty|is_natural_no_zero',
                'lines.*.to_warehouse_id' => 'permit_empty|is_natural_no_zero',
            ]);
            if (in_array($payload['movement_type'], ['return', 'loss', 'adjustment_increase', 'adjustment_decrease'], true)) {
                $userCan = function_exists('auth') && auth()->loggedIn() && auth()->user()->can('inventory.adjust');
                $apiCan = service('apiClientContext')->can('inventory.adjust');
                if (! $userCan && ! $apiCan) {
                    throw new DomainException('El movimiento requiere permiso de ajuste de inventario.', 'PERMISSION_DENIED');
                }
            }
            return service('inventoryService')->createMovement(CreateMovementData::fromArray($payload, $this->actorId()));
        }, 201);
    }

    public function reverse(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('inventory_document', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, ['reason' => 'required|min_length[5]|max_length[255]']);
            return service('inventoryService')->reverse($id, (string) $payload['reason'], $this->actorId());
        }, 201);
    }

    private function sectorDisplay(string $code, string $name): string
    {
        $name = trim($name);
        if ($name !== '' && preg_match('/^(fila|pasillo)\b/i', $name)) {
            return $name;
        }
        if (preg_match('/([FP])(\d+)$/i', $code, $m)) {
            return (strtoupper($m[1]) === 'F' ? 'Fila ' : 'Pasillo ') . str_pad((string) ((int) $m[2]), 2, '0', STR_PAD_LEFT);
        }
        return $name !== '' ? $name : $code;
    }
}
