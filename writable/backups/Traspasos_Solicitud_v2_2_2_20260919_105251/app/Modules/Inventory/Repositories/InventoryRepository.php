<?php

namespace App\Modules\Inventory\Repositories;

use App\Common\Exceptions\DomainException;
use CodeIgniter\Database\BaseConnection;

final class InventoryRepository
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function findStockUnitForUpdate(int $id): array
    {
        $row = $this->db->query('SELECT * FROM stock_units WHERE id = ? FOR UPDATE', [$id])->getRowArray();

        if ($row === null) {
            throw new DomainException('Unidad de inventario no encontrada.', 'STOCK_UNIT_NOT_FOUND', ['stock_unit_id' => $id]);
        }

        return $row;
    }

    public function findDocumentForUpdate(int $id): array
    {
        $row = $this->db->query('SELECT * FROM inventory_documents WHERE id = ? FOR UPDATE', [$id])->getRowArray();

        if ($row === null) {
            throw new DomainException('Movimiento de inventario no encontrado.', 'INVENTORY_DOCUMENT_NOT_FOUND', ['document_id' => $id]);
        }

        return $row;
    }

    public function documentLines(int $documentId): array
    {
        return $this->db->table('inventory_document_lines')
            ->where('inventory_document_id', $documentId)
            ->orderBy('line_number')
            ->get()
            ->getResultArray();
    }

    public function availableByCriteria(
        int $companyId,
        int $siteId,
        int $speciesId,
        ?int $sizeGradeId,
        ?string $qualityGrade,
        int $limit = 250
    ): array {
        $builder = $this->db->table('v_available_stock vas')
            ->select('vas.*, l.code AS lot_code, loc.path AS location_path')
            ->join('lots l', 'l.id = vas.lot_id')
            ->join('locations loc', 'loc.id = vas.location_id')
            ->where('vas.company_id', $companyId)
            ->where('vas.site_id', $siteId)
            ->where('vas.species_id', $speciesId);

        if ($sizeGradeId !== null) {
            $builder->where('vas.size_grade_id', $sizeGradeId);
        }

        if ($qualityGrade !== null) {
            $builder->where('vas.quality_grade', $qualityGrade);
        }

        return $builder->orderBy('vas.received_at', 'ASC')->limit($limit)->get()->getResultArray();
    }
}
