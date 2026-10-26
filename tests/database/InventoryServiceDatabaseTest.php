<?php

namespace Tests\Database;

use App\Common\Exceptions\DomainException;
use App\Database\Seeds\CoreCatalogSeeder;
use App\Modules\Inventory\DTO\CreateMovementData;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use Tests\Support\OperationalFixture;

final class InventoryServiceDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $seed = CoreCatalogSeeder::class;

    public function testReceptionCreatesHeaderDetailLedgerLocationAndStockUnit(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $result = Services::inventoryService(false)->receiveWeighings([
            'company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'],
            'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'],
            'weighing_ids' => [$fixture['weighingId']],
        ], 1);

        self::assertCount(1, $result['stock_units']);
        self::assertSame(1, $this->db->table('inventory_documents')->where('movement_type', 'receipt')->countAllResults());
        self::assertSame(1, $this->db->table('inventory_document_lines')->countAllResults());
        self::assertSame(1, $this->db->table('inventory_movements')->countAllResults());
        self::assertSame(1, $this->db->table('stock_unit_location_history')->countAllResults());
        self::assertSame('available', $this->db->table('stock_units')->get()->getRow('status'));
    }

    public function testDuplicateReceptionRollsBackWithoutCreatingAdditionalDocuments(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $payload = ['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]];
        $service = Services::inventoryService(false);
        $service->receiveWeighings($payload, 1);
        $before = $this->db->table('inventory_documents')->countAllResults();
        try {
            $service->receiveWeighings($payload, 1);
            self::fail('La recepción duplicada debió rechazarse.');
        } catch (DomainException $exception) {
            self::assertContains($exception->domainCode, ['WEIGHING_NOT_RECEIVABLE', 'DUPLICATE_RECEPTION']);
        }
        self::assertSame($before, $this->db->table('inventory_documents')->countAllResults());
    }

    public function testReceptionRejectsRepeatedWeighingInSamePayload(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();

        try {
            Services::inventoryService(false)->receiveWeighings([
                'company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'],
                'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'],
                'weighing_ids' => [$fixture['weighingId'], $fixture['weighingId']],
            ], 1);
            self::fail('Un pesaje repetido debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('INVALID_RECEPTION_LINES', $exception->domainCode);
        }

        self::assertSame(0, $this->db->table('receptions')->countAllResults());
        self::assertSame(0, $this->db->table('inventory_documents')->countAllResults());
    }

    public function testMovementWithInsufficientBalanceRollsBackCompletely(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $stockId = $received['stock_units'][0]['id'];
        $before = $this->db->table('inventory_documents')->countAllResults();
        $data = CreateMovementData::fromArray([
            'movement_type' => 'adjustment_decrease', 'company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'],
            'reason_code' => 'TEST', 'lines' => [['stock_unit_id' => $stockId, 'quantity_kg' => 900]],
        ], 1);
        try {
            Services::inventoryService(false)->createMovement($data);
            self::fail('No debe permitir saldo negativo.');
        } catch (DomainException $exception) {
            self::assertSame('INSUFFICIENT_AVAILABLE_STOCK', $exception->domainCode);
        }
        self::assertSame($before, $this->db->table('inventory_documents')->countAllResults());
        self::assertEqualsWithDelta(782.8, (float) $this->db->table('stock_units')->where('id', $stockId)->get()->getRow('on_hand_weight_kg'), 0.001);
    }

    public function testGenericMovementRejectsDuplicateUnitsBeforeOpeningADocument(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $stockId = $received['stock_units'][0]['id'];
        $before = $this->db->table('inventory_documents')->countAllResults();
        $data = CreateMovementData::fromArray([
            'movement_type' => 'adjustment_decrease', 'company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'reason_code' => 'TEST_DUP',
            'lines' => [['stock_unit_id' => $stockId, 'quantity_kg' => 1], ['stock_unit_id' => $stockId, 'quantity_kg' => 1]],
        ], 1);

        try {
            Services::inventoryService(false)->createMovement($data);
            self::fail('Una unidad repetida debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('DUPLICATE_MOVEMENT_STOCK_UNIT', $exception->domainCode);
        }

        self::assertSame($before, $this->db->table('inventory_documents')->countAllResults());
    }

    public function testProductionIssueRequiresReservationWorkflow(): void
    {
        $data = CreateMovementData::fromArray([
            'movement_type' => 'production_issue', 'company_id' => 1, 'site_id' => 1,
            'lines' => [['stock_unit_id' => 1, 'quantity_kg' => 1]],
        ], 1);

        try {
            Services::inventoryService(false)->createMovement($data);
            self::fail('El surtido genérico debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('SPECIALIZED_MOVEMENT_REQUIRED', $exception->domainCode);
        }
    }

    public function testSpecificUnitCannotBeReservedTwice(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $stockId = $received['stock_units'][0]['id'];
        $requestService = Services::requestService(false);
        $makeRequest = function () use ($requestService, $fixture): array {
            $request = $requestService->create(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'destination' => 'Producción', 'required_at' => '2026-12-01 08:00:00', 'lines' => [['species_id' => $fixture['speciesId'], 'size_grade_id' => $fixture['sizeId'], 'quality_grade' => 'AAA', 'requested_weight_kg' => 782.8]]], 1);
            $requestService->authorize($request['id'], true, 2);
            $lineId = (int) $this->db->table('raw_material_request_lines')->where('request_id', $request['id'])->get()->getRow('id');
            return [$request['id'], $lineId];
        };
        [$requestOne, $lineOne] = $makeRequest();
        Services::inventoryService(false)->reserveForRequest($requestOne, [['request_line_id' => $lineOne, 'stock_unit_id' => $stockId, 'quantity_kg' => 782.8]], 2);
        [$requestTwo, $lineTwo] = $makeRequest();
        try {
            Services::inventoryService(false)->reserveForRequest($requestTwo, [['request_line_id' => $lineTwo, 'stock_unit_id' => $stockId, 'quantity_kg' => 1]], 2);
            self::fail('La segunda reserva debe perder la carrera por el bloqueo de la unidad.');
        } catch (DomainException $exception) {
            self::assertSame('INSUFFICIENT_AVAILABLE_STOCK', $exception->domainCode);
        }
        $stock = $this->db->table('stock_units')->where('id', $stockId)->get()->getRowArray();
        self::assertEqualsWithDelta((float) $stock['on_hand_weight_kg'], (float) $stock['reserved_weight_kg'], 0.001);
    }

    public function testReservationFulfillmentConsumesOnHandAndReservedInOneDocument(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $stockId = $received['stock_units'][0]['id'];
        $request = Services::requestService(false)->create(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'destination' => 'Línea 1', 'required_at' => '2026-12-01 08:00:00', 'lines' => [['species_id' => $fixture['speciesId'], 'size_grade_id' => $fixture['sizeId'], 'quality_grade' => 'AAA', 'requested_weight_kg' => 400]]], 1);
        Services::requestService(false)->authorize($request['id'], true, 2);
        $requestLineId = (int) $this->db->table('raw_material_request_lines')->where('request_id', $request['id'])->get()->getRow('id');
        $reservation = Services::inventoryService(false)->reserveForRequest($request['id'], [['request_line_id' => $requestLineId, 'stock_unit_id' => $stockId, 'quantity_kg' => 400]], 2);

        try {
            Services::inventoryService(false)->reverse($reservation['inventory_document_id'], 'No debe desincronizar reserva', 3);
            self::fail('La reserva debe liberarse con su caso de uso, no con reverso genérico.');
        } catch (DomainException $exception) {
            self::assertSame('SPECIALIZED_REVERSAL_REQUIRED', $exception->domainCode);
        }

        $result = Services::inventoryService(false)->fulfillReservation($reservation['reservation_id'], 3);

        self::assertSame('fulfilled', $result['status']);
        self::assertSame('fulfilled', $result['request_status']);
        $stock = $this->db->table('stock_units')->where('id', $stockId)->get()->getRowArray();
        self::assertEqualsWithDelta(382.8, (float) $stock['on_hand_weight_kg'], 0.001);
        self::assertEqualsWithDelta(0, (float) $stock['reserved_weight_kg'], 0.001);
        self::assertSame('available', $stock['status']);
        self::assertSame('production_issue', $this->db->table('inventory_documents')->where('id', $result['inventory_document_id'])->get()->getRow('movement_type'));
        $movement = $this->db->table('inventory_movements')->where('inventory_document_id', $result['inventory_document_id'])->get()->getRowArray();
        self::assertEqualsWithDelta(-400, (float) $movement['quantity_delta_kg'], 0.001);
        self::assertEqualsWithDelta(-400, (float) $movement['reserved_delta_kg'], 0.001);
    }

    public function testExpiredReservationCannotBeFulfilledAndDoesNotCreateDocument(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $request = Services::requestService(false)->create(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'destination' => 'Línea 2', 'required_at' => '2026-12-01 08:00:00', 'lines' => [['species_id' => $fixture['speciesId'], 'size_grade_id' => $fixture['sizeId'], 'quality_grade' => 'AAA', 'requested_weight_kg' => 100]]], 1);
        Services::requestService(false)->authorize($request['id'], true, 2);
        $lineId = (int) $this->db->table('raw_material_request_lines')->where('request_id', $request['id'])->get()->getRow('id');
        $reservation = Services::inventoryService(false)->reserveForRequest($request['id'], [['request_line_id' => $lineId, 'stock_unit_id' => $received['stock_units'][0]['id'], 'quantity_kg' => 100]], 2, date('Y-m-d H:i:s', time() + 300));
        $this->db->table('reservations')->where('id', $reservation['reservation_id'])->update(['expires_at' => '2020-01-01 00:00:00']);
        $before = $this->db->table('inventory_documents')->countAllResults();

        try {
            Services::inventoryService(false)->fulfillReservation($reservation['reservation_id'], 3);
            self::fail('Una reserva vencida no debe surtirse.');
        } catch (DomainException $exception) {
            self::assertSame('RESERVATION_EXPIRED', $exception->domainCode);
        }

        self::assertSame($before, $this->db->table('inventory_documents')->countAllResults());
        self::assertEqualsWithDelta(100, (float) $this->db->table('stock_units')->where('id', $received['stock_units'][0]['id'])->get()->getRow('reserved_weight_kg'), 0.001);
    }

    public function testReversalCreatesCompensatingDocumentAndKeepsOriginal(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $result = Services::inventoryService(false)->reverse($received['inventory_document_id'], 'Error controlado de prueba', 3);
        self::assertSame($received['inventory_document_id'], $result['reversal_of_id']);
        self::assertSame('confirmed', $this->db->table('inventory_documents')->where('id', $received['inventory_document_id'])->get()->getRow('status'));
        self::assertSame(2, $this->db->table('inventory_documents')->countAllResults());
        self::assertEqualsWithDelta(0, (float) $this->db->table('stock_units')->get()->getRow('on_hand_weight_kg'), 0.001);
        self::assertSame('reversed', $this->db->table('receptions')->where('id', $received['reception_id'])->get()->getRow('status'));

        Services::weighingService(false)->reverse($fixture['weighingId'], 'Reemplazo posterior a recepción reversada', 3);
        self::assertSame('reversed', $this->db->table('weighings')->where('id', $fixture['weighingId'])->get()->getRow('status'));
        self::assertSame('canceled', $this->db->table('labels')->where('weighing_id', $fixture['weighingId'])->get()->getRow('status'));
    }
}
