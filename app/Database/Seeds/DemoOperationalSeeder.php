<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use Ramsey\Uuid\Uuid;

class DemoOperationalSeeder extends Seeder
{
    public function run(): void
    {
        if (ENVIRONMENT === 'production') {
            throw new \RuntimeException('Los datos demo están bloqueados en producción.');
        }

        $companyId = (int) $this->db->table('companies')->where('code', 'GMAR')->get()->getRow('id');
        $siteId = (int) $this->db->table('sites')->where('code', 'MZO')->get()->getRow('id');
        $vesselId = (int) $this->db->table('vessels')->where('code', 'FDA')->get()->getRow('id');
        $now = date('Y-m-d H:i:s');

        if ($this->db->table('voyages')->where(['vessel_id' => $vesselId, 'year' => (int) date('Y'), 'annual_number' => 1])->countAllResults() > 0) {
            return;
        }

        $this->db->table('voyages')->insert([
            'uuid' => Uuid::uuid7()->toString(),
            'company_id' => $companyId,
            'site_id' => $siteId,
            'vessel_id' => $vesselId,
            'annual_number' => 1,
            'year' => (int) date('Y'),
            'arrival_at' => $now,
            'status' => 'planned',
            'notes' => 'DATO DEMO: viaje para pruebas y capacitación.',
            'created_by' => 0,
            'created_at' => $now,
        ]);
        $voyageId = (int) $this->db->insertID();

        $tanks = [];
        foreach (['1-BB', '2-BB', '3-EB', '4-EB'] as $tankNumber) {
            $tanks[] = [
                'voyage_id' => $voyageId,
                'tank_number' => $tankNumber,
                'planned_weight_kg' => 25000,
                'status' => 'available',
                'created_at' => $now,
            ];
        }
        $this->db->table('voyage_tanks')->insertBatch($tanks);
    }
}
