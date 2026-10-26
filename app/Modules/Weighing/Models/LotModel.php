<?php

namespace App\Modules\Weighing\Models;

use CodeIgniter\Model;

class LotModel extends Model
{
    protected $table = 'lots';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'uuid', 'code', 'lot_type', 'voyage_id', 'vessel_code', 'species_id', 'species_code',
        'annual_voyage_number', 'period_component', 'year_component', 'certification_component',
        'rule_version', 'status', 'created_by', 'created_at', 'closed_at',
    ];
}
