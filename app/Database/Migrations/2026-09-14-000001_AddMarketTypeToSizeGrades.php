<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMarketTypeToSizeGrades extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('size_grades', [
            'market_type' => [
                'type'       => 'ENUM',
                'constraint' => ['national', 'export'],
                'default'    => 'national',
                'null'       => false,
                'after'      => 'max_kg',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('size_grades', 'market_type');
    }
}
