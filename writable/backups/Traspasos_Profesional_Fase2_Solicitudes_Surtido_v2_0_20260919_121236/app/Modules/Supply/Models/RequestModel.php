<?php

namespace App\Modules\Supply\Models;

use CodeIgniter\Model;

class RequestModel extends Model
{
    protected $table = 'raw_material_requests';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'uuid', 'folio', 'company_id', 'site_id', 'request_type', 'destination_warehouse_id', 'destination', 'priority', 'required_at',
        'status', 'requested_by', 'requested_at', 'authorized_by', 'authorized_at',
        'rejected_by', 'rejected_at', 'rejection_reason', 'observations', 'lock_version',
    ];
}
