<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class LinkRequestsToTransfers extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('request_type', 'raw_material_requests')) {
            $this->db->query("ALTER TABLE raw_material_requests ADD COLUMN request_type VARCHAR(30) NOT NULL DEFAULT 'production' AFTER site_id");
        }
        if (! $this->db->fieldExists('destination_warehouse_id', 'raw_material_requests')) {
            $this->db->query('ALTER TABLE raw_material_requests ADD COLUMN destination_warehouse_id BIGINT UNSIGNED NULL AFTER request_type');
        }
        $this->addIndexIfMissing('raw_material_requests', 'ix_requests_destination_warehouse', 'ALTER TABLE raw_material_requests ADD KEY ix_requests_destination_warehouse (destination_warehouse_id)');
        $this->addForeignKeyIfMissing(
            'raw_material_requests',
            'fk_requests_destination_warehouse',
            'ALTER TABLE raw_material_requests ADD CONSTRAINT fk_requests_destination_warehouse FOREIGN KEY (destination_warehouse_id) REFERENCES warehouses(id)'
        );

        if (! $this->db->fieldExists('transfer_type', 'transfers')) {
            $this->db->query("ALTER TABLE transfers ADD COLUMN transfer_type VARCHAR(30) NOT NULL DEFAULT 'administrative' AFTER destination_warehouse_id");
        }
        if (! $this->db->fieldExists('request_id', 'transfers')) {
            $this->db->query('ALTER TABLE transfers ADD COLUMN request_id BIGINT UNSIGNED NULL AFTER transfer_type');
        }
        if (! $this->db->fieldExists('reservation_id', 'transfers')) {
            $this->db->query('ALTER TABLE transfers ADD COLUMN reservation_id BIGINT UNSIGNED NULL AFTER request_id');
        }
        $this->addIndexIfMissing('transfers', 'ix_transfers_request', 'ALTER TABLE transfers ADD KEY ix_transfers_request (request_id, status)');
        $this->addIndexIfMissing('transfers', 'ix_transfers_reservation', 'ALTER TABLE transfers ADD KEY ix_transfers_reservation (reservation_id, status)');
        $this->addForeignKeyIfMissing(
            'transfers',
            'fk_transfers_request',
            'ALTER TABLE transfers ADD CONSTRAINT fk_transfers_request FOREIGN KEY (request_id) REFERENCES raw_material_requests(id)'
        );
        $this->addForeignKeyIfMissing(
            'transfers',
            'fk_transfers_reservation',
            'ALTER TABLE transfers ADD CONSTRAINT fk_transfers_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id)'
        );
    }

    public function down(): void
    {
        // Deliberadamente conservador: una vez que existan traspasos ligados,
        // eliminar columnas rompería trazabilidad. El rollback operativo restaura
        // código; el esquema aditivo puede permanecer sin afectar el código anterior.
    }

    private function addIndexIfMissing(string $table, string $index, string $sql): void
    {
        $row = $this->db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index])->getRowArray();
        if ($row === null) {
            $this->db->query($sql);
        }
    }

    private function addForeignKeyIfMissing(string $table, string $constraint, string $sql): void
    {
        $row = $this->db->query(
            'SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint]
        )->getRowArray();
        if ($row === null) {
            $this->db->query($sql);
        }
    }
}
