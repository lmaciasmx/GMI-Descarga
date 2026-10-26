<?php

namespace App\Modules\Dashboard\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\Database\BaseConnection;

final class DashboardController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope') ?? [];
        $companyId = (int) ($scope['company_id'] ?? env('operational.defaultCompanyId', 1));
        $siteId = (int) ($scope['site_id'] ?? env('operational.defaultSiteId', 1));
        $warehouseId = ! empty($scope['warehouse_id']) ? (int) $scope['warehouse_id'] : null;

        $db = db_connect();

        $criticalAlerts = $this->criticalAlerts(
            $db,
            $companyId,
            $siteId,
            $warehouseId
        );

        $kpis = $this->kpis(
            $db,
            $companyId,
            $siteId,
            $warehouseId,
            $criticalAlerts
        );

        return view('App\\Modules\\Dashboard\\Views\\index', [
            'title' => 'Centro de operaciones',
            'subtitle' => 'Resumen operativo del centro de trabajo',
            'kpis' => $kpis,
            'operationalStatus' => $this->operationalStatus(
                $db,
                $companyId,
                $siteId
            ),
            'recentActivity' => $this->recentActivity(
                $db,
                $companyId,
                $siteId
            ),
            'criticalAlerts' => array_slice($criticalAlerts, 0, 4),
            'chamberOccupancy' => array_slice(
                $this->chamberOccupancy(
                    $db,
                    $companyId,
                    $siteId,
                    $warehouseId
                ),
                0,
                4
            ),
            'speciesSummary' => $this->speciesSummary(
                $db,
                $companyId,
                $siteId,
                $warehouseId
            ),
        ]);
    }

    private function kpis(
        BaseConnection $db,
        int $companyId,
        int $siteId,
        ?int $warehouseId,
        array $criticalAlerts
    ): array {
        $activeVoyages = $db->table('voyages')
            ->where([
                'company_id' => $companyId,
                'site_id' => $siteId,
            ])
            ->whereIn('status', ['planned', 'unloading'])
            ->countAllResults();

        $plannedVoyages = $db->table('voyages')
            ->where([
                'company_id' => $companyId,
                'site_id' => $siteId,
            ])
            ->whereIn('status', ['planned', 'unloading'])
            ->countAllResults();

        $todayReception = $db->table('receptions r')
            ->selectSum('rl.accepted_weight_kg', 'kg')
            ->join('reception_lines rl', 'rl.reception_id = r.id')
            ->where([
                'r.company_id' => $companyId,
                'r.site_id' => $siteId,
                'r.status' => 'confirmed',
            ])
            ->where('r.received_at >=', date('Y-m-d 00:00:00'))
            ->where('r.received_at <', date('Y-m-d 00:00:00', strtotime('+1 day')));

        $yesterdayReception = $db->table('receptions r')
            ->selectSum('rl.accepted_weight_kg', 'kg')
            ->join('reception_lines rl', 'rl.reception_id = r.id')
            ->where([
                'r.company_id' => $companyId,
                'r.site_id' => $siteId,
                'r.status' => 'confirmed',
            ])
            ->where('r.received_at >=', date('Y-m-d 00:00:00', strtotime('-1 day')))
            ->where('r.received_at <', date('Y-m-d 00:00:00'));

        if ($warehouseId !== null) {
            $todayReception->where('r.warehouse_id', $warehouseId);
            $yesterdayReception->where('r.warehouse_id', $warehouseId);
        }

        $todayKg = (float) ($todayReception->get()->getRow('kg') ?? 0);
        $yesterdayKg = (float) ($yesterdayReception->get()->getRow('kg') ?? 0);

        $receptionDelta = null;
        if ($yesterdayKg > 0) {
            $receptionDelta = round((($todayKg - $yesterdayKg) / $yesterdayKg) * 100, 1);
        }

        $coldBuilder = $db->table('stock_units')
            ->where([
                'company_id' => $companyId,
                'site_id' => $siteId,
            ])
            ->where('on_hand_weight_kg >', 0)
            ->whereNotIn('status', ['in_transit', 'dispatched', 'exhausted']);

        if ($warehouseId !== null) {
            $coldBuilder->where('warehouse_id', $warehouseId);
        }

        $coldUnits = $coldBuilder->countAllResults();

        $criticalCount = 0;
        $attentionCount = 0;

        foreach ($criticalAlerts as $alert) {
            if (($alert['severity'] ?? '') === 'danger') {
                $criticalCount += (int) ($alert['count'] ?? 0);
            } else {
                $attentionCount += (int) ($alert['count'] ?? 0);
            }
        }

        return [
            'active_voyages' => $activeVoyages,
            'planned_voyages' => $plannedVoyages,
            'today_reception_kg' => $todayKg,
            'yesterday_reception_kg' => $yesterdayKg,
            'reception_delta' => $receptionDelta,
            'cold_units' => $coldUnits,
            'alerts_total' => $criticalCount + $attentionCount,
            'alerts_critical' => $criticalCount,
            'alerts_attention' => $attentionCount,
        ];
    }

    private function operationalStatus(
        BaseConnection $db,
        int $companyId,
        int $siteId
    ): array {
        $total = (int) ($db->table('tub_fillings tf')
            ->select('COUNT(DISTINCT tf.id) AS total', false)
            ->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->where([
                'v.company_id' => $companyId,
                'v.site_id' => $siteId,
            ])
            ->whereIn('v.status', ['planned', 'unloading'])
            ->get()
            ->getRow('total') ?? 0);

        $weighed = (int) ($db->table('tub_fillings tf')
            ->select('COUNT(DISTINCT tf.id) AS total', false)
            ->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join(
                'weighings w',
                'w.tub_filling_id = tf.id AND w.is_current = 1',
                'inner',
                false
            )
            ->where([
                'v.company_id' => $companyId,
                'v.site_id' => $siteId,
            ])
            ->whereIn('v.status', ['planned', 'unloading'])
            ->get()
            ->getRow('total') ?? 0);

        $qualityApproved = (int) ($db->table('tub_fillings tf')
            ->select('COUNT(DISTINCT tf.id) AS total', false)
            ->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join(
                'weighings w',
                'w.tub_filling_id = tf.id AND w.is_current = 1',
                'inner',
                false
            )
            ->join(
                'quality_inspections qi',
                "qi.weighing_id = w.id
                 AND qi.status = 'approved'
                 AND qi.superseded_by_inspection_id IS NULL",
                'inner',
                false
            )
            ->where([
                'v.company_id' => $companyId,
                'v.site_id' => $siteId,
            ])
            ->whereIn('v.status', ['planned', 'unloading'])
            ->get()
            ->getRow('total') ?? 0);

        $placed = (int) ($db->table('tub_fillings tf')
            ->select('COUNT(DISTINCT tf.id) AS total', false)
            ->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join(
                'warehouse_entry_destinations wed',
                "wed.tub_filling_id = tf.id AND wed.status = 'placed'",
                'inner',
                false
            )
            ->where([
                'v.company_id' => $companyId,
                'v.site_id' => $siteId,
            ])
            ->whereIn('v.status', ['planned', 'unloading'])
            ->get()
            ->getRow('total') ?? 0);

        $inventoried = (int) ($db->table('tub_fillings tf')
            ->select('COUNT(DISTINCT tf.id) AS total', false)
            ->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('weighings w', 'w.tub_filling_id = tf.id')
            ->join('stock_units su', 'su.weighing_id = w.id')
            ->where([
                'v.company_id' => $companyId,
                'v.site_id' => $siteId,
            ])
            ->whereIn('v.status', ['planned', 'unloading'])
            ->get()
            ->getRow('total') ?? 0);

        $percent = static fn (int $value): int => $total > 0
            ? (int) round(min(100, ($value / $total) * 100))
            : 0;

        return [
            [
                'key' => 'discharge',
                'label' => 'Descarga',
                'icon' => 'bi-water',
                'count' => $weighed,
                'meta' => $weighed === 1 ? '1 tina pesada' : number_format($weighed) . ' tinas pesadas',
                'percent' => $percent($weighed),
                'tone' => 'teal',
            ],
            [
                'key' => 'quality',
                'label' => 'Calidad',
                'icon' => 'bi-clipboard2-check',
                'count' => $qualityApproved,
                'meta' => $qualityApproved === 1 ? '1 liberada' : number_format($qualityApproved) . ' liberadas',
                'percent' => $percent($qualityApproved),
                'tone' => 'blue',
            ],
            [
                'key' => 'cold',
                'label' => 'Entrada a Frío',
                'icon' => 'bi-snow',
                'count' => $placed,
                'meta' => $placed === 1 ? '1 ubicada' : number_format($placed) . ' ubicadas',
                'percent' => $percent($placed),
                'tone' => 'ice',
            ],
            [
                'key' => 'inventory',
                'label' => 'Inventario',
                'icon' => 'bi-box-seam',
                'count' => $inventoried,
                'meta' => $inventoried === 1 ? '1 registrada' : number_format($inventoried) . ' registradas',
                'percent' => $percent($inventoried),
                'tone' => 'navy',
            ],
        ];
    }

    private function recentActivity(
        BaseConnection $db,
        int $companyId,
        int $siteId
    ): array {
        $actions = [
            'inventory.receipt_confirmed',
            'quality.approved',
            'quality.inspected',
            'warehouse_entry.placed',
            'warehouse_entry.confirmed',
            'weighing.confirmed',
            'tub_filling.completed',
            'tub_filling.started',
            'discharge_plan.transitioned',
            'voyage.transitioned',
            'warehouse_request.created',
            'warehouse_request.accepted',
            'warehouse_request.supply_finalized',
            'warehouse_request.receipt_registered',
            'warehouse_request.closed',
            'inventory.movement_confirmed',
            'transfer.created',
            'transfer.authorized',
            'transfer.shipped',
            'transfer.received',
        ];

        $rows = $db->table('audit_logs al')
            ->select([
                'al.id',
                'al.action',
                'al.aggregate_type',
                'al.aggregate_id',
                'al.actor_user_id',
                'al.actor_type',
                'al.actor_reference',
                'al.reason',
                'al.occurred_at',
            ])
            ->where('al.company_id', $companyId)
            ->groupStart()
                ->where('al.site_id', $siteId)
                ->orWhere('al.site_id', null)
            ->groupEnd()
            ->whereIn('al.action', $actions)
            ->orderBy('al.occurred_at', 'DESC')
            ->orderBy('al.id', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $catalog = [
            'inventory.receipt_confirmed' => ['Recepción registrada', 'Recepción', 'cyan'],
            'quality.approved' => ['Calidad liberada', 'Calidad', 'green'],
            'quality.inspected' => ['Inspección de calidad', 'Calidad', 'green'],
            'warehouse_entry.placed' => ['Entrada a frigorífico', 'Frío', 'blue'],
            'warehouse_entry.confirmed' => ['Entrada confirmada', 'Frío', 'blue'],
            'weighing.confirmed' => ['Pesaje confirmado', 'Pesaje', 'cyan'],
            'tub_filling.completed' => ['Descarga registrada', 'Operación', 'teal'],
            'tub_filling.started' => ['Descarga iniciada', 'Operación', 'teal'],
            'discharge_plan.transitioned' => ['Plan actualizado', 'Planeación', 'blue'],
            'voyage.transitioned' => ['Marea actualizada', 'Operación', 'teal'],
            'warehouse_request.created' => ['Solicitud generada', 'Solicitud', 'violet'],
            'warehouse_request.accepted' => ['Solicitud aceptada', 'Solicitud', 'violet'],
            'warehouse_request.supply_finalized' => ['Surtido finalizado', 'Solicitud', 'violet'],
            'warehouse_request.receipt_registered' => ['Recepción de solicitud', 'Solicitud', 'violet'],
            'warehouse_request.closed' => ['Solicitud cerrada', 'Solicitud', 'violet'],
            'inventory.movement_confirmed' => ['Movimiento confirmado', 'Inventario', 'navy'],
            'transfer.created' => ['Traspaso creado', 'Traspaso', 'navy'],
            'transfer.authorized' => ['Traspaso autorizado', 'Traspaso', 'navy'],
            'transfer.shipped' => ['Traspaso enviado', 'Traspaso', 'navy'],
            'transfer.received' => ['Traspaso recibido', 'Traspaso', 'navy'],
        ];

        $result = [];

        foreach ($rows as $row) {
            [$label, $badge, $tone] = $catalog[$row['action']]
                ?? ['Actividad operativa', 'Operación', 'navy'];

            $result[] = [
                'id' => (int) $row['id'],
                'label' => $label,
                'badge' => $badge,
                'tone' => $tone,
                'aggregate' => trim(
                    (string) $row['aggregate_type']
                    . ' #'
                    . (string) $row['aggregate_id']
                ),
                'reason' => $row['reason'] ?? null,
                'occurred_at' => (string) $row['occurred_at'],
            ];
        }

        return $result;
    }

    private function criticalAlerts(
        BaseConnection $db,
        int $companyId,
        int $siteId,
        ?int $warehouseId
    ): array {
        $alerts = [];

        $heldBuilder = $db->table('stock_units')
            ->where([
                'company_id' => $companyId,
                'site_id' => $siteId,
                'status' => 'held',
            ]);

        if ($warehouseId !== null) {
            $heldBuilder->where('warehouse_id', $warehouseId);
        }

        $heldUnits = $heldBuilder->countAllResults();

        if ($heldUnits > 0) {
            $alerts[] = [
                'type' => 'inventory_hold',
                'severity' => 'danger',
                'icon' => 'bi-exclamation-lg',
                'title' => 'Tinas retenidas',
                'detail' => $heldUnits === 1
                    ? '1 tina requiere revisión o liberación.'
                    : number_format($heldUnits) . ' tinas requieren revisión o liberación.',
                'count' => $heldUnits,
            ];
        }

        $pendingQuality = $db->table('weighings w')
            ->join('tub_fillings tf', 'tf.id = w.tub_filling_id')
            ->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->where([
                'v.company_id' => $companyId,
                'v.site_id' => $siteId,
                'w.status' => 'pending_inspection',
            ])
            ->countAllResults();

        if ($pendingQuality > 0) {
            $alerts[] = [
                'type' => 'quality_pending',
                'severity' => 'danger',
                'icon' => 'bi-exclamation-lg',
                'title' => 'Tinas por clasificar',
                'detail' => $pendingQuality === 1
                    ? '1 pesaje está pendiente de calidad.'
                    : number_format($pendingQuality) . ' pesajes están pendientes de calidad.',
                'count' => $pendingQuality,
            ];
        }

        $requestBuilder = $db->table('warehouse_requests wr')
            ->join('warehouses sw', 'sw.id = wr.source_warehouse_id')
            ->join('warehouses dw', 'dw.id = wr.destination_warehouse_id')
            ->where('wr.company_id', $companyId)
            ->where('wr.required_at <', date('Y-m-d H:i:s'))
            ->whereNotIn('wr.status', ['closed', 'rejected', 'canceled', 'cancelled']);

        if ($warehouseId !== null) {
            $requestBuilder
                ->groupStart()
                    ->where('wr.source_warehouse_id', $warehouseId)
                    ->orWhere('wr.destination_warehouse_id', $warehouseId)
                ->groupEnd();
        } else {
            $requestBuilder
                ->groupStart()
                    ->where('sw.site_id', $siteId)
                    ->orWhere('dw.site_id', $siteId)
                ->groupEnd();
        }

        $overdueRequests = $requestBuilder->countAllResults();

        if ($overdueRequests > 0) {
            $alerts[] = [
                'type' => 'request_overdue',
                'severity' => 'warning',
                'icon' => 'bi-exclamation-lg',
                'title' => 'Solicitud de movimiento vencida',
                'detail' => $overdueRequests === 1
                    ? '1 solicitud superó su fecha requerida.'
                    : number_format($overdueRequests) . ' solicitudes superaron su fecha requerida.',
                'count' => $overdueRequests,
            ];
        }

        $transferBuilder = $db->table('transfers t')
            ->join('warehouses ow', 'ow.id = t.origin_warehouse_id')
            ->join('warehouses dw', 'dw.id = t.destination_warehouse_id')
            ->where([
                't.company_id' => $companyId,
                't.status' => 'discrepancy',
            ]);

        if ($warehouseId !== null) {
            $transferBuilder
                ->groupStart()
                    ->where('t.origin_warehouse_id', $warehouseId)
                    ->orWhere('t.destination_warehouse_id', $warehouseId)
                ->groupEnd();
        } else {
            $transferBuilder
                ->groupStart()
                    ->where('ow.site_id', $siteId)
                    ->orWhere('dw.site_id', $siteId)
                ->groupEnd();
        }

        $transferDiscrepancies = $transferBuilder->countAllResults();

        if ($transferDiscrepancies > 0) {
            $alerts[] = [
                'type' => 'transfer_discrepancy',
                'severity' => 'warning',
                'icon' => 'bi-exclamation-lg',
                'title' => 'Diferencia en traspaso',
                'detail' => $transferDiscrepancies === 1
                    ? '1 traspaso presenta diferencia.'
                    : number_format($transferDiscrepancies) . ' traspasos presentan diferencias.',
                'count' => $transferDiscrepancies,
            ];
        }

        return $alerts;
    }

    private function chamberOccupancy(
        BaseConnection $db,
        int $companyId,
        int $siteId,
        ?int $warehouseId
    ): array {
        $builder = $db->table('chambers c')
            ->select([
                'c.id',
                'c.code',
                'c.name',
                'c.warehouse_id',
                'w.name AS warehouse_name',
                'COUNT(DISTINCT l.id) AS total',
                'COUNT(DISTINCT CASE WHEN lr.id IS NOT NULL THEN l.id END) AS blocked',
                'COUNT(DISTINCT CASE WHEN lr.id IS NULL AND su.location_id IS NOT NULL THEN l.id END) AS occupied',
            ], false)
            ->join('warehouses w', 'w.id = c.warehouse_id')
            ->join(
                'locations l',
                "l.chamber_id = c.id
                 AND l.active = 1
                 AND l.location_type IN ('stack_level', 'stack')",
                'inner',
                false
            )
            ->join(
                'location_restrictions lr',
                'lr.location_id = l.id AND lr.active = 1',
                'left',
                false
            )
            ->join(
                'stock_units su',
                "su.location_id = l.id
                 AND su.on_hand_weight_kg > 0
                 AND su.status NOT IN ('in_transit', 'dispatched', 'exhausted')",
                'left',
                false
            )
            ->where('c.active', 1)
            ->where('w.active', 1);

        if ($warehouseId !== null) {
            $builder->where('c.warehouse_id', $warehouseId);
        } else {
            $builder
                ->where('w.company_id', $companyId)
                ->where('w.site_id', $siteId);
        }

        $rows = $builder
            ->groupBy([
                'c.id',
                'c.code',
                'c.name',
                'c.warehouse_id',
                'w.name',
            ])
            ->orderBy('w.name', 'ASC')
            ->orderBy('c.code', 'ASC')
            ->get()
            ->getResultArray();

        $result = [];

        foreach ($rows as $row) {
            $total = (int) $row['total'];
            $blocked = (int) $row['blocked'];
            $occupied = (int) $row['occupied'];
            $usable = max(0, $total - $blocked);
            $free = max(0, $usable - $occupied);
            $percent = $usable > 0
                ? round(($occupied / $usable) * 100, 1)
                : 0.0;

            $result[] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'warehouse_name' => (string) $row['warehouse_name'],
                'total' => $total,
                'blocked' => $blocked,
                'usable' => $usable,
                'occupied' => $occupied,
                'free' => $free,
                'percent' => $percent,
            ];
        }

        usort(
            $result,
            static fn (array $a, array $b): int =>
                $b['percent'] <=> $a['percent']
        );

        return $result;
    }

    private function speciesSummary(
        BaseConnection $db,
        int $companyId,
        int $siteId,
        ?int $warehouseId
    ): array {
        $builder = $db->table('v_available_stock vas')
            ->select([
                's.id',
                's.code',
                's.name',
                'ROUND(SUM(vas.available_weight_kg), 3) AS kg',
                'COUNT(*) AS units',
            ], false)
            ->join('species s', 's.id = vas.species_id')
            ->where([
                'vas.company_id' => $companyId,
                'vas.site_id' => $siteId,
            ]);

        if ($warehouseId !== null) {
            $builder->where('vas.warehouse_id', $warehouseId);
        }

        $rows = $builder
            ->groupBy(['s.id', 's.code', 's.name'])
            ->orderBy('kg', 'DESC')
            ->get()
            ->getResultArray();

        $totalKg = array_sum(array_map(
            static fn (array $row): float => (float) $row['kg'],
            $rows
        ));

        $items = [];

        foreach ($rows as $index => $row) {
            if ($index < 4) {
                $items[] = [
                    'name' => (string) $row['name'],
                    'code' => (string) $row['code'],
                    'kg' => (float) $row['kg'],
                    'units' => (int) $row['units'],
                ];
                continue;
            }

            if (! isset($items[4])) {
                $items[4] = [
                    'name' => 'Otros',
                    'code' => 'OTROS',
                    'kg' => 0.0,
                    'units' => 0,
                ];
            }

            $items[4]['kg'] += (float) $row['kg'];
            $items[4]['units'] += (int) $row['units'];
        }

        foreach ($items as &$item) {
            $item['percent'] = $totalKg > 0
                ? round(($item['kg'] / $totalKg) * 100, 1)
                : 0.0;
        }
        unset($item);

        return [
            'total_kg' => $totalKg,
            'items' => array_values($items),
        ];
    }
}
