<?php

namespace App\Modules\Maritime\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use CodeIgniter\Database\BaseConnection;

final class RsaService
{
    public function __construct(private readonly BaseConnection $db, private readonly AuditService $audit)
    {
    }

    public function create(array $payload, int $actorUserId): array
    {
        $issuedOn = empty($payload['issued_on']) ? null : (string) $payload['issued_on'];
        $expiresOn = empty($payload['expires_on']) ? null : (string) $payload['expires_on'];
        if ($issuedOn !== null && $expiresOn !== null && $expiresOn < $issuedOn) {
            throw new DomainException('La vigencia del RSA no puede terminar antes de su emisión.', 'INVALID_RSA_VALIDITY');
        }
        $this->db->transException(true)->transBegin();
        try {
            $voyage = $this->db->query('SELECT * FROM voyages WHERE id = ? FOR UPDATE', [(int) $payload['voyage_id']])->getRowArray();
            if ($voyage === null || ! in_array($voyage['status'], ['planned', 'unloading'], true)) {
                throw new DomainException('La marea no admite un RSA nuevo.', 'VOYAGE_NOT_AVAILABLE_FOR_RSA');
            }
            $code = strtoupper(trim((string) $payload['code']));
            if ($this->db->table('rsa_records')->where('code', $code)->countAllResults() > 0) {
                throw new DomainException('El código RSA ya está registrado.', 'DUPLICATE_RSA');
            }
            $now = date('Y-m-d H:i:s');
            $this->db->table('rsa_records')->insert([
                'voyage_id' => (int) $voyage['id'],
                'code' => $code,
                'issued_on' => $issuedOn,
                'expires_on' => $expiresOn,
                'status' => 'active',
                'created_by' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->audit->record('rsa.created', 'rsa', $id, $actorUserId, (int) $voyage['company_id'], (int) $voyage['site_id'], null, ['code' => $code, 'voyage_id' => $voyage['id'], 'expires_on' => $expiresOn]);
            $this->db->transCommit();
            return ['id' => $id, 'code' => $code, 'status' => 'active'];
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
