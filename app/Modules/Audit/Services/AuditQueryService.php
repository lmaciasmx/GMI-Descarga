<?php

namespace App\Modules\Audit\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final class AuditQueryService
{
    private const MAX_ROWS = 1000;

    private array $locationLabelCache = [];

    private const ACTION_LABELS = [
        'catalog.created' => 'Catálogo creado',
        'catalog.updated' => 'Catálogo actualizado',
        'catalog.deactivated' => 'Catálogo desactivado',
        'request.created' => 'Solicitud creada',
        'request.accepted' => 'Solicitud aceptada',
        'request.rejected' => 'Solicitud rechazada',
        'request.closed' => 'Solicitud cerrada',
        'transfer.created' => 'Traspaso creado',
        'transfer.shipped' => 'Traspaso enviado',
        'transfer.received' => 'Traspaso recibido',
        'sale.created' => 'Venta creada',
        'sale.dispatched' => 'Venta despachada',
        'inventory.received' => 'Inventario recibido',
        'inventory.relocated' => 'Inventario reubicado',
        'inventory.reversed' => 'Movimiento revertido',
        'label.printed' => 'Etiqueta impresa',
        'label.reprinted' => 'Etiqueta reimpresa',
        'quality.inspected' => 'Inspección de calidad',
        'quality.annulled' => 'Inspección anulada',
        'scale.reading_captured' => 'Lectura de báscula capturada',
        'warehouse_entry_destination.placed' => 'Ubicación confirmada en frigorífico',
        'warehouse_entry_destination.proposed' => 'Ubicación propuesta en frigorífico',
        'warehouse_entry_destination.canceled' => 'Ubicación cancelada',
        'user.access_updated' => 'Permisos de usuario actualizados',
        'user.identity_updated' => 'Datos de usuario actualizados',
    ];

    private const AGGREGATE_LABELS = [
        'warehouse_request' => 'Solicitud de almacén',
        'warehouse_request_unit' => 'Tina de solicitud',
        'transfer' => 'Traspaso',
        'sale' => 'Venta',
        'sale_order' => 'Venta',
        'sale_dispatch' => 'Despacho de venta',
        'stock_unit' => 'Tina / inventario',
        'inventory_movement' => 'Movimiento de inventario',
        'reception' => 'Recepción',
        'discharge_plan' => 'Plan de descarga',
        'voyage' => 'Marea',
        'weighing' => 'Pesaje',
        'scale_reading' => 'Lectura de báscula',
        'label' => 'Etiqueta',
        'quality_inspection' => 'Inspección de calidad',
        'user' => 'Usuario',
        'configuration' => 'Configuración',
        'catalog' => 'Catálogo',
        'warehouse' => 'Almacén',
        'location' => 'Ubicación',
        'tub' => 'Tina',
        'warehouse_entry_destination' => 'Entrada a frigorífico',
    ];

    private const FIELD_LABELS = [
        'status' => 'Estado',
        'active' => 'Activo',
        'weight_kg' => 'Peso (kg)',
        'gross_weight_kg' => 'Peso bruto (kg)',
        'net_weight_kg' => 'Peso neto (kg)',
        'tare_kg' => 'Tara (kg)',
        'accepted_weight_kg' => 'Peso aceptado (kg)',
        'warehouse_id' => 'Almacén',
        'source_warehouse_id' => 'Almacén origen',
        'destination_warehouse_id' => 'Almacén destino',
        'location_id' => 'Ubicación',
        'current_location_id' => 'Ubicación actual',
        'owner_id' => 'Propietario',
        'species_id' => 'Especie',
        'size_id' => 'Talla',
        'lot_id' => 'Lote',
        'lot_code' => 'Lote',
        'folio' => 'Folio',
        'reason' => 'Motivo',
        'observations' => 'Observaciones',
        'priority' => 'Prioridad',
        'request_type' => 'Tipo de solicitud',
        'movement_type' => 'Tipo de movimiento',
        'received_at' => 'Fecha de recepción',
        'closed_at' => 'Fecha de cierre',
        'updated_at' => 'Actualizado',
        'created_at' => 'Creado',
        'final_location_id' => 'Ubicación final',
        'sector_location_id' => 'Fila / sector',
        'chamber_id' => 'Cámara',
        'legacy_reception' => 'Recepción heredada',
        'changed' => 'Cambio detectado',
        'permissions' => 'Permisos',
        'password_changed' => 'Contraseña',
    ];

