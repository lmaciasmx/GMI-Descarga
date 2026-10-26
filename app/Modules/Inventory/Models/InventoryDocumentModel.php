<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Entities\InventoryDocument;
use CodeIgniter\Model;

class InventoryDocumentModel extends Model
{
    protected $table = 'inventory_documents';
    protected $primaryKey = 'id';
    protected $returnType = InventoryDocument::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'uuid', 'folio', 'movement_type', 'status', 'company_id', 'site_id',
        'origin_warehouse_id', 'destination_warehouse_id', 'source_document_type',
        'source_document_id', 'destination_document_type', 'destination_document_id',
        'reversal_of_id', 'reason_code', 'observations', 'confirmed_by', 'confirmed_at',
        'reversed_by', 'reversed_at', 'lock_version', 'created_by',
    ];
}
