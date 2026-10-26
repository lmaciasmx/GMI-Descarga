<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class PrinterStationConfiguration extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('connection_mode', "VARCHAR(30) NOT NULL DEFAULT 'local_agent' AFTER adapter_key");
        $this->addColumnIfMissing('station_code', "VARCHAR(50) NULL AFTER connection_mode");
        $this->addColumnIfMissing('model', "VARCHAR(80) NULL AFTER station_code");
        $this->addColumnIfMissing('windows_printer_name', "VARCHAR(160) NULL AFTER model");
        $this->addColumnIfMissing('agent_url', "VARCHAR(255) NULL AFTER windows_printer_name");
        $this->addColumnIfMissing('network_host', "VARCHAR(100) NULL AFTER agent_url");
        $this->addColumnIfMissing('network_port', "INT UNSIGNED NULL AFTER network_host");
        $this->addColumnIfMissing('printer_language', "VARCHAR(20) NOT NULL DEFAULT 'zpl' AFTER network_port");
        $this->addColumnIfMissing('dpi', "SMALLINT UNSIGNED NOT NULL DEFAULT 203 AFTER printer_language");
        $this->addColumnIfMissing('label_width_mm', "DECIMAL(7,2) NOT NULL DEFAULT 101.60 AFTER dpi");
        $this->addColumnIfMissing('label_height_mm', "DECIMAL(7,2) NOT NULL DEFAULT 152.40 AFTER label_width_mm");
        $this->addColumnIfMissing('darkness', "TINYINT UNSIGNED NULL DEFAULT 10 AFTER label_height_mm");
        $this->addColumnIfMissing('speed_ips', "DECIMAL(4,1) NULL DEFAULT 3.0 AFTER darkness");
        $this->addColumnIfMissing('copies', "TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER speed_ips");
        $this->addColumnIfMissing('is_default', "TINYINT(1) NOT NULL DEFAULT 0 AFTER copies");
        $this->addColumnIfMissing('last_test_at', "DATETIME NULL AFTER is_default");
        $this->addColumnIfMissing('last_test_status', "VARCHAR(20) NOT NULL DEFAULT 'never' AFTER last_test_at");
        $this->addColumnIfMissing('last_test_message', "VARCHAR(255) NULL AFTER last_test_status");

        // Conserva compatibilidad con registros existentes.
        $this->db->query("UPDATE printers SET connection_mode = CASE WHEN adapter_key IN ('middleware','zpl') THEN 'local_agent' WHEN adapter_key='browser' THEN 'browser' ELSE 'pdf' END");
        $this->db->query("UPDATE printers SET agent_url = 'http://127.0.0.1:18181' WHERE connection_mode='local_agent' AND (agent_url IS NULL OR agent_url='')");
        $this->db->query("UPDATE printers SET model = 'Honeywell / Intermec PC43t' WHERE connection_mode='local_agent' AND model IS NULL");
    }

    public function down(): void
    {
        // No se eliminan columnas de configuración de dispositivos en rollback automático
        // para no perder parámetros de estaciones ya configuradas.
    }

    private function addColumnIfMissing(string $name, string $definition): void
    {
        if (! $this->db->fieldExists($name, 'printers')) {
            $this->db->query("ALTER TABLE printers ADD COLUMN {$name} {$definition}");
        }
    }
}
