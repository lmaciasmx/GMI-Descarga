<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Entities\StockUnit;
use CodeIgniter\Model;

class StockUnitModel extends Model
{
    protected $table = 'stock_units';
    protected $primaryKey = 'id';
    protected $returnType = StockUnit::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'uuid', 'unit_code', 'company_id', 'site_id', 'warehouse_id', 'location_id',
        'reception_line_id', 'weighing_id', 'label_id', 'tub_id', 'lot_id', 'species_id',
        'size_grade_id', 'quality_grade', 'original_weight_kg', 'on_hand_weight_kg',
        'reserved_weight_kg', 'status', 'hold_reason', 'received_at', 'lock_version',
    ];
}
