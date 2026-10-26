<?php

namespace Tests\Database;

use App\Common\Exceptions\DomainException;
use App\Database\Seeds\CoreCatalogSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use Tests\Support\OperationalFixture;

final class InventoryCountDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $seed = CoreCatalogSeeder::class;

    public function testCountReconciliationCreatesCompensatingMovementAndRelocation(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings([
            'company_id' => $fixture['companyId'],
            'site_id' => $fixture['siteId'],
            'warehouse_id' => $fixture['warehouseId'],
            'location_id' => $fixture['locationId'],
            'weighing_ids' => [$fixture['weighingId']],
        ], 1);
        $stockId = $received['stock_units'][0]['id'];
        $targetLocationId = (int) $this->db->table('locations')->where('code', 'C01-Z01')->get()->getRow('id');
        $countService = Services::inventoryCountService(false);
        $count = $countService->start($fixture['warehouseId'], null, 1, 'Conteo de prueba');
        $countService->record($count['id'], $stockId, $targetLocationId, 780.000, 1);
        $countService->finish($count['id'], 1);

        $result = Services::inventoryService(false)->reconcileCount($count['id'], 'Diferencia validada por supervisor', 2);

        self::assertSame('reconciled', $result['status']);
        self::assertCount(2, $result['documents']);
        $stock = $this->db->table('stock_units')->where('id', $stockId)->get()->getRowArray();
        self::assertEqualsWithDelta(780.000, (float) $stock['on_hand_weight_kg'], 0.001);
        self::assertSame($targetLocationId, (int) $stock['location_id']);
        self::assertSame('adjusted_and_relocated', $this->db->table('inventory_count_lines')->where('inventory_count_id', $count['id'])->get()->getRow('result'));
    }

    public function testReconciliationRejectsStaleSnapshotWithoutPartialDocuments(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings([
            'company_id' => $fixture['companyId'],
            'site_id' => $fixture['siteId'],
            'warehouse_id' => $fixture['warehouseId'],
            'location_id' => $fixture['locationId'],
            'weighing_ids' => [$fixture['weighingId']],
        ], 1);
        $stockId = $received['stock_units'][0]['id'];
        $countService = Services::inventoryCountService(false);
        $count = $countService->start($fixture['warehouseId'], null, 1);
        $countService->record($count['id'], $stockId, $fixture['locationId'], 782.800, 1);
        $countService->finish($count['id'], 1);
        $this->db->table('stock_units')->where('id', $stockId)->set('lock_version', 'lock_version + 1', false)->update();
        $before = $this->db->table('inventory_documents')->countAllResults();

        try {
            Services::inventoryService(false)->reconcileCount($count['id'], 'Intento sobre fotografía obsoleta', 2);
            self::fail('La fotografía obsoleta debe rechazarse.');
        } catch (DomainException $exception) {
            self::assertSame('INVENTORY_COUNT_SNAPSHOT_STALE', $exception->domainCode);
        }

        self::assertSame($before, $this->db->table('inventory_documents')->countAllResults());
        self::assertSame('counted', $this->db->table('inventory_counts')->where('id', $count['id'])->get()->getRow('status'));
    }
}
