<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateReportExportHistory extends Migration
{
    private string $table = 'report_export_history';

    public function up()
    {
        if ($this->db->tableExists($this->table)) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'company_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
            ],
            'site_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => true,
            ],
            'warehouse_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => true,
            ],
            'report_key' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
            ],
            'report_title' => [
                'type' => 'VARCHAR',
                'constraint' => 160,
            ],
            'format' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
            ],
            'date_from' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'date_to' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'filters_json' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'row_count' => [
                'type' => 'INT',
                'unsigned' => true,
                'default' => 0,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'processing',
            ],
            'error_message' => [
                'type' => 'VARCHAR',
                'constraint' => 500,
                'null' => true,
            ],
            'user_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => true,
            ],
            'user_name' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
            'generated_at' => [
                'type' => 'DATETIME',
            ],
            'completed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['company_id', 'site_id', 'warehouse_id'], false, false, 'idx_report_export_history_scope');
        $this->forge->addKey(['report_key', 'format'], false, false, 'idx_report_export_history_report');
        $this->forge->addKey('generated_at', false, false, 'idx_report_export_history_generated');
        $this->forge->addKey('status', false, false, 'idx_report_export_history_status');

        $this->forge->createTable($this->table, true);
    }

    public function down()
    {
        if ($this->db->tableExists($this->table)) {
            $this->forge->dropTable($this->table, true);
        }
    }
}
