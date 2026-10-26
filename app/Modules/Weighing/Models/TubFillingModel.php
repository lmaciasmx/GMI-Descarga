<?php

namespace App\Modules\Weighing\Models;

use CodeIgniter\Model;

class TubFillingModel extends Model
{
    protected $table = 'tub_fillings';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'uuid', 'discharge_plan_id', 'tub_id', 'actual_voyage_tank_id', 'species_id',
        'size_grade_id', 'sorter_user_id', 'labeler_user_id', 'status', 'started_at',
        'completed_at', 'observations', 'lock_version', 'created_by',
    ];
}
