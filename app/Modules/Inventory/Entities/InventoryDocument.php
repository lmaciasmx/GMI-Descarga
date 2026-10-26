<?php

namespace App\Modules\Inventory\Entities;

use CodeIgniter\Entity\Entity;

class InventoryDocument extends Entity
{
    protected $casts = [
        'id' => 'integer',
        'company_id' => 'integer',
        'site_id' => 'integer',
        'origin_warehouse_id' => '?integer',
        'destination_warehouse_id' => '?integer',
        'reversal_of_id' => '?integer',
        'lock_version' => 'integer',
    ];

    protected $dates = ['confirmed_at', 'reversed_at', 'created_at', 'updated_at'];
}
