<?php

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class OperationalFixture
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function createReceivableWeighing(string $suffix = '01', string $qualityDisposition = 'accepted'): array
    {
        $companyId = (int) $this->db->table('companies')->where('code', 'GMAR')->get()->getRow('id');
        $siteId = (int) $this->db->table('sites')->where('code', 'MZO')->get()->getRow('id');
        $warehouseId = (int) $this->db->table('warehouses')->where('code', 'FRIO-01')->get()->getRow('id');
        $locationId = (int) $this->db->table('locations')->where('code', 'REC')->get()->getRow('id');
        $vesselId = (int) $this->db->table('vessels')->where('code', 'FDA')->get()->getRow('id');
        $speciesId = (int) $this->db->table('species')->where('code', 'A')->get()->getRow('id');
        $sizeId = (int) $this->db->table('size_grades')->where('code', '3-5')->get()->getRow('id');
        $tubId = (int) $this->db->table('tubs')->where('code', 'T-' . str_pad($suffix, 3, '0', STR_PAD_LEFT))->get()->getRow('id');
        if ($tubId === 0) {
            $tubId = (int) $this->db->table('tubs')->orderBy('id')->get()->getRow('id');
        }
        $now = date('Y-m-d H:i:s');

        $this->db->table('voyages')->insert([
            'uuid' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'site_id' => $siteId,
            'vessel_id' => $vesselId, 'annual_number' => 80 + (int) $suffix, 'year' => 2026,
            'arrival_at' => $now, 'status' => 'unloading', 'created_by' => 1, 'created_at' => $now,
        ]);
        $voyageId = (int) $this->db->insertID();
        $this->db->table('voyage_tanks')->insert(['voyage_id' => $voyageId, 'tank_number' => 'TEST-' . $suffix, 'status' => 'available', 'created_at' => $now]);
        $tankId = (int) $this->db->insertID();
        $this->db->table('discharge_plans')->insert([
            'uuid' => Uuid::uuid7()->toString(), 'folio' => 'TEST-PLAN-' . $suffix, 'voyage_id' => $voyageId,
            'status' => 'in_progress', 'created_by' => 1, 'created_at' => $now,
        ]);
        $planId = (int) $this->db->insertID();
        $this->db->table('tub_fillings')->insert([
            'uuid' => Uuid::uuid7()->toString(), 'discharge_plan_id' => $planId, 'tub_id' => $tubId,
            'actual_voyage_tank_id' => $tankId, 'species_id' => $speciesId, 'size_grade_id' => $sizeId,
            'status' => 'weighed', 'started_at' => $now, 'completed_at' => $now, 'created_by' => 1, 'created_at' => $now,
        ]);
        $fillingId = (int) $this->db->insertID();
        $this->db->table('lots')->insert([
            'uuid' => Uuid::uuid7()->toString(), 'code' => 'L-TEST-' . $suffix, 'lot_type' => 'discharge',
            'voyage_id' => $voyageId, 'vessel_code' => 'FDA', 'species_id' => $speciesId, 'species_code' => 'A',
            'annual_voyage_number' => 80 + (int) $suffix, 'period_component' => '09', 'year_component' => '26',
            'rule_version' => 1, 'status' => 'open', 'created_by' => 1, 'created_at' => $now,
        ]);
        $lotId = (int) $this->db->insertID();
        $this->db->table('weighings')->insert([
            'uuid' => Uuid::uuid7()->toString(), 'folio' => 'TEST-PES-' . $suffix, 'tub_filling_id' => $fillingId,
            'tub_id' => $tubId, 'lot_id' => $lotId, 'gross_weight_kg' => 1000, 'applied_tare_kg' => 176,
            'tare_source' => 'catalog_snapshot', 'physical_net_kg' => 824, 'penalty_percent' => 5,
            'penalty_kg' => 41.2, 'accepted_net_kg' => 782.8, 'weight_source' => 'simulator', 'stable' => 1,
            'status' => 'confirmed', 'is_current' => 1, 'confirmed_by' => 1, 'confirmed_at' => $now,
            'created_by' => 1, 'created_at' => $now,
        ]);
        $weighingId = (int) $this->db->insertID();
        $templateId = (int) $this->db->table('label_templates')->where('code', 'TINA_RECEPCION')->get()->getRow('id');
        $this->db->table('labels')->insert([
            'uuid' => Uuid::uuid7()->toString(), 'code' => 'TEST-ETQ-' . $suffix, 'weighing_id' => $weighingId,
            'template_id' => $templateId, 'qr_payload' => '{}', 'barcode_value' => 'TEST-ETQ-' . $suffix,
            'status' => 'generated', 'created_by' => 1, 'created_at' => $now,
        ]);
        $labelId = (int) $this->db->insertID();
        $this->db->table('quality_inspections')->insert([
            'uuid' => Uuid::uuid7()->toString(), 'weighing_id' => $weighingId, 'quality_grade' => 'AAA',
            'defect_percent' => 3, 'disposition' => $qualityDisposition, 'status' => 'approved',
            'inspected_by' => 1, 'inspected_at' => $now, 'approved_by' => 1, 'approved_at' => $now,
            'created_at' => $now,
        ]);

        return compact('companyId', 'siteId', 'warehouseId', 'locationId', 'speciesId', 'sizeId', 'tubId', 'voyageId', 'planId', 'fillingId', 'lotId', 'weighingId', 'labelId');
    }
}
