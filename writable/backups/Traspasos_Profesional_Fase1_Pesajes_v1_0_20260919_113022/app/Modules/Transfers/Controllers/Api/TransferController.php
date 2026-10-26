<?php

namespace App\Modules\Transfers\Controllers\Api;

use App\Common\Controllers\ApiController;
use App\Common\Exceptions\DomainException;

final class TransferController extends ApiController
{
    private const STATUS_LABELS = [
        'requested' => 'Solicitado',
        'authorized' => 'Autorizado',
        'prepared' => 'Preparado',
        'shipped' => 'Embarcado',
        'in_transit' => 'En tránsito',
        'discrepancy' => 'Con diferencias',
        'received' => 'Recibido',
        'canceled' => 'Cancelado',
    ];

    private const LINE_STATUS_LABELS = [
        'prepared' => 'Preparada',
        'shipped' => 'En tránsito',
        'received' => 'Recibida',
        'missing' => 'No recibida',
        'discrepancy' => 'Con diferencia',
    ];

    public function show(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('transfer_origin_or_destination', $id);
            $db = db_connect();

            $transfer = $db->table('transfers t')
                ->select('t.*, ow.code AS origin_code, ow.name AS origin_name, ow.site_id AS origin_site_id, dw.code AS destination_code, dw.name AS destination_name, dw.site_id AS destination_site_id, req.folio AS request_folio, rs.folio AS reservation_folio')
                ->join('warehouses ow', 'ow.id = t.origin_warehouse_id')
                ->join('warehouses dw', 'dw.id = t.destination_warehouse_id')
                ->join('raw_material_requests req', 'req.id = t.request_id', 'left')
                ->join('reservations rs', 'rs.id = t.reservation_id', 'left')
                ->where('t.id', $id)->get()->getRowArray();

            if ($transfer === null) {
                throw new DomainException('Traspaso no encontrado.', 'TRANSFER_NOT_FOUND');
            }

            $lines = $db->table('transfer_lines tl')
                ->select("tl.id, tl.line_number, tl.stock_unit_id, tl.shipped_weight_kg, tl.received_weight_kg, tl.difference_kg, tl.status, su.unit_code, su.status AS stock_status, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name, tub.code AS tub_code, loc.path AS origin_location_path", false)
                ->join('stock_units su', 'su.id = tl.stock_unit_id')
                ->join('lots lo', 'lo.id = su.lot_id')
                ->join('species s', 's.id = su.species_id')
                ->join('size_grades sg', 'sg.id = su.size_grade_id')
                ->join('tubs tub', 'tub.id = su.tub_id', 'left')
                ->join('locations loc', 'loc.id = su.location_id', 'left')
                ->where('tl.transfer_id', $id)
                ->orderBy('tl.line_number')->get()->getResultArray();

            foreach ($lines as &$line) {
                $line['status_label'] = self::LINE_STATUS_LABELS[$line['status']] ?? $line['status'];
                $line['display_code'] = trim((string) ($line['tub_code'] ?? '')) !== '' ? $line['tub_code'] : $line['unit_code'];
            }
            unset($line);

            $transfer['status_label'] = self::STATUS_LABELS[$transfer['status']] ?? $transfer['status'];
            $transfer['unit_count'] = count($lines);
            $transfer['received_count'] = count(array_filter($lines, static fn (array $line): bool => $line['status'] === 'received'));
            $transfer['missing_count'] = count(array_filter($lines, static fn (array $line): bool => $line['status'] === 'missing'));

            return ['transfer' => $transfer, 'lines' => $lines];
        });
    }

    public function units()
    {
        return $this->execute(function (): array {
            $q = trim((string) $this->request->getGet('q'));
            $originWarehouseId = (int) $this->request->getGet('origin_warehouse_id');
            if ($originWarehouseId <= 0 || mb_strlen($q) < 2) {
                return ['items' => []];
            }

            $scope = session('operational_scope') ?: [];
            $companyId = (int) ($scope['company_id'] ?? 0);
            $siteId = (int) ($scope['site_id'] ?? 0);
            if ($companyId <= 0 || $siteId <= 0) {
                throw new DomainException('No hay alcance operativo activo.', 'OPERATIONAL_SCOPE_REQUIRED');
            }

            $db = db_connect();
            $warehouse = $db->table('warehouses')->where([
                'id' => $originWarehouseId,
                'company_id' => $companyId,
                'site_id' => $siteId,
                'active' => 1,
            ])->get()->getRowArray();
            if ($warehouse === null) {
                throw new DomainException('El almacén origen está fuera del alcance operativo.', 'TRANSFER_ORIGIN_SCOPE_MISMATCH');
            }

            $rows = $db->table('stock_units su')
                ->select("su.id, su.unit_code, su.warehouse_id, su.location_id, su.on_hand_weight_kg, su.status, lo.code AS lot_code, s.name AS species_name, sg.name AS size_name, t.code AS tub_code, lb.code AS label_code, loc.path AS location_path", false)
                ->join('lots lo', 'lo.id = su.lot_id')
                ->join('species s', 's.id = su.species_id')
                ->join('size_grades sg', 'sg.id = su.size_grade_id')
                ->join('tubs t', 't.id = su.tub_id', 'left')
                ->join('labels lb', "lb.weighing_id = su.weighing_id AND lb.status != 'canceled'", 'left', false)
                ->join('locations loc', 'loc.id = su.location_id', 'left')
                ->where([
                    'su.company_id' => $companyId,
                    'su.site_id' => $siteId,
                    'su.warehouse_id' => $originWarehouseId,
                    'su.status' => 'available',
                    'su.reserved_weight_kg' => 0,
                ])
                ->where('su.on_hand_weight_kg >', 0)
                ->groupStart()
                    ->like('t.code', $q)
                    ->orLike('su.unit_code', $q)
                    ->orLike('lb.code', $q)
                    ->orLike('lo.code', $q)
                ->groupEnd()
                ->orderBy('su.received_at', 'DESC')
                ->limit(25)->get()->getResultArray();

            $items = [];
            foreach ($rows as $row) {
                $tina = trim((string) ($row['tub_code'] ?? ''));
                $label = trim((string) ($row['label_code'] ?? ''));
                $main = $tina !== '' ? $tina : ($label !== '' ? $label : (string) $row['unit_code']);
                $items[] = $row + [
                    'display_code' => $main,
                    'text' => $main . ' · ' . $row['lot_code'] . ' · ' . $row['species_name'] . ' · ' . $row['size_name'] . ' · ' . number_format((float) $row['on_hand_weight_kg'], 3) . ' kg',
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
                $items[] = $row + ['display_name' => $this->sectorDisplay((string) ($row['code'] ?? ''), (string) ($row['name'] ?? ''))];
            }
            return ['items' => $items, 'layout_configured' => (bool) ($result['layout_configured'] ?? false)];
        });
    }

    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();

            if (! empty($payload['reservation_id'])) {
                $this->validatePayload($payload, [
                    'reservation_id' => 'required|is_natural_no_zero',
                ]);
                $this->assertAggregateScope('reservation', (int) $payload['reservation_id']);
                return service('transferService')->create($payload, $this->actorId());
            }

            $this->validatePayload($payload, [
                'company_id' => 'required|is_natural_no_zero',
                'origin_warehouse_id' => 'required|is_natural_no_zero',
                'destination_warehouse_id' => 'required|is_natural_no_zero',
                'transfer_type' => 'permit_empty|in_list[administrative]',
                'stock_unit_ids' => 'required|is_array',
                'stock_unit_ids.*' => 'required|is_natural_no_zero',
                'carrier_reference' => 'permit_empty|max_length[100]',
                'observations' => 'permit_empty|max_length[1000]',
            ]);
            return service('transferService')->create($payload, $this->actorId());
        }, 201);
    }

    public function authorize(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('transfer_origin', $id);
            $payload = $this->payload();
            if (! array_key_exists('approved', $payload)
                || (! is_bool($payload['approved']) && ! in_array(strtolower((string) $payload['approved']), ['0', '1', 'true', 'false'], true))) {
                throw new DomainException('El campo approved debe ser booleano.', 'VALIDATION_FAILED', ['approved' => 'Valor booleano inválido.']);
            }
            return service('transferService')->authorize($id, filter_var($payload['approved'], FILTER_VALIDATE_BOOLEAN), $this->actorId());
        });
    }

    public function ship(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('transfer_origin', $id);
            return service('inventoryService')->shipTransfer($id, $this->actorId());
        });
    }

    public function receive(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('transfer_destination', $id);
            $payload = $this->payload();
            $this->validatePayload($payload, [
                'row_location_id' => 'required|is_natural_no_zero',
                'received_stock_unit_ids' => 'required|is_array',
                'received_stock_unit_ids.*' => 'required|is_natural_no_zero',
            ]);
            return service('inventoryService')->receiveTransfer(
                $id,
                (int) $payload['row_location_id'],
                array_values(array_map('intval', $payload['received_stock_unit_ids'])),
                $this->actorId(),
            );
        });
    }

    private function sectorDisplay(string $code, string $name): string
    {
        $name = trim($name);
        if ($name !== '' && preg_match('/^(fila|pasillo)\\b/i', $name)) {
            return $name;
        }
        if (preg_match('/([FP])(\\d+)$/i', $code, $m)) {
            return (strtoupper($m[1]) === 'F' ? 'Fila ' : 'Pasillo ') . str_pad((string) ((int) $m[2]), 2, '0', STR_PAD_LEFT);
        }
        return $name !== '' ? $name : $code;
    }
}
