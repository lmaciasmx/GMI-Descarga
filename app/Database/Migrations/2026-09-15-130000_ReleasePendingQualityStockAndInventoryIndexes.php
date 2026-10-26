<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class ReleasePendingQualityStockAndInventoryIndexes extends Migration
{
    public function up()
    {
        // Corrige existencias creadas con la regla anterior: "pendiente de calidad"
        // no es una retención real. Una retención explícita de Calidad se conserva.
        $this->db->query(<<<'SQL'
UPDATE stock_units su
LEFT JOIN quality_inspections qi
    ON qi.weighing_id = su.weighing_id
   AND qi.status = 'approved'
   AND qi.disposition IN ('hold', 'rejected')
SET su.status = 'available',
    su.hold_reason = NULL,
    su.updated_at = NOW()
WHERE su.status = 'held'
  AND su.hold_reason = 'Inspección pendiente'
  AND qi.id IS NULL
SQL);

        if (! $this->indexExists('stock_units', 'ix_stock_received_at')) {
            $this->db->query('CREATE INDEX ix_stock_received_at ON stock_units (company_id, site_id, warehouse_id, received_at)');
        }

        if (! $this->indexExists('stock_units', 'ix_stock_status_received')) {
            $this->db->query('CREATE INDEX ix_stock_status_received ON stock_units (company_id, site_id, status, received_at)');
        }
    }

    public function down()
    {
        // No se revierte el estado de inventario porque, después de esta migración,
        // una tina disponible pudo haber tenido movimientos legítimos.
        if ($this->indexExists('stock_units', 'ix_stock_status_received')) {
            $this->db->query('DROP INDEX ix_stock_status_received ON stock_units');
        }

        if ($this->indexExists('stock_units', 'ix_stock_received_at')) {
            $this->db->query('DROP INDEX ix_stock_received_at ON stock_units');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = $this->db->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index])->getResultArray();
        return $rows !== [];
    }
}
