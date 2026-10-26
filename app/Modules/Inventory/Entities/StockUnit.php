<?php

namespace App\Modules\Inventory\Entities;

use CodeIgniter\Entity\Entity;

class StockUnit extends Entity
{
    protected $casts = [
        'id' => 'integer',
        'company_id' => 'integer',
        'site_id' => 'integer',
        'warehouse_id' => 'integer',
        'location_id' => 'integer',
        'lot_id' => 'integer',
        'species_id' => 'integer',
        'size_grade_id' => 'integer',
        'original_weight_kg' => 'float',
        'on_hand_weight_kg' => 'float',
        'reserved_weight_kg' => 'float',
        'lock_version' => 'integer',
    ];

    protected $dates = ['received_at', 'created_at', 'updated_at'];

    public function availableWeight(): float
    {
        return round((float) $this->on_hand_weight_kg - (float) $this->reserved_weight_kg, 3);
    }
}
