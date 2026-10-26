<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class QualityInspectionCorrections extends Migration
{
    public function up()
    {
        if ($this->indexExists('quality_inspections', 'uq_quality_weighing')) {
            $this->db->query('ALTER TABLE quality_inspections DROP INDEX uq_quality_weighing');
        }
        if (! $this->indexExists('quality_inspections', 'ix_quality_weighing')) {
            $this->db->query('ALTER TABLE quality_inspections ADD KEY ix_quality_weighing (weighing_id)');
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

        if (! $this->indexExists('quality_inspections', 'ix_quality_annulled')) {
            $this->db->query('ALTER TABLE quality_inspections ADD KEY ix_quality_annulled (status, annulled_at)');
        }
        if (! $this->indexExists('quality_inspections', 'ix_quality_supersedes')) {
            $this->db->query('ALTER TABLE quality_inspections ADD KEY ix_quality_supersedes (supersedes_inspection_id)');
        }
        if (! $this->indexExists('quality_inspections', 'ix_quality_superseded_by')) {
            $this->db->query('ALTER TABLE quality_inspections ADD KEY ix_quality_superseded_by (superseded_by_inspection_id)');
        }
    }

    public function down()
    {
        foreach (['ix_quality_superseded_by', 'ix_quality_supersedes', 'ix_quality_annulled'] as $index) {
            if ($this->indexExists('quality_inspections', $index)) {
                $this->db->query(sprintf('ALTER TABLE quality_inspections DROP INDEX %s', $index));
            }
        }
        foreach (['superseded_by_inspection_id', 'supersedes_inspection_id', 'annul_reason', 'annulled_at', 'annulled_by'] as $column) {
            if ($this->db->fieldExists($column, 'quality_inspections')) {
                $this->db->query(sprintf('ALTER TABLE quality_inspections DROP COLUMN %s', $column));
            }
        }
        if ($this->indexExists('quality_inspections', 'ix_quality_weighing')) {
            $this->db->query('ALTER TABLE quality_inspections DROP INDEX ix_quality_weighing');
        }
        if (! $this->indexExists('quality_inspections', 'uq_quality_weighing')) {
            $this->db->query('ALTER TABLE quality_inspections ADD UNIQUE KEY uq_quality_weighing (weighing_id)');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return $this->db->query(sprintf("SHOW INDEX FROM `%s` WHERE Key_name = ?", $table), [$index])->getNumRows() > 0;
    }
}
