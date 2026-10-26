<?php

namespace App\Modules\Supply\Models;

use CodeIgniter\Model;

class ReservationModel extends Model
{
    protected $table = 'reservations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'uuid', 'folio', 'request_id', 'status', 'expires_at', 'created_by', 'released_by',
        'released_at', 'release_reason', 'lock_version',
    ];
}
