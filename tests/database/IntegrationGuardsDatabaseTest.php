<?php

namespace Tests\Database;

use App\Database\Seeds\CoreCatalogSeeder;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Ramsey\Uuid\Uuid;

final class IntegrationGuardsDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $migrate = true;
    protected $refresh = true;
    protected $seed = CoreCatalogSeeder::class;

    public function testScaleReadingFingerprintRejectsDuplicateDeviceMessage(): void
    {
        $scaleId = (int) $this->db->table('scales')->get()->getRow('id');
        $reading = [
            'uuid' => Uuid::uuid7()->toString(), 'scale_id' => $scaleId, 'weight' => 900,
            'unit' => 'kg', 'stable' => 1, 'device_timestamp' => '2026-09-13 08:00:00',
            'received_at' => '2026-09-13 08:00:01', 'original_value' => 'ST,GS,900.000kg',
            'original_hash' => hash('sha256', 'ST,GS,900.000kg'), 'connection_status' => 'connected',
            'source' => 'simulator', 'device_identifier' => 'SIMULATOR-TEST',
        ];
        $this->db->table('scale_readings')->insert($reading);
        $reading['uuid'] = Uuid::uuid7()->toString();
        $this->expectException(DatabaseException::class);
        $this->db->table('scale_readings')->insert($reading);
    }

    public function testIdempotencyRequestHashIsUnique(): void
    {
        $row = [
            'request_hash' => hash('sha256', 'POST|/api/v1/weighings|same-key'), 'idempotency_key' => 'same-key',
            'owner_key' => 'client:test', 'payload_hash' => hash('sha256', '{"gross":900}'),
            'method' => 'POST', 'path' => '/api/v1/weighings', 'status' => 'processing',
            'expires_at' => '2026-09-14 08:00:00', 'created_at' => '2026-09-13 08:00:00',
        ];
        $this->db->table('api_idempotency_keys')->insert($row);
        $this->expectException(DatabaseException::class);
        $this->db->table('api_idempotency_keys')->insert($row);
    }
}
