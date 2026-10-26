<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCommercialAuditAndIntegrations extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
CREATE TABLE sales_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    folio VARCHAR(50) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    customer_company_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    required_at DATETIME NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    authorized_by BIGINT UNSIGNED NULL,
    dispatched_by BIGINT UNSIGNED NULL,
    dispatched_at DATETIME NULL,
    observations TEXT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_sales_orders_uuid (uuid),
    UNIQUE KEY uq_sales_orders_folio (folio),
    KEY ix_sales_orders_status (company_id, site_id, status, required_at),
    CONSTRAINT fk_sales_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_sales_site FOREIGN KEY (site_id) REFERENCES sites(id),
    CONSTRAINT fk_sales_customer FOREIGN KEY (customer_company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE sales_order_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_order_id BIGINT UNSIGNED NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    stock_unit_id BIGINT UNSIGNED NOT NULL,
    quantity_kg DECIMAL(14,3) NOT NULL,
    unit_price DECIMAL(14,4) NULL,
    currency CHAR(3) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'allocated',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_sales_order_line (sales_order_id, line_number),
    UNIQUE KEY uq_sales_stock (sales_order_id, stock_unit_id),
    CONSTRAINT fk_sales_lines_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id),
    CONSTRAINT fk_sales_lines_stock FOREIGN KEY (stock_unit_id) REFERENCES stock_units(id),
    CONSTRAINT ck_sales_line_quantity CHECK (quantity_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE billing_references (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    sales_order_id BIGINT UNSIGNED NULL,
    external_system VARCHAR(40) NOT NULL DEFAULT 'INVoiice',
    external_document_id VARCHAR(120) NOT NULL,
    document_type VARCHAR(30) NOT NULL,
    fiscal_uuid CHAR(36) NULL,
    fiscal_status VARCHAR(30) NOT NULL,
    issued_at DATETIME NULL,
    canceled_at DATETIME NULL,
    payload_fingerprint CHAR(64) NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_billing_external_document (external_system, external_document_id),
    UNIQUE KEY uq_billing_fiscal_uuid (fiscal_uuid),
    KEY ix_billing_scope (company_id, site_id, fiscal_status, updated_at),
    KEY ix_billing_sales_order (sales_order_id, fiscal_status),
    CONSTRAINT fk_billing_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_billing_site FOREIGN KEY (site_id) REFERENCES sites(id),
    CONSTRAINT fk_billing_sales_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE api_clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    permissions JSON NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    site_id BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_api_clients_client_id (client_id),
    CONSTRAINT fk_api_clients_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_api_clients_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE api_idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_hash CHAR(64) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    owner_key VARCHAR(191) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    method VARCHAR(10) NOT NULL,
    path VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL,
    response_status SMALLINT UNSIGNED NULL,
    response_body MEDIUMTEXT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_idempotency_request_hash (request_hash),
    KEY ix_idempotency_owner_key (owner_key, idempotency_key),
    KEY ix_idempotency_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE scale_readings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    scale_id BIGINT UNSIGNED NULL,
    weighing_id BIGINT UNSIGNED NULL,
    weight DECIMAL(14,3) NOT NULL,
    unit VARCHAR(10) NOT NULL,
    stable TINYINT(1) NOT NULL,
    device_timestamp DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    original_value VARCHAR(255) NOT NULL,
    original_hash CHAR(64) NOT NULL,
    connection_status VARCHAR(30) NOT NULL,
    source VARCHAR(30) NOT NULL,
    device_identifier VARCHAR(100) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_reference VARCHAR(100) NULL,
    UNIQUE KEY uq_scale_readings_uuid (uuid),
    UNIQUE KEY uq_scale_reading_dedup (scale_id, original_hash, device_timestamp),
    KEY ix_scale_readings_time (scale_id, received_at),
    KEY ix_scale_readings_unclaimed (scale_id, weighing_id, received_at),
    CONSTRAINT fk_scale_readings_scale FOREIGN KEY (scale_id) REFERENCES scales(id),
    CONSTRAINT fk_scale_readings_weighing FOREIGN KEY (weighing_id) REFERENCES weighings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
ALTER TABLE weighings
    ADD CONSTRAINT fk_weighings_scale_reading
    FOREIGN KEY (scale_reading_id) REFERENCES scale_readings(id)
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE integration_endpoints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(120) NOT NULL,
    integration_type VARCHAR(40) NOT NULL,
    base_url VARCHAR(255) NULL,
    secret_reference VARCHAR(120) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    configuration JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_integration_endpoint_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE integration_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    endpoint_id BIGINT UNSIGNED NULL,
    direction VARCHAR(20) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    aggregate_type VARCHAR(80) NULL,
    aggregate_id VARCHAR(80) NULL,
    correlation_id VARCHAR(100) NULL,
    idempotency_key VARCHAR(128) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    payload MEDIUMTEXT NULL,
    payload_hash CHAR(64) NULL,
    response_code SMALLINT UNSIGNED NULL,
    error_summary VARCHAR(500) NULL,
    occurred_at DATETIME NOT NULL,
    next_attempt_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_integration_events_uuid (uuid),
    UNIQUE KEY uq_integration_idempotency (endpoint_id, idempotency_key),
    KEY ix_integration_dispatch (status, next_attempt_at),
    KEY ix_integration_aggregate (aggregate_type, aggregate_id),
    CONSTRAINT fk_integration_events_endpoint FOREIGN KEY (endpoint_id) REFERENCES integration_endpoints(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_type VARCHAR(30) NOT NULL DEFAULT 'user',
    actor_reference VARCHAR(100) NULL,
    action VARCHAR(80) NOT NULL,
    aggregate_type VARCHAR(80) NOT NULL,
    aggregate_id VARCHAR(80) NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    site_id BIGINT UNSIGNED NULL,
    before_data JSON NULL,
    after_data JSON NULL,
    reason VARCHAR(500) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    correlation_id VARCHAR(100) NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_audit_logs_uuid (uuid),
    KEY ix_audit_aggregate (aggregate_type, aggregate_id, occurred_at),
    KEY ix_audit_actor (actor_user_id, actor_reference, occurred_at),
    KEY ix_audit_scope (company_id, site_id, occurred_at),
    CONSTRAINT fk_audit_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_audit_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(60) NOT NULL,
    severity VARCHAR(20) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    outcome VARCHAR(30) NOT NULL,
    safe_context JSON NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_security_type_time (event_type, occurred_at),
    KEY ix_security_severity_time (severity, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE import_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    source_type VARCHAR(30) NOT NULL DEFAULT 'excel',
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NOT NULL,
    target_type VARCHAR(60) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'uploaded',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
    invalid_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL,
    validated_at DATETIME NULL,
    imported_at DATETIME NULL,
    UNIQUE KEY uq_import_batches_uuid (uuid),
    UNIQUE KEY uq_import_batches_hash_target (file_hash, target_type),
    KEY ix_import_batches_status (status, uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE import_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    raw_data JSON NOT NULL,
    normalized_data JSON NULL,
    validation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    validation_errors JSON NULL,
    imported_entity_type VARCHAR(80) NULL,
    imported_entity_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_import_row (import_batch_id, source_row_number),
    KEY ix_import_rows_status (import_batch_id, validation_status),
    CONSTRAINT fk_import_rows_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE weighings DROP FOREIGN KEY fk_weighings_scale_reading');

        foreach ([
            'import_rows', 'import_batches', 'security_events', 'audit_logs', 'integration_events',
            'integration_endpoints', 'scale_readings', 'api_idempotency_keys', 'api_clients', 'billing_references',
            'sales_order_lines', 'sales_orders',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
