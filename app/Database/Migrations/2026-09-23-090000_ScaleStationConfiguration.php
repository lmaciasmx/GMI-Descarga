<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class ScaleStationConfiguration extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('station_code', "VARCHAR(50) NULL AFTER adapter_key");
        $this->addColumnIfMissing('model', "VARCHAR(80) NULL AFTER station_code");
        $this->addColumnIfMissing('agent_url', "VARCHAR(255) NULL AFTER model");
        $this->addColumnIfMissing('port_name', "VARCHAR(20) NULL AFTER agent_url");
        $this->addColumnIfMissing('baud_rate', "INT UNSIGNED NOT NULL DEFAULT 9600 AFTER port_name");
        $this->addColumnIfMissing('data_bits', "TINYINT UNSIGNED NOT NULL DEFAULT 8 AFTER baud_rate");
        $this->addColumnIfMissing('parity', "VARCHAR(10) NOT NULL DEFAULT 'none' AFTER data_bits");
        $this->addColumnIfMissing('stop_bits', "VARCHAR(10) NOT NULL DEFAULT '1' AFTER parity");
        $this->addColumnIfMissing('read_command', "VARCHAR(20) NULL DEFAULT 'P' AFTER stop_bits");
        $this->addColumnIfMissing('command_terminator', "VARCHAR(10) NOT NULL DEFAULT 'none' AFTER read_command");
        $this->addColumnIfMissing('response_timeout_ms', "INT UNSIGNED NOT NULL DEFAULT 2000 AFTER command_terminator");
        $this->addColumnIfMissing('settle_delay_ms', "INT UNSIGNED NOT NULL DEFAULT 250 AFTER response_timeout_ms");
        $this->addColumnIfMissing('stable_reads', "TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER settle_delay_ms");
        $this->addColumnIfMissing('stable_tolerance_kg', "DECIMAL(8,3) NOT NULL DEFAULT 0.050 AFTER stable_reads");
        $this->addColumnIfMissing('weight_regex', "VARCHAR(160) NOT NULL DEFAULT '[-+]?[0-9]+(?:[.,][0-9]+)?' AFTER stable_tolerance_kg");
        $this->addColumnIfMissing('unit', "VARCHAR(10) NOT NULL DEFAULT 'kg' AFTER weight_regex");
        $this->addColumnIfMissing('last_calibrated_on', "DATE NULL AFTER unit");
        $this->addColumnIfMissing('is_default', "TINYINT(1) NOT NULL DEFAULT 0 AFTER calibration_expires_on");
        $this->addColumnIfMissing('last_test_at', "DATETIME NULL AFTER is_default");
        $this->addColumnIfMissing('last_test_status', "VARCHAR(20) NOT NULL DEFAULT 'never' AFTER last_test_at");
        $this->addColumnIfMissing('last_test_message', "VARCHAR(255) NULL AFTER last_test_status");
        $this->addColumnIfMissing('last_read_weight_kg', "DECIMAL(12,3) NULL AFTER last_test_message");
        $this->addColumnIfMissing('last_raw_value', "VARCHAR(255) NULL AFTER last_read_weight_kg");

        // Conserva íntegros los registros existentes. Sólo completa valores neutros
        // en columnas nuevas; NO convierte simuladores ni activa hardware automáticamente.
        $this->db->query("UPDATE scales SET baud_rate=9600 WHERE baud_rate IS NULL OR baud_rate=0");
        $this->db->query("UPDATE scales SET data_bits=8 WHERE data_bits IS NULL OR data_bits=0");
        $this->db->query("UPDATE scales SET parity='none' WHERE parity IS NULL OR parity=''");
        $this->db->query("UPDATE scales SET stop_bits='1' WHERE stop_bits IS NULL OR stop_bits=''");
        $this->db->query("UPDATE scales SET unit='kg' WHERE unit IS NULL OR unit=''");
    }

    public function down(): void
    {
        // No se eliminan columnas de configuración de dispositivos en rollback
        // automático para no perder parámetros de estaciones ya configuradas.
    }

    private function addColumnIfMissing(string $name, string $definition): void
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            ['scales', $name]
        )->getRowArray();
        if ((int) ($row['n'] ?? 0) === 0) {
            $this->db->query("ALTER TABLE `scales` ADD COLUMN `{$name}` {$definition}");
        }
    }
}
