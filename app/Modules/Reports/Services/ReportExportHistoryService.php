<?php

namespace App\Modules\Reports\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;

final class ReportExportHistoryService
{
    private const TABLE = 'report_export_history';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    public function exists(): bool
    {
        try {
            return $this->db->tableExists(self::TABLE);
        } catch (Throwable $e) {
            log_message('error', '[Reports] No fue posible validar historial de exportaciones: {message}', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function begin(
        string $reportKey,
        string $reportTitle,
        string $format,
        array $scope,
        array $filters,
        ?int $userId,
        string $userName
    ): ?int {
        if (! $this->exists()) {
            return null;
        }

        try {
            $payload = [
                'company_id' => (int) ($scope['company_id'] ?? 0),
                'site_id' => isset($scope['site_id']) ? (int) $scope['site_id'] : null,
                'warehouse_id' => isset($scope['warehouse_id']) ? (int) $scope['warehouse_id'] : null,
                'report_key' => $reportKey,
                'report_title' => $reportTitle,
                'format' => strtoupper($format),
                'date_from' => $filters['from'] ?? null,
                'date_to' => $filters['to'] ?? null,
                'filters_json' => json_encode(
                    [
                        'from' => $filters['from'] ?? null,
                        'to' => $filters['to'] ?? null,
                        'company_id' => $scope['company_id'] ?? null,
                        'site_id' => $scope['site_id'] ?? null,
                        'warehouse_id' => $scope['warehouse_id'] ?? null,
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'row_count' => 0,
                'status' => 'processing',
                'error_message' => null,
                'user_id' => $userId,
                'user_name' => mb_substr($userName !== '' ? $userName : 'Usuario', 0, 120),
                'generated_at' => date('Y-m-d H:i:s'),
                'completed_at' => null,
            ];

            $builder = $this->db->table(self::TABLE);
            $builder->insert($payload);

            $id = (int) $this->db->insertID();

            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            log_message('error', '[Reports] No fue posible iniciar historial de exportación: {message}', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function complete(?int $id, int $rowCount): void
    {
        if (! $id) {
            return;
        }

        try {
            $this->db->table(self::TABLE)
                ->where('id', $id)
                ->update([
                    'row_count' => max(0, $rowCount),
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'error_message' => null,
                ]);
        } catch (Throwable $e) {
            log_message('error', '[Reports] No fue posible completar historial de exportación #{id}: {message}', [
                'id' => $id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function fail(?int $id, Throwable $error): void
    {
        if (! $id) {
            return;
        }

        try {
            $this->db->table(self::TABLE)
                ->where('id', $id)
                ->update([
                    'status' => 'error',
                    'error_message' => mb_substr($error->getMessage(), 0, 500),
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (Throwable $e) {
            log_message('error', '[Reports] No fue posible registrar error de exportación #{id}: {message}', [
                'id' => $id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function recent(array $scope, int $limit = 20): array
    {
        if (! $this->exists()) {
            return [];
        }

        try {
            $builder = $this->db->table(self::TABLE)
                ->select(
                    'id, report_key, report_title, format, date_from, date_to, '
                    . 'row_count, status, user_name, generated_at, completed_at'
                )
                ->where('company_id', (int) ($scope['company_id'] ?? 0));

            if (isset($scope['site_id'])) {
                $builder->where('site_id', (int) $scope['site_id']);
            }

            if (isset($scope['warehouse_id'])) {
                $builder->where('warehouse_id', (int) $scope['warehouse_id']);
            }

            return $builder
                ->orderBy('generated_at', 'DESC')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->getResultArray();
        } catch (Throwable $e) {
            log_message('error', '[Reports] No fue posible consultar historial de exportaciones: {message}', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
