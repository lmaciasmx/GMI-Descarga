<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateLocationRestrictions extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'location_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'restriction_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],

            'reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
            ],

            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],

            'created_by' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'created_at' => [
                'type'    => 'DATETIME',
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
            ],

            'released_by' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],

            'released_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->addKey('location_id');
        $this->forge->addKey(['location_id', 'active']);
        $this->forge->addKey(['restriction_type', 'active']);

        $this->forge->addForeignKey(
            'location_id',
            'locations',
            'id',
            'RESTRICT',
            'CASCADE',
            'fk_location_restrictions_location'
        );

        $this->forge->createTable('location_restrictions', true);
    }

    public function down()
    {
        $this->forge->dropTable('location_restrictions', true);
    }
}