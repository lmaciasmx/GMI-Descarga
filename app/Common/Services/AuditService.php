<?php

namespace App\Common\Services;

use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class AuditService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function record(
        string $action,
        string $aggregateType,
        string|int $aggregateId,
        int $actorUserId,
        ?int $companyId = null,
        ?int $siteId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $correlationId = null,
    ): void {
        $request = service('request');
        $apiClient = service('apiClientContext')->client();
        $actorType = $apiClient !== null ? 'api_client' : ($actorUserId === 0 ? 'service' : 'user');
        $actorReference = $apiClient === null ? null : (string) $apiClient['client_id'];
        $this->db->table('audit_logs')->insert([
            'uuid' => Uuid::uuid7()->toString(),
            'actor_user_id' => $actorUserId === 0 ? null : $actorUserId,
            'actor_type' => $actorType,
            'actor_reference' => $actorReference,
            'action' => $action,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'company_id' => $companyId,
            'site_id' => $siteId,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'ip_address' => method_exists($request, 'getIPAddress') ? $request->getIPAddress() : null,
            'user_agent' => method_exists($request, 'getUserAgent') ? mb_substr((string) $request->getUserAgent(), 0, 255) : null,
            'correlation_id' => $correlationId,
            'occurred_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $channel = match (true) {
            str_starts_with($action, 'inventory.'), str_starts_with($action, 'inventory_count.') => 'inventory',
            str_starts_with($action, 'label.') => 'printing',
            str_starts_with($action, 'scale.') => 'scales',
            str_starts_with($action, 'integration.') => 'integrations',
            default => 'audit',
        };
        service('operationalLogger')->write($channel, 'info', $action, [
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'actor_type' => $actorType,
            'actor_reference' => $actorReference,
            'actor_user_id' => $actorUserId === 0 ? null : $actorUserId,
            'company_id' => $companyId,
            'site_id' => $siteId,
            'correlation_id' => $correlationId,
        ]);
    }
}
