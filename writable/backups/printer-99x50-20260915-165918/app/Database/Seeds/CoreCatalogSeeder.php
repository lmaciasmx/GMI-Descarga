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
                'template_body' => '<section style="border:2px solid #102f46;padding:12px"><div style="display:flex;gap:12px"><div style="flex:1"><h2 style="margin:0">{{label_code}}</h2><strong>Tina {{tub}}</strong><br>{{company}}<br>{{vessel}} · marea {{voyage}} · RSA {{rsa}}<hr><strong>{{species}} · {{size}}</strong><br>Tanques: {{source_tanks}}<br>MSC {{msc}} · Dolphin Safe {{dolphin_safe}} · Cong. {{freezing_temperature_c}} °C<br>Bruto {{gross_weight_kg}} · Tara {{tare_kg}} · Neto físico {{physical_net_kg}} kg<br>Castigo {{penalty_percent}}% / {{penalty_kg}} kg<br><strong>Neto aceptado {{accepted_net_kg}} kg</strong><br>Lote {{lot_code}}<br>Sorteador {{sorter}} · Etiquetador {{labeler}}<br>{{date}}</div><div><img src="{{qr_image}}" width="145" height="145"><br><img src="{{barcode_image}}" width="220" height="56"></div></div></section>',
                'active' => 1,
                'created_by' => 0,
                'created_at' => $now,
            ],
            [
                'code' => 'TINA_RECEPCION_ZPL',
                'name' => 'Marbete de tina para recepción (Zebra ZPL)',
                'version' => 1,
                'format' => 'zpl',
                'template_body' => "^XA\n^CI28\n^PW812\n^LL1218\n^FO30,25^A0N,44,44^FD{{label_code}}^FS\n^FO30,80^A0N,36,36^FDTina {{tub}}^FS\n^FO30,130^A0N,26,26^FD{{company}}^FS\n^FO30,170^A0N,26,26^FD{{vessel_code}} Marea {{voyage}} RSA {{rsa}}^FS\n^FO30,215^GB750,3,3^FS\n^FO30,240^A0N,34,34^FD{{species}} / {{size}}^FS\n^FO30,290^A0N,25,25^FDTanques {{source_tanks}}^FS\n^FO30,330^A0N,25,25^FDMSC {{msc}} Dolphin {{dolphin_safe}} Cong {{freezing_temperature_c}} C^FS\n^FO30,385^A0N,28,28^FDBruto {{gross_weight_kg}} kg^FS\n^FO30,425^A0N,28,28^FDTara {{tare_kg}} kg^FS\n^FO30,465^A0N,28,28^FDNeto fisico {{physical_net_kg}} kg^FS\n^FO30,505^A0N,28,28^FDCastigo {{penalty_percent}}% / {{penalty_kg}} kg^FS\n^FO30,555^A0N,40,40^FDACEPTADO {{accepted_net_kg}} kg^FS\n^FO30,615^A0N,28,28^FDLote {{lot_code}}^FS\n^FO30,660^A0N,22,22^FDSort {{sorter}} Etiq {{labeler}}^FS\n^FO30,700^A0N,22,22^FD{{date}}^FS\n^FO500,750^BQN,2,5^FDLA,{{qr_payload}}^FS\n^FO60,980^BY3^BCN,100,Y,N,N^FD{{barcode_value}}^FS\n^XZ",
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