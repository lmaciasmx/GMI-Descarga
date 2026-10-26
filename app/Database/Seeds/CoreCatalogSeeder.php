<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class CoreCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        if ($this->db->table('companies')->countAllResults() > 0) {
            return;
        }

        $this->db->transException(true)->transStart();

        $this->db->table('companies')->insert([
            'code' => 'GMAR',
            'legal_name' => 'Grupo Mar',
            'trade_name' => 'Grupo Mar',
            'company_type' => 'owner',
            'active' => 1,
            'created_at' => $now,
        ]);
        $companyId = (int) $this->db->insertID();

        $this->db->table('sites')->insert([
            'company_id' => $companyId,
            'code' => 'MZO',
            'name' => 'Manzanillo',
            'timezone' => 'America/Mexico_City',
            'active' => 1,
            'created_at' => $now,
        ]);
        $siteId = (int) $this->db->insertID();

        $this->db->table('warehouses')->insert([
            'company_id' => $companyId,
            'site_id' => $siteId,
            'code' => 'FRIO-01',
            'name' => 'Frigorífico principal',
            'warehouse_type' => 'internal',
            'active' => 1,
            'created_at' => $now,
        ]);
        $warehouseId = (int) $this->db->insertID();

        $this->db->table('chambers')->insertBatch([
            ['warehouse_id' => $warehouseId, 'code' => 'CAM-01', 'name' => 'Cámara 01', 'active' => 1, 'created_at' => $now],
            ['warehouse_id' => $warehouseId, 'code' => 'CAM-02', 'name' => 'Cámara 02', 'active' => 1, 'created_at' => $now],
        ]);
        $chamberId = (int) $this->db->table('chambers')->where(['warehouse_id' => $warehouseId, 'code' => 'CAM-01'])->get()->getRow('id');

        $this->db->table('locations')->insertBatch([
            ['warehouse_id' => $warehouseId, 'chamber_id' => null, 'parent_id' => null, 'code' => 'REC', 'name' => 'Andén de recepción', 'location_type' => 'staging', 'path' => '/REC', 'max_units' => 30, 'active' => 1, 'created_at' => $now],
            ['warehouse_id' => $warehouseId, 'chamber_id' => $chamberId, 'parent_id' => null, 'code' => 'C01-Z01', 'name' => 'Cámara 01 / Zona 01', 'location_type' => 'zone', 'path' => '/CAM-01/Z01', 'max_units' => 100, 'active' => 1, 'created_at' => $now],
        ]);

        $this->db->table('vessels')->insertBatch([
            ['owner_company_id' => $companyId, 'code' => 'FDA', 'name' => 'B/M Ma. Fernanda', 'active' => 1, 'created_at' => $now],
            ['owner_company_id' => $companyId, 'code' => 'LSA', 'name' => 'B/M Ma. Luisa', 'active' => 1, 'created_at' => $now],
            ['owner_company_id' => $companyId, 'code' => 'DLA', 'name' => 'B/M Ma. Delia', 'active' => 1, 'created_at' => $now],
            ['owner_company_id' => $companyId, 'code' => 'GIJ', 'name' => 'B/M Gijón', 'active' => 1, 'created_at' => $now],
            ['owner_company_id' => $companyId, 'code' => 'OAX', 'name' => 'B/M Oaxaca', 'active' => 1, 'created_at' => $now],
            ['owner_company_id' => $companyId, 'code' => 'MZO', 'name' => 'B/M Manzanillo', 'active' => 1, 'created_at' => $now],
        ]);

        $this->db->table('species')->insertBatch([
            ['code' => 'A', 'name' => 'Atún aleta amarilla', 'scientific_name' => 'Thunnus albacares', 'active' => 1, 'created_at' => $now],
            ['code' => 'B', 'name' => 'Bonita', 'scientific_name' => null, 'active' => 1, 'created_at' => $now],
            ['code' => 'S', 'name' => 'Skipjack / Barrilete', 'scientific_name' => 'Katsuwonus pelamis', 'active' => 1, 'created_at' => $now],
        ]);
        $yellowfinId = (int) $this->db->table('species')->where('code', 'A')->get()->getRow('id');

        $this->db->table('size_grades')->insertBatch([
            ['species_id' => $yellowfinId, 'code' => '3-5', 'name' => '3 a 5 kg', 'min_kg' => 3, 'max_kg' => 5, 'market_type' => 'national', 'active' => 1, 'created_at' => $now],
            ['species_id' => $yellowfinId, 'code' => '5-10', 'name' => '5 a 10 kg', 'min_kg' => 5, 'max_kg' => 10, 'market_type' => 'national', 'active' => 1, 'created_at' => $now],
            ['species_id' => null, 'code' => 'MIX', 'name' => 'Talla por validar', 'min_kg' => null, 'max_kg' => null, 'market_type' => 'national', 'active' => 1, 'created_at' => $now],
        ]);

        $tubs = [];
        for ($number = 1; $number <= 20; $number++) {
            $code = sprintf('T-%03d', $number);
            $tubs[] = [
                'company_id' => $companyId,
                'code' => $code,
                'barcode' => 'GMAR-' . $code,
                'current_tare_kg' => 176.000,
                'status' => 'available',
                'active' => 1,
                'created_at' => $now,
            ];
        }
        $this->db->table('tubs')->insertBatch($tubs);

        $this->db->table('scales')->insert([
            'site_id' => $siteId,
            'code' => 'BAS-DEV-01',
            'name' => 'Báscula simulada de desarrollo',
            'connection_type' => 'simulator',
            'adapter_key' => 'simulator',
            'configuration' => json_encode(['clearly_simulated' => true], JSON_THROW_ON_ERROR),
            'active' => 1,
            'created_at' => $now,
        ]);

        $this->db->table('printers')->insert([
            'site_id' => $siteId,
            'code' => 'PDF-DEV-01',
            'name' => 'Impresora PDF de contingencia',
            'printer_type' => 'pdf',
            'adapter_key' => 'pdf',
            'configuration' => json_encode(['clearly_simulated' => true], JSON_THROW_ON_ERROR),
            'active' => 1,
            'created_at' => $now,
        ]);

        $this->db->table('printers')->insert([
            'site_id' => $siteId,
            'code' => 'ZEBRA-PEND',
            'name' => 'Zebra pendiente de modelo y conectividad',
            'printer_type' => 'thermal',
            'adapter_key' => 'zpl',
            'configuration' => json_encode(['configured' => false, 'requires_device_confirmation' => true], JSON_THROW_ON_ERROR),
            'active' => 0,
            'created_at' => $now,
        ]);

        $this->db->table('label_templates')->insertBatch([
            [
                'code' => 'TINA_RECEPCION',
                'name' => 'Marbete de tina para recepción (HTML/PDF)',
                'version' => 1,
                'format' => 'html',
                'template_body' => "^XA\n^CI28\n^PW791\n^LL400\n^LH0,0\n^FO8,8^GB775,384,2^FS\n^FO20,16^A0N,22,22^FD{{label_code}}^FS\n^FO20,45^A0N,31,31^FDTINA {{tub}}^FS\n^FO20,82^A0N,17,17^FD{{company}}^FS\n^FO20,105^A0N,17,17^FD{{vessel_code}} MAREA {{voyage}} RSA {{rsa}}^FS\n^FO20,132^GB550,2,2^FS\n^FO20,143^A0N,23,23^FD{{species}} / {{size}}^FS\n^FO20,172^A0N,17,17^FDTANQUES {{source_tanks}}^FS\n^FO20,195^A0N,15,15^FDMSC {{msc}} DOLPHIN {{dolphin_safe}} TEMP {{freezing_temperature_c}} C^FS\n^FO20,220^A0N,17,17^FDBRUTO {{gross_weight_kg}} TARA {{tare_kg}} NETO FIS {{physical_net_kg}} KG^FS\n^FO20,245^A0N,17,17^FDCASTIGO {{penalty_percent}}% / {{penalty_kg}} KG^FS\n^FO20,270^A0N,28,28^FDACEPTADO {{accepted_net_kg}} KG^FS\n^FO20,305^A0N,15,15^FDLOTE {{lot_code}} SORT {{sorter}} ETIQ {{labeler}}^FS\n^FO20,328^A0N,14,14^FD{{date}}^FS\n^FO615,20^BQN,2,3^FDLA,{{qr_payload}}^FS\n^FO495,260^BY2^BCN,45,Y,N,N^FD{{barcode_value}}^FS\n^XZ",
                'active' => 1,
                'created_by' => 0,
                'created_at' => $now,
            ],
        ]);

        $this->seedStates();
        $this->seedSettings($companyId, $siteId, $now);
        $this->seedQualityDefects($now);

        $this->db->transComplete();
    }

    private function seedStates(): void
    {
        $states = [
            'voyage' => ['planned', 'unloading', 'closed', 'canceled'],
            'plan' => ['draft', 'approved', 'in_progress', 'paused', 'finished', 'canceled'],
            'tub' => ['available', 'assigned', 'filling', 'pending_weighing', 'weighed', 'received', 'empty', 'maintenance', 'inactive'],
            'weighing' => ['draft', 'confirmed', 'pending_inspection', 'received', 'reversed'],
            'stock_unit' => ['received', 'located', 'available', 'held', 'reserved', 'in_transit', 'dispatched', 'rejected', 'exhausted'],
            'request' => ['draft', 'requested', 'authorized', 'reserved', 'partially_fulfilled', 'fulfilled', 'rejected', 'canceled'],
            'transfer' => ['requested', 'authorized', 'prepared', 'shipped', 'in_transit', 'received', 'discrepancy', 'canceled'],
        ];
        $rows = [];

        foreach ($states as $aggregate => $items) {
            foreach ($items as $order => $code) {
                $rows[] = [
                    'aggregate' => $aggregate,
                    'code' => $code,
                    'name' => ucwords(str_replace('_', ' ', $code)),
                    'is_terminal' => in_array($code, ['closed', 'canceled', 'reversed', 'dispatched', 'rejected', 'exhausted', 'fulfilled', 'received'], true),
                    'sort_order' => $order + 1,
                    'active' => 1,
                ];
            }
        }

        $this->db->table('state_catalog')->insertBatch($rows);
    }

    private function seedSettings(int $companyId, int $siteId, string $now): void
    {
        $settings = [
            ['tare.estimated_kg', 'decimal', '176.000', 'Tara estimada de contingencia; exige motivo.'],
            ['scale.reading_max_age_seconds', 'integer', '120', 'Vigencia máxima de una lectura capturada antes de confirmar el pesaje.'],
            ['lot.period_mode', 'string', 'month', 'Componente temporal preliminar y versionado; validar contra operación.'],
            ['lot.rule_version', 'integer', '1', 'Versión activa de regla de lote.'],
            ['quality.preliminary_rules_active', 'boolean', 'false', 'Permanece inactivo hasta resolver el traslape del 15%.'],
            ['quality.preliminary_ranges', 'json', json_encode(['AAA' => [0, 5], 'AA' => [6, 10], 'A' => [11, 15], 'B' => [15, 100]], JSON_THROW_ON_ERROR), 'Rangos recibidos; no aplicar automáticamente.'],
            ['certification.msc_values', 'json', json_encode(['1.0', '2.0', '3.0', '3.1'], JSON_THROW_ON_ERROR), 'Valores MSC configurables.'],
            ['certification.dolphin_safe_values', 'json', json_encode(['A', 'B'], JSON_THROW_ON_ERROR), 'Valores Dolphin Safe configurables.'],
            ['reservation.duration_minutes', 'integer', '120', 'Vigencia predeterminada de una reserva.'],
        ];
        $rows = [];

        foreach ($settings as [$key, $type, $value, $description]) {
            $rows[] = [
                'company_id' => $companyId,
                'site_id' => $siteId,
                'setting_key' => $key,
                'value_type' => $type,
                'setting_value' => $value,
                'description' => $description,
                'is_sensitive' => 0,
                'created_at' => $now,
            ];
        }

        $this->db->table('system_settings')->insertBatch($rows);
    }

    private function seedQualityDefects(string $now): void
    {
        $this->db->table('quality_defects')->insertBatch([
            ['code' => 'TEMP', 'name' => 'Temperatura fuera de rango', 'default_destination' => 'hold', 'requires_hold' => 1, 'active' => 1, 'created_at' => $now],
            ['code' => 'DAÑO', 'name' => 'Daño físico', 'default_destination' => 'inspection', 'requires_hold' => 1, 'active' => 1, 'created_at' => $now],
            ['code' => 'DOC', 'name' => 'Documentación incompleta', 'default_destination' => 'hold', 'requires_hold' => 1, 'active' => 1, 'created_at' => $now],
        ]);
    }
}