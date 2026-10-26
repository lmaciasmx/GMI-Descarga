<?php

namespace App\Modules\Receiving\Models;

use CodeIgniter\Model;

class ReceptionModel extends Model
{
    protected $table = 'receptions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'uuid', 'folio', 'company_id', 'site_id', 'warehouse_id', 'status', 'received_by',
        'received_at', 'observations', 'lock_version',
    ];
}
