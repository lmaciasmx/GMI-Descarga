<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrganizationAndCatalogs extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
CREATE TABLE companies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    legal_name VARCHAR(180) NOT NULL,
    trade_name VARCHAR(120) NOT NULL,
    tax_id VARCHAR(20) NULL,
    company_type VARCHAR(30) NOT NULL DEFAULT 'owner',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_companies_code (code),
    KEY ix_companies_name (trade_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE sites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(120) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Mexico_City',
    address VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_sites_company_code (company_id, code),
    CONSTRAINT fk_sites_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE warehouses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(120) NOT NULL,
    warehouse_type VARCHAR(30) NOT NULL DEFAULT 'internal',
    external_company_id BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_warehouses_site_code (site_id, code),
    KEY ix_warehouses_company (company_id, active),
    CONSTRAINT fk_warehouses_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_warehouses_site FOREIGN KEY (site_id) REFERENCES sites(id),
    CONSTRAINT fk_warehouses_external FOREIGN KEY (external_company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE chambers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    min_temperature_c DECIMAL(5,2) NULL,
    max_temperature_c DECIMAL(5,2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_chambers_warehouse_code (warehouse_id, code),
    CONSTRAINT fk_chambers_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    chamber_id BIGINT UNSIGNED NULL,
    parent_id BIGINT UNSIGNED NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    location_type VARCHAR(30) NOT NULL,
    path VARCHAR(255) NOT NULL,
    max_units INT UNSIGNED NULL,
    max_weight_kg DECIMAL(14,3) NULL,
    segregation_rule VARCHAR(50) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_locations_warehouse_code (warehouse_id, code),
    KEY ix_locations_path (warehouse_id, path),
    CONSTRAINT fk_locations_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_locations_chamber FOREIGN KEY (chamber_id) REFERENCES chambers(id),
    CONSTRAINT fk_locations_parent FOREIGN KEY (parent_id) REFERENCES locations(id),
    CONSTRAINT ck_locations_capacity CHECK (max_units IS NULL OR max_units > 0),
    CONSTRAINT ck_locations_weight CHECK (max_weight_kg IS NULL OR max_weight_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE vessels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(12) NOT NULL,
    name VARCHAR(120) NOT NULL,
    registration_number VARCHAR(60) NULL,
    flag_country CHAR(2) NULL,
    capacity_tons DECIMAL(12,3) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_vessels_code (code),
    KEY ix_vessels_owner (owner_company_id, active),
    CONSTRAINT fk_vessels_owner FOREIGN KEY (owner_company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE vessel_tanks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vessel_id BIGINT UNSIGNED NOT NULL,
    tank_number VARCHAR(20) NOT NULL,
    side VARCHAR(20) NULL,
    capacity_kg DECIMAL(14,3) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_vessel_tank (vessel_id, tank_number),
    KEY ix_vessel_tanks_active (vessel_id, active),
    CONSTRAINT fk_vessel_tanks_vessel FOREIGN KEY (vessel_id) REFERENCES vessels(id),
    CONSTRAINT ck_vessel_tank_capacity CHECK (capacity_kg IS NULL OR capacity_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE species (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(12) NOT NULL,
    name VARCHAR(100) NOT NULL,
    scientific_name VARCHAR(150) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_species_code (code),
    UNIQUE KEY uq_species_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE size_grades (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    species_id BIGINT UNSIGNED NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(80) NOT NULL,
    min_kg DECIMAL(10,3) NULL,
    max_kg DECIMAL(10,3) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_size_species_code (species_id, code),
    CONSTRAINT fk_size_species FOREIGN KEY (species_id) REFERENCES species(id),
    CONSTRAINT ck_size_range CHECK (min_kg IS NULL OR max_kg IS NULL OR min_kg <= max_kg)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE voyages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    vessel_id BIGINT UNSIGNED NOT NULL,
    receiving_warehouse_id BIGINT UNSIGNED NULL,
    annual_number INT UNSIGNED NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    port_name VARCHAR(120) NULL,
    arrival_at DATETIME NULL,
    unloading_started_at DATETIME NULL,
    unloading_finished_at DATETIME NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planned',
    notes TEXT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_voyages_uuid (uuid),
    UNIQUE KEY uq_voyages_vessel_year_number (vessel_id, year, annual_number),
    KEY ix_voyages_active (company_id, site_id, status, arrival_at),
    CONSTRAINT fk_voyages_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_voyages_site FOREIGN KEY (site_id) REFERENCES sites(id),
    CONSTRAINT fk_voyages_vessel FOREIGN KEY (vessel_id) REFERENCES vessels(id),
    CONSTRAINT fk_voyages_receiving_warehouse FOREIGN KEY (receiving_warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE voyage_tanks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    voyage_id BIGINT UNSIGNED NOT NULL,
    vessel_tank_id BIGINT UNSIGNED NULL,
    tank_number VARCHAR(20) NOT NULL,
    side VARCHAR(20) NULL,
    capacity_kg DECIMAL(14,3) NULL,
    planned_weight_kg DECIMAL(14,3) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_voyage_tank (voyage_id, tank_number),
    CONSTRAINT fk_voyage_tanks_voyage FOREIGN KEY (voyage_id) REFERENCES voyages(id),
    CONSTRAINT fk_voyage_tanks_master FOREIGN KEY (vessel_tank_id) REFERENCES vessel_tanks(id),
    CONSTRAINT ck_voyage_tank_capacity CHECK (capacity_kg IS NULL OR capacity_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE tubs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    barcode VARCHAR(80) NULL,
    current_tare_kg DECIMAL(10,3) NOT NULL,
    tare_verified_at DATETIME NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'available',
    active TINYINT(1) NOT NULL DEFAULT 1,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_tubs_company_code (company_id, code),
    UNIQUE KEY uq_tubs_barcode (barcode),
    CONSTRAINT fk_tubs_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT ck_tubs_tare CHECK (current_tare_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE scales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    connection_type VARCHAR(30) NOT NULL,
    adapter_key VARCHAR(40) NOT NULL,
    configuration JSON NULL,
    calibration_expires_on DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_scales_site_code (site_id, code),
    CONSTRAINT fk_scales_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE printers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    printer_type VARCHAR(30) NOT NULL,
    adapter_key VARCHAR(40) NOT NULL,
    configuration JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_printers_site_code (site_id, code),
    CONSTRAINT fk_printers_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE rsa_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    voyage_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(80) NOT NULL,
    issued_on DATE NULL,
    expires_on DATE NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    document_path VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_rsa_code (code),
    KEY ix_rsa_voyage (voyage_id, status),
    CONSTRAINT fk_rsa_voyage FOREIGN KEY (voyage_id) REFERENCES voyages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE state_catalog (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    aggregate VARCHAR(50) NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_terminal TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_state_aggregate_code (aggregate, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE operational_user_scopes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NULL,
    warehouse_id BIGINT UNSIGNED NULL,
    station_code VARCHAR(40) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_scope (user_id, company_id, site_id, warehouse_id, station_code),
    KEY ix_scopes_user (user_id, active),
    CONSTRAINT fk_scopes_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_scopes_site FOREIGN KEY (site_id) REFERENCES sites(id),
    CONSTRAINT fk_scopes_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE system_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    site_id BIGINT UNSIGNED NULL,
    setting_key VARCHAR(100) NOT NULL,
    value_type VARCHAR(20) NOT NULL DEFAULT 'string',
    setting_value TEXT NOT NULL,
    description VARCHAR(255) NULL,
    is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_settings_scope_key (company_id, site_id, setting_key),
    CONSTRAINT fk_settings_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_settings_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
    }

    public function down(): void
    {
        foreach ([
            'system_settings', 'operational_user_scopes', 'state_catalog', 'rsa_records', 'printers',
            'scales', 'tubs', 'voyage_tanks', 'voyages', 'size_grades', 'species', 'vessel_tanks', 'vessels',
            'locations', 'chambers', 'warehouses', 'sites', 'companies',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
