<?php

namespace Tests\Database;

use App\Common\Exceptions\DomainException;
use App\Database\Seeds\CoreCatalogSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use Tests\Support\OperationalFixture;

final class BillingIsolationDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $migrate = true;
    protected $refresh = true;
    protected $seed = CoreCatalogSeeder::class;

    public function testFiscalIssueAndCancellationNeverChangePhysicalInventory(): void
    {
        $fixture = (new OperationalFixture($this->db))->createReceivableWeighing();
        $received = Services::inventoryService(false)->receiveWeighings(['company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'warehouse_id' => $fixture['warehouseId'], 'location_id' => $fixture['locationId'], 'weighing_ids' => [$fixture['weighingId']]], 1);
        $stockId = $received['stock_units'][0]['id'];
        $sale = Services::salesService(false)->create([
            'company_id' => $fixture['companyId'],
            'site_id' => $fixture['siteId'],
            'customer_company_id' => $fixture['companyId'],
            'lines' => [['stock_unit_id' => $stockId, 'quantity_kg' => 100, 'unit_price' => 35, 'currency' => 'MXN']],
        ], 1);
        $documentsBefore = $this->db->table('inventory_documents')->countAllResults();
        $stockBefore = $this->db->table('stock_units')->where('id', $stockId)->get()->getRowArray();

        $issued = Services::billingReferenceService(false)->record([
            'company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'sales_order_id' => $sale['id'],
            'external_system' => 'INVoiice', 'external_document_id' => 'INV-TEST-001', 'document_type' => 'CFDI',
            'fiscal_status' => 'issued', 'fiscal_uuid' => '01994c24-8f52-7d6d-9e8b-0f00aabbccdd', 'issued_at' => '2026-09-13 10:00:00',
        ], 2);
        $canceled = Services::billingReferenceService(false)->record([
            'company_id' => $fixture['companyId'], 'site_id' => $fixture['siteId'], 'sales_order_id' => $sale['id'],
            'external_system' => 'INVoiice', 'external_document_id' => 'INV-TEST-001', 'document_type' => 'CFDI',
            'fiscal_status' => 'canceled', 'fiscal_uuid' => '01994c24-8f52-7d6d-9e8b-0f00aabbccdd', 'canceled_at' => '2026-09-13 11:00:00',
        ], 2);

        self::assertFalse($issued['inventory_changed']);
        self::assertFalse($canceled['inventory_changed']);
        self::assertSame($documentsBefore, $this->db->table('inventory_documents')->countAllResults());
        $stockAfter = $this->db->table('stock_units')->where('id', $stockId)->get()->getRowArray();
        self::assertEqualsWithDelta((float) $stockBefore['on_hand_weight_kg'], (float) $stockAfter['on_hand_weight_kg'], 0.001);
        self::assertEqualsWithDelta((float) $stockBefore['reserved_weight_kg'], (float) $stockAfter['reserved_weight_kg'], 0.001);
        self::assertSame('canceled', $this->db->table('billing_references')->where('id', $issued['id'])->get()->getRow('fiscal_status'));
    }

    public function testReferenceCannotBeMovedToAnotherOperationalScope(): void
    {
        $companyId = (int) $this->db->table('companies')->where('code', 'GMAR')->get()->getRow('id');
        $siteId = (int) $this->db->table('sites')->where('code', 'MZO')->get()->getRow('id');
        Services::billingReferenceService(false)->record(['company_id' => $companyId, 'site_id' => $siteId, 'external_system' => 'INVoiice', 'external_document_id' => 'INV-SCOPE-001', 'document_type' => 'CFDI', 'fiscal_status' => 'pending'], 1);
        $this->db->table('sites')->insert(['company_id' => $companyId, 'code' => 'ALT', 'name' => 'Sitio alterno', 'timezone' => 'America/Mexico_City', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $otherSiteId = (int) $this->db->insertID();

        try {
            Services::billingReferenceService(false)->record(['company_id' => $companyId, 'site_id' => $otherSiteId, 'external_system' => 'INVoiice', 'external_document_id' => 'INV-SCOPE-001', 'document_type' => 'CFDI', 'fiscal_status' => 'issued'], 1);
            self::fail('La referencia externa no debe cambiar de alcance.');
        } catch (DomainException $exception) {
            self::assertSame('BILLING_SCOPE_MISMATCH', $exception->domainCode);
        }
    }
}
