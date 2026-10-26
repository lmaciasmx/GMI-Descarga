<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOperationalFlow extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
CREATE TABLE discharge_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    folio VARCHAR(40) NOT NULL,
    voyage_id BIGINT UNSIGNED NOT NULL,
    rsa_id BIGINT UNSIGNED NULL,
    planned_start_at DATETIME NULL,
    planned_end_at DATETIME NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    notes TEXT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_discharge_plans_uuid (uuid),
    UNIQUE KEY uq_discharge_plans_folio (folio),
    KEY ix_discharge_plans_voyage_status (voyage_id, status),
    CONSTRAINT fk_plans_voyage FOREIGN KEY (voyage_id) REFERENCES voyages(id),
    CONSTRAINT fk_plans_rsa FOREIGN KEY (rsa_id) REFERENCES rsa_records(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE discharge_plan_tanks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    discharge_plan_id BIGINT UNSIGNED NOT NULL,
    voyage_tank_id BIGINT UNSIGNED NOT NULL,
    species_id BIGINT UNSIGNED NOT NULL,
    size_grade_id BIGINT UNSIGNED NULL,
    planned_weight_kg DECIMAL(14,3) NULL,
    msc_code VARCHAR(12) NOT NULL,
    dolphin_safe_code VARCHAR(12) NOT NULL,
    freezing_temperature_c DECIMAL(6,2) NOT NULL,
    sequence_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_plan_tank_product (discharge_plan_id, voyage_tank_id, species_id, size_grade_id),
    KEY ix_plan_tanks_sequence (discharge_plan_id, sequence_number),
    CONSTRAINT fk_plan_tanks_plan FOREIGN KEY (discharge_plan_id) REFERENCES discharge_plans(id),
    CONSTRAINT fk_plan_tanks_tank FOREIGN KEY (voyage_tank_id) REFERENCES voyage_tanks(id),
    CONSTRAINT fk_plan_tanks_species FOREIGN KEY (species_id) REFERENCES species(id),
    CONSTRAINT fk_plan_tanks_size FOREIGN KEY (size_grade_id) REFERENCES size_grades(id),
    CONSTRAINT ck_plan_tank_weight CHECK (planned_weight_kg IS NULL OR planned_weight_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE tub_fillings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    discharge_plan_id BIGINT UNSIGNED NOT NULL,
    tub_id BIGINT UNSIGNED NOT NULL,
    actual_voyage_tank_id BIGINT UNSIGNED NOT NULL,
    species_id BIGINT UNSIGNED NOT NULL,
    size_grade_id BIGINT UNSIGNED NOT NULL,
    sorter_user_id BIGINT UNSIGNED NULL,
    labeler_user_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'filling',
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    observations TEXT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_tub_fillings_uuid (uuid),
    KEY ix_tub_fillings_plan_status (discharge_plan_id, status),
    KEY ix_tub_fillings_tub_status (tub_id, status),
    CONSTRAINT fk_fillings_plan FOREIGN KEY (discharge_plan_id) REFERENCES discharge_plans(id),
    CONSTRAINT fk_fillings_tub FOREIGN KEY (tub_id) REFERENCES tubs(id),
    CONSTRAINT fk_fillings_actual_tank FOREIGN KEY (actual_voyage_tank_id) REFERENCES voyage_tanks(id),
    CONSTRAINT fk_fillings_species FOREIGN KEY (species_id) REFERENCES species(id),
    CONSTRAINT fk_fillings_size FOREIGN KEY (size_grade_id) REFERENCES size_grades(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE filling_source_tanks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tub_filling_id BIGINT UNSIGNED NOT NULL,
    voyage_tank_id BIGINT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_filling_source_tank (tub_filling_id, voyage_tank_id),
    KEY ix_source_tanks_tank (voyage_tank_id),
    CONSTRAINT fk_source_tanks_filling FOREIGN KEY (tub_filling_id) REFERENCES tub_fillings(id),
    CONSTRAINT fk_source_tanks_tank FOREIGN KEY (voyage_tank_id) REFERENCES voyage_tanks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE lots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    code VARCHAR(100) NOT NULL,
    lot_type VARCHAR(30) NOT NULL DEFAULT 'discharge',
    voyage_id BIGINT UNSIGNED NOT NULL,
    vessel_code VARCHAR(12) NOT NULL,
    species_id BIGINT UNSIGNED NOT NULL,
    species_code VARCHAR(12) NOT NULL,
    annual_voyage_number INT UNSIGNED NOT NULL,
    period_component VARCHAR(8) NOT NULL,
    year_component CHAR(2) NOT NULL,
    certification_component VARCHAR(12) NULL,
    rule_version INT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    UNIQUE KEY uq_lots_uuid (uuid),
    UNIQUE KEY uq_lots_code_version (code, rule_version),
    KEY ix_lots_traceability (voyage_id, species_id, status),
    CONSTRAINT fk_lots_voyage FOREIGN KEY (voyage_id) REFERENCES voyages(id),
    CONSTRAINT fk_lots_species FOREIGN KEY (species_id) REFERENCES species(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE weighings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    folio VARCHAR(50) NOT NULL,
    tub_filling_id BIGINT UNSIGNED NOT NULL,
    tub_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    rsa_id BIGINT UNSIGNED NULL,
    scale_id BIGINT UNSIGNED NULL,
    scale_reading_id BIGINT UNSIGNED NULL,
    gross_weight_kg DECIMAL(14,3) NOT NULL,
    applied_tare_kg DECIMAL(10,3) NOT NULL,
    tare_source VARCHAR(30) NOT NULL,
    physical_net_kg DECIMAL(14,3) NOT NULL,
    penalty_percent DECIMAL(6,3) NOT NULL DEFAULT 0,
    penalty_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
    accepted_net_kg DECIMAL(14,3) NOT NULL,
    weight_source VARCHAR(30) NOT NULL,
    stable TINYINT(1) NOT NULL DEFAULT 0,
    original_value VARCHAR(255) NULL,
    device_timestamp DATETIME NULL,
    reason_code VARCHAR(50) NULL,
    observations TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    is_current TINYINT(1) NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    reversed_by BIGINT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    reversal_reason VARCHAR(255) NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_weighings_uuid (uuid),
    UNIQUE KEY uq_weighings_folio (folio),
    UNIQUE KEY uq_weighings_current_filling (tub_filling_id, is_current),
    KEY ix_weighings_lot_status (lot_id, status, confirmed_at),
    KEY ix_weighings_tub (tub_id, confirmed_at),
    KEY ix_weighings_scale_reading (scale_reading_id),
    CONSTRAINT fk_weighings_filling FOREIGN KEY (tub_filling_id) REFERENCES tub_fillings(id),
    CONSTRAINT fk_weighings_tub FOREIGN KEY (tub_id) REFERENCES tubs(id),
    CONSTRAINT fk_weighings_lot FOREIGN KEY (lot_id) REFERENCES lots(id),
    CONSTRAINT fk_weighings_rsa FOREIGN KEY (rsa_id) REFERENCES rsa_records(id),
    CONSTRAINT fk_weighings_scale FOREIGN KEY (scale_id) REFERENCES scales(id),
    CONSTRAINT ck_weighings_weights CHECK (gross_weight_kg > 0 AND applied_tare_kg > 0 AND physical_net_kg > 0 AND accepted_net_kg >= 0),
    CONSTRAINT ck_weighings_penalty CHECK (penalty_percent >= 0 AND penalty_percent <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE label_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(100) NOT NULL,
    version INT UNSIGNED NOT NULL,
    format VARCHAR(20) NOT NULL,
    template_body MEDIUMTEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_label_template_version (code, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE labels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    code VARCHAR(100) NOT NULL,
    weighing_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    qr_payload TEXT NOT NULL,
    barcode_value VARCHAR(120) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'generated',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    canceled_at DATETIME NULL,
    UNIQUE KEY uq_labels_uuid (uuid),
    UNIQUE KEY uq_labels_code (code),
    UNIQUE KEY uq_labels_weighing (weighing_id),
    CONSTRAINT fk_labels_weighing FOREIGN KEY (weighing_id) REFERENCES weighings(id),
    CONSTRAINT fk_labels_template FOREIGN KEY (template_id) REFERENCES label_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE print_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    label_id BIGINT UNSIGNED NOT NULL,
    printer_id BIGINT UNSIGNED NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    template_version INT UNSIGNED NOT NULL,
    print_number INT UNSIGNED NOT NULL,
    is_reprint TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NULL,
    result VARCHAR(30) NOT NULL,
    provider_reference VARCHAR(120) NULL,
    output_path VARCHAR(255) NULL,
    error_message VARCHAR(500) NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_print_jobs_uuid (uuid),
    UNIQUE KEY uq_label_print_number (label_id, print_number),
    KEY ix_print_jobs_result (result, requested_at),
    CONSTRAINT fk_print_jobs_label FOREIGN KEY (label_id) REFERENCES labels(id),
    CONSTRAINT fk_print_jobs_printer FOREIGN KEY (printer_id) REFERENCES printers(id),
    CONSTRAINT fk_print_jobs_template FOREIGN KEY (template_id) REFERENCES label_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE quality_defects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(120) NOT NULL,
    default_destination VARCHAR(40) NULL,
    requires_hold TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_quality_defects_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE quality_inspections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    weighing_id BIGINT UNSIGNED NOT NULL,
    quality_grade VARCHAR(20) NULL,
    defect_percent DECIMAL(6,3) NULL,
    temperature_c DECIMAL(6,2) NULL,
    disposition VARCHAR(40) NOT NULL DEFAULT 'pending',
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    observations TEXT NULL,
    inspected_by BIGINT UNSIGNED NOT NULL,
    inspected_at DATETIME NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    annulled_by BIGINT UNSIGNED NULL,
    annulled_at DATETIME NULL,
    annul_reason VARCHAR(255) NULL,
    supersedes_inspection_id BIGINT UNSIGNED NULL,
    superseded_by_inspection_id BIGINT UNSIGNED NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_quality_inspections_uuid (uuid),
    KEY ix_quality_weighing (weighing_id),
    KEY ix_quality_status (status, disposition),
    KEY ix_quality_annulled (status, annulled_at),
    KEY ix_quality_supersedes (supersedes_inspection_id),
    KEY ix_quality_superseded_by (superseded_by_inspection_id),
    CONSTRAINT fk_quality_weighing FOREIGN KEY (weighing_id) REFERENCES weighings(id),
    CONSTRAINT ck_quality_percent CHECK (defect_percent IS NULL OR (defect_percent >= 0 AND defect_percent <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE quality_inspection_defects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quality_inspection_id BIGINT UNSIGNED NOT NULL,
    defect_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12,3) NULL,
    unit VARCHAR(20) NULL,
    notes VARCHAR(255) NULL,
    UNIQUE KEY uq_inspection_defect (quality_inspection_id, defect_id),
    CONSTRAINT fk_inspection_defects_inspection FOREIGN KEY (quality_inspection_id) REFERENCES quality_inspections(id),
    CONSTRAINT fk_inspection_defects_defect FOREIGN KEY (defect_id) REFERENCES quality_defects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE receptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    folio VARCHAR(50) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    received_by BIGINT UNSIGNED NOT NULL,
    received_at DATETIME NULL,
    observations TEXT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_receptions_uuid (uuid),
    UNIQUE KEY uq_receptions_folio (folio),
    KEY ix_receptions_warehouse_status (warehouse_id, status, received_at),
    CONSTRAINT fk_receptions_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_receptions_site FOREIGN KEY (site_id) REFERENCES sites(id),
    CONSTRAINT fk_receptions_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE reception_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reception_id BIGINT UNSIGNED NOT NULL,
    weighing_id BIGINT UNSIGNED NOT NULL,
    label_id BIGINT UNSIGNED NOT NULL,
    destination_location_id BIGINT UNSIGNED NULL,
    accepted_weight_kg DECIMAL(14,3) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'received',
    observations VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reception_weighing (weighing_id),
    UNIQUE KEY uq_reception_label (label_id),
    KEY ix_reception_lines_reception (reception_id),
    CONSTRAINT fk_reception_lines_reception FOREIGN KEY (reception_id) REFERENCES receptions(id),
    CONSTRAINT fk_reception_lines_weighing FOREIGN KEY (weighing_id) REFERENCES weighings(id),
    CONSTRAINT fk_reception_lines_label FOREIGN KEY (label_id) REFERENCES labels(id),
    CONSTRAINT fk_reception_lines_location FOREIGN KEY (destination_location_id) REFERENCES locations(id),
    CONSTRAINT ck_reception_weight CHECK (accepted_weight_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
    }

    public function down(): void
    {
        foreach ([
            'reception_lines', 'receptions', 'quality_inspection_defects', 'quality_inspections',
            'quality_defects', 'print_jobs', 'labels', 'label_templates', 'weighings', 'lots',
            'filling_source_tanks', 'tub_fillings', 'discharge_plan_tanks', 'discharge_plans',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
