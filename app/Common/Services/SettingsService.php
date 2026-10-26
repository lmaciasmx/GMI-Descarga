<?php

namespace App\Common\Services;

use App\Common\Exceptions\DomainException;
use CodeIgniter\Database\BaseConnection;

final class SettingsService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function get(string $key, ?int $companyId = null, ?int $siteId = null, mixed $default = null): mixed
    {
        $builder = $this->db->table('system_settings')->where('setting_key', $key);

        if ($companyId !== null) {
            $builder->groupStart()->where('company_id', $companyId)->orWhere('company_id', null)->groupEnd();
        } else {
            $builder->where('company_id', null);
        }

        if ($siteId !== null) {
            $builder->groupStart()->where('site_id', $siteId)->orWhere('site_id', null)->groupEnd();
        } else {
            $builder->where('site_id', null);
        }

        $row = $builder
            ->orderBy('site_id IS NULL', 'ASC', false)
            ->orderBy('company_id IS NULL', 'ASC', false)
            ->limit(1)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return $default;
        }

        return $this->cast((string) $row['setting_value'], (string) $row['value_type']);
    }

    public function listEditable(int $companyId, int $siteId): array
    {
        service('scopeContext')->assertCompanySite($companyId, $siteId);
        $rows = $this->db->table('system_settings')
            ->where(['company_id' => $companyId, 'site_id' => $siteId])
            ->orderBy('setting_key')->get()->getResultArray();
        foreach ($rows as &$row) {
            if ((int) $row['is_sensitive'] === 1) {
                $row['setting_value'] = '[VALOR PROTEGIDO]';
            }
        }
        return $rows;
    }

    public function update(int $id, string $value, int $actorUserId): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $row = $this->db->query('SELECT * FROM system_settings WHERE id = ? FOR UPDATE', [$id])->getRowArray();
            if ($row === null) {
                throw new DomainException('Configuración no encontrada.', 'SETTING_NOT_FOUND');
            }
            service('scopeContext')->assertCompanySite(
                $row['company_id'] === null ? null : (int) $row['company_id'],
                $row['site_id'] === null ? null : (int) $row['site_id'],
            );
            if ((int) $row['is_sensitive'] === 1) {
                throw new DomainException('Los secretos se administran exclusivamente mediante variables de entorno o un gestor de secretos.', 'SENSITIVE_SETTING_PROTECTED');
            }
            $normalized = $this->normalize($value, (string) $row['value_type']);
            $this->db->table('system_settings')->where('id', $id)->update([
                'setting_value' => $normalized,
                'updated_by' => $actorUserId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            service('auditService')->record('setting.updated', 'system_setting', $id, $actorUserId, $row['company_id'] === null ? null : (int) $row['company_id'], $row['site_id'] === null ? null : (int) $row['site_id'], ['setting_value' => $row['setting_value']], ['setting_value' => $normalized]);
            $this->db->transCommit();
            return ['id' => $id, 'setting_key' => $row['setting_key'], 'status' => 'updated'];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function normalize(string $value, string $type): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new DomainException('El valor no puede quedar vacío.', 'SETTING_VALUE_REQUIRED');
        }
        return match ($type) {
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? (string) (int) $value
                : throw new DomainException('El valor debe ser entero.', 'INVALID_SETTING_VALUE'),
            'decimal', 'float' => is_numeric($value)
                ? (string) (float) $value
                : throw new DomainException('El valor debe ser numérico.', 'INVALID_SETTING_VALUE'),
            'boolean' => in_array(strtolower($value), ['true', 'false', '1', '0'], true)
                ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false')
                : throw new DomainException('El valor debe ser true o false.', 'INVALID_SETTING_VALUE'),
            'json' => $this->normalizeJson($value),
            default => mb_substr($value, 0, 5000),
        };
    }

    private function normalizeJson(string $value): string
    {
        try {
            return json_encode(json_decode($value, true, 512, JSON_THROW_ON_ERROR), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DomainException('El valor debe ser JSON válido.', 'INVALID_SETTING_VALUE', [], 0, $exception);
        }
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'decimal', 'float' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }
}
