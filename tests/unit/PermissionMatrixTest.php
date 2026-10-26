<?php

namespace Tests\Unit;

use Config\AuthGroups;
use CodeIgniter\Test\CIUnitTestCase;

final class PermissionMatrixTest extends CIUnitTestCase
{
    public function testWeigherDoesNotReceiveInventoryAdjustmentPermission(): void
    {
        $config = new AuthGroups();
        self::assertContains('weighings.create', $config->matrix['weigher']);
        self::assertNotContains('inventory.adjust', $config->matrix['weigher']);
    }

    public function testBillingRoleCannotMovePhysicalInventory(): void
    {
        $config = new AuthGroups();
        self::assertContains('billing.reference', $config->matrix['billing']);
        self::assertNotContains('inventory.move', $config->matrix['billing']);
    }
}
