<?php

namespace App\Modules\Billing\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use CodeIgniter\Database\BaseConnection;

final class BillingReferenceService
{
    public function __construct(private readonly BaseConnection $db, private readonly AuditService $audit)
    {
    }

    public function record(array $payload, int $actorUserId): array
    {
        $externalSystem = trim((string) ($payload['external_system'] ?? 'INVoiice'));
        $externalId = trim((string) $payload['external_document_id']);
        if ($externalId === '') {
            throw new DomainException('La referencia fiscal externa es obligatoria.', 'BILLING_REFERENCE_REQUIRED');
        }
        $this->db->transException(true)->transBegin();
        try {
            $companyId = (int) $payload['company_id'];
            $siteId = (int) $payload['site_id'];
            if ($this->db->table('sites')->where(['id' => $siteId, 'company_id' => $companyId, 'active' => 1])->countAllResults() !== 1) {
                throw new DomainException('La instalación fiscal no pertenece a la empresa.', 'SITE_COMPANY_MISMATCH');
            }
            $salesOrderId = empty($payload['sales_order_id']) ? null : (int) $payload['sales_order_id'];
            if ($salesOrderId !== null) {
                $salesOrder = $this->db->table('sales_orders')->where('id', $salesOrderId)->get()->getRowArray();
                if ($salesOrder === null) {
                    throw new DomainException('La orden física asociada no existe.', 'SALES_ORDER_NOT_FOUND');
                }
                if ((int) $salesOrder['company_id'] !== $companyId || (int) $salesOrder['site_id'] !== $siteId) {
                    throw new DomainException('La orden física está fuera del alcance fiscal seleccionado.', 'BILLING_SCOPE_MISMATCH');
                }
            }
            $existing = $this->db->query('SELECT * FROM billing_references WHERE external_system = ? AND external_document_id = ? FOR UPDATE', [$externalSystem, $externalId])->getRowArray();
            $now = date('Y-m-d H:i:s');
            if ($existing !== null) {
                if ((int) $existing['company_id'] !== $companyId || (int) $existing['site_id'] !== $siteId) {
                    throw new DomainException('La referencia fiscal ya pertenece a otro alcance operativo.', 'BILLING_SCOPE_MISMATCH');
                }
                if ((string) $existing['fiscal_status'] === (string) $payload['fiscal_status'] && (string) ($existing['fiscal_uuid'] ?? '') === (string) ($payload['fiscal_uuid'] ?? '')) {
                    $this->db->transCommit();
                    return ['id' => (int) $existing['id'], 'fiscal_status' => $existing['fiscal_status'], 'replayed' => true, 'inventory_changed' => false];
                }
                $this->db->table('billing_references')->where('id', $existing['id'])->update([
                    'sales_order_id' => $salesOrderId ?? $existing['sales_order_id'],
                    'fiscal_uuid' => $payload['fiscal_uuid'] ?? $existing['fiscal_uuid'],
                    'fiscal_status' => (string) $payload['fiscal_status'],
                    'issued_at' => $payload['issued_at'] ?? $existing['issued_at'],
                    'canceled_at' => $payload['canceled_at'] ?? $existing['canceled_at'],
                    'updated_at' => $now,
                ]);
                $this->audit->record('billing.reference_updated', 'billing_reference', (int) $existing['id'], $actorUserId, $companyId, $siteId, ['fiscal_status' => $existing['fiscal_status']], ['fiscal_status' => $payload['fiscal_status'], 'inventory_changed' => false]);
                $this->db->transCommit();
                return ['id' => (int) $existing['id'], 'fiscal_status' => $payload['fiscal_status'], 'replayed' => false, 'inventory_changed' => false];
            }
            $this->db->table('billing_references')->insert([
                'company_id' => $companyId,
                'site_id' => $siteId,
                'sales_order_id' => $salesOrderId,
                'external_system' => $externalSystem,
                'external_document_id' => $externalId,
                'document_type' => (string) $payload['document_type'],
                'fiscal_uuid' => $payload['fiscal_uuid'] ?? null,
                'fiscal_status' => (string) $payload['fiscal_status'],
                'issued_at' => $payload['issued_at'] ?? null,
                'canceled_at' => $payload['canceled_at'] ?? null,
                'payload_fingerprint' => isset($payload['payload']) ? hash('sha256', json_encode($payload['payload'], JSON_THROW_ON_ERROR)) : null,
                'recorded_by' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->audit->record('billing.reference_recorded', 'billing_reference', $id, $actorUserId, $companyId, $siteId, null, ['external_system' => $externalSystem, 'external_document_id' => $externalId, 'fiscal_status' => $payload['fiscal_status'], 'inventory_changed' => false]);
            $this->db->transCommit();
            return ['id' => $id, 'fiscal_status' => $payload['fiscal_status'], 'replayed' => false, 'inventory_changed' => false];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
