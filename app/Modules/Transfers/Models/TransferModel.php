<?php

namespace App\Modules\Transfers\Models;

use CodeIgniter\Model;

class TransferModel extends Model
{
    protected $table = 'transfers';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'uuid', 'folio', 'company_id', 'origin_warehouse_id', 'destination_warehouse_id', 'transfer_type', 'request_id', 'reservation_id',
        'status', 'requested_by', 'authorized_by', 'shipped_by', 'received_by', 'requested_at',
        'shipped_at', 'received_at', 'carrier_reference', 'observations', 'lock_version',
    ];
}