    private const SENSITIVE_FRAGMENTS = [
        'password',
        'passwd',
        'pass',
        'secret',
        'token',
        'authorization',
        'credential',
        'private_key',
        'api_key',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function normalizeFilters(array $input): array
    {
        $from = trim((string) ($input['from'] ?? ''));
        $to = trim((string) ($input['to'] ?? ''));

        return [
            'from' => $this->validDate($from) ? $from : '',
            'to' => $this->validDate($to) ? $to : '',
            'actor' => mb_substr(trim((string) ($input['actor'] ?? '')), 0, 120),
            'aggregate' => mb_substr(trim((string) ($input['aggregate'] ?? '')), 0, 80),
            'action' => mb_substr(trim((string) ($input['action'] ?? '')), 0, 80),
            'q' => mb_substr(trim((string) ($input['q'] ?? '')), 0, 120),
        ];
    }

    public function dashboard(array $scope, array $filters, bool $canSeeSecurity): array
    {
        $logs = $this->queryRows($scope, $filters, self::MAX_ROWS);

        return [
            'logs' => array_map(fn (array $row): array => $this->presentRow($row), $logs),
            'kpis' => $this->kpis($scope, $filters, $canSeeSecurity),
            'facets' => $this->facets($scope),
            'securityEvents' => $canSeeSecurity ? $this->securityEvents(300) : [],
            'canSeeSecurity' => $canSeeSecurity,
            'maxRows' => self::MAX_ROWS,
        ];
    }

    private function queryRows(array $scope, array $filters, int $limit): array
    {
        $builder = $this->baseBuilder($scope, $filters)
            ->select('al.*')
            ->orderBy('al.occurred_at', 'DESC')
            ->limit($limit);

        return $builder->get()->getResultArray();
    }

    private function kpis(array $scope, array $filters, bool $canSeeSecurity): array
    {
        $total = $this->baseBuilder($scope, $filters)->countAllResults();

        $actorsBuilder = $this->baseBuilder($scope, $filters);
        $actorRow = $actorsBuilder
            ->select(
                "COUNT(DISTINCT COALESCE(NULLIF(al.actor_reference, ''), CAST(al.actor_user_id AS CHAR), al.actor_type)) AS total",
                false
            )
            ->get()
            ->getRowArray();
        $actors = (int) ($actorRow['total'] ?? 0);

        $changesBuilder = $this->baseBuilder($scope, $filters)
            ->groupStart()
                ->where('al.before_data IS NOT NULL', null, false)
                ->orWhere('al.after_data IS NOT NULL', null, false)
            ->groupEnd();
        $withChanges = $changesBuilder->countAllResults();

        $sensitive = $this->sensitiveBuilder($scope, $filters)->countAllResults();

        $security = 0;
        if ($canSeeSecurity && $this->db->tableExists('security_events')) {
            $security = $this->db->table('security_events')->countAllResults();
        }

        return [
            ['label' => 'Eventos', 'value' => $total, 'icon' => 'bi-journal-text'],
            ['label' => 'Actores', 'value' => $actors, 'icon' => 'bi-people'],
            ['label' => 'Con antes / después', 'value' => $withChanges, 'icon' => 'bi-arrow-left-right'],
            ['label' => 'Sensibles', 'value' => $sensitive, 'icon' => 'bi-exclamation-triangle'],
            ['label' => 'Seguridad', 'value' => $security, 'icon' => 'bi-shield-lock'],
        ];
    }

    private function facets(array $scope): array
    {
        $scopeBuilder = $this->scopeOnlyBuilder($scope);

        $actions = (clone $scopeBuilder)
            ->select('al.action')
            ->distinct()
            ->orderBy('al.action')
            ->limit(250)
            ->get()
            ->getResultArray();

        $aggregates = (clone $scopeBuilder)
            ->select('al.aggregate_type')
            ->distinct()
            ->orderBy('al.aggregate_type')
            ->limit(250)
            ->get()
            ->getResultArray();

        $actors = (clone $scopeBuilder)
            ->select('al.actor_type, al.actor_user_id, al.actor_reference')
            ->groupBy(['al.actor_type', 'al.actor_user_id', 'al.actor_reference'])
            ->orderBy('al.actor_reference')
            ->limit(250)
            ->get()
            ->getResultArray();

        return [
            'actions' => array_map(fn (array $row): array => [
                'value' => (string) $row['action'],
                'label' => $this->actionLabel((string) $row['action'], ''),
            ], $actions),
            'aggregates' => array_map(fn (array $row): array => [
                'value' => (string) $row['aggregate_type'],
                'label' => $this->aggregateLabel((string) $row['aggregate_type']),
            ], $aggregates),
            'actors' => array_map(fn (array $row): array => [
                'value' => $this->actorFilterValue($row),
                'label' => $this->actorLabel($row),
            ], $actors),
        ];
    }

    private function securityEvents(int $limit): array
    {
        if (! $this->db->tableExists('security_events')) {
            return [];
        }

        $rows = $this->db->table('security_events')
            ->orderBy('occurred_at', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return array_map(function (array $row): array {
            $row['event_label'] = $this->humanize((string) ($row['event_type'] ?? 'Evento'));
            $row['severity_label'] = match (strtolower((string) ($row['severity'] ?? ''))) {
                'critical' => 'Crítico',
                'warning' => 'Advertencia',
                'info' => 'Informativo',
                default => $this->humanize((string) ($row['severity'] ?? '')),
            };
            $row['outcome_label'] = match (strtolower((string) ($row['outcome'] ?? ''))) {
                'success', 'allowed' => 'Correcto',
                'failure', 'failed', 'denied' => 'Falló',
                default => $this->humanize((string) ($row['outcome'] ?? '')),
            };

            return $row;
        }, $rows);
    }

    private function presentRow(array $row): array
    {
        $row['date_label'] = $this->formatDate((string) ($row['occurred_at'] ?? ''));
        $row['actor_label'] = $this->actorLabel($row);
        $row['action_label'] = $this->actionLabel(
            (string) ($row['action'] ?? ''),
            (string) ($row['aggregate_type'] ?? '')
        );
        $row['aggregate_label'] = $this->aggregateLabel((string) ($row['aggregate_type'] ?? ''));
        $row['reference_label'] = $row['aggregate_label'] . ' #' . (string) ($row['aggregate_id'] ?? '—');
        $row['changes'] = $this->changes(
            $this->decodeJson($row['before_data'] ?? null),
            $this->decodeJson($row['after_data'] ?? null)
        );
        $row['change_summary'] = $this->changeSummary($row['changes']);
        $row['before_present'] = $row['before_data'] !== null;
        $row['after_present'] = $row['after_data'] !== null;

        return $row;
    }

    private function baseBuilder(array $scope, array $filters): BaseBuilder
    {
        $builder = $this->scopeOnlyBuilder($scope);

        if ($filters['from'] !== '') {
            $builder->where('al.occurred_at >=', $filters['from'] . ' 00:00:00');
        }

        if ($filters['to'] !== '') {
            $builder->where('al.occurred_at <=', $filters['to'] . ' 23:59:59');
        }

        if ($filters['aggregate'] !== '') {
            $builder->where('al.aggregate_type', $filters['aggregate']);
        }

        if ($filters['action'] !== '') {
            $builder->where('al.action', $filters['action']);
        }

        if ($filters['actor'] !== '') {
            if (str_starts_with($filters['actor'], 'id:')) {
                $builder->where('al.actor_user_id', (int) substr($filters['actor'], 3));
            } elseif (str_starts_with($filters['actor'], 'ref:')) {
                $builder->where('al.actor_reference', substr($filters['actor'], 4));
            } elseif (str_starts_with($filters['actor'], 'type:')) {
                $builder->where('al.actor_type', substr($filters['actor'], 5));
            }
        }

        if ($filters['q'] !== '') {
            $q = $filters['q'];
            $builder->groupStart()
                ->like('al.action', $q)
                ->orLike('al.aggregate_type', $q)
                ->orLike('al.aggregate_id', $q)
                ->orLike('al.actor_reference', $q)
                ->orLike('al.reason', $q)
                ->orLike('al.correlation_id', $q)
                ->orLike('al.ip_address', $q)
            ->groupEnd();
        }

        return $builder;
    }

    private function scopeOnlyBuilder(array $scope): BaseBuilder
    {
        $builder = $this->db->table('audit_logs al');

        if (isset($scope['company_id'])) {
            $builder->groupStart()
                ->where('al.company_id', (int) $scope['company_id'])
                ->orWhere('al.company_id', null)
            ->groupEnd();
        }

        if (isset($scope['site_id'])) {
            $builder->groupStart()
                ->where('al.site_id', (int) $scope['site_id'])
                ->orWhere('al.site_id', null)
            ->groupEnd();
        }

        return $builder;
    }

    private function sensitiveBuilder(array $scope, array $filters): BaseBuilder
    {
        $builder = $this->baseBuilder($scope, $filters);

        return $builder
            ->groupStart()
                ->like('al.action', 'cancel')
                ->orLike('al.action', 'annul')
                ->orLike('al.action', 'reverse')
                ->orLike('al.action', 'reject')
                ->orLike('al.action', 'deactiv')
                ->orLike('al.action', 'delete')
            ->groupEnd();
    }

    private function changes(?array $before, ?array $after): array
    {
        $beforeFlat = $this->flatten($before ?? []);
        $afterFlat = $this->flatten($after ?? []);
        $keys = array_values(array_unique(array_merge(array_keys($beforeFlat), array_keys($afterFlat))));
        sort($keys);

        $changes = [];

        foreach ($keys as $key) {
            $beforeValue = $beforeFlat[$key] ?? null;
            $afterValue = $afterFlat[$key] ?? null;

            if ($this->sameValue($beforeValue, $afterValue)) {
                continue;
            }

            $changes[] = [
                'field' => $key,
                'label' => $this->fieldLabel($key),
                'before' => $this->displayValue($key, $beforeValue),
                'after' => $this->displayValue($key, $afterValue),
            ];

            if (count($changes) >= 60) {
                break;
            }
        }

        return $changes;
    }

    private function changeSummary(array $changes): string
    {
        if ($changes === []) {
            return 'Sin cambios visibles';
        }

        $preferred = ['status', 'final_location_id', 'location_id', 'current_location_id', 'permissions', 'password_changed'];

        $selected = null;
        foreach ($preferred as $needle) {
            foreach ($changes as $change) {
                if (($change['field'] ?? '') === $needle || str_ends_with((string) ($change['field'] ?? ''), '.' . $needle)) {
                    $selected = $change;
                    break 2;
                }
            }
        }

        $selected ??= $changes[0];

        if (($selected['field'] ?? '') === 'permissions') {
            return 'Permisos de acceso modificados'
                . (count($changes) > 1 ? ' · +' . (count($changes) - 1) . ' cambio(s)' : '');
        }

        if (($selected['field'] ?? '') === 'password_changed') {
            return 'Contraseña actualizada'
                . (count($changes) > 1 ? ' · +' . (count($changes) - 1) . ' cambio(s)' : '');
        }

        $summary = $selected['label'] . ': ' . $this->shortValue($selected['before']) . ' → ' . $this->shortValue($selected['after']);

        if (count($changes) > 1) {
            $summary .= ' · +' . (count($changes) - 1) . ' cambio(s)';
        }

        return $summary;
    }

    private function shortValue(string $value): string
    {
        if (mb_strlen($value) <= 55) {
            return $value;
        }

        return mb_substr($value, 0, 52) . '…';
    }

    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && ! array_is_list($value)) {
                $flat += $this->flatten($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    private function displayValue(string $key, mixed $value): string
    {
        if ($this->isSensitive($key)) {
            return '[PROTEGIDO]';
        }

        if ($value === null) {
            return '—';
        }

        $leaf = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

        if (
            in_array($leaf, ['location_id', 'current_location_id', 'final_location_id', 'sector_location_id'], true)
            && is_numeric($value)
        ) {
            return $this->locationLabel((int) $value);
        }

        if ($leaf === 'permissions' && is_array($value)) {
            $count = count($value);
            return $count === 1 ? '1 permiso' : $count . ' permisos';
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $items = array_map(static fn ($item): string => is_scalar($item) ? (string) $item : '[detalle]', $value);
                $preview = implode(', ', array_slice($items, 0, 4));
                return count($items) > 4 ? $preview . ' · +' . (count($items) - 4) . ' más' : $preview;
            }

            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return mb_substr((string) $json, 0, 180) . (mb_strlen((string) $json) > 180 ? '…' : '');
        }

        if (is_float($value)) {
            return number_format($value, 3);
        }

        $text = trim((string) $value);

        if ($text === '') {
            return '—';
        }

        $lower = strtolower($text);
        $translations = [
            'draft' => 'Borrador',
            'pending' => 'Pendiente',
            'accepted' => 'Aceptado',
            'rejected' => 'Rechazado',
            'closed' => 'Cerrado',
            'cancelled' => 'Cancelado',
            'canceled' => 'Cancelado',
            'confirmed' => 'Confirmado',
            'received' => 'Recibido',
            'available' => 'Disponible',
            'reserved' => 'Reservado',
            'in_transit' => 'En tránsito',
            'dispatched' => 'Despachado',
            'completed' => 'Completado',
            'active' => 'Activo',
            'inactive' => 'Inactivo',
            'proposed' => 'Propuesta',
            'placed' => 'Ubicada',
            'changed' => 'Sí',
            'unchanged' => 'No',
        ];

        return $translations[$lower] ?? mb_substr($text, 0, 180);
    }

    private function locationLabel(int $locationId): string
    {
        if ($locationId <= 0) {
            return '—';
        }

        if (array_key_exists($locationId, $this->locationLabelCache)) {
            return $this->locationLabelCache[$locationId];
        }

        try {
            $row = $this->db->table('locations l')
                ->select('l.id, l.code, l.name, l.path, c.code AS chamber_code, c.name AS chamber_name')
                ->join('chambers c', 'c.id = l.chamber_id', 'left')
                ->where('l.id', $locationId)
                ->get()
                ->getRowArray();

            if ($row === null) {
                return $this->locationLabelCache[$locationId] = 'Ubicación #' . $locationId;
            }

            $path = trim((string) ($row['path'] ?? ''));
            $parts = array_values(array_filter(
                array_map('trim', explode('/', trim($path, '/'))),
                static fn (string $part): bool => $part !== ''
            ));

            $camera = trim((string) ($row['chamber_code'] ?? ''));
            if ($camera === '') {
                foreach ($parts as $part) {
                    if (preg_match('/^(CAM|CAMARA|CÁMARA)[-_ ]?\\d+/iu', $part)) {
                        $camera = $part;
                        break;
                    }
                }
            }

            $fila = '';
            foreach ($parts as $part) {
                if (preg_match('/^(F|FILA)[-_ ]?\\d+/iu', $part)) {
                    $fila = $part;
                    break;
                }
            }

            if ($fila === '') {
                $code = trim((string) ($row['code'] ?? ''));
                if (preg_match('/^(F|FILA)[-_ ]?\\d+/iu', $code)) {
                    $fila = $code;
                }
            }

            $compact = implode(' / ', array_values(array_filter([$camera, $fila])));
            if ($compact !== '') {
                return $this->locationLabelCache[$locationId] = $compact;
            }

            $name = trim((string) ($row['name'] ?? ''));
            return $this->locationLabelCache[$locationId] = ($name !== '' ? $name : 'Ubicación #' . $locationId);
        } catch (\Throwable) {
            return $this->locationLabelCache[$locationId] = 'Ubicación #' . $locationId;
        }
    }

    private function actionLabel(string $action, string $aggregate): string
    {
        if (isset(self::ACTION_LABELS[$action])) {
            return self::ACTION_LABELS[$action];
        }

        $verb = str_contains($action, '.') ? substr($action, strrpos($action, '.') + 1) : $action;
        $verbLabel = match (strtolower($verb)) {
            'created', 'create' => 'Creación',
            'updated', 'update' => 'Actualización',
            'accepted', 'accept' => 'Aceptación',
            'rejected', 'reject' => 'Rechazo',
            'closed', 'close' => 'Cierre',
            'cancelled', 'canceled', 'cancel' => 'Cancelación',
            'annulled', 'annul' => 'Anulación',
            'reversed', 'reverse' => 'Reverso',
            'received', 'receive' => 'Recepción',
            'shipped', 'ship' => 'Salida',
            'dispatched', 'dispatch' => 'Despacho',
            'printed', 'print' => 'Impresión',
            'reprinted', 'reprint' => 'Reimpresión',
            'deleted', 'delete' => 'Eliminación',
            'deactivated', 'deactivate' => 'Desactivación',
            default => $this->humanize($verb),
        };

        if ($aggregate !== '') {
            return $this->aggregateLabel($aggregate) . ' · ' . $verbLabel;
        }

        $prefix = str_contains($action, '.') ? substr($action, 0, strrpos($action, '.')) : '';
        return ($prefix !== '' ? $this->humanize($prefix) . ' · ' : '') . $verbLabel;
    }

    private function aggregateLabel(string $aggregate): string
    {
        return self::AGGREGATE_LABELS[$aggregate] ?? $this->humanize($aggregate);
    }

    private function fieldLabel(string $field): string
    {
        $leaf = str_contains($field, '.') ? substr($field, strrpos($field, '.') + 1) : $field;
        return self::FIELD_LABELS[$leaf] ?? $this->humanize($field);
    }

    private function actorLabel(array $row): string
    {
        $reference = trim((string) ($row['actor_reference'] ?? ''));
        if ($reference !== '') {
            return $reference;
        }

        if (! empty($row['actor_user_id'])) {
            return 'Usuario #' . (int) $row['actor_user_id'];
        }

        $type = trim((string) ($row['actor_type'] ?? ''));
        return $type !== '' ? $this->humanize($type) : 'Servicio';
    }

    private function actorFilterValue(array $row): string
    {
        $reference = trim((string) ($row['actor_reference'] ?? ''));
        if ($reference !== '') {
            return 'ref:' . $reference;
        }

        if (! empty($row['actor_user_id'])) {
            return 'id:' . (int) $row['actor_user_id'];
        }

        return 'type:' . (string) ($row['actor_type'] ?? 'service');
    }

    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function sameValue(mixed $a, mixed $b): bool
    {
        return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            === json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isSensitive(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function formatDate(string $value): string
    {
        if ($value === '') {
            return '—';
        }

        try {
            return (new \DateTimeImmutable($value))->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function humanize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '—';
        }

        $value = str_replace(['_', '-', '.'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?: $value;

        return ucfirst(mb_strtolower($value));
    }

    private function validDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
