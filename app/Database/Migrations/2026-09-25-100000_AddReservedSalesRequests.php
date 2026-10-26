<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddReservedSalesRequests extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('sales_request_lines')) {
            $this->db->query(<<<'SQL'
CREATE TABLE sales_request_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_order_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    species_id BIGINT UNSIGNED NOT NULL,
    size_grade_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NULL,
    owner_company_id BIGINT UNSIGNED NULL,
    requested_weight_kg DECIMAL(14,3) NOT NULL,
    requested_unit_count INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_sales_request_line (sales_order_id, line_number),
    KEY ix_sales_request_product (species_id, size_grade_id, lot_id, owner_company_id),
    CONSTRAINT fk_sales_request_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id),
    CONSTRAINT fk_sales_request_species FOREIGN KEY (species_id) REFERENCES species(id),
    CONSTRAINT fk_sales_request_size FOREIGN KEY (size_grade_id) REFERENCES size_grades(id),
    CONSTRAINT fk_sales_request_lot FOREIGN KEY (lot_id) REFERENCES lots(id),
    CONSTRAINT fk_sales_request_owner FOREIGN KEY (owner_company_id) REFERENCES companies(id),
    CONSTRAINT ck_sales_request_weight CHECK (requested_weight_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        }

        if (! $this->db->fieldExists('request_line_id', 'sales_order_lines')) {
            $this->db->query(<<<'SQL'
ALTER TABLE sales_order_lines
    ADD COLUMN request_line_id BIGINT UNSIGNED NULL AFTER sales_order_id,
    ADD KEY ix_sales_lines_request_line (request_line_id),
    ADD CONSTRAINT fk_sales_lines_request_line
        FOREIGN KEY (request_line_id) REFERENCES sales_request_lines(id)
SQL);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('request_line_id', 'sales_order_lines')) {
            $this->db->query('ALTER TABLE sales_order_lines DROP FOREIGN KEY fk_sales_lines_request_line');
            $this->db->query('ALTER TABLE sales_order_lines DROP COLUMN request_line_id');
        }

        $this->forge->dropTable('sales_request_lines', true);
    }
}
