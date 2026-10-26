<?php

namespace Tests\Database;

use App\Common\Exceptions\DomainException;
use App\Database\Seeds\CoreCatalogSeeder;
use App\Modules\Weighing\DTO\CreateWeighingData;
use App\Modules\Weighing\DTO\WeightReading;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use Ramsey\Uuid\Uuid;

final class WeighingServiceDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $migrate = true;
    protected $refresh = true;
    protected $seed = CoreCatalogSeeder::class;

    public function testStableReadingNormalizesTimestampAndPreservesAllWeights(): void
    {
        $context = $this->createPendingFilling();
        $readingId = $this->captureReading($context['scaleId']);
        $result = Services::weighingService(false)->confirm(CreateWeighingData::fromArray([
            'filling_id' => $context['fillingId'], 'rsa_id' => $context['rsaId'], 'scale_id' => $context['scaleId'],
            'scale_reading_id' => $readingId,
            'gross_weight_kg' => 1000, 'tare_source' => 'catalog', 'penalty_percent' => 5,
            'weight_source' => 'simulator', 'stable' => true, 'original_value' => 'SIM|1000.000|kg|STABLE',
            'device_timestamp' => '2026-09-13T08:30:00-06:00', 'reason_code' => 'CASTIGO_AUTORIZADO',
        ], 1));

        self::assertEqualsWithDelta(824, (float) $result->physical_net_kg, 0.001);
        self::assertEqualsWithDelta(41.2, (float) $result->penalty_kg, 0.001);
        self::assertEqualsWithDelta(782.8, (float) $result->accepted_net_kg, 0.001);
        self::assertMatchesRegularExpression('/^2026-09-13 \d{2}:30:00$/', (string) $result->device_timestamp);
        self::assertSame(1, $this->db->table('scale_readings')->where('weighing_id', $result->id)->countAllResults());
        self::assertSame($context['rsaId'], (int) $result->rsa_id);
    }

    public function testPenaltyWithoutReasonRollsBackLotAndWeighing(): void
    {
        $context = $this->createPendingFilling();
        $readingId = $this->captureReading($context['scaleId']);
        try {
            Services::weighingService(false)->confirm(CreateWeighingData::fromArray([
                'filling_id' => $context['fillingId'], 'rsa_id' => $context['rsaId'], 'scale_id' => $context['scaleId'],
                'scale_reading_id' => $readingId,
                'gross_weight_kg' => 1000, 'tare_source' => 'catalog', 'penalty_percent' => 5,
                'weight_source' => 'simulator', 'stable' => true, 'original_value' => 'SIM|1000.000|kg|STABLE',
                'device_timestamp' => '2026-09-13T08:30:00-06:00',
            ], 1));
            self::fail('Un castigo sin motivo debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('PENALTY_REASON_REQUIRED', $exception->domainCode);
        }

        self::assertSame(0, $this->db->table('weighings')->countAllResults());
        self::assertSame(0, $this->db->table('lots')->countAllResults());
        self::assertSame('pending_weighing', $this->db->table('tub_fillings')->where('id', $context['fillingId'])->get()->getRow('status'));
        self::assertNull($this->db->table('scale_readings')->where('id', $readingId)->get()->getRow('weighing_id'));
    }

    public function testClientCannotAlterCapturedGrossWeight(): void
    {
        $context = $this->createPendingFilling();
        $readingId = $this->captureReading($context['scaleId']);

        try {
            Services::weighingService(false)->confirm(CreateWeighingData::fromArray([
                'filling_id' => $context['fillingId'], 'rsa_id' => $context['rsaId'], 'scale_id' => $context['scaleId'],
                'scale_reading_id' => $readingId, 'gross_weight_kg' => 1001,
                'tare_source' => 'catalog', 'penalty_percent' => 0, 'weight_source' => 'simulator',
                'stable' => true, 'original_value' => 'SIM|1000.000|kg|STABLE',
                'device_timestamp' => '2026-09-13T08:30:00-06:00',
            ], 1));
            self::fail('El peso manipulado debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('SCALE_READING_TAMPERED', $exception->domainCode);
        }

        self::assertSame(0, $this->db->table('weighings')->countAllResults());
        self::assertNull($this->db->table('scale_readings')->where('id', $readingId)->get()->getRow('weighing_id'));
    }

    public function testCapturedReadingCannotBeReused(): void
    {
        $context = $this->createPendingFilling();
        $readingId = $this->captureReading($context['scaleId']);
        $payload = [
            'filling_id' => $context['fillingId'], 'rsa_id' => $context['rsaId'], 'scale_id' => $context['scaleId'],
            'scale_reading_id' => $readingId, 'gross_weight_kg' => 1000,
            'tare_source' => 'catalog', 'penalty_percent' => 0, 'weight_source' => 'simulator',
            'stable' => true, 'original_value' => 'SIM|1000.000|kg|STABLE',
            'device_timestamp' => '2026-09-13T08:30:00-06:00',
        ];
        Services::weighingService(false)->confirm(CreateWeighingData::fromArray($payload, 1));
        $this->db->table('tub_fillings')->where('id', $context['fillingId'])->update(['status' => 'pending_weighing']);

        try {
            Services::weighingService(false)->confirm(CreateWeighingData::fromArray($payload, 1));
            self::fail('Una lectura consumida no debe reutilizarse.');
        } catch (DomainException $exception) {
            self::assertSame('SCALE_READING_ALREADY_USED', $exception->domainCode);
        }
    }

    public function testExpiredCapturedReadingIsRejected(): void
    {
        $context = $this->createPendingFilling();
        $readingId = $this->captureReading($context['scaleId']);
        $this->db->table('scale_readings')->where('id', $readingId)->update([
            'received_at' => date('Y-m-d H:i:s', time() - 121),
        ]);

        try {
            Services::weighingService(false)->confirm(CreateWeighingData::fromArray([
                'filling_id' => $context['fillingId'], 'rsa_id' => $context['rsaId'], 'scale_id' => $context['scaleId'],
                'scale_reading_id' => $readingId, 'gross_weight_kg' => 1000,
                'tare_source' => 'catalog', 'penalty_percent' => 0, 'weight_source' => 'simulator',
                'stable' => true, 'original_value' => 'SIM|1000.000|kg|STABLE',
                'device_timestamp' => '2026-09-13T08:30:00-06:00',
            ], 1));
            self::fail('Una lectura expirada debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('STALE_SCALE_READING', $exception->domainCode);
        }
    }

    private function captureReading(int $scaleId): int
    {
        $reading = new WeightReading(
            1000,
            'kg',
            true,
            new \DateTimeImmutable('2026-09-13T08:30:00-06:00'),
            'SIMULATOR-NOT-A-REAL-SCALE',
            'SIM|1000.000|kg|STABLE',
            'connected',
            'simulator',
        );

        return (int) Services::scaleReadingService(false)->capture($reading, $scaleId, 1)['id'];
    }

    /** @return array{fillingId:int,rsaId:int,scaleId:int} */
    private function createPendingFilling(): array
    {
        $companyId = (int) $this->db->table('companies')->where('code', 'GMAR')->get()->getRow('id');
        $siteId = (int) $this->db->table('sites')->where('code', 'MZO')->get()->getRow('id');
        $vesselId = (int) $this->db->table('vessels')->where('code', 'FDA')->get()->getRow('id');
        $speciesId = (int) $this->db->table('species')->where('code', 'A')->get()->getRow('id');
        $sizeId = (int) $this->db->table('size_grades')->where('code', '3-5')->get()->getRow('id');
        $tubId = (int) $this->db->table('tubs')->where('code', 'T-001')->get()->getRow('id');
        $scaleId = (int) $this->db->table('scales')->where('code', 'BAS-DEV-01')->get()->getRow('id');
        $now = date('Y-m-d H:i:s');
        $this->db->table('voyages')->insert(['uuid' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'site_id' => $siteId, 'vessel_id' => $vesselId, 'annual_number' => 700, 'year' => 2026, 'arrival_at' => '2026-09-13 06:00:00', 'status' => 'unloading', 'created_by' => 1, 'created_at' => $now]);
        $voyageId = (int) $this->db->insertID();
        $this->db->table('voyage_tanks')->insert(['voyage_id' => $voyageId, 'tank_number' => '1-BB', 'capacity_kg' => 5000, 'status' => 'available', 'created_at' => $now]);
        $tankId = (int) $this->db->insertID();
        $this->db->table('rsa_records')->insert(['voyage_id' => $voyageId, 'code' => 'RSA-WEIGH-001', 'issued_on' => '2026-09-01', 'expires_on' => '2099-12-31', 'status' => 'active', 'created_by' => 1, 'created_at' => $now]);
        $rsaId = (int) $this->db->insertID();
        $this->db->table('discharge_plans')->insert(['uuid' => Uuid::uuid7()->toString(), 'folio' => 'PLAN-WEIGH-001', 'voyage_id' => $voyageId, 'rsa_id' => $rsaId, 'status' => 'in_progress', 'created_by' => 1, 'created_at' => $now]);
        $planId = (int) $this->db->insertID();
        $this->db->table('discharge_plan_tanks')->insert(['discharge_plan_id' => $planId, 'voyage_tank_id' => $tankId, 'species_id' => $speciesId, 'size_grade_id' => $sizeId, 'planned_weight_kg' => 900, 'msc_code' => '3.1', 'dolphin_safe_code' => 'A', 'freezing_temperature_c' => -18, 'sequence_number' => 1, 'status' => 'pending', 'created_at' => $now]);
        $this->db->table('tub_fillings')->insert(['uuid' => Uuid::uuid7()->toString(), 'discharge_plan_id' => $planId, 'tub_id' => $tubId, 'actual_voyage_tank_id' => $tankId, 'species_id' => $speciesId, 'size_grade_id' => $sizeId, 'status' => 'pending_weighing', 'started_at' => $now, 'completed_at' => $now, 'created_by' => 1, 'created_at' => $now]);
        $fillingId = (int) $this->db->insertID();
        $this->db->table('filling_source_tanks')->insert(['tub_filling_id' => $fillingId, 'voyage_tank_id' => $tankId, 'is_primary' => 1, 'created_at' => $now]);
        $this->db->table('tubs')->where('id', $tubId)->update(['status' => 'pending_weighing']);

        return ['fillingId' => $fillingId, 'rsaId' => $rsaId, 'scaleId' => $scaleId];
    }
}
