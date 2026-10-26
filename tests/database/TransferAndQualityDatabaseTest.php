<?php

namespace Tests\Database;

use App\Common\Exceptions\DomainException;
use App\Database\Seeds\CoreCatalogSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use Tests\Support\OperationalFixture;

final class TransferAndQualityDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $migrate = true;
    protected $refresh = true;
    protected $seed = CoreCatalogSeeder::class;

    public function testTransferPreservesInTransitStateAndConfirmsDestinationReceipt(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $this->db->table('sites')->insert(['company_id' => $fixture['companyId'], 'code' => 'EXT', 'name' => 'Instalación externa de prueba', 'timezone' => 'America/Mexico_City', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $destinationSiteId = (int) $this->db->insertID();
        $this->db->table('warehouses')->insert(['company_id' => $fixture['companyId'], 'site_id' => $destinationSiteId, 'code' => 'EXT-TEST', 'name' => 'Almacén externo de prueba', 'warehouse_type' => 'external', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $destinationWarehouseId = (int) $this->db->insertID();
        $this->db->table('locations')->insert(['warehouse_id' => $destinationWarehouseId, 'code' => 'REC-EXT', 'name' => 'Recepción externa', 'location_type' => 'staging', 'path' => '/REC-EXT', 'max_units' => 10, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $destinationLocationId = (int) $this->db->insertID();
        $transfer = Services::transferService(false)->create(['company_id' => $fixture['companyId'], 'origin_warehouse_id' => $fixture['warehouseId'], 'destination_warehouse_id' => $destinationWarehouseId, 'stock_unit_ids' => [$received['stock_units'][0]['id']]], 1);
        Services::transferService(false)->authorize($transfer['id'], true, 2);
        Services::inventoryService(false)->shipTransfer($transfer['id'], 3);
        self::assertSame('in_transit', $this->db->table('stock_units')->where('id', $received['stock_units'][0]['id'])->get()->getRow('status'));
        $result = Services::inventoryService(false)->receiveTransfer($transfer['id'], $destinationLocationId, [], 4);
        self::assertSame('received', $result['status']);
        $stock = $this->db->table('stock_units')->where('id', $received['stock_units'][0]['id'])->get()->getRowArray();
        self::assertSame($destinationWarehouseId, (int) $stock['warehouse_id']);
        self::assertSame($destinationLocationId, (int) $stock['location_id']);
        self::assertSame($destinationSiteId, (int) $stock['site_id']);
        self::assertSame('available', $stock['status']);
    }

    public function testTransferDifferenceHoldsStockForReconciliation(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $this->db->table('warehouses')->insert(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'code' => 'EXT-DIF', 'name' => 'Destino con diferencia', 'warehouse_type' => 'external', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $warehouseId = (int) $this->db->insertID();
        $this->db->table('locations')->insert(['warehouse_id' => $warehouseId, 'code' => 'DIF', 'name' => 'Zona diferencias', 'location_type' => 'staging', 'path' => '/DIF', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $locationId = (int) $this->db->insertID();
        $stockId = $received['stock_units'][0]['id'];
        $transfer = Services::transferService(false)->create(['company_id' => $fixture['companyId'], 'origin_warehouse_id' => $fixture['warehouseId'], 'destination_warehouse_id' => $warehouseId, 'stock_unit_ids' => [$stockId]], 1);
        Services::transferService(false)->authorize($transfer['id'], true, 2);
        Services::inventoryService(false)->shipTransfer($transfer['id'], 3);
        $documentsBefore = $this->db->table('inventory_documents')->countAllResults();
        try {
            Services::inventoryService(false)->receiveTransfer($transfer['id'], $locationId, ['999999' => 1], 4);
            self::fail('No debe aceptar pesos de unidades ajenas al traspaso.');
        } catch (DomainException $exception) {
            self::assertSame('TRANSFER_RECEIPT_UNIT_MISMATCH', $exception->domainCode);
        }
        self::assertSame($documentsBefore, $this->db->table('inventory_documents')->countAllResults());
        $result = Services::inventoryService(false)->receiveTransfer($transfer['id'], $locationId, [(string) $stockId => 780.000], 4);
        self::assertSame('discrepancy', $result['status']);
        self::assertSame('held', $this->db->table('stock_units')->where('id', $stockId)->get()->getRow('status'));
    }

    public function testTransferRejectsRepeatedPhysicalUnits(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $this->db->table('warehouses')->insert(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'code' => 'EXT-DUP', 'name' => 'Destino duplicado', 'warehouse_type' => 'external', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $destinationWarehouseId = (int) $this->db->insertID();
        $stockId = $received['stock_units'][0]['id'];

        try {
            Services::transferService(false)->create(['company_id' => $fixture['companyId'], 'origin_warehouse_id' => $fixture['warehouseId'], 'destination_warehouse_id' => $destinationWarehouseId, 'stock_unit_ids' => [$stockId, $stockId]], 1);
            self::fail('Una unidad repetida debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('INVALID_TRANSFER_UNITS', $exception->domainCode);
        }

        self::assertSame(0, $this->db->table('transfers')->countAllResults());
    }

    public function testQualityReleaseIsIndependentInventoryMovement(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing('01', 'pending');
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $stockId = $received['stock_units'][0]['id'];
        self::assertSame('held', $this->db->table('stock_units')->where('id', $stockId)->get()->getRow('status'));
        $inspectionId = (int) $this->db->table('quality_inspections')->where('weighing_id', $fixture['weighingId'])->get()->getRow('id');
        $this->db->table('quality_inspections')->where('id', $inspectionId)->update(['status' => 'submitted']);
        $result = Services::inventoryService(false)->applyQualityDecision($inspectionId, 'released', 'AAA', 2);
        self::assertSame('available', $this->db->table('stock_units')->where('id', $stockId)->get()->getRow('status'));
        self::assertNotNull($result['inventory_document_id']);
        self::assertSame('quality_release', $this->db->table('inventory_documents')->where('id', $result['inventory_document_id'])->get()->getRow('movement_type'));
    }

    public function testProposedAcceptanceDoesNotReleaseStockBeforeApproval(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing('02', 'accepted');
        $this->db->table('quality_inspections')->where('weighing_id', $fixture['weighingId'])->update(['status' => 'submitted', 'approved_by' => null, 'approved_at' => null]);

        $received = Services::inventoryService(false)->receiveWeighings([
            'company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'],
            'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'],
            'weighing_ids' => [$fixture['weighingId']],
        ], 1);

        self::assertSame('held', $this->db->table('stock_units')->where('id', $received['stock_units'][0]['id'])->get()->getRow('status'));
    }
}
