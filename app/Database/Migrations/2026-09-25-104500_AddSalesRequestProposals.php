<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddSalesRequestProposals extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('source_warehouse_id', 'sales_orders')) {
            $this->db->query(<<<'SQL'
ALTER TABLE sales_orders
    ADD COLUMN source_warehouse_id BIGINT UNSIGNED NULL AFTER site_id,
    ADD KEY ix_sales_orders_source_warehouse (source_warehouse_id),
    ADD CONSTRAINT fk_sales_orders_source_warehouse
        FOREIGN KEY (source_warehouse_id) REFERENCES warehouses(id)
SQL);
        }

        if (! $this->db->tableExists('sales_request_proposals')) {
            $this->db->query(<<<'SQL'
CREATE TABLE sales_request_proposals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_order_id BIGINT UNSIGNED NOT NULL,
    request_line_id BIGINT UNSIGNED NOT NULL,
    stock_unit_id BIGINT UNSIGNED NOT NULL,
    proposed_weight_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
    proposed_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_request_proposal_unit (sales_order_id, stock_unit_id),
    KEY ix_sales_request_proposal_line (request_line_id),
    KEY ix_sales_request_proposal_stock (stock_unit_id),
    CONSTRAINT fk_sales_request_proposal_order
        FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id),
    CONSTRAINT fk_sales_request_proposal_line
        FOREIGN KEY (request_line_id) REFERENCES sales_request_lines(id),
    CONSTRAINT fk_sales_request_proposal_stock
        FOREIGN KEY (stock_unit_id) REFERENCES stock_units(id),
    CONSTRAINT ck_sales_request_proposal_weight CHECK (proposed_weight_kg >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('sales_request_proposals', true);

        if ($this->db->fieldExists('source_warehouse_id', 'sales_orders')) {
            $this->db->query('ALTER TABLE sales_orders DROP FOREIGN KEY fk_sales_orders_source_warehouse');
            $this->db->query('ALTER TABLE sales_orders DROP COLUMN source_warehouse_id');
        }
    }
}
