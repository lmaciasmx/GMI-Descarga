<?php

namespace Tests\Database;

use App\Common\Exceptions\DomainException;
use App\Database\Seeds\CoreCatalogSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;

final class DischargePlanDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $migrate = true;
    protected $refresh = true;
    protected $seed = CoreCatalogSeeder::class;

    public function testPlanPersistsCertifiedTankCompositionAndRequiresActiveRsa(): void
    {
        $context = $this->createVoyageWithTank(1000);
        $rsa = Services::rsaService(false)->create(['voyage_id' => $context['voyageId'], 'code' => 'RSA-TEST-001', 'issued_on' => '2026-09-01', 'expires_on' => '2026-12-31'], 1);
        $plan = Services::dischargePlanService(false)->create([
            'voyage_id' => $context['voyageId'], 'rsa_id' => $rsa['id'], 'planned_start_at' => '2026-10-01 08:00:00',
            'tank_lines' => [['voyage_tank_id' => $context['voyageTankId'], 'species_id' => $context['speciesId'], 'size_grade_id' => $context['sizeId'], 'planned_weight_kg' => 900, 'msc_code' => '3.1', 'dolphin_safe_code' => 'A', 'freezing_temperature_c' => -18]],
        ], 1);

        Services::dischargePlanService(false)->transition($plan['id'], 'approved', 2);
        Services::dischargePlanService(false)->transition($plan['id'], 'in_progress', 2);

        $composition = $this->db->table('discharge_plan_tanks')->where('discharge_plan_id', $plan['id'])->get()->getRowArray();
        self::assertSame('3.1', $composition['msc_code']);
        self::assertSame('A', $composition['dolphin_safe_code']);
        self::assertEqualsWithDelta(-18, (float) $composition['freezing_temperature_c'], 0.01);
        self::assertSame('in_progress', $this->db->table('discharge_plans')->where('id', $plan['id'])->get()->getRow('status'));
        self::assertSame('unloading', $this->db->table('voyages')->where('id', $context['voyageId'])->get()->getRow('status'));
    }

    public function testTankCapacityViolationRollsBackWholePlan(): void
    {
        $context = $this->createVoyageWithTank(500);
        $before = $this->db->table('discharge_plans')->countAllResults();

        try {
            Services::dischargePlanService(false)->create([
                'voyage_id' => $context['voyageId'],
                'tank_lines' => [['voyage_tank_id' => $context['voyageTankId'], 'species_id' => $context['speciesId'], 'size_grade_id' => $context['sizeId'], 'planned_weight_kg' => 501, 'msc_code' => '1.0', 'dolphin_safe_code' => 'B', 'freezing_temperature_c' => -20]],
            ], 1);
            self::fail('Un plan por encima de la capacidad debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('PLAN_TANK_CAPACITY_EXCEEDED', $exception->domainCode);
        }

        self::assertSame($before, $this->db->table('discharge_plans')->countAllResults());
        self::assertSame(0, $this->db->table('discharge_plan_tanks')->countAllResults());
    }

    public function testDuplicateCompositionSequenceRollsBackWholePlan(): void
    {
        $context = $this->createVoyageWithTank(1000);
        $otherSizeId = (int) $this->db->table('size_grades')->where('code', '5-10')->get()->getRow('id');

        try {
            Services::dischargePlanService(false)->create([
                'voyage_id' => $context['voyageId'],
                'tank_lines' => [
                    ['voyage_tank_id' => $context['voyageTankId'], 'species_id' => $context['speciesId'], 'size_grade_id' => $context['sizeId'], 'planned_weight_kg' => 100, 'msc_code' => '3.0', 'dolphin_safe_code' => 'A', 'freezing_temperature_c' => -18, 'sequence_number' => 1],
                    ['voyage_tank_id' => $context['voyageTankId'], 'species_id' => $context['speciesId'], 'size_grade_id' => $otherSizeId, 'planned_weight_kg' => 100, 'msc_code' => '3.0', 'dolphin_safe_code' => 'A', 'freezing_temperature_c' => -18, 'sequence_number' => 1],
                ],
            ], 1);
            self::fail('Las secuencias repetidas deben rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('DUPLICATE_PLAN_SEQUENCE', $exception->domainCode);
        }

        self::assertSame(0, $this->db->table('discharge_plans')->countAllResults());
        self::assertSame(0, $this->db->table('discharge_plan_tanks')->countAllResults());
    }

    public function testPlanWithoutRsaRemainsDraftWhenApprovalIsRejected(): void
    {
        $context = $this->createVoyageWithTank(1000);
        $plan = Services::dischargePlanService(false)->create([
            'voyage_id' => $context['voyageId'], 'planned_start_at' => '2026-10-01 08:00:00',
            'tank_lines' => [['voyage_tank_id' => $context['voyageTankId'], 'species_id' => $context['speciesId'], 'planned_weight_kg' => 400, 'msc_code' => '2.0', 'dolphin_safe_code' => 'A', 'freezing_temperature_c' => -19]],
        ], 1);

        try {
            Services::dischargePlanService(false)->transition($plan['id'], 'approved', 2);
            self::fail('Un plan sin RSA no debe aprobarse.');
        } catch (DomainException $exception) {
            self::assertSame('PLAN_RSA_REQUIRED', $exception->domainCode);
        }

        self::assertSame('draft', $this->db->table('discharge_plans')->where('id', $plan['id'])->get()->getRow('status'));
    }

    public function testPlanCannotFinishOrCancelWhileItHasTubOperations(): void
    {
        $context = $this->createVoyageWithTank(1000);
        $rsa = Services::rsaService(false)->create(['voyage_id' => $context['voyageId'], 'code' => 'RSA-TEST-002', 'issued_on' => '2026-09-01', 'expires_on' => '2026-12-31'], 1);
        $plan = Services::dischargePlanService(false)->create([
            'voyage_id' => $context['voyageId'], 'rsa_id' => $rsa['id'], 'planned_start_at' => '2026-10-01 08:00:00',
            'tank_lines' => [['voyage_tank_id' => $context['voyageTankId'], 'species_id' => $context['speciesId'], 'size_grade_id' => $context['sizeId'], 'planned_weight_kg' => 500, 'msc_code' => '3.1', 'dolphin_safe_code' => 'A', 'freezing_temperature_c' => -18]],
        ], 1);
        Services::dischargePlanService(false)->transition($plan['id'], 'approved', 2);
        Services::dischargePlanService(false)->transition($plan['id'], 'in_progress', 2);
        $tubId = (int) $this->db->table('tubs')->where('code', 'T-001')->get()->getRow('id');

        try {
            Services::dischargePlanService(false)->startFilling([
                'plan_id' => $plan['id'], 'tub_id' => $tubId, 'actual_tank_id' => $context['voyageTankId'],
                'species_id' => $context['speciesId'], 'size_grade_id' => $context['sizeId'],
                'source_tank_ids' => [$context['voyageTankId'], $context['voyageTankId']],
            ], 2);
            self::fail('Una procedencia duplicada debe rechazarse sin reservar la tina.');
        } catch (DomainException $exception) {
            self::assertSame('INVALID_SOURCE_TANKS', $exception->domainCode);
        }
        self::assertSame('available', $this->db->table('tubs')->where('id', $tubId)->get()->getRow('status'));

        Services::dischargePlanService(false)->startFilling([
            'plan_id' => $plan['id'], 'tub_id' => $tubId, 'actual_tank_id' => $context['voyageTankId'],
            'species_id' => $context['speciesId'], 'size_grade_id' => $context['sizeId'],
            'source_tank_ids' => [$context['voyageTankId']],
        ], 2);

        foreach ([['finished', 'PLAN_HAS_PENDING_FILLINGS'], ['canceled', 'PLAN_HAS_OPERATIONS']] as [$target, $code]) {
            try {
                Services::dischargePlanService(false)->transition($plan['id'], $target, 2, $target === 'canceled' ? 'Operación ya iniciada' : null);
                self::fail('El plan no debe terminar ni cancelarse con una tina activa.');
            } catch (DomainException $exception) {
                self::assertSame($code, $exception->domainCode);
            }
        }
        self::assertSame('in_progress', $this->db->table('discharge_plans')->where('id', $plan['id'])->get()->getRow('status'));
    }

    public function testVoyageClosesOnlyAfterItsPlanIsFinished(): void
    {
        $context = $this->createVoyageWithTank(1000);
        $rsa = Services::rsaService(false)->create(['voyage_id' => $context['voyageId'], 'code' => 'RSA-TEST-003', 'issued_on' => '2026-09-01', 'expires_on' => '2026-12-31'], 1);
        $plan = Services::dischargePlanService(false)->create([
            'voyage_id' => $context['voyageId'], 'rsa_id' => $rsa['id'],
            'tank_lines' => [['voyage_tank_id' => $context['voyageTankId'], 'species_id' => $context['speciesId'], 'size_grade_id' => $context['sizeId'], 'planned_weight_kg' => 500, 'msc_code' => '3.1', 'dolphin_safe_code' => 'A', 'freezing_temperature_c' => -18]],
        ], 1);
        Services::dischargePlanService(false)->transition($plan['id'], 'approved', 2);
        Services::dischargePlanService(false)->transition($plan['id'], 'in_progress', 2);

        try {
            Services::voyageService(false)->transition($context['voyageId'], 'closed', 2);
            self::fail('Una marea no debe cerrar con planes abiertos.');
        } catch (DomainException $exception) {
            self::assertSame('VOYAGE_HAS_OPEN_PLANS', $exception->domainCode);
        }

        Services::dischargePlanService(false)->transition($plan['id'], 'finished', 2);
        $result = Services::voyageService(false)->transition($context['voyageId'], 'closed', 2);
        self::assertSame('closed', $result['status']);
        self::assertNotNull($this->db->table('voyages')->where('id', $context['voyageId'])->get()->getRow('unloading_finished_at'));
    }

    public function testVoyageCancellationRequiresReasonAndCancelsUntouchedPlans(): void
    {
        $context = $this->createVoyageWithTank(1000);
        $plan = Services::dischargePlanService(false)->create([
            'voyage_id' => $context['voyageId'],
            'tank_lines' => [['voyage_tank_id' => $context['voyageTankId'], 'species_id' => $context['speciesId'], 'planned_weight_kg' => 200, 'msc_code' => '2.0', 'dolphin_safe_code' => 'B', 'freezing_temperature_c' => -20]],
        ], 1);

        try {
            Services::voyageService(false)->transition($context['voyageId'], 'canceled', 2, 'no');
            self::fail('La cancelación debe exigir un motivo útil.');
        } catch (DomainException $exception) {
            self::assertSame('VOYAGE_CANCEL_REASON_REQUIRED', $exception->domainCode);
        }

        $result = Services::voyageService(false)->transition($context['voyageId'], 'canceled', 2, 'Arribo cancelado por capitanía');
        self::assertSame(1, $result['canceled_plans']);
        self::assertSame('canceled', $this->db->table('voyages')->where('id', $context['voyageId'])->get()->getRow('status'));
        self::assertSame('canceled', $this->db->table('discharge_plans')->where('id', $plan['id'])->get()->getRow('status'));
    }

    /** @return array{voyageId:int, voyageTankId:int, speciesId:int, sizeId:int} */
    private function createVoyageWithTank(float $capacity): array
    {
        $companyId = (int) $this->db->table('companies')->where('code', 'GMAR')->get()->getRow('id');
        $siteId = (int) $this->db->table('sites')->where('code', 'MZO')->get()->getRow('id');
        $warehouseId = (int) $this->db->table('warehouses')->where('code', 'FRIO-01')->get()->getRow('id');
        $vesselId = (int) $this->db->table('vessels')->where('code', 'FDA')->get()->getRow('id');
        $speciesId = (int) $this->db->table('species')->where('code', 'A')->get()->getRow('id');
        $sizeId = (int) $this->db->table('size_grades')->where('code', '3-5')->get()->getRow('id');
        $this->db->table('vessel_tanks')->insert(['vessel_id' => $vesselId, 'tank_number' => '1-BB', 'side' => 'babor', 'capacity_kg' => $capacity, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $voyage = Services::voyageService(false)->create(['company_id' => $companyId, 'site_id' => $siteId, 'vessel_id' => $vesselId, 'receiving_warehouse_id' => $warehouseId, 'annual_number' => 999, 'year' => 2026, 'arrival_at' => '2026-10-01 06:00:00'], 1);
        $voyageTankId = (int) $this->db->table('voyage_tanks')->where('voyage_id', $voyage['id'])->get()->getRow('id');

        return ['voyageId' => $voyage['id'], 'voyageTankId' => $voyageTankId, 'speciesId' => $speciesId, 'sizeId' => $sizeId];
    }
}
