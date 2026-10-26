<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddUnexpectedInventoryCountObservations extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('inventory_count_unexpected')) {
            return;
        }

        $this->db->query(<<<'SQL'
CREATE TABLE inventory_count_unexpected (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_count_id BIGINT UNSIGNED NOT NULL,
    stock_unit_id BIGINT UNSIGNED NOT NULL,
    counted_location_id BIGINT UNSIGNED NOT NULL,
    result VARCHAR(30) NOT NULL DEFAULT 'unexpected',
    counted_by BIGINT UNSIGNED NOT NULL,
    counted_at DATETIME NOT NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_inventory_count_unexpected_stock (inventory_count_id, stock_unit_id),
    KEY ix_inventory_count_unexpected_location (counted_location_id),
    CONSTRAINT fk_count_unexpected_count FOREIGN KEY (inventory_count_id) REFERENCES inventory_counts(id),
    CONSTRAINT fk_count_unexpected_stock FOREIGN KEY (stock_unit_id) REFERENCES stock_units(id),
    CONSTRAINT fk_count_unexpected_location FOREIGN KEY (counted_location_id) REFERENCES locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS inventory_count_unexpected');
    }
}
