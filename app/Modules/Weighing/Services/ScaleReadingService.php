<?php

namespace App\Modules\Weighing\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\AuditService;
use App\Modules\Weighing\DTO\WeightReading;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class ScaleReadingService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function capture(WeightReading $reading, int $scaleId, int $actorUserId): array
    {
        $scale = $this->db->table('scales sc')
            ->select('sc.id, sc.code, sc.site_id, s.company_id')
            ->join('sites s', 's.id = sc.site_id')
            ->where(['sc.id' => $scaleId, 'sc.active' => 1, 's.active' => 1])
            ->get()
            ->getRowArray();

        if ($scale === null) {
            throw new DomainException('La báscula no existe o está inactiva.', 'SCALE_NOT_AVAILABLE');
        }
        if (! is_finite($reading->weight) || $reading->weight <= 0 || strtolower(trim($reading->unit)) !== 'kg') {
            throw new DomainException('La lectura debe contener un peso positivo expresado en kg.', 'INVALID_SCALE_READING');
        }
        if (! $reading->stable) {
            throw new DomainException('La báscula aún no reporta un peso estable.', 'UNSTABLE_WEIGHT');
        }

        $raw = trim($reading->originalValue);
        $deviceIdentifier = trim($reading->scaleIdentifier);
        if ($raw === '' || mb_strlen($raw) > 255) {
            throw new DomainException('El valor original de báscula es obligatorio y admite hasta 255 caracteres.', 'INVALID_SCALE_RAW_VALUE');
        }
        if ($deviceIdentifier === '' || mb_strlen($deviceIdentifier) > 100) {
            throw new DomainException('El identificador del dispositivo es obligatorio y admite hasta 100 caracteres.', 'INVALID_SCALE_IDENTIFIER');
        }

        $source = trim($reading->source);
        $connectionStatus = trim($reading->connectionStatus);
        if ($source === '' || mb_strlen($source) > 30 || $connectionStatus === '' || mb_strlen($connectionStatus) > 30) {
            throw new DomainException('La fuente o el estado de conexión de la lectura no son válidos.', 'INVALID_SCALE_READING');
        }

        $timezone = new \DateTimeZone(config('App')->appTimezone);
        $deviceTimestamp = $reading->capturedAt->setTimezone($timezone)->format('Y-m-d H:i:s');
        $receivedAt = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
        $fingerprint = hash('sha256', $raw);
        $apiClient = service('apiClientContext')->client();
        $actorReference = $actorUserId === 0 && $apiClient !== null ? (string) $apiClient['client_id'] : null;

        $this->db->transException(true)->transBegin();
        try {
            $duplicate = $this->db->table('scale_readings')->where([
                'scale_id' => $scaleId,
                'original_hash' => $fingerprint,
                'device_timestamp' => $deviceTimestamp,
            ])->countAllResults();
            if ($duplicate > 0) {
                throw new DomainException('La lectura de báscula ya fue capturada.', 'DUPLICATE_SCALE_READING');
            }

            $this->db->table('scale_readings')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'scale_id' => $scaleId,
                'weighing_id' => null,
                'weight' => round($reading->weight, 3),
                'unit' => 'kg',
                'stable' => 1,
                'device_timestamp' => $deviceTimestamp,
                'received_at' => $receivedAt,
                'original_value' => $raw,
                'original_hash' => $fingerprint,
                'connection_status' => $connectionStatus,
                'source' => $source,
                'device_identifier' => $deviceIdentifier,
                'actor_user_id' => $actorUserId === 0 ? null : $actorUserId,
                'actor_reference' => $actorReference,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->record(
                'scale.reading_captured',
                'scale_reading',
                $id,
                $actorUserId,
                (int) $scale['company_id'],
                (int) $scale['site_id'],
                null,
                [
                    'scale_id' => $scaleId,
                    'scale_code' => $scale['code'],
                    'weight_kg' => round($reading->weight, 3),
                    'stable' => true,
                    'device_identifier' => $deviceIdentifier,
                    'source' => $source,
                ],
            );
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            if (! $exception instanceof DomainException) {
                $exists = $this->db->table('scale_readings')->where([
                    'scale_id' => $scaleId,
                    'original_hash' => $fingerprint,
                    'device_timestamp' => $deviceTimestamp,
                ])->countAllResults();
                if ($exists > 0) {
                    throw new DomainException('La lectura de báscula ya fue capturada.', 'DUPLICATE_SCALE_READING', [], 0, $exception);
                }
            }
            throw $exception;
        }

        return [
            'id' => $id,
            'received_at' => $receivedAt,
            'weight_source' => $this->publicWeightSource($source),
        ] + $reading->toArray();
    }

    private function publicWeightSource(string $source): string
    {
        if ($source === 'simulator') {
            return 'simulator';
        }
        if ($source === 'middleware' || str_starts_with($source, 'local_middleware:')) {
            return 'middleware';
        }

        return 'scale';
    }
}
