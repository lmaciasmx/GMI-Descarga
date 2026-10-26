<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class RepairQualityInspectionCorrections extends Migration
{
    public function up()
    {
        // Reparación idempotente para instalaciones donde la migración v2.6 quedó a medias.
        if (! $this->indexExists('quality_inspections', 'ix_quality_weighing')) {
            $this->db->query('ALTER TABLE quality_inspections ADD KEY ix_quality_weighing (weighing_id)');
        }

        if ($this->indexExists('quality_inspections', 'uq_quality_weighing')) {
            $this->db->query('ALTER TABLE quality_inspections DROP INDEX uq_quality_weighing');
        }

        $columns = [
            'annulled_by' => 'BIGINT UNSIGNED NULL AFTER approved_at',
            'annulled_at' => 'DATETIME NULL AFTER annulled_by',
            'annul_reason' => 'VARCHAR(255) NULL AFTER annulled_at',
            'supersedes_inspection_id' => 'BIGINT UNSIGNED NULL AFTER annul_reason',
            'superseded_by_inspection_id' => 'BIGINT UNSIGNED NULL AFTER supersedes_inspection_id',
        ];

        foreach ($columns as $name => $definition) {
            if (! $this->db->fieldExists($name, 'quality_inspections')) {
                $this->db->query(sprintf('ALTER TABLE quality_inspections ADD COLUMN %s %s', $name, $definition));
            }
        }

        $indexes = [
            'ix_quality_annulled' => '(status, annulled_at)',
            'ix_quality_supersedes' => '(supersedes_inspection_id)',
            'ix_quality_superseded_by' => '(superseded_by_inspection_id)',
        ];
        foreach ($indexes as $name => $columnsSql) {
            if (! $this->indexExists('quality_inspections', $name)) {
                $this->db->query(sprintf('ALTER TABLE quality_inspections ADD KEY %s %s', $name, $columnsSql));
            }
        }
    }

    public function down()
    {
        // Migración de reparación: no revierte estructura para evitar romper instalaciones parciales.
    }

    private function indexExists(string $table, string $index): bool
    {
        return $this->db->query(sprintf("SHOW INDEX FROM `%s` WHERE Key_name = ?", $table), [$index])->getNumRows() > 0;
    }
}
