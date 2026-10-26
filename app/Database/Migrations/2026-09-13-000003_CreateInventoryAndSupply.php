<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInventoryAndSupply extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
CREATE TABLE inventory_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    folio VARCHAR(50) NOT NULL,
    movement_type VARCHAR(40) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    company_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    origin_warehouse_id BIGINT UNSIGNED NULL,
    destination_warehouse_id BIGINT UNSIGNED NULL,
    source_document_type VARCHAR(60) NULL,
    source_document_id BIGINT UNSIGNED NULL,
    destination_document_type VARCHAR(60) NULL,
    destination_document_id BIGINT UNSIGNED NULL,
    reversal_of_id BIGINT UNSIGNED NULL,
    reason_code VARCHAR(50) NULL,
    observations TEXT NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    reversed_by BIGINT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_inventory_documents_uuid (uuid),
    UNIQUE KEY uq_inventory_documents_folio (folio),
    UNIQUE KEY uq_inventory_reversal (reversal_of_id),
    KEY ix_inventory_documents_scope (company_id, site_id, movement_type, status, confirmed_at),
    KEY ix_inventory_documents_source (source_document_type, source_document_id),
    CONSTRAINT fk_inventory_documents_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_inventory_documents_site FOREIGN KEY (site_id) REFERENCES sites(id),
    CONSTRAINT fk_inventory_documents_origin_warehouse FOREIGN KEY (origin_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_inventory_documents_destination_warehouse FOREIGN KEY (destination_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_inventory_documents_reversal FOREIGN KEY (reversal_of_id) REFERENCES inventory_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE stock_units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    unit_code VARCHAR(100) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    reception_line_id BIGINT UNSIGNED NOT NULL,
    weighing_id BIGINT UNSIGNED NOT NULL,
    label_id BIGINT UNSIGNED NOT NULL,
    tub_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    species_id BIGINT UNSIGNED NOT NULL,
    size_grade_id BIGINT UNSIGNED NOT NULL,
    quality_grade VARCHAR(20) NULL,
    original_weight_kg DECIMAL(14,3) NOT NULL,
    on_hand_weight_kg DECIMAL(14,3) NOT NULL,
    reserved_weight_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'received',
    hold_reason VARCHAR(255) NULL,
    received_at DATETIME NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_stock_units_uuid (uuid),
    UNIQUE KEY uq_stock_units_code (unit_code),
    UNIQUE KEY uq_stock_units_reception_line (reception_line_id),
    UNIQUE KEY uq_stock_units_weighing (weighing_id),
    UNIQUE KEY uq_stock_units_label (label_id),
    KEY ix_stock_availability (company_id, site_id, warehouse_id, status, species_id, size_grade_id, quality_grade),
    KEY ix_stock_location (location_id, status),
    KEY ix_stock_lot (lot_id, status),
    CONSTRAINT fk_stock_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_stock_site FOREIGN KEY (site_id) REFERENCES sites(id),
    CONSTRAINT fk_stock_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_stock_location FOREIGN KEY (location_id) REFERENCES locations(id),
    CONSTRAINT fk_stock_reception_line FOREIGN KEY (reception_line_id) REFERENCES reception_lines(id),
    CONSTRAINT fk_stock_weighing FOREIGN KEY (weighing_id) REFERENCES weighings(id),
    CONSTRAINT fk_stock_label FOREIGN KEY (label_id) REFERENCES labels(id),
    CONSTRAINT fk_stock_tub FOREIGN KEY (tub_id) REFERENCES tubs(id),
    CONSTRAINT fk_stock_lot FOREIGN KEY (lot_id) REFERENCES lots(id),
    CONSTRAINT fk_stock_species FOREIGN KEY (species_id) REFERENCES species(id),
    CONSTRAINT fk_stock_size FOREIGN KEY (size_grade_id) REFERENCES size_grades(id),
    CONSTRAINT ck_stock_weights CHECK (original_weight_kg > 0 AND on_hand_weight_kg >= 0 AND reserved_weight_kg >= 0 AND reserved_weight_kg <= on_hand_weight_kg)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE inventory_document_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_document_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    stock_unit_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    from_warehouse_id BIGINT UNSIGNED NULL,
    to_warehouse_id BIGINT UNSIGNED NULL,
    from_location_id BIGINT UNSIGNED NULL,
    to_location_id BIGINT UNSIGNED NULL,
    quantity_kg DECIMAL(14,3) NOT NULL,
    stock_status_before VARCHAR(30) NULL,
    stock_status_after VARCHAR(30) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inventory_document_line (inventory_document_id, line_number),
    KEY ix_inventory_lines_stock (stock_unit_id),
    CONSTRAINT fk_inventory_lines_document FOREIGN KEY (inventory_document_id) REFERENCES inventory_documents(id),
    CONSTRAINT fk_inventory_lines_stock FOREIGN KEY (stock_unit_id) REFERENCES stock_units(id),
    CONSTRAINT fk_inventory_lines_lot FOREIGN KEY (lot_id) REFERENCES lots(id),
    CONSTRAINT fk_inventory_lines_from_warehouse FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_inventory_lines_to_warehouse FOREIGN KEY (to_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_inventory_lines_from_location FOREIGN KEY (from_location_id) REFERENCES locations(id),
    CONSTRAINT fk_inventory_lines_to_location FOREIGN KEY (to_location_id) REFERENCES locations(id),
    CONSTRAINT ck_inventory_line_qty CHECK (quantity_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    inventory_document_id BIGINT UNSIGNED NOT NULL,
    inventory_document_line_id BIGINT UNSIGNED NOT NULL,
    stock_unit_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    movement_type VARCHAR(40) NOT NULL,
    quantity_delta_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
    reserved_delta_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
    from_location_id BIGINT UNSIGNED NULL,
    to_location_id BIGINT UNSIGNED NULL,
    resulting_on_hand_kg DECIMAL(14,3) NOT NULL,
    resulting_reserved_kg DECIMAL(14,3) NOT NULL,
    occurred_at DATETIME NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inventory_movements_uuid (uuid),
    UNIQUE KEY uq_inventory_movement_line (inventory_document_line_id),
    KEY ix_inventory_ledger_stock_time (stock_unit_id, occurred_at, id),
    KEY ix_inventory_ledger_lot_time (lot_id, occurred_at),
    CONSTRAINT fk_movements_document FOREIGN KEY (inventory_document_id) REFERENCES inventory_documents(id),
    CONSTRAINT fk_movements_document_line FOREIGN KEY (inventory_document_line_id) REFERENCES inventory_document_lines(id),
    CONSTRAINT fk_movements_stock FOREIGN KEY (stock_unit_id) REFERENCES stock_units(id),
    CONSTRAINT fk_movements_lot FOREIGN KEY (lot_id) REFERENCES lots(id),
    CONSTRAINT fk_movements_from_location FOREIGN KEY (from_location_id) REFERENCES locations(id),
    CONSTRAINT fk_movements_to_location FOREIGN KEY (to_location_id) REFERENCES locations(id),
    CONSTRAINT ck_movement_result CHECK (resulting_on_hand_kg >= 0 AND resulting_reserved_kg >= 0 AND resulting_reserved_kg <= resulting_on_hand_kg)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE stock_unit_location_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_unit_id BIGINT UNSIGNED NOT NULL,
    inventory_movement_id BIGINT UNSIGNED NOT NULL,
    from_location_id BIGINT UNSIGNED NULL,
    to_location_id BIGINT UNSIGNED NOT NULL,
    moved_by BIGINT UNSIGNED NOT NULL,
    moved_at DATETIME NOT NULL,
    KEY ix_location_history_stock (stock_unit_id, moved_at),
    CONSTRAINT fk_location_history_stock FOREIGN KEY (stock_unit_id) REFERENCES stock_units(id),
    CONSTRAINT fk_location_history_movement FOREIGN KEY (inventory_movement_id) REFERENCES inventory_movements(id),
    CONSTRAINT fk_location_history_from FOREIGN KEY (from_location_id) REFERENCES locations(id),
    CONSTRAINT fk_location_history_to FOREIGN KEY (to_location_id) REFERENCES locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE raw_material_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    folio VARCHAR(50) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    destination VARCHAR(120) NOT NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    required_at DATETIME NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    requested_by BIGINT UNSIGNED NOT NULL,
    requested_at DATETIME NULL,
    authorized_by BIGINT UNSIGNED NULL,
    authorized_at DATETIME NULL,
    rejected_by BIGINT UNSIGNED NULL,
    rejected_at DATETIME NULL,
    rejection_reason VARCHAR(255) NULL,
    observations TEXT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_requests_uuid (uuid),
    UNIQUE KEY uq_requests_folio (folio),
    KEY ix_requests_status_date (company_id, site_id, status, required_at),
    CONSTRAINT fk_requests_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_requests_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE raw_material_request_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    species_id BIGINT UNSIGNED NOT NULL,
    size_grade_id BIGINT UNSIGNED NULL,
    quality_grade VARCHAR(20) NULL,
    requested_weight_kg DECIMAL(14,3) NOT NULL,
    reserved_weight_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
    fulfilled_weight_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_request_line (request_id, line_number),
    KEY ix_request_line_criteria (species_id, size_grade_id, quality_grade, status),
    CONSTRAINT fk_request_lines_request FOREIGN KEY (request_id) REFERENCES raw_material_requests(id),
    CONSTRAINT fk_request_lines_species FOREIGN KEY (species_id) REFERENCES species(id),
    CONSTRAINT fk_request_lines_size FOREIGN KEY (size_grade_id) REFERENCES size_grades(id),
    CONSTRAINT ck_request_line_weights CHECK (requested_weight_kg > 0 AND reserved_weight_kg >= 0 AND fulfilled_weight_kg >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    folio VARCHAR(50) NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    released_by BIGINT UNSIGNED NULL,
    released_at DATETIME NULL,
    release_reason VARCHAR(255) NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_reservations_uuid (uuid),
    UNIQUE KEY uq_reservations_folio (folio),
    KEY ix_reservations_request_status (request_id, status, expires_at),
    CONSTRAINT fk_reservations_request FOREIGN KEY (request_id) REFERENCES raw_material_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE reservation_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id BIGINT UNSIGNED NOT NULL,
    request_line_id BIGINT UNSIGNED NOT NULL,
    stock_unit_id BIGINT UNSIGNED NOT NULL,
    reserved_weight_kg DECIMAL(14,3) NOT NULL,
    fulfilled_weight_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_reservation_stock (reservation_id, stock_unit_id),
    KEY ix_reservation_lines_stock_status (stock_unit_id, status),
    CONSTRAINT fk_reservation_lines_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id),
    CONSTRAINT fk_reservation_lines_request_line FOREIGN KEY (request_line_id) REFERENCES raw_material_request_lines(id),
    CONSTRAINT fk_reservation_lines_stock FOREIGN KEY (stock_unit_id) REFERENCES stock_units(id),
    CONSTRAINT ck_reservation_line_weight CHECK (reserved_weight_kg > 0 AND fulfilled_weight_kg >= 0 AND fulfilled_weight_kg <= reserved_weight_kg)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    folio VARCHAR(50) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    origin_warehouse_id BIGINT UNSIGNED NOT NULL,
    destination_warehouse_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'requested',
    requested_by BIGINT UNSIGNED NOT NULL,
    authorized_by BIGINT UNSIGNED NULL,
    shipped_by BIGINT UNSIGNED NULL,
    received_by BIGINT UNSIGNED NULL,
    requested_at DATETIME NOT NULL,
    shipped_at DATETIME NULL,
    received_at DATETIME NULL,
    carrier_reference VARCHAR(100) NULL,
    observations TEXT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_transfers_uuid (uuid),
    UNIQUE KEY uq_transfers_folio (folio),
    KEY ix_transfers_status (company_id, status, requested_at),
    CONSTRAINT fk_transfers_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_transfers_origin FOREIGN KEY (origin_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_transfers_destination FOREIGN KEY (destination_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT ck_transfer_different_warehouses CHECK (origin_warehouse_id <> destination_warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE transfer_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    stock_unit_id BIGINT UNSIGNED NOT NULL,
    shipped_weight_kg DECIMAL(14,3) NOT NULL,
    received_weight_kg DECIMAL(14,3) NULL,
    difference_kg DECIMAL(14,3) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'prepared',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_transfer_line (transfer_id, line_number),
    UNIQUE KEY uq_transfer_stock (transfer_id, stock_unit_id),
    CONSTRAINT fk_transfer_lines_transfer FOREIGN KEY (transfer_id) REFERENCES transfers(id),
    CONSTRAINT fk_transfer_lines_stock FOREIGN KEY (stock_unit_id) REFERENCES stock_units(id),
    CONSTRAINT ck_transfer_line_weights CHECK (shipped_weight_kg > 0 AND (received_weight_kg IS NULL OR received_weight_kg >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE inventory_counts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    folio VARCHAR(50) NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    chamber_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planned',
    frozen_at DATETIME NULL,
    counted_at DATETIME NULL,
    reconciled_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    reconciled_by BIGINT UNSIGNED NULL,
    observations TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_inventory_counts_uuid (uuid),
    UNIQUE KEY uq_inventory_counts_folio (folio),
    CONSTRAINT fk_counts_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_counts_chamber FOREIGN KEY (chamber_id) REFERENCES chambers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE inventory_count_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_count_id BIGINT UNSIGNED NOT NULL,
    stock_unit_id BIGINT UNSIGNED NOT NULL,
    expected_location_id BIGINT UNSIGNED NOT NULL,
    counted_location_id BIGINT UNSIGNED NULL,
    expected_weight_kg DECIMAL(14,3) NOT NULL,
    expected_lock_version INT UNSIGNED NOT NULL,
    counted_weight_kg DECIMAL(14,3) NULL,
    result VARCHAR(30) NOT NULL DEFAULT 'pending',
    counted_by BIGINT UNSIGNED NULL,
    counted_at DATETIME NULL,
    notes VARCHAR(500) NULL,
    UNIQUE KEY uq_inventory_count_stock (inventory_count_id, stock_unit_id),
    CONSTRAINT fk_count_lines_count FOREIGN KEY (inventory_count_id) REFERENCES inventory_counts(id),
    CONSTRAINT fk_count_lines_stock FOREIGN KEY (stock_unit_id) REFERENCES stock_units(id),
    CONSTRAINT fk_count_lines_expected_location FOREIGN KEY (expected_location_id) REFERENCES locations(id),
    CONSTRAINT fk_count_lines_counted_location FOREIGN KEY (counted_location_id) REFERENCES locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE OR REPLACE VIEW v_available_stock AS
SELECT
    su.id,
    su.unit_code,
    su.company_id,
    su.site_id,
    su.warehouse_id,
    su.location_id,
    su.lot_id,
    su.species_id,
    su.size_grade_id,
    su.quality_grade,
    su.on_hand_weight_kg,
    su.reserved_weight_kg,
    (su.on_hand_weight_kg - su.reserved_weight_kg) AS available_weight_kg,
    su.received_at,
    su.lock_version
FROM stock_units su
WHERE su.status = 'available'
  AND su.on_hand_weight_kg > su.reserved_weight_kg
SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP VIEW IF EXISTS v_available_stock');

        foreach ([
            'inventory_count_lines', 'inventory_counts', 'transfer_lines', 'transfers', 'reservation_lines',
            'reservations', 'raw_material_request_lines', 'raw_material_requests', 'stock_unit_location_history',
            'inventory_movements', 'inventory_document_lines', 'stock_units', 'inventory_documents',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
