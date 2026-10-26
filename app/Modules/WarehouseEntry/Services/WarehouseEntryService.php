<?php

namespace App\Modules\WarehouseEntry\Services;

use App\Common\Domain\MovementType;
use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Common\Services\SettingsService;
use App\Modules\DischargePlanning\Services\DischargePlanService;
use App\Modules\Inventory\DTO\CreateMovementData;
use App\Modules\Inventory\DTO\MovementLineData;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Labeling\Services\LabelService;
use App\Modules\Weighing\DTO\CreateWeighingData;
use App\Modules\Weighing\Services\WeighingService;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class WarehouseEntryService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly DischargePlanService $plans,
        private readonly WeighingService $weighings,
        private readonly LabelService $labels,
        private readonly InventoryService $inventory,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Contexto completo para la estación unificada Entrada a Frigorífico.
     */
    public function stationContext(): array
    {
        $scope = $this->scope();
        $companyId = (int) $scope['company_id'];
        $siteId = (int) $scope['site_id'];
        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);

        if ($warehouseId <= 0) {
            throw new DomainException(
                'Selecciona un frigorífico en el alcance operativo antes de usar Entrada a Frigorífico.',
                'WAREHOUSE_ENTRY_WAREHOUSE_SCOPE_REQUIRED'
            );
        }

        $warehouse = $this->db->table('warehouses')
            ->select('id, company_id, site_id, code, name')
            ->where([
                'id' => $warehouseId,
                'company_id' => $companyId,
                'site_id' => $siteId,
                'active' => 1,
            ])
            ->get()
            ->getRowArray();

        if ($warehouse === null) {
            throw new DomainException(
                'El frigorífico del alcance operativo no está activo o no pertenece a la instalación actual.',
                'WAREHOUSE_ENTRY_WAREHOUSE_SCOPE_INVALID'
            );
        }

        $planBuilder = $this->db->table('discharge_plans dp')
            ->select(
                'dp.id, dp.folio, dp.voyage_id, dp.rsa_id, dp.status, ' .
                'v.annual_number, v.year, v.receiving_warehouse_id, ' .
                've.code AS vessel_code, ve.name AS vessel_name, ' .
                'rsa.code AS rsa_code, rsa.expires_on AS rsa_expires_on'
            )
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->join('rsa_records rsa', 'rsa.id = dp.rsa_id', 'left')
            ->where([
                'v.company_id' => $companyId,
                'v.site_id' => $siteId,
                'dp.status' => 'in_progress',
            ])
            ->orderBy('dp.planned_start_at')
            ->orderBy('dp.id');

        $planBuilder->groupStart()
            ->where('v.receiving_warehouse_id', $warehouseId)
            ->orWhere('v.receiving_warehouse_id', null)
        ->groupEnd();

        $plans = $planBuilder->get()->getResultArray();
        $planIds = array_map('intval', array_column($plans, 'id')) ?: [0];
        $voyageIds = array_map('intval', array_column($plans, 'voyage_id')) ?: [0];

        $planTotals = $this->planTotals($planIds);

        foreach ($plans as &$plan) {
            $totals = $planTotals[(int) $plan['id']] ?? [
                'planned_kg' => 0.0,
                'processed_kg' => 0.0,
                'out_of_plan_kg' => 0.0,
            ];
            $plan['planned_kg'] = $totals['planned_kg'];
            $plan['processed_kg'] = $totals['processed_kg'];
            $plan['pending_kg'] = max(0, round($totals['planned_kg'] - $totals['processed_kg'], 3));
            $plan['out_of_plan_kg'] = $totals['out_of_plan_kg'];
        }
        unset($plan);

        $lines = $this->planLines($planIds);

        $tanks = $this->db->table('voyage_tanks')
            ->select('id, voyage_id, tank_number, capacity_kg')
            ->whereIn('voyage_id', $voyageIds)
            ->orderBy('tank_number')
            ->get()
            ->getResultArray();

        $species = $this->db->table('species')
            ->where('active', 1)
            ->orderBy('name')
            ->get()
            ->getResultArray();

        $sizes = $this->db->table('size_grades')
            ->where('active', 1)
            ->orderBy('name')
            ->get()
            ->getResultArray();

        $warehouses = [$warehouse];

        $chambers = $this->db->table('chambers')
            ->select('id, warehouse_id, code, name')
            ->where('warehouse_id', $warehouseId)
            ->where('active', 1)
            ->orderBy('code')
            ->get()
            ->getResultArray();

        $templateCode = (string) env(
            'integrations.printer.templateCode',
            in_array((string) env('integrations.printer.driver', 'pdf'), ['zpl', 'thermal'], true)
                ? 'TINA_RECEPCION_ZPL'
                : 'TINA_RECEPCION'
        );

        $template = $this->db->table('label_templates')
            ->where(['code' => $templateCode, 'active' => 1])
            ->orderBy('version', 'DESC')
            ->get()
            ->getRowArray();

        // Cargar toda la configuración técnica necesaria para impresoras locales.
        // La estación no debe depender del driver global del servidor cuando la
        // impresora opera mediante el agente Node local (PC43t / Windows USB).
        $printers = $this->db->table('printers')
            ->select('id, site_id, code, name, printer_type, adapter_key, connection_mode, agent_url, windows_printer_name, printer_language, dpi, label_width_mm, label_height_mm, darkness, speed_ips, copies, is_default')
            ->where(['site_id' => $siteId, 'active' => 1])
            ->orderBy('is_default', 'DESC')
            ->orderBy('name')
            ->get()
            ->getResultArray();

        // La estación necesita la configuración técnica completa de la báscula
        // cuando opera mediante el agente serial local del navegador.
        $scales = $this->db->table('scales')
            ->select(
                'id, site_id, code, name, station_code, model, connection_type, adapter_key, '
                . 'agent_url, port_name, baud_rate, data_bits, parity, stop_bits, '
                . 'read_command, command_terminator, response_timeout_ms, settle_delay_ms, '
                . 'stable_reads, stable_tolerance_kg, weight_regex, unit, is_default'
            )
            ->where(['site_id' => $siteId, 'active' => 1])
            ->orderBy('is_default', 'DESC')
            ->orderBy('name')
            ->get()
            ->getResultArray();

        $printerDriver = strtolower((string) env('integrations.printer.driver', 'pdf'));
        $scaleDriver = strtolower((string) env('integrations.scale.driver', 'simulator'));

        $compatiblePrinterAdapters = match ($printerDriver) {
            'pdf' => ['pdf'],
            'browser' => ['browser'],
            'middleware' => ['middleware', 'zpl'],
            'thermal', 'zpl' => ['zpl', 'middleware'],
            default => [],
        };

        $printer = null;

        // 1) Preferir la impresora predeterminada configurada con agente local.
        //    Ésta es independiente de integrations.printer.driver porque el ZPL
        //    se entrega al navegador y el navegador lo envía a 127.0.0.1:18181.
        foreach ($printers as $candidate) {
            $connectionMode = strtolower((string) ($candidate['connection_mode'] ?? ''));
            $adapter = strtolower((string) ($candidate['adapter_key'] ?? ''));
            $windowsName = trim((string) ($candidate['windows_printer_name'] ?? ''));

            if (
                $connectionMode === 'local_agent'
                && in_array($adapter, ['middleware', 'zpl'], true)
                && $windowsName !== ''
                && (int) ($candidate['is_default'] ?? 0) === 1
            ) {
                $printer = $candidate;
                break;
            }
        }

        // 2) Si no hay predeterminada, tomar cualquier agente local válido.
        if ($printer === null) {
            foreach ($printers as $candidate) {
                $connectionMode = strtolower((string) ($candidate['connection_mode'] ?? ''));
                $adapter = strtolower((string) ($candidate['adapter_key'] ?? ''));
                $windowsName = trim((string) ($candidate['windows_printer_name'] ?? ''));

                if (
                    $connectionMode === 'local_agent'
                    && in_array($adapter, ['middleware', 'zpl'], true)
                    && $windowsName !== ''
                ) {
                    $printer = $candidate;
                    break;
                }
            }
        }

        // 3) Respaldo: comportamiento anterior para PDF/browser/middleware del servidor.
        if ($printer === null) {
            foreach ($printers as $candidate) {
                if (in_array((string) $candidate['adapter_key'], $compatiblePrinterAdapters, true)) {
                    $printer = $candidate;
                    break;
                }
            }
        }

        $printer ??= $printers[0] ?? null;

        $scale = null;

        // 1) Preferir una báscula serial local predeterminada. Igual que con
        //    la PC43t, el navegador habla con 127.0.0.1 y no depende del
        //    driver global del servidor.
        foreach ($scales as $candidate) {
            $connection = strtolower((string) ($candidate['connection_type'] ?? ''));
            $adapter = strtolower((string) ($candidate['adapter_key'] ?? ''));
            $agentUrl = trim((string) ($candidate['agent_url'] ?? ''));
            $portName = trim((string) ($candidate['port_name'] ?? ''));

            if (
                in_array($connection, ['usb_serial', 'rs232'], true)
                && $adapter === $connection
                && $agentUrl !== ''
                && $portName !== ''
                && (int) ($candidate['is_default'] ?? 0) === 1
            ) {
                $scale = $candidate;
                break;
            }
        }

        // 2) Si no hay predeterminada, usar cualquier báscula serial local válida.
        if ($scale === null) {
            foreach ($scales as $candidate) {
                $connection = strtolower((string) ($candidate['connection_type'] ?? ''));
                $adapter = strtolower((string) ($candidate['adapter_key'] ?? ''));
                $agentUrl = trim((string) ($candidate['agent_url'] ?? ''));
                $portName = trim((string) ($candidate['port_name'] ?? ''));

                if (
                    in_array($connection, ['usb_serial', 'rs232'], true)
                    && $adapter === $connection
                    && $agentUrl !== ''
                    && $portName !== ''
                ) {
                    $scale = $candidate;
                    break;
                }
            }
        }

        // 3) Respaldo: comportamiento anterior para drivers administrados
        //    por el servidor.
        if ($scale === null) {
            foreach ($scales as $candidate) {
                if (
                    (string) $candidate['adapter_key'] === $scaleDriver
                    && (string) $candidate['connection_type'] === $scaleDriver
                ) {
                    $scale = $candidate;
                    break;
                }
            }
        }

        $scale ??= $scales[0] ?? null;

        return [
            'plans' => $plans,
            'lines' => $lines,
            'tanks' => $tanks,
            'species' => $species,
            'sizes' => $sizes,
            'warehouse' => $warehouse,
            'warehouses' => $warehouses,
            'chambers' => $chambers,
            'template' => $template,
            'printer' => $printer,
            'printers' => $printers,
            'printerDriver' => $printerDriver,
            'scale' => $scale,
            'scales' => $scales,
            'scaleDriver' => $scaleDriver,
            'mscValues' => (array) $this->settings->get(
                'certification.msc_values',
                $companyId,
                $siteId,
                []
            ),
            'dolphinValues' => (array) $this->settings->get(
                'certification.dolphin_safe_values',
                $companyId,
                $siteId,
                []
            ),
            'currentUserId' => (int) auth()->id(),
            'currentUsername' => (string) auth()->user()->username,
        ];
    }


    /**
     * Búsqueda operativa de tinas disponibles. Evita cargar miles de tinas en la vista.
     */
    public function searchAvailableTubs(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $builder = $this->db->table('tubs')
            ->select('id, code, barcode, current_tare_kg, tare_verified_at, status')
            ->where(['active' => 1, 'status' => 'available'])
            ->groupStart()
                ->like('code', $query)
                ->orLike('barcode', $query)
            ->groupEnd()
            ->orderBy('code')
            ->limit(20);

        return $builder->get()->getResultArray();
    }

    /**
     * Busca participantes operativos del alcance actual.
     * No son usuarios del sistema: sólo identifican personal presente en piso.
     */
    public function searchParticipants(string $query, string $role): array
    {
        $this->assertOperatorPermissions();

        $scope = $this->scope();
        $capability = $this->participantCapability($role);
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');

        $builder = $this->db->table('operation_participants')
            ->select('id, code, name, can_sort, can_label')
            ->where([
                'company_id' => (int) $scope['company_id'],
                'site_id' => (int) $scope['site_id'],
                'active' => 1,
                $capability => 1,
            ])
            ->orderBy('name')
            ->limit(12);

        if ($query !== '') {
            $builder->groupStart()
                ->like('name', $query)
                ->orLike('code', $query)
            ->groupEnd();
        }

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'can_sort' => (bool) $row['can_sort'],
                'can_label' => (bool) $row['can_label'],
            ],
            $builder->get()->getResultArray()
        );
    }

    /**
     * Alta rápida desde la estación. Si la persona ya existe, agrega la
     * capacidad faltante en lugar de duplicarla.
     */
    public function quickCreateParticipant(string $name, string $role, int $actorUserId): array
    {
        $this->assertOperatorPermissions();

        $scope = $this->scope();
        $companyId = (int) $scope['company_id'];
        $siteId = (int) $scope['site_id'];
        $capability = $this->participantCapability($role);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new DomainException(
                'Captura un nombre de participante entre 2 y 120 caracteres.',
                'OPERATION_PARTICIPANT_NAME_INVALID'
            );
        }

        $this->db->transException(true)->transBegin();

        try {
            $existing = $this->db->table('operation_participants')
                ->where([
                    'company_id' => $companyId,
                    'site_id' => $siteId,
                    'name' => $name,
                ])
                ->get()
                ->getRowArray();

            $created = false;
            $capabilityAdded = false;
            $now = date('Y-m-d H:i:s');

            if ($existing !== null) {
                if (! (bool) $existing['active']) {
                    throw new DomainException(
                        'Ese participante existe, pero está inactivo. Reactívalo desde Catálogos para volver a utilizarlo.',
                        'OPERATION_PARTICIPANT_INACTIVE'
                    );
                }

                if (! (bool) $existing[$capability]) {
                    $this->db->table('operation_participants')
                        ->where('id', (int) $existing['id'])
                        ->update([
                            $capability => 1,
                            'updated_by' => $actorUserId,
                            'updated_at' => $now,
                        ]);
                    $capabilityAdded = true;
                }

                $participantId = (int) $existing['id'];
            } else {
                $code = 'OP-' . strtoupper(substr(
                    str_replace('-', '', Uuid::uuid7()->toString()),
                    0,
                    12
                ));

                $this->db->table('operation_participants')->insert([
                    'company_id' => $companyId,
                    'site_id' => $siteId,
                    'code' => $code,
                    'name' => $name,
                    'can_sort' => $capability === 'can_sort' ? 1 : 0,
                    'can_label' => $capability === 'can_label' ? 1 : 0,
                    'active' => 1,
                    'created_by' => $actorUserId,
                    'created_at' => $now,
                    'updated_by' => $actorUserId,
                    'updated_at' => $now,
                ]);

                $participantId = (int) $this->db->insertID();
                $created = true;
            }

            $row = $this->db->table('operation_participants')
                ->select('id, code, name, can_sort, can_label, active')
                ->where('id', $participantId)
                ->get()
                ->getRowArray();

            $this->audit->record(
                $created
                    ? 'operation_participant.quick_created'
                    : ($capabilityAdded
                        ? 'operation_participant.capability_added'
                        : 'operation_participant.reused'),
                'operation_participant',
                $participantId,
                $actorUserId,
                $companyId,
                $siteId,
                null,
                [
                    'role' => $role,
                    'name' => $name,
                    'created' => $created,
                    'capability_added' => $capabilityAdded,
                ]
            );

            $this->db->transCommit();

            return [
                'item' => [
                    'id' => (int) $row['id'],
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'can_sort' => (bool) $row['can_sort'],
                    'can_label' => (bool) $row['can_label'],
                ],
                'created' => $created,
                'capability_added' => $capabilityAdded,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /**
     * Cámaras activas del frigorífico receptor.
     */
    public function chambersForWarehouse(int $warehouseId): array
    {
        $scope = $this->scope();
        service('scopeContext')->assertWarehouse($warehouseId);

        $warehouse = $this->db->table('warehouses')
            ->select('id, company_id, site_id, active')
            ->where('id', $warehouseId)
            ->get()
            ->getRowArray();

        if (
            $warehouse === null
            || ! (bool) $warehouse['active']
            || (int) $warehouse['company_id'] !== (int) $scope['company_id']
            || (int) $warehouse['site_id'] !== (int) $scope['site_id']
        ) {
            throw new DomainException(
                'El frigorífico no pertenece al alcance operativo actual.',
                'WAREHOUSE_ENTRY_WAREHOUSE_SCOPE_MISMATCH'
            );
        }

        return $this->db->table('chambers')
            ->select('id, warehouse_id, code, name')
            ->where(['warehouse_id' => $warehouseId, 'active' => 1])
            ->orderBy('code')
            ->get()
            ->getResultArray();
    }

    /**
     * Disponibilidad operativa por fila/pasillo.
     * Descuenta inventario, restricciones y propuestas pendientes.
     */
    public function sectorAvailability(int $chamberId): array
    {
        $scope = $this->scope();
        $chamber = $this->chamber($chamberId, $scope);
        $layout = $this->stackRows($chamberId);

        if ($layout === []) {
            return ['chamber' => $chamber, 'layout_configured' => false, 'sectors' => []];
        }

        $stackIds = array_map('intval', array_column($layout, 'stack_id'));
        $occupied = $this->occupiedStackSet($stackIds);
        $blocked = $this->restrictedStackSet($stackIds);

        // Las entradas nuevas ya ocupan físicamente una estiba desde este punto.
        // Sólo descontamos propuestas heredadas que todavía no tienen stock creado.
        $proposedRows = $this->db->table('warehouse_entry_destinations wed')
            ->select('wed.sector_location_id, COUNT(*) AS proposed_count')
            ->join('stock_units su', 'su.weighing_id = wed.weighing_id', 'left')
            ->where('wed.chamber_id', $chamberId)
            ->where('wed.status', 'proposed')
            ->where('su.id', null)
            ->groupBy('wed.sector_location_id')
            ->get()
            ->getResultArray();

        $proposedBySector = [];
        foreach ($proposedRows as $row) {
            $proposedBySector[(int) $row['sector_location_id']] = (int) $row['proposed_count'];
        }

        $sectors = [];
        foreach ($layout as $row) {
            $sectorId = (int) $row['sector_id'];
            if (! isset($sectors[$sectorId])) {
                $sectors[$sectorId] = [
                    'id' => $sectorId,
                    'code' => (string) $row['sector_code'],
                    'name' => (string) $row['sector_name'],
                    'type' => (string) $row['sector_type'],
                    'total_stacks' => 0,
                    'free_physical' => 0,
                    'proposed' => $proposedBySector[$sectorId] ?? 0,
                    'available' => 0,
                ];
            }

            $stackId = (int) $row['stack_id'];
            $sectors[$sectorId]['total_stacks']++;
            if (! isset($occupied[$stackId]) && ! isset($blocked[$stackId])) {
                $sectors[$sectorId]['free_physical']++;
            }
        }

        foreach ($sectors as &$sector) {
            $sector['available'] = max(
                0,
                (int) $sector['free_physical'] - (int) $sector['proposed']
            );
        }
        unset($sector);

        $sectors = array_values($sectors);
        usort($sectors, static fn (array $a, array $b): int => strnatcasecmp($a['code'], $b['code']));

        return ['chamber' => $chamber, 'layout_configured' => true, 'sectors' => $sectors];
    }

    /**
     * Estibas físicamente libres para el montacarguista.
     */
    public function freeStacks(int $chamberId): array
    {
        $scope = $this->scope();
        $chamber = $this->chamber($chamberId, $scope);
        $rows = $this->stackRows($chamberId);
        $stackIds = array_map('intval', array_column($rows, 'stack_id'));
        $occupied = $this->occupiedStackSet($stackIds);
        $blocked = $this->restrictedStackSet($stackIds);

        $items = [];
        foreach ($rows as $row) {
            $stackId = (int) $row['stack_id'];
            if (isset($occupied[$stackId]) || isset($blocked[$stackId])) {
                continue;
            }

            $items[] = [
                'id' => $stackId,
                'code' => (string) $row['stack_code'],
                'name' => (string) $row['stack_name'],
                'path' => (string) $row['stack_path'],
                'position_id' => (int) $row['position_id'],
                'position_code' => (string) $row['position_code'],
                'sector_id' => (int) $row['sector_id'],
                'sector_code' => (string) $row['sector_code'],
                'sector_name' => (string) $row['sector_name'],
                'chamber_id' => (int) $chamber['id'],
                'chamber_code' => (string) $chamber['code'],
            ];
        }

        return ['chamber' => $chamber, 'layout_configured' => $rows !== [], 'items' => $items];
    }

    /**
     * Registro unificado de llenado + pesaje + marbete + recepción/inventario.
     * Cámara y fila/pasillo son la ubicación inicial propuesta. El sistema
     * asigna automáticamente una estiba interna libre de adentro hacia afuera.
     * La impresión se ejecuta DESPUÉS del commit de negocio.
     */
    public function register(array $payload, int $actorUserId): array
    {
        $this->assertOperatorPermissions();

        $scope = $this->scope();
        $companyId = (int) $scope['company_id'];
        $siteId = (int) $scope['site_id'];
        $templateId = (int) ($payload['template_id'] ?? 0);

        if ($templateId <= 0) {
            throw new DomainException(
                'No existe una plantilla de marbete seleccionada.',
                'LABEL_TEMPLATE_REQUIRED'
            );
        }

        $scaleReadingId = (int) ($payload['scale_reading_id'] ?? 0);
        if ($scaleReadingId <= 0) {
            throw new DomainException(
                'Primero debes obtener una lectura estable directamente de la báscula.',
                'SCALE_READING_REQUIRED'
            );
        }

        $plan = $this->db->table('discharge_plans dp')
            ->select('dp.id, dp.rsa_id, dp.voyage_id, v.company_id, v.site_id, v.receiving_warehouse_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->where('dp.id', (int) ($payload['plan_id'] ?? 0))
            ->get()
            ->getRowArray();

        if (
            $plan === null
            || (int) $plan['company_id'] !== $companyId
            || (int) $plan['site_id'] !== $siteId
        ) {
            throw new DomainException(
                'El plan no pertenece al alcance operativo actual.',
                'WAREHOUSE_ENTRY_PLAN_SCOPE_MISMATCH'
            );
        }

        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new DomainException(
                'Selecciona un frigorífico en el alcance operativo antes de confirmar la tina.',
                'WAREHOUSE_ENTRY_WAREHOUSE_SCOPE_REQUIRED'
            );
        }

        $receivingWarehouseId = (int) ($plan['receiving_warehouse_id'] ?? 0);
        if ($receivingWarehouseId > 0 && $receivingWarehouseId !== $warehouseId) {
            throw new DomainException(
                'La marea está asignada a otro frigorífico y no puede registrarse desde este alcance.',
                'WAREHOUSE_ENTRY_RECEIVER_MISMATCH'
            );
        }

        $payloadWarehouseId = (int) ($payload['warehouse_id'] ?? 0);
        if ($payloadWarehouseId > 0 && $payloadWarehouseId !== $warehouseId) {
            throw new DomainException(
                'El frigorífico enviado no coincide con el alcance operativo del usuario.',
                'WAREHOUSE_ENTRY_WAREHOUSE_SCOPE_MISMATCH'
            );
        }

        service('scopeContext')->assertWarehouse($warehouseId);

        if (
            (int) ($payload['sorter_participant_id'] ?? 0) <= 0
            || (int) ($payload['labeler_participant_id'] ?? 0) <= 0
        ) {
            throw new DomainException(
                'Selecciona al sorteador y al etiquetador antes de confirmar la tina.',
                'WAREHOUSE_ENTRY_PARTICIPANTS_REQUIRED'
            );
        }

        $tareOverride = filter_var(
            $payload['tare_override'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $declaredTareKg = $tareOverride
            ? (float) ($payload['declared_tare_kg'] ?? 0)
            : null;

        if (
            $tareOverride
            && (
                $declaredTareKg === null
                || ! is_finite($declaredTareKg)
                || $declaredTareKg <= 0
            )
        ) {
            throw new DomainException(
                'La tara corregida debe ser mayor que cero.',
                'WAREHOUSE_ENTRY_TARE_OVERRIDE_INVALID'
            );
        }

        $weighingPayload = [
            'filling_id' => 0,
            'gross_weight_kg' => (float) ($payload['gross_weight_kg'] ?? 0),
            'tare_source' => $tareOverride ? 'entry_override' : 'catalog',
            'declared_tare_kg' => $declaredTareKg,
            'penalty_percent' => (float) ($payload['penalty_percent'] ?? 0),
            'weight_source' => (string) ($payload['weight_source'] ?? 'scale'),
            'scale_id' => empty($payload['scale_id']) ? null : (int) $payload['scale_id'],
            'scale_reading_id' => $scaleReadingId,
            'rsa_id' => empty($plan['rsa_id']) ? null : (int) $plan['rsa_id'],
            'stable' => true,
            'original_value' => $payload['original_value'] ?? null,
            'device_timestamp' => $payload['device_timestamp'] ?? null,
            'reason_code' => trim((string) ($payload['reason_code'] ?? '')) ?: null,
            'observations' => trim((string) ($payload['observations'] ?? '')) ?: null,
        ];

        if (! in_array($weighingPayload['weight_source'], ['scale', 'middleware', 'simulator'], true)) {
            throw new DomainException(
                'Entrada a Frigorífico sólo admite lectura directa de báscula.',
                'WAREHOUSE_ENTRY_DIRECT_SCALE_REQUIRED'
            );
        }

        $business = null;

        $this->db->transException(true)->transBegin();
        try {
            $fillingPayload = [
                'plan_id' => (int) $payload['plan_id'],
                'tub_id' => (int) ($payload['tub_id'] ?? 0),
                'actual_tank_id' => (int) ($payload['actual_tank_id'] ?? 0),
                'species_id' => (int) ($payload['species_id'] ?? 0),
                'size_grade_id' => (int) ($payload['size_grade_id'] ?? 0),
                'source_tank_ids' => array_values($payload['source_tank_ids'] ?? []),
                'sorter_participant_id' => (int) $payload['sorter_participant_id'],
                'labeler_participant_id' => (int) $payload['labeler_participant_id'],
                'sorter_user_id' => null,
                'labeler_user_id' => null,
                'msc_code' => $payload['msc_code'] ?? null,
                'dolphin_safe_code' => $payload['dolphin_safe_code'] ?? null,
                'freezing_temperature_c' => $payload['freezing_temperature_c'] ?? null,
                'observations' => trim((string) ($payload['observations'] ?? '')) ?: null,
            ];

            $filling = $this->plans->startFillingInTransaction($fillingPayload, $actorUserId);
            $this->plans->completeFillingInTransaction((int) $filling['id'], $actorUserId);

            $weighingPayload['filling_id'] = (int) $filling['id'];
            $weighing = $this->weighings->confirmInTransaction(
                CreateWeighingData::fromArray($weighingPayload, $actorUserId)
            );
            $weighingRaw = $weighing->toRawArray();

            // Inventario exige un marbete vigente: primero se prepara la etiqueta.
            $label = $this->labels->prepare(
                (int) $weighingRaw['id'],
                $templateId,
                $actorUserId
            );

            $reservation = $this->reserveDestinationInTransaction(
                (int) $filling['id'],
                (int) $weighingRaw['id'],
                $warehouseId,
                (int) ($payload['chamber_id'] ?? 0),
                (int) ($payload['sector_location_id'] ?? 0),
                $actorUserId,
                trim((string) ($payload['observations'] ?? '')) ?: null
            );
            $destination = $reservation['destination'];
            $initialLocation = $reservation['initial_location'];

            // Desde Entrada a Frigorífico ya existe recepción e inventario.
            // Acomodo sólo confirmará/corregirá Cámara + Fila/Pasillo.
            $receipt = $this->inventory->receiveWeighings([
                'company_id' => $companyId,
                'site_id' => $siteId,
                'warehouse_id' => $warehouseId,
                'location_id' => (int) $initialLocation['id'],
                'weighing_ids' => [(int) $weighingRaw['id']],
                'final_placement' => true,
                'observations' => sprintf(
                    'Entrada a Frigorífico. Ubicación inicial automática %s · %s · %s · %s.',
                    (string) $initialLocation['chamber_code'],
                    (string) $initialLocation['sector_code'],
                    (string) $initialLocation['position_code'],
                    (string) $initialLocation['code']
                ),
            ], $actorUserId);

            $this->audit->record(
                'warehouse_entry.confirmed',
                'warehouse_entry_destination',
                (int) $destination['id'],
                $actorUserId,
                $companyId,
                $siteId,
                null,
                [
                    'plan_id' => (int) $payload['plan_id'],
                    'filling_id' => (int) $filling['id'],
                    'weighing_id' => (int) $weighingRaw['id'],
                    'label_id' => (int) $label['id'],
                    'reception_id' => (int) $receipt['reception_id'],
                    'warehouse_id' => $warehouseId,
                    'chamber_id' => (int) $destination['chamber_id'],
                    'sector_location_id' => (int) $destination['sector_location_id'],
                    'initial_location_id' => (int) $initialLocation['id'],
                    'out_of_plan' => (bool) ($filling['out_of_plan'] ?? false),
                ]
            );

            $business = [
                'filling' => $filling,
                'weighing' => $weighingRaw,
                'label' => $label,
                'destination' => $destination,
                'initial_location' => $initialLocation,
                'reception' => $receipt,
            ];

            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        $printRequested = ! array_key_exists('print_requested', $payload)
            || filter_var($payload['print_requested'], FILTER_VALIDATE_BOOLEAN);

        $print = [
            'ok' => false,
            'requested' => $printRequested,
            'error_code' => null,
            'message' => $printRequested
                ? 'La operación quedó guardada, pero la impresión no pudo iniciarse.'
                : 'La operación quedó guardada sin impresión automática.',
        ];

        if ($printRequested) {
            try {
                $result = $this->labels->printPrepared(
                    (int) $business['label']['id'],
                    empty($payload['printer_id']) ? null : (int) $payload['printer_id'],
                    $actorUserId
                );
                $print = ['ok' => true, 'requested' => true] + $result;
            } catch (\Throwable $exception) {
                $print = [
                    'ok' => false,
                    'requested' => true,
                    'error_code' => 'LABEL_PRINT_FAILED',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $business + ['print_result' => $print];
    }

    /**
     * Tinas pendientes de confirmar/corregir su acomodo físico.
     * La existencia ya fue creada por Entrada a Frigorífico.
     */
    /**
     * Detalle de consulta del plan para la modal de la estación.
     * No modifica operación; resume plan, avance, composición y tinas vigentes.
     */
    public function planDetail(int $planId): array
    {
        $this->assertOperatorPermissions();

        $scope = $this->scope();
        $companyId = (int) $scope['company_id'];
        $siteId = (int) $scope['site_id'];
        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);

        $plan = $this->db->table('discharge_plans dp')
            ->select(
                'dp.id, dp.folio, dp.voyage_id, dp.status, ' .
                'v.annual_number, v.year, v.receiving_warehouse_id, ' .
                've.code AS vessel_code, ve.name AS vessel_name, ' .
                'rsa.code AS rsa_code'
            )
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->join('rsa_records rsa', 'rsa.id = dp.rsa_id', 'left')
            ->where([
                'dp.id' => $planId,
                'v.company_id' => $companyId,
                'v.site_id' => $siteId,
            ])
            ->get()
            ->getRowArray();

        if ($plan === null) {
            throw new DomainException(
                'El plan no existe o no pertenece al alcance operativo actual.',
                'WAREHOUSE_ENTRY_PLAN_SCOPE_MISMATCH'
            );
        }

        $receivingWarehouseId = (int) ($plan['receiving_warehouse_id'] ?? 0);
        if ($warehouseId <= 0 || ($receivingWarehouseId > 0 && $receivingWarehouseId !== $warehouseId)) {
            throw new DomainException(
                'El plan pertenece a otro frigorífico receptor.',
                'WAREHOUSE_ENTRY_RECEIVER_MISMATCH'
            );
        }

        $warehouse = $this->db->table('warehouses')
            ->select('id, code, name')
            ->where([
                'id' => $warehouseId,
                'company_id' => $companyId,
                'site_id' => $siteId,
                'active' => 1,
            ])
            ->get()
            ->getRowArray();

        if ($warehouse === null) {
            throw new DomainException(
                'El frigorífico del alcance ya no está disponible.',
                'WAREHOUSE_ENTRY_WAREHOUSE_SCOPE_INVALID'
            );
        }

        $totals = $this->planTotals([$planId])[$planId] ?? [
            'planned_kg' => 0.0,
            'processed_kg' => 0.0,
            'out_of_plan_kg' => 0.0,
        ];

        $plannedKg = round((float) $totals['planned_kg'], 3);
        $processedKg = round((float) $totals['processed_kg'], 3);
        $pendingKg = max(0, round($plannedKg - $processedKg, 3));
        $progress = $plannedKg > 0
            ? round(min(100, max(0, ($processedKg / $plannedKg) * 100)), 1)
            : 0.0;

        $lines = $this->planLines([$planId]);

        $sourceRows = $this->db->table('tub_fillings tf')
            ->select(
                "tf.species_id, tf.size_grade_id, " .
                "GROUP_CONCAT(DISTINCT srcvt.tank_number ORDER BY srcvt.tank_number SEPARATOR ', ') AS source_tanks",
                false
            )
            ->join('weighings wg', "wg.tub_filling_id = tf.id AND wg.status <> 'reversed'", 'inner', false)
            ->join('filling_source_tanks fst', 'fst.tub_filling_id = tf.id')
            ->join('voyage_tanks srcvt', 'srcvt.id = fst.voyage_tank_id')
            ->where('tf.discharge_plan_id', $planId)
            ->groupBy('tf.species_id, tf.size_grade_id')
            ->get()
            ->getResultArray();

        $sourceByComposition = [];
        foreach ($sourceRows as $row) {
            $sourceByComposition[
                (int) $row['species_id'] . ':' . (int) ($row['size_grade_id'] ?? 0)
            ] = trim((string) ($row['source_tanks'] ?? ''));
        }

        $products = [];
        foreach ($lines as $line) {
            $key = (int) $line['species_id'] . ':' . (int) ($line['size_grade_id'] ?? 0);

            if (! isset($products[$key])) {
                $products[$key] = [
                    'species_id' => (int) $line['species_id'],
                    'species_code' => (string) $line['species_code'],
                    'species_name' => (string) $line['species_name'],
                    'size_grade_id' => $line['size_grade_id'] === null ? null : (int) $line['size_grade_id'],
                    'size_code' => (string) ($line['size_code'] ?? ''),
                    'size_name' => (string) ($line['size_name'] ?? 'Todas'),
                    'plan_tanks' => [],
                    'product_tanks' => '',
                    'planned_kg' => 0.0,
                    'processed_kg' => 0.0,
                    'pending_kg' => 0.0,
                ];
            }

            $products[$key]['plan_tanks'][(string) $line['tank_number']] = true;
            $products[$key]['planned_kg'] = round(
                (float) $products[$key]['planned_kg'] + (float) $line['planned_weight_kg'],
                3
            );
            $products[$key]['processed_kg'] = round(
                (float) $products[$key]['processed_kg'] + (float) $line['processed_kg'],
                3
            );
        }

        foreach ($products as $key => &$product) {
            $planTanks = implode(', ', array_keys($product['plan_tanks']));
            $product['plan_tanks'] = $planTanks;
            $product['product_tanks'] = $sourceByComposition[$key] ?? $planTanks;
            $product['pending_kg'] = max(
                0,
                round((float) $product['planned_kg'] - (float) $product['processed_kg'], 3)
            );
        }
        unset($product);

        $entries = $this->db->query(
            <<<'SQL'
SELECT
    wg.id AS weighing_id,
    wg.folio AS weighing_folio,
    wg.status AS weighing_status,
    wg.accepted_net_kg,
    wg.confirmed_at,
    tf.id AS filling_id,
    t.id AS tub_id,
    t.code AS tub_code,
    s.name AS species_name,
    sg.name AS size_name,
    actor.username AS registered_by,
    wed.id AS destination_id,
    wed.status AS destination_status,
    su.id AS stock_unit_id,
    su.status AS stock_status,
    su.reserved_weight_kg,
    rl.reception_id,
    receipt_document.id AS receipt_document_id,
    receipt_reversal.id AS receipt_reversal_id,
    (
        SELECT MAX(im.id)
        FROM inventory_movements im
        WHERE im.stock_unit_id = su.id
    ) AS latest_movement_id,
    (
        SELECT MAX(imr.id)
        FROM inventory_movements imr
        WHERE imr.stock_unit_id = su.id
          AND imr.inventory_document_id = receipt_document.id
    ) AS receipt_movement_id
FROM tub_fillings tf
INNER JOIN weighings wg
    ON wg.tub_filling_id = tf.id
   AND wg.status <> 'reversed'
INNER JOIN tubs t ON t.id = tf.tub_id
INNER JOIN species s ON s.id = tf.species_id
LEFT JOIN size_grades sg ON sg.id = tf.size_grade_id
LEFT JOIN users actor ON actor.id = wg.confirmed_by
LEFT JOIN warehouse_entry_destinations wed ON wed.weighing_id = wg.id
LEFT JOIN stock_units su ON su.weighing_id = wg.id
LEFT JOIN reception_lines rl ON rl.weighing_id = wg.id
LEFT JOIN inventory_documents receipt_document
    ON receipt_document.source_document_type = 'reception'
   AND receipt_document.source_document_id = rl.reception_id
   AND receipt_document.movement_type = 'receipt'
LEFT JOIN inventory_documents receipt_reversal
    ON receipt_reversal.reversal_of_id = receipt_document.id
WHERE tf.discharge_plan_id = ?
ORDER BY wg.confirmed_at DESC, wg.id DESC
SQL,
            [$planId]
        )->getResultArray();

        $requestCommitted = [];
        if ($this->db->tableExists('warehouse_request_units')) {
            $stockIds = array_values(array_filter(array_map(
                static fn (array $row): int => (int) ($row['stock_unit_id'] ?? 0),
                $entries
            )));

            if ($stockIds !== []) {
                $commitments = $this->db->table('warehouse_request_units')
                    ->select('active_stock_id')
                    ->whereIn('active_stock_id', $stockIds)
                    ->get()
                    ->getResultArray();

                foreach ($commitments as $commitment) {
                    $requestCommitted[(int) $commitment['active_stock_id']] = true;
                }
            }
        }

        $user = auth()->user();
        $canCancelPermission = $user !== null
            && $user->can('weighings.reverse')
            && $user->can('inventory.reverse');

        foreach ($entries as &$entry) {
            $stockId = (int) ($entry['stock_unit_id'] ?? 0);
            $latestMovementId = (int) ($entry['latest_movement_id'] ?? 0);
            $receiptMovementId = (int) ($entry['receipt_movement_id'] ?? 0);

            $entry['cancelable'] = $canCancelPermission
                && (string) ($entry['destination_status'] ?? '') === 'proposed'
                && in_array((string) ($entry['weighing_status'] ?? ''), ['confirmed', 'pending_inspection', 'received'], true)
                && (int) ($entry['receipt_document_id'] ?? 0) > 0
                && (int) ($entry['receipt_reversal_id'] ?? 0) === 0
                && $stockId > 0
                && abs((float) ($entry['reserved_weight_kg'] ?? 0)) < 0.0001
                && $latestMovementId > 0
                && $latestMovementId === $receiptMovementId
                && ! isset($requestCommitted[$stockId]);

            $entry['destination_label'] = match ((string) ($entry['destination_status'] ?? '')) {
                'placed' => 'Ingresada en destino',
                'proposed' => 'Pendiente de ingreso',
                'canceled' => 'Cancelada',
                default => 'Sin estado',
            };
        }
        unset($entry);

        $last = $entries[0] ?? null;

        return [
            'plan' => [
                'id' => (int) $plan['id'],
                'folio' => (string) $plan['folio'],
                'vessel_code' => (string) $plan['vessel_code'],
                'vessel_name' => (string) $plan['vessel_name'],
                'annual_number' => (int) $plan['annual_number'],
                'year' => (int) $plan['year'],
                'rsa_code' => (string) ($plan['rsa_code'] ?? ''),
                'status' => (string) $plan['status'],
                'warehouse_code' => (string) $warehouse['code'],
                'warehouse_name' => (string) $warehouse['name'],
                'planned_kg' => $plannedKg,
                'processed_kg' => $processedKg,
                'pending_kg' => $pendingKg,
                'progress_percent' => $progress,
            ],
            'products' => array_values($products),
            'entries' => $entries,
            'processed_tub_count' => count($entries),
            'last_record' => $last === null ? null : [
                'tub_code' => (string) $last['tub_code'],
                'accepted_net_kg' => round((float) $last['accepted_net_kg'], 3),
                'confirmed_at' => (string) $last['confirmed_at'],
            ],
            'can_cancel_entries' => $canCancelPermission,
        ];
    }

    /**
     * Cancela una Entrada a Frigorífico únicamente mientras la propuesta de
     * destino siga pendiente. La operación compensa inventario, revierte el
     * pesaje y libera la tina conservando toda la trazabilidad.
     */
    public function cancelRegisteredEntry(int $weighingId, string $reason, int $actorUserId): array
    {
        $this->assertOperatorPermissions();

        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new DomainException(
                'Cancelar el registro requiere un motivo de 5 a 500 caracteres.',
                'WAREHOUSE_ENTRY_CANCEL_REASON_REQUIRED'
            );
        }

        $user = auth()->user();
        if (
            $user === null
            || ! $user->can('weighings.reverse')
            || ! $user->can('inventory.reverse')
        ) {
            throw new DomainException(
                'No tienes permisos para cancelar una entrada que ya generó pesaje e inventario.',
                'WAREHOUSE_ENTRY_CANCEL_PERMISSION_REQUIRED'
            );
        }

        $scope = $this->scope();
        $companyId = (int) $scope['company_id'];
        $siteId = (int) $scope['site_id'];
        $warehouseId = (int) ($scope['warehouse_id'] ?? 0);

        $this->db->transException(true)->transBegin();

        try {
            $entry = $this->db->query(
                <<<'SQL'
SELECT
    wg.id AS weighing_id,
    wg.status AS weighing_status,
    wg.tub_filling_id,
    wg.tub_id,
    tf.discharge_plan_id,
    tf.status AS filling_status,
    v.company_id,
    v.site_id,
    wed.id AS destination_id,
    wed.status AS destination_status,
    wed.warehouse_id,
    su.id AS stock_unit_id,
    su.reserved_weight_kg,
    rl.reception_id,
    receipt_document.id AS receipt_document_id,
    receipt_reversal.id AS receipt_reversal_id
FROM weighings wg
INNER JOIN tub_fillings tf ON tf.id = wg.tub_filling_id
INNER JOIN discharge_plans dp ON dp.id = tf.discharge_plan_id
INNER JOIN voyages v ON v.id = dp.voyage_id
LEFT JOIN warehouse_entry_destinations wed ON wed.weighing_id = wg.id
LEFT JOIN stock_units su ON su.weighing_id = wg.id
LEFT JOIN reception_lines rl ON rl.weighing_id = wg.id
LEFT JOIN inventory_documents receipt_document
    ON receipt_document.source_document_type = 'reception'
   AND receipt_document.source_document_id = rl.reception_id
   AND receipt_document.movement_type = 'receipt'
LEFT JOIN inventory_documents receipt_reversal
    ON receipt_reversal.reversal_of_id = receipt_document.id
WHERE wg.id = ?
FOR UPDATE
SQL,
                [$weighingId]
            )->getRowArray();

            if ($entry === null) {
                throw new DomainException(
                    'El registro de entrada ya no existe.',
                    'WAREHOUSE_ENTRY_NOT_FOUND'
                );
            }

            if (
                (int) $entry['company_id'] !== $companyId
                || (int) $entry['site_id'] !== $siteId
                || (int) ($entry['warehouse_id'] ?? 0) !== $warehouseId
            ) {
                throw new DomainException(
                    'La entrada no pertenece al alcance operativo actual.',
                    'WAREHOUSE_ENTRY_CANCEL_SCOPE_MISMATCH'
                );
            }

            if ((string) $entry['weighing_status'] === 'reversed') {
                $this->db->transCommit();

                return [
                    'weighing_id' => $weighingId,
                    'canceled' => true,
                    'already_canceled' => true,
                ];
            }

            if ((string) ($entry['destination_status'] ?? '') !== 'proposed') {
                throw new DomainException(
                    'La tina ya fue ingresada/confirmada en el destino y ya no puede cancelarse desde esta estación.',
                    'WAREHOUSE_ENTRY_ALREADY_IN_DESTINATION'
                );
            }

            $stockUnitId = (int) ($entry['stock_unit_id'] ?? 0);
            $receiptDocumentId = (int) ($entry['receipt_document_id'] ?? 0);

            if ($stockUnitId <= 0 || $receiptDocumentId <= 0) {
                throw new DomainException(
                    'La entrada no tiene una recepción/inventario reversible asociado.',
                    'WAREHOUSE_ENTRY_RECEIPT_NOT_REVERSIBLE'
                );
            }

            if ((int) ($entry['receipt_reversal_id'] ?? 0) > 0) {
                throw new DomainException(
                    'La recepción de esta tina ya fue reversada.',
                    'WAREHOUSE_ENTRY_RECEIPT_ALREADY_REVERSED'
                );
            }

            if (abs((float) ($entry['reserved_weight_kg'] ?? 0)) >= 0.0001) {
                throw new DomainException(
                    'La tina ya tiene cantidad reservada y no puede cancelarse desde Entrada a Frigorífico.',
                    'WAREHOUSE_ENTRY_HAS_RESERVATION'
                );
            }

            if ($this->db->tableExists('warehouse_request_units')) {
                $commitment = $this->db->table('warehouse_request_units')
                    ->where('active_stock_id', $stockUnitId)
                    ->countAllResults();

                if ($commitment > 0) {
                    throw new DomainException(
                        'La tina ya está comprometida con una solicitud y no puede cancelarse desde esta estación.',
                        'WAREHOUSE_ENTRY_HAS_REQUEST_COMMITMENT'
                    );
                }
            }

            $latestMovementId = (int) $this->db->table('inventory_movements')
                ->selectMax('id')
                ->where('stock_unit_id', $stockUnitId)
                ->get()
                ->getRow('id');

            $receiptMovementId = (int) $this->db->table('inventory_movements')
                ->selectMax('id')
                ->where([
                    'stock_unit_id' => $stockUnitId,
                    'inventory_document_id' => $receiptDocumentId,
                ])
                ->get()
                ->getRow('id');

            if ($latestMovementId <= 0 || $latestMovementId !== $receiptMovementId) {
                throw new DomainException(
                    'La tina ya tuvo movimientos posteriores; no puede cancelarse desde Entrada a Frigorífico.',
                    'WAREHOUSE_ENTRY_HAS_LATER_MOVEMENTS'
                );
            }

            // Ambas operaciones usan la misma conexión y respetan transacciones
            // anidadas de CI4; el commit real se realiza al cerrar este orquestador.
            $inventoryReversal = $this->inventory->reverse(
                $receiptDocumentId,
                $reason,
                $actorUserId
            );

            $this->weighings->reverse(
                $weighingId,
                $reason,
                $actorUserId
            );

            $now = date('Y-m-d H:i:s');
            $fillingTerminalStatus = $this->warehouseEntryCanceledFillingStatus();

            $this->db->table('tub_fillings')
                ->where('id', (int) $entry['tub_filling_id'])
                ->update([
                    'status' => $fillingTerminalStatus,
                    'updated_at' => $now,
                ]);

            $tubLockVersion = (int) $this->db
                ->query('SELECT lock_version FROM tubs WHERE id = ?', [(int) $entry['tub_id']])
                ->getRow('lock_version');

            $this->db->table('tubs')
                ->where('id', (int) $entry['tub_id'])
                ->update([
                    'status' => 'available',
                    'lock_version' => $tubLockVersion + 1,
                    'updated_at' => $now,
                ]);

            $this->db->table('warehouse_entry_destinations')
                ->where('id', (int) $entry['destination_id'])
                ->update([
                    'status' => 'canceled',
                    'change_reason' => $reason,
                    'updated_at' => $now,
                ]);

            $this->audit->record(
                'warehouse_entry.canceled',
                'warehouse_entry_destination',
                (int) $entry['destination_id'],
                $actorUserId,
                $companyId,
                $siteId,
                [
                    'status' => 'proposed',
                    'weighing_status' => (string) $entry['weighing_status'],
                    'filling_status' => (string) $entry['filling_status'],
                ],
                [
                    'status' => 'canceled',
                    'weighing_status' => 'reversed',
                    'filling_status' => $fillingTerminalStatus,
                    'tub_status' => 'available',
                    'inventory_reversal_document_id' => $inventoryReversal['document_id'] ?? null,
                ],
                $reason
            );

            $this->db->transCommit();

            return [
                'weighing_id' => $weighingId,
                'destination_id' => (int) $entry['destination_id'],
                'canceled' => true,
                'already_canceled' => false,
                'inventory_reversal_document_id' => $inventoryReversal['document_id'] ?? null,
            ];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function placementContext(): array
    {
        $scope = $this->scope();

        if ((int) ($scope['warehouse_id'] ?? 0) <= 0) {
            throw new DomainException(
                'Selecciona un frigorífico en el alcance operativo antes de acomodar tinas.',
                'WAREHOUSE_ENTRY_WAREHOUSE_SCOPE_REQUIRED'
            );
        }

        $builder = $this->db->table('warehouse_entry_destinations wed')
            ->select(
                'wed.*, w.code AS warehouse_code, w.name AS warehouse_name, ' .
                'c.code AS chamber_code, c.name AS chamber_name, ' .
                'sector.code AS sector_code, sector.name AS sector_name, ' .
                'tf.discharge_plan_id, t.code AS tub_code, ' .
                'wg.folio AS weighing_folio, wg.accepted_net_kg, ' .
                'l.id AS label_id, l.code AS label_code, ' .
                'lo.code AS lot_code, ' .
                's.code AS species_code, s.name AS species_name, ' .
                'sg.code AS size_code, sg.name AS size_name, ' .
                'dp.folio AS plan_folio, v.annual_number, v.year, ' .
                've.code AS vessel_code, ve.name AS vessel_name'
            )
            ->join('warehouses w', 'w.id = wed.warehouse_id')
            ->join('chambers c', 'c.id = wed.chamber_id')
            ->join('locations sector', 'sector.id = wed.sector_location_id')
            ->join('tub_fillings tf', 'tf.id = wed.tub_filling_id')
            ->join('tubs t', 't.id = tf.tub_id')
            ->join('weighings wg', 'wg.id = wed.weighing_id')
            ->join('labels l', "l.weighing_id = wg.id AND l.status <> 'canceled'")
            ->join('lots lo', 'lo.id = wg.lot_id')
            ->join('species s', 's.id = tf.species_id')
            ->join('size_grades sg', 'sg.id = tf.size_grade_id', 'left')
            ->join('discharge_plans dp', 'dp.id = tf.discharge_plan_id')
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->where('wed.status', 'proposed')
            ->where('w.company_id', (int) $scope['company_id'])
            ->where('w.site_id', (int) $scope['site_id']);

        if ($scope['warehouse_id'] !== null) {
            $builder->where('wed.warehouse_id', (int) $scope['warehouse_id']);
        }

        $pending = $builder
            ->orderBy('wed.proposed_at')
            ->get()
            ->getResultArray();

        $warehouses = $this->allowedWarehouses($scope);
        $warehouseIds = array_map('intval', array_column($warehouses, 'id')) ?: [0];
        $chambers = $this->db->table('chambers')
            ->select('id, warehouse_id, code, name')
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('active', 1)
            ->orderBy('code')
            ->get()
            ->getResultArray();

        $warehouse = $warehouses[0] ?? null;

        return [
            'pending' => $pending,
            'warehouse' => $warehouse,
            'warehouses' => $warehouses,
            'chambers' => $chambers,
        ];
    }

    /**
     * Confirma Cámara + Fila/Pasillo real. Si cambió respecto de Entrada a
     * Frigorífico, reubica la existencia con un movimiento trazable. La estiba
     * interna se elige automáticamente de adentro hacia afuera.
     */
    public function place(
        int $destinationId,
        int $chamberId,
        int $sectorId,
        ?string $reason,
        int $actorUserId
    ): array {
        $reason = trim((string) $reason);
        $destination = $this->destinationContext($destinationId);

        if ($destination['status'] === 'placed') {
            $stock = $this->db->table('stock_units')
                ->where('weighing_id', (int) $destination['weighing_id'])
                ->get()
                ->getRowArray();

            return [
                'destination_id' => $destinationId,
                'status' => 'placed',
                'final_location_id' => $stock === null ? (int) ($destination['final_location_id'] ?? 0) : (int) $stock['location_id'],
                'already_placed' => true,
            ];
        }

        if ($destination['status'] !== 'proposed') {
            throw new DomainException(
                'La propuesta de ubicación ya no está pendiente.',
                'WAREHOUSE_ENTRY_DESTINATION_NOT_PROPOSED'
            );
        }

        $changed = $chamberId !== (int) $destination['chamber_id']
            || $sectorId !== (int) $destination['sector_location_id'];

        if ($changed && mb_strlen($reason) < 5) {
            throw new DomainException(
                'Si la tina cambia de cámara o fila/pasillo, indica el motivo del cambio.',
                'WAREHOUSE_ENTRY_LOCATION_CHANGE_REASON_REQUIRED'
            );
        }

        $receipt = null;
        $movement = null;
        $legacyReception = false;
        $targetStack = null;

        $this->db->transException(true)->transBegin();
        try {
            $locked = $this->db->query(
                'SELECT * FROM warehouse_entry_destinations WHERE id = ? FOR UPDATE',
                [$destinationId]
            )->getRowArray();

            if ($locked === null) {
                throw new DomainException(
                    'La propuesta de ubicación ya no existe.',
                    'WAREHOUSE_ENTRY_DESTINATION_NOT_FOUND'
                );
            }

            if ($locked['status'] === 'placed') {
                $stock = $this->db->table('stock_units')
                    ->where('weighing_id', (int) $locked['weighing_id'])
                    ->get()
                    ->getRowArray();
                $this->db->transCommit();

                return [
                    'destination_id' => $destinationId,
                    'status' => 'placed',
                    'final_location_id' => $stock === null ? (int) ($locked['final_location_id'] ?? 0) : (int) $stock['location_id'],
                    'already_placed' => true,
                ];
            }

            if ($locked['status'] !== 'proposed') {
                throw new DomainException(
                    'La propuesta de ubicación ya no está pendiente.',
                    'WAREHOUSE_ENTRY_DESTINATION_NOT_PROPOSED'
                );
            }

            $existingStock = $this->db->query(
                'SELECT * FROM stock_units WHERE weighing_id = ? FOR UPDATE',
                [(int) $destination['weighing_id']]
            )->getRowArray();

            if ($existingStock === null) {
                // Compatibilidad con pendientes creados antes de v2.2: reciben aquí una sola vez.
                $targetStack = $this->selectFreeStackInSectorForUpdate(
                    (int) $destination['warehouse_id'],
                    $chamberId,
                    $sectorId,
                    $destinationId
                );
                $receipt = $this->inventory->receiveWeighings([
                    'company_id' => (int) $destination['company_id'],
                    'site_id' => (int) $destination['site_id'],
                    'warehouse_id' => (int) $destination['warehouse_id'],
                    'location_id' => (int) $targetStack['id'],
                    'weighing_ids' => [(int) $destination['weighing_id']],
                    'final_placement' => true,
                    'observations' => $reason !== ''
                        ? 'Acomodo de pendiente heredado: ' . $reason
                        : 'Acomodo de pendiente heredado de Entrada a Frigorífico.',
                ], $actorUserId);
                $legacyReception = true;
                $existingStock = $this->db->query(
                    'SELECT * FROM stock_units WHERE weighing_id = ? FOR UPDATE',
                    [(int) $destination['weighing_id']]
                )->getRowArray();
            } else {
                $currentStack = $this->stackContext(
                    (int) $existingStock['location_id'],
                    (int) $destination['warehouse_id']
                );

                $alreadyInSelectedSector = (int) $currentStack['chamber_id'] === $chamberId
                    && (int) $currentStack['sector_id'] === $sectorId;

                if ($alreadyInSelectedSector) {
                    $targetStack = $currentStack;
                } else {
                    $targetStack = $this->selectFreeStackInSectorForUpdate(
                        (int) $destination['warehouse_id'],
                        $chamberId,
                        $sectorId
                    );

                    $movement = $this->inventory->createMovement(new CreateMovementData(
                        MovementType::Relocation,
                        (int) $destination['company_id'],
                        (int) $destination['site_id'],
                        (int) $destination['warehouse_id'],
                        (int) $destination['warehouse_id'],
                        'warehouse_entry_destination',
                        $destinationId,
                        null,
                        null,
                        $changed ? 'warehouse_placement_correction' : 'warehouse_placement',
                        $reason !== ''
                            ? 'Acomodo físico de tina: ' . $reason
                            : 'Confirmación de acomodo físico de tina.',
                        $actorUserId,
                        [new MovementLineData(
                            (int) $existingStock['id'],
                            (float) $existingStock['on_hand_weight_kg'],
                            (int) $existingStock['location_id'],
                            (int) $targetStack['id'],
                            (int) $destination['warehouse_id'],
                            (int) $destination['warehouse_id']
                        )]
                    ));
                }
            }

            if ($targetStack === null) {
                throw new DomainException(
                    'No fue posible determinar la ubicación interna final.',
                    'WAREHOUSE_ENTRY_FINAL_STACK_REQUIRED'
                );
            }

            $now = date('Y-m-d H:i:s');
            $this->db->table('warehouse_entry_destinations')
                ->where('id', $destinationId)
                ->update([
                    'final_location_id' => (int) $targetStack['id'],
                    'status' => 'placed',
                    'placed_by' => $actorUserId,
                    'placed_at' => $now,
                    'change_reason' => $reason !== '' ? $reason : null,
                    'lock_version' => (int) $locked['lock_version'] + 1,
                    'updated_at' => $now,
                ]);

            $this->audit->record(
                'warehouse_entry.placed',
                'warehouse_entry_destination',
                $destinationId,
                $actorUserId,
                (int) $destination['company_id'],
                (int) $destination['site_id'],
                [
                    'status' => $locked['status'],
                    'chamber_id' => (int) $destination['chamber_id'],
                    'sector_location_id' => (int) $destination['sector_location_id'],
                ],
                [
                    'status' => 'placed',
                    'chamber_id' => $chamberId,
                    'sector_location_id' => $sectorId,
                    'final_location_id' => (int) $targetStack['id'],
                    'changed' => $changed,
                    'legacy_reception' => $legacyReception,
                    'reception_id' => $receipt['reception_id'] ?? null,
                    'relocation_document_id' => $movement['document_id'] ?? null,
                ],
                $reason !== '' ? $reason : null
            );

            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        return [
            'destination_id' => $destinationId,
            'status' => 'placed',
            'final_location_id' => (int) $targetStack['id'],
            'final_location' => $targetStack,
            'changed' => $changed,
            'legacy_reception' => $legacyReception,
            'reception' => $receipt,
            'relocation' => $movement,
        ];
    }

    /**
     * Si el esquema admite "canceled" se usa explícitamente. En esquemas antiguos
     * con ENUM sin ese valor se conserva "weighed" como estado terminal técnico;
     * el pesaje queda reversed y la auditoría conserva la cancelación real.
     */
    private function warehouseEntryCanceledFillingStatus(): string
    {
        $column = $this->db->query("SHOW COLUMNS FROM tub_fillings LIKE 'status'")
            ->getRowArray();

        $type = strtolower((string) ($column['Type'] ?? ''));
        if (str_starts_with($type, 'enum(') && ! str_contains($type, "'canceled'")) {
            return 'weighed';
        }

        return 'canceled';
    }

    private function planTotals(array $planIds): array
    {
        $plannedRows = $this->db->table('discharge_plan_tanks')
            ->select('discharge_plan_id, SUM(planned_weight_kg) AS planned_kg')
            ->whereIn('discharge_plan_id', $planIds)
            ->groupBy('discharge_plan_id')
            ->get()
            ->getResultArray();

        $processedRows = $this->db->table('tub_fillings tf')
            ->select(
                'tf.discharge_plan_id, ' .
                'SUM(CASE WHEN w.status <> \'reversed\' AND tf.out_of_plan = 0 THEN w.accepted_net_kg ELSE 0 END) AS processed_kg, ' .
                'SUM(CASE WHEN w.status <> \'reversed\' AND tf.out_of_plan = 1 THEN w.accepted_net_kg ELSE 0 END) AS out_of_plan_kg',
                false
            )
            ->join('weighings w', 'w.tub_filling_id = tf.id')
            ->whereIn('tf.discharge_plan_id', $planIds)
            ->groupBy('tf.discharge_plan_id')
            ->get()
            ->getResultArray();

        $result = [];
        foreach ($planIds as $planId) {
            $result[(int) $planId] = ['planned_kg' => 0.0, 'processed_kg' => 0.0, 'out_of_plan_kg' => 0.0];
        }
        foreach ($plannedRows as $row) {
            $result[(int) $row['discharge_plan_id']]['planned_kg'] = round((float) $row['planned_kg'], 3);
        }
        foreach ($processedRows as $row) {
            $result[(int) $row['discharge_plan_id']]['processed_kg'] = round((float) $row['processed_kg'], 3);
            $result[(int) $row['discharge_plan_id']]['out_of_plan_kg'] = round((float) $row['out_of_plan_kg'], 3);
        }

        return $result;
    }

    private function planLines(array $planIds): array
    {
        $lines = $this->db->table('discharge_plan_tanks dpt')
            ->select(
                'dpt.id, dpt.discharge_plan_id, dpt.voyage_tank_id, dpt.species_id, ' .
                'dpt.size_grade_id, dpt.planned_weight_kg, dpt.msc_code, ' .
                'dpt.dolphin_safe_code, dpt.freezing_temperature_c, dpt.sequence_number, ' .
                'vt.tank_number, s.code AS species_code, s.name AS species_name, ' .
                'sg.code AS size_code, sg.name AS size_name'
            )
            ->join('voyage_tanks vt', 'vt.id = dpt.voyage_tank_id')
            ->join('species s', 's.id = dpt.species_id')
            ->join('size_grades sg', 'sg.id = dpt.size_grade_id', 'left')
            ->whereIn('dpt.discharge_plan_id', $planIds)
            ->orderBy('dpt.sequence_number')
            ->get()
            ->getResultArray();

        // planned_line_id deja una relación inequívoca entre la tina procesada
        // y la composición del plan que consumió. Evita doble conteo cuando un
        // plan combina líneas genéricas (sin talla) y líneas específicas.
        $processedRows = $this->db->table('tub_fillings tf')
            ->select('tf.planned_line_id, SUM(w.accepted_net_kg) AS processed_kg', false)
            ->join('weighings w', 'w.tub_filling_id = tf.id AND w.status <> \'reversed\'', 'inner', false)
            ->whereIn('tf.discharge_plan_id', $planIds)
            ->where('tf.out_of_plan', 0)
            ->where('tf.planned_line_id IS NOT NULL', null, false)
            ->groupBy('tf.planned_line_id')
            ->get()
            ->getResultArray();

        $processed = [];
        foreach ($processedRows as $row) {
            $processed[(int) $row['planned_line_id']] = round((float) $row['processed_kg'], 3);
        }

        foreach ($lines as &$line) {
            $line['processed_kg'] = $processed[(int) $line['id']] ?? 0.0;
            $line['pending_kg'] = max(
                0,
                round((float) $line['planned_weight_kg'] - (float) $line['processed_kg'], 3)
            );
        }
        unset($line);

        return $lines;
    }

    /**
     * Registra la Cámara + Fila/Pasillo propuesta y reserva la ubicación interna
     * que se utilizará para crear la recepción/existencia en el mismo guardado.
     * final_location_id permanece nulo hasta que Acomodo confirme la ubicación real.
     *
     * @return array{destination: array, initial_location: array}
     */
    private function reserveDestinationInTransaction(
        int $fillingId,
        int $weighingId,
        int $warehouseId,
        int $chamberId,
        int $sectorId,
        int $actorUserId,
        ?string $notes
    ): array {
        $warehouse = $this->db->query(
            'SELECT * FROM warehouses WHERE id = ? FOR UPDATE',
            [$warehouseId]
        )->getRowArray();

        if ($warehouse === null || ! (bool) $warehouse['active']) {
            throw new DomainException(
                'El frigorífico seleccionado no está disponible.',
                'WAREHOUSE_ENTRY_WAREHOUSE_NOT_AVAILABLE'
            );
        }

        $initialLocation = $this->selectFreeStackInSectorForUpdate(
            $warehouseId,
            $chamberId,
            $sectorId
        );

        $now = date('Y-m-d H:i:s');
        $this->db->table('warehouse_entry_destinations')->insert([
            'uuid' => Uuid::uuid7()->toString(),
            'tub_filling_id' => $fillingId,
            'weighing_id' => $weighingId,
            'warehouse_id' => $warehouseId,
            'chamber_id' => $chamberId,
            'sector_location_id' => $sectorId,
            'final_location_id' => null,
            'status' => 'proposed',
            'proposed_by' => $actorUserId,
            'proposed_at' => $now,
            'notes' => $notes,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->db->insertID();
        $destination = $this->db->table('warehouse_entry_destinations')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        return [
            'destination' => $destination,
            'initial_location' => $initialLocation,
        ];
    }

    /**
     * Selecciona automáticamente una estiba libre dentro de Cámara + Fila/Pasillo.
     * Los códigos físicos actuales son cero-rellenados (POS01, POS02, ...), por lo
     * que position_code DESC representa el recorrido de adentro hacia afuera.
     */
    private function selectFreeStackInSectorForUpdate(
        int $warehouseId,
        int $chamberId,
        int $sectorId,
        ?int $excludeLegacyDestinationId = null
    ): array {
        $chamber = $this->db->query(
            'SELECT * FROM chambers WHERE id = ? AND warehouse_id = ? AND active = 1 FOR UPDATE',
            [$chamberId, $warehouseId]
        )->getRowArray();

        if ($chamber === null) {
            throw new DomainException(
                'La cámara seleccionada no pertenece al frigorífico receptor.',
                'WAREHOUSE_ENTRY_INVALID_CHAMBER'
            );
        }

        $sector = $this->db->query(
            'SELECT * FROM locations WHERE id = ? FOR UPDATE',
            [$sectorId]
        )->getRowArray();

        if (
            $sector === null
            || ! (bool) $sector['active']
            || (int) $sector['warehouse_id'] !== $warehouseId
            || (int) ($sector['chamber_id'] ?? 0) !== $chamberId
            || ! in_array((string) $sector['location_type'], ['row', 'aisle'], true)
        ) {
            throw new DomainException(
                'La fila/pasillo no pertenece a la cámara seleccionada.',
                'WAREHOUSE_ENTRY_INVALID_SECTOR'
            );
        }

        $stackRows = $this->db->query(
            <<<'SQL'
SELECT
    s.id,
    s.code,
    s.name,
    s.path,
    s.warehouse_id,
    s.chamber_id,
    p.id AS position_id,
    p.code AS position_code,
    p.name AS position_name,
    sector.id AS sector_id,
    sector.code AS sector_code,
    sector.name AS sector_name,
    c.code AS chamber_code,
    c.name AS chamber_name
FROM locations s
INNER JOIN locations p ON p.id = s.parent_id
INNER JOIN locations sector ON sector.id = p.parent_id
INNER JOIN chambers c ON c.id = s.chamber_id
WHERE sector.id = ?
  AND s.warehouse_id = ?
  AND s.chamber_id = ?
  AND s.location_type IN ('stack_level', 'stack')
  AND s.active = 1
  AND p.active = 1
  AND sector.active = 1
ORDER BY p.code DESC, s.code ASC, s.id ASC
FOR UPDATE
SQL,
            [$sectorId, $warehouseId, $chamberId]
        )->getResultArray();

        if ($stackRows === []) {
            throw new DomainException(
                'La fila/pasillo seleccionado no tiene estibas activas.',
                'WAREHOUSE_ENTRY_SECTOR_WITHOUT_STACKS'
            );
        }

        $stackIds = array_map('intval', array_column($stackRows, 'id'));
        $occupied = $this->occupiedStackSet($stackIds);
        $blocked = $this->restrictedStackSet($stackIds);

        $legacyBuilder = $this->db->table('warehouse_entry_destinations wed')
            ->join('stock_units su', 'su.weighing_id = wed.weighing_id', 'left')
            ->where('wed.sector_location_id', $sectorId)
            ->where('wed.status', 'proposed')
            ->where('su.id', null);

        if ($excludeLegacyDestinationId !== null && $excludeLegacyDestinationId > 0) {
            $legacyBuilder->where('wed.id !=', $excludeLegacyDestinationId);
        }

        $legacyPending = $legacyBuilder->countAllResults();
        $freeRows = [];
        foreach ($stackRows as $row) {
            $stackId = (int) $row['id'];
            if (! isset($occupied[$stackId]) && ! isset($blocked[$stackId])) {
                $freeRows[] = $row;
            }
        }

        if (count($freeRows) - $legacyPending <= 0) {
            throw new DomainException(
                'La fila/pasillo seleccionado ya no tiene capacidad operativa disponible.',
                'WAREHOUSE_ENTRY_SECTOR_FULL'
            );
        }

        $selected = $freeRows[0];
        $selected['id'] = (int) $selected['id'];
        $selected['warehouse_id'] = (int) $selected['warehouse_id'];
        $selected['chamber_id'] = (int) $selected['chamber_id'];
        $selected['position_id'] = (int) $selected['position_id'];
        $selected['sector_id'] = (int) $selected['sector_id'];

        return $selected;
    }

    /**
     * Contexto de una estiba ya ocupada por la propia tina; no valida libertad.
     */
    private function stackContext(int $locationId, int $warehouseId): array
    {
        $row = $this->db->table('locations s')
            ->select(
                's.id, s.warehouse_id, s.chamber_id, s.code, s.name, s.path, s.location_type, ' .
                'p.id AS position_id, p.code AS position_code, p.name AS position_name, ' .
                'sector.id AS sector_id, sector.code AS sector_code, sector.name AS sector_name, ' .
                'c.code AS chamber_code, c.name AS chamber_name'
            )
            ->join('locations p', 'p.id = s.parent_id')
            ->join('locations sector', 'sector.id = p.parent_id')
            ->join('chambers c', 'c.id = s.chamber_id')
            ->where('s.id', $locationId)
            ->where('s.warehouse_id', $warehouseId)
            ->where('s.active', 1)
            ->where('p.active', 1)
            ->where('sector.active', 1)
            ->whereIn('s.location_type', ['stack_level', 'stack'])
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw new DomainException(
                'La existencia no tiene una estiba interna válida.',
                'WAREHOUSE_ENTRY_STOCK_LOCATION_INVALID'
            );
        }

        $row['id'] = (int) $row['id'];
        $row['warehouse_id'] = (int) $row['warehouse_id'];
        $row['chamber_id'] = (int) $row['chamber_id'];
        $row['position_id'] = (int) $row['position_id'];
        $row['sector_id'] = (int) $row['sector_id'];

        return $row;
    }

    private function destinationContext(int $destinationId): array
    {
        $scope = $this->scope();
        $row = $this->db->table('warehouse_entry_destinations wed')
            ->select('wed.*, w.company_id, w.site_id, c.code AS chamber_code, sector.code AS sector_code')
            ->join('warehouses w', 'w.id = wed.warehouse_id')
            ->join('chambers c', 'c.id = wed.chamber_id')
            ->join('locations sector', 'sector.id = wed.sector_location_id')
            ->where('wed.id', $destinationId)
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw new DomainException(
                'Propuesta de ubicación no encontrada.',
                'WAREHOUSE_ENTRY_DESTINATION_NOT_FOUND'
            );
        }

        if (
            (int) $row['company_id'] !== (int) $scope['company_id']
            || (int) $row['site_id'] !== (int) $scope['site_id']
            || (
                $scope['warehouse_id'] !== null
                && (int) $row['warehouse_id'] !== (int) $scope['warehouse_id']
            )
        ) {
            throw new DomainException(
                'La propuesta de ubicación está fuera del alcance actual.',
                'WAREHOUSE_ENTRY_DESTINATION_SCOPE_MISMATCH'
            );
        }

        return $row;
    }

    private function finalStackContext(int $locationId, int $warehouseId): array
    {
        $row = $this->db->table('locations s')
            ->select(
                's.id, s.warehouse_id, s.chamber_id, s.code, s.name, s.location_type, ' .
                'p.id AS position_id, p.code AS position_code, ' .
                'sector.id AS sector_id, sector.code AS sector_code'
            )
            ->join('locations p', 'p.id = s.parent_id')
            ->join('locations sector', 'sector.id = p.parent_id')
            ->where('s.id', $locationId)
            ->where('s.warehouse_id', $warehouseId)
            ->where('s.active', 1)
            ->where('p.active', 1)
            ->where('sector.active', 1)
            ->whereIn('s.location_type', ['stack_level', 'stack'])
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw new DomainException(
                'Selecciona una estiba final activa del frigorífico.',
                'WAREHOUSE_ENTRY_FINAL_STACK_REQUIRED'
            );
        }

        if (
            $this->db->table('location_restrictions')
                ->where(['location_id' => $locationId, 'active' => 1])
                ->countAllResults() > 0
        ) {
            throw new DomainException(
                'La estiba seleccionada está inhabilitada.',
                'WAREHOUSE_ENTRY_FINAL_STACK_BLOCKED'
            );
        }

        if (
            $this->db->table('stock_units')
                ->where('location_id', $locationId)
                ->where('on_hand_weight_kg >', 0)
                ->whereNotIn('status', ['in_transit', 'dispatched', 'exhausted'])
                ->countAllResults() > 0
        ) {
            throw new DomainException(
                'La estiba seleccionada ya está ocupada.',
                'WAREHOUSE_ENTRY_FINAL_STACK_OCCUPIED'
            );
        }

        return $row;
    }

    private function chamber(int $chamberId, array $scope): array
    {
        $row = $this->db->table('chambers c')
            ->select('c.id, c.warehouse_id, c.code, c.name, w.company_id, w.site_id, w.code AS warehouse_code, w.name AS warehouse_name')
            ->join('warehouses w', 'w.id = c.warehouse_id')
            ->where(['c.id' => $chamberId, 'c.active' => 1, 'w.active' => 1])
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw new DomainException('Cámara no encontrada o inactiva.', 'CHAMBER_NOT_FOUND');
        }

        if (
            (int) $row['company_id'] !== (int) $scope['company_id']
            || (int) $row['site_id'] !== (int) $scope['site_id']
            || ($scope['warehouse_id'] !== null && (int) $row['warehouse_id'] !== (int) $scope['warehouse_id'])
        ) {
            throw new DomainException('La cámara está fuera del alcance actual.', 'CHAMBER_SCOPE_MISMATCH');
        }

        return $row;
    }

    private function stackRows(int $chamberId): array
    {
        return $this->db->table('locations s')
            ->select(
                's.id AS stack_id, s.code AS stack_code, s.name AS stack_name, s.path AS stack_path, ' .
                'p.id AS position_id, p.code AS position_code, p.name AS position_name, ' .
                'sector.id AS sector_id, sector.code AS sector_code, sector.name AS sector_name, ' .
                'sector.location_type AS sector_type'
            )
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
    }

    private function occupiedStackSet(array $stackIds): array
    {
        if ($stackIds === []) {
            return [];
        }

        $rows = $this->db->table('stock_units')
            ->select('location_id')
            ->whereIn('location_id', $stackIds)
            ->where('on_hand_weight_kg >', 0)
            ->whereNotIn('status', ['in_transit', 'dispatched', 'exhausted'])
            ->groupBy('location_id')
            ->get()
            ->getResultArray();

        return array_fill_keys(array_map('intval', array_column($rows, 'location_id')), true);
    }

    private function restrictedStackSet(array $stackIds): array
    {
        if ($stackIds === []) {
            return [];
        }

        $rows = $this->db->table('location_restrictions')
            ->select('location_id')
            ->whereIn('location_id', $stackIds)
            ->where('active', 1)
            ->groupBy('location_id')
            ->get()
            ->getResultArray();

        return array_fill_keys(array_map('intval', array_column($rows, 'location_id')), true);
    }

    private function participantCapability(string $role): string
    {
        return match (strtolower(trim($role))) {
            'sorter', 'sorteador' => 'can_sort',
            'labeler', 'etiquetador' => 'can_label',
            default => throw new DomainException(
                'Tipo de participante no soportado.',
                'OPERATION_PARTICIPANT_ROLE_INVALID'
            ),
        };
    }

    private function allowedWarehouses(array $scope): array
    {
        $builder = $this->db->table('warehouses')
            ->select('id, company_id, site_id, code, name')
            ->where([
                'company_id' => (int) $scope['company_id'],
                'site_id' => (int) $scope['site_id'],
                'active' => 1,
            ])
            ->orderBy('name');

        if ($scope['warehouse_id'] !== null) {
            $builder->where('id', (int) $scope['warehouse_id']);
        }

        return $builder->get()->getResultArray();
    }

    private function scope(): array
    {
        $scope = service('scopeContext')->restrictions();

        if (
            ! ($scope['configured'] ?? false)
            || $scope['company_id'] === null
            || $scope['site_id'] === null
        ) {
            throw new DomainException(
                'Selecciona empresa e instalación antes de operar.',
                'OPERATIONAL_SCOPE_REQUIRED'
            );
        }

        return $scope;
    }

    private function assertOperatorPermissions(): void
    {
        $user = auth()->user();
        foreach (['discharge.operate', 'weighings.create', 'labels.print'] as $permission) {
            if (! $user->can($permission)) {
                throw new DomainException(
                    'El usuario no cuenta con todos los permisos de Entrada a Frigorífico.',
                    'WAREHOUSE_ENTRY_PERMISSION_DENIED',
                    ['permission' => $permission]
                );
            }
        }
    }
}
