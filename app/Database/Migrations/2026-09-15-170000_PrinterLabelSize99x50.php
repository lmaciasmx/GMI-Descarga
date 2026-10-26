<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class PrinterLabelSize99x50 extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('printers')) {
            // Sólo corrige impresoras PC43t que todavía conservan el tamaño anterior 4x6.
            $this->db->query(
                "UPDATE printers
                    SET label_width_mm = 99.00,
                        label_height_mm = 50.00
                  WHERE model LIKE '%PC43t%'
                    AND dpi = 203
                    AND ROUND(label_width_mm, 2) = 101.60
                    AND ROUND(label_height_mm, 2) = 152.40"
            );
        }

        if ($this->db->tableExists('label_templates')) {
            $template = $this->db->table('label_templates')
                ->select('id, version, template_body')
                ->where('code', 'TINA_RECEPCION_ZPL')
                ->get()
                ->getRowArray();

            if ($template !== null
                && str_contains((string) $template['template_body'], '^PW812')
                && str_contains((string) $template['template_body'], '^LL1218')) {
                $this->db->table('label_templates')
                    ->where('id', (int) $template['id'])
                    ->update([
                        'version' => max(2, ((int) $template['version']) + 1),
                        'template_body' => $this->template99x50(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // No se revierte automáticamente el tamaño físico de una impresora ya calibrada.
    }

    private function template99x50(): string
    {
        return "^XA\n"
            . "^CI28\n"
            . "^PW791\n"
            . "^LL400\n"
            . "^LH0,0\n"
            . "^FO8,8^GB775,384,2^FS\n"
            . "^FO20,16^A0N,22,22^FD{{label_code}}^FS\n"
            . "^FO20,45^A0N,31,31^FDTINA {{tub}}^FS\n"
            . "^FO20,82^A0N,17,17^FD{{company}}^FS\n"
            . "^FO20,105^A0N,17,17^FD{{vessel_code}}  MAREA {{voyage}}  RSA {{rsa}}^FS\n"
            . "^FO20,132^GB550,2,2^FS\n"
            . "^FO20,143^A0N,23,23^FD{{species}} / {{size}}^FS\n"
            . "^FO20,172^A0N,17,17^FDTANQUES {{source_tanks}}^FS\n"
            . "^FO20,195^A0N,15,15^FDMSC {{msc}}  DOLPHIN {{dolphin_safe}}  TEMP {{freezing_temperature_c}} C^FS\n"
            . "^FO20,220^A0N,17,17^FDBRUTO {{gross_weight_kg}}  TARA {{tare_kg}}  NETO FIS {{physical_net_kg}} KG^FS\n"
            . "^FO20,245^A0N,17,17^FDCASTIGO {{penalty_percent}}% / {{penalty_kg}} KG^FS\n"
            . "^FO20,270^A0N,28,28^FDACEPTADO {{accepted_net_kg}} KG^FS\n"
            . "^FO20,305^A0N,15,15^FDLOTE {{lot_code}}  SORT {{sorter}}  ETIQ {{labeler}}^FS\n"
            . "^FO20,328^A0N,14,14^FD{{date}}^FS\n"
            . "^FO615,20^BQN,2,3^FDLA,{{qr_payload}}^FS\n"
            . "^FO495,260^BY2^BCN,45,Y,N,N^FD{{barcode_value}}^FS\n"
            . "^XZ";
    }
}
