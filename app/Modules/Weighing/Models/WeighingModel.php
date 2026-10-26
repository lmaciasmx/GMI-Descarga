<?php

namespace App\Modules\Weighing\Models;

use App\Modules\Weighing\Entities\Weighing;
use CodeIgniter\Model;

class WeighingModel extends Model
{
    protected $table = 'weighings';
    protected $primaryKey = 'id';
    protected $returnType = Weighing::class;
    protected $useAutoIncrement = true;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'uuid', 'folio', 'tub_filling_id', 'tub_id', 'lot_id', 'rsa_id', 'scale_id', 'scale_reading_id',
        'gross_weight_kg', 'applied_tare_kg', 'tare_source', 'physical_net_kg',
        'penalty_percent', 'penalty_kg', 'accepted_net_kg', 'weight_source', 'stable',
        'original_value', 'device_timestamp', 'reason_code', 'observations', 'status',
        'is_current', 'confirmed_by', 'confirmed_at', 'reversed_by', 'reversed_at',
        'reversal_reason', 'lock_version', 'created_by',
    ];
}
