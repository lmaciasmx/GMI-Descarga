<?php

namespace App\Modules\Weighing\Entities;

use CodeIgniter\Entity\Entity;

class Weighing extends Entity
{
    protected $casts = [
        'id' => 'integer',
        'tub_filling_id' => 'integer',
        'tub_id' => 'integer',
        'lot_id' => 'integer',
        'scale_id' => '?integer',
        'scale_reading_id' => '?integer',
        'gross_weight_kg' => 'float',
        'applied_tare_kg' => 'float',
        'physical_net_kg' => 'float',
        'penalty_percent' => 'float',
        'penalty_kg' => 'float',
        'accepted_net_kg' => 'float',
        'stable' => 'boolean',
        'is_current' => '?boolean',
        'lock_version' => 'integer',
    ];

    protected $dates = ['device_timestamp', 'confirmed_at', 'reversed_at', 'created_at', 'updated_at'];
}
