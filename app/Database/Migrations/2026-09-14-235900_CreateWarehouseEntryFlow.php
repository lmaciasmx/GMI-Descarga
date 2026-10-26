<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWarehouseEntryFlow extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('operation_participants')) {
            $this->db->query(<<<'SQL'
CREATE TABLE operation_participants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(120) NOT NULL,
    can_sort TINYINT(1) NOT NULL DEFAULT 0,
    can_label TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_operation_participant_scope_code (company_id, site_id, code),
    KEY ix_operation_participant_scope_active (company_id, site_id, active),
    CONSTRAINT fk_operation_participant_company FOREIGN KEY (company_id) REFERENCES companies (id),
    CONSTRAINT fk_operation_participant_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT ck_operation_participant_role CHECK (can_sort = 1 OR can_label = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        }

        $this->addTubFillingColumns();
        $this->backfillTubFillingPlanLinks();
        $this->addTubFillingIndexesAndForeignKeys();

        if (! $this->db->tableExists('warehouse_entry_destinations')) {
            $this->db->query(<<<'SQL'
CREATE TABLE warehouse_entry_destinations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    tub_filling_id BIGINT UNSIGNED NOT NULL,
    weighing_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    chamber_id BIGINT UNSIGNED NOT NULL,
    sector_location_id BIGINT UNSIGNED NOT NULL,
    final_location_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'proposed',
    proposed_by BIGINT UNSIGNED NOT NULL,
    proposed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    placed_by BIGINT UNSIGNED NULL,
    placed_at DATETIME NULL,
    change_reason VARCHAR(255) NULL,
    notes VARCHAR(1000) NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entry_destination_uuid (uuid),
    UNIQUE KEY uq_entry_destination_filling (tub_filling_id),
    UNIQUE KEY uq_entry_destination_weighing (weighing_id),
    KEY ix_entry_destination_status (status, proposed_at),
    KEY ix_entry_destination_sector_status (sector_location_id, status),
    KEY ix_entry_destination_chamber_status (chamber_id, status),
    CONSTRAINT fk_entry_destination_filling FOREIGN KEY (tub_filling_id) REFERENCES tub_fillings (id),
    CONSTRAINT fk_entry_destination_weighing FOREIGN KEY (weighing_id) REFERENCES weighings (id),
    CONSTRAINT fk_entry_destination_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_entry_destination_chamber FOREIGN KEY (chamber_id) REFERENCES chambers (id),
    CONSTRAINT fk_entry_destination_sector FOREIGN KEY (sector_location_id) REFERENCES locations (id),
    CONSTRAINT fk_entry_destination_final FOREIGN KEY (final_location_id) REFERENCES locations (id),
    CONSTRAINT ck_entry_destination_status CHECK (status IN ('proposed', 'placed', 'canceled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('warehouse_entry_destinations')) {
            $this->forge->dropTable('warehouse_entry_destinations', true);
        }

        foreach ([
            'fk_fillings_labeler_participant',
            'fk_fillings_sorter_participant',
            'fk_fillings_planned_line',
        ] as $constraint) {
            if ($this->constraintExists('tub_fillings', $constraint)) {
                $this->db->query('ALTER TABLE tub_fillings DROP FOREIGN KEY ' . $constraint);
            }
        }

        foreach ([
            'labeler_participant_id',
            'sorter_participant_id',
            'freezing_temperature_c_snapshot',
            'dolphin_safe_code_snapshot',
            'msc_code_snapshot',
            'out_of_plan',
            'planned_line_id',
        ] as $column) {
            if ($this->db->fieldExists($column, 'tub_fillings')) {
                $this->forge->dropColumn('tub_fillings', $column);
            }
        }

        if ($this->db->tableExists('operation_participants')) {
            $this->forge->dropTable('operation_participants', true);
        }
    }

    private function addTubFillingColumns(): void
    {
        /*
         * Cada addColumn usa un arreglo literal. Además de ser válido para
         * CodeIgniter, esto permite que el auditor estático del proyecto
         * incorpore las columnas nuevas al esquema reconstruido desde
         * migrations.
         */
        if (! $this->db->fieldExists('planned_line_id', 'tub_fillings')) {
            $this->forge->addColumn('tub_fillings', [
                'planned_line_id' => [
                    'type' => 'BIGINT',
                    'constraint' => 20,
                    'unsigned' => true,
                    'null' => true,
                    'after' => 'size_grade_id',
                ],
            ]);
        }

        if (! $this->db->fieldExists('out_of_plan', 'tub_fillings')) {
            $this->forge->addColumn('tub_fillings', [
                'out_of_plan' => [
                    'type' => 'TINYINT',
                    'constraint' => 1,
                    'null' => false,
                    'default' => 0,
                    'after' => 'planned_line_id',
                ],
            ]);
        }

        if (! $this->db->fieldExists('msc_code_snapshot', 'tub_fillings')) {
            $this->forge->addColumn('tub_fillings', [
                'msc_code_snapshot' => [
                    'type' => 'VARCHAR',
                    'constraint' => 12,
                    'null' => true,
                    'after' => 'out_of_plan',
                ],
            ]);
        }

        if (! $this->db->fieldExists('dolphin_safe_code_snapshot', 'tub_fillings')) {
            $this->forge->addColumn('tub_fillings', [
                'dolphin_safe_code_snapshot' => [
                    'type' => 'VARCHAR',
                    'constraint' => 12,
                    'null' => true,
                    'after' => 'msc_code_snapshot',
                ],
            ]);
        }

        if (! $this->db->fieldExists('freezing_temperature_c_snapshot', 'tub_fillings')) {
            $this->forge->addColumn('tub_fillings', [
                'freezing_temperature_c_snapshot' => [
                    'type' => 'DECIMAL',
                    'constraint' => '8,2',
                    'null' => true,
                    'after' => 'dolphin_safe_code_snapshot',
                ],
            ]);
        }

        if (! $this->db->fieldExists('sorter_participant_id', 'tub_fillings')) {
            $this->forge->addColumn('tub_fillings', [
                'sorter_participant_id' => [
                    'type' => 'BIGINT',
                    'constraint' => 20,
                    'unsigned' => true,
                    'null' => true,
                    'after' => 'sorter_user_id',
                ],
            ]);
        }

        if (! $this->db->fieldExists('labeler_participant_id', 'tub_fillings')) {
            $this->forge->addColumn('tub_fillings', [
                'labeler_participant_id' => [
                    'type' => 'BIGINT',
                    'constraint' => 20,
                    'unsigned' => true,
                    'null' => true,
                    'after' => 'labeler_user_id',
                ],
            ]);
        }
    }

    private function backfillTubFillingPlanLinks(): void
    {
        // Conserva el avance de planes que ya tenían operación antes de esta
        // migración. Primero intenta la coincidencia exacta de talla y después
        // una línea genérica (size_grade_id NULL), igual que la regla operativa.
        $this->db->query(<<<'SQL'
UPDATE tub_fillings tf
SET tf.planned_line_id = (
    SELECT dpt.id
    FROM discharge_plan_tanks dpt
    WHERE dpt.discharge_plan_id = tf.discharge_plan_id
      AND dpt.voyage_tank_id = tf.actual_voyage_tank_id
      AND dpt.species_id = tf.species_id
      AND (dpt.size_grade_id = tf.size_grade_id OR dpt.size_grade_id IS NULL)
    ORDER BY (dpt.size_grade_id = tf.size_grade_id) DESC, dpt.id
    LIMIT 1
)
WHERE tf.planned_line_id IS NULL
SQL);

        $this->db->query(<<<'SQL'
UPDATE tub_fillings tf
LEFT JOIN discharge_plan_tanks dpt ON dpt.id = tf.planned_line_id
SET tf.out_of_plan = CASE WHEN tf.planned_line_id IS NULL THEN 1 ELSE 0 END,
    tf.msc_code_snapshot = COALESCE(tf.msc_code_snapshot, dpt.msc_code),
    tf.dolphin_safe_code_snapshot = COALESCE(tf.dolphin_safe_code_snapshot, dpt.dolphin_safe_code),
    tf.freezing_temperature_c_snapshot = COALESCE(tf.freezing_temperature_c_snapshot, dpt.freezing_temperature_c)
SQL);
    }

    private function addTubFillingIndexesAndForeignKeys(): void
    {
        $indexes = [
            'ix_fillings_planned_line' => '(planned_line_id)',
            'ix_fillings_sorter_participant' => '(sorter_participant_id)',
            'ix_fillings_labeler_participant' => '(labeler_participant_id)',
            'ix_fillings_out_of_plan' => '(discharge_plan_id, out_of_plan)',
        ];

        foreach ($indexes as $name => $columns) {
            if (! $this->indexExists('tub_fillings', $name)) {
                $this->db->query('ALTER TABLE tub_fillings ADD KEY ' . $name . ' ' . $columns);
            }
        }

        $foreignKeys = [
            'fk_fillings_planned_line' => 'FOREIGN KEY (planned_line_id) REFERENCES discharge_plan_tanks (id)',
            'fk_fillings_sorter_participant' => 'FOREIGN KEY (sorter_participant_id) REFERENCES operation_participants (id)',
            'fk_fillings_labeler_participant' => 'FOREIGN KEY (labeler_participant_id) REFERENCES operation_participants (id)',
        ];

        foreach ($foreignKeys as $name => $definition) {
            if (! $this->constraintExists('tub_fillings', $name)) {
                $this->db->query('ALTER TABLE tub_fillings ADD CONSTRAINT ' . $name . ' ' . $definition);
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->db->query(
            <<<'SQL'
SELECT 1
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND INDEX_NAME = ?
LIMIT 1
SQL,
            [$table, $index]
        )->getRowArray();
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return (bool) $this->db->query(
            <<<'SQL'
SELECT 1
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND CONSTRAINT_NAME = ?
LIMIT 1
SQL,
            [$table, $constraint]
        )->getRowArray();
    }
}
