<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class ImproveReceptionLabelTemplate extends Migration
{
    public function up(): void
    {
        $body = <<<'HTML'
<style>
@page { size: 4in 6in; margin: 0; }
* { box-sizing: border-box; }
.label-sheet { width: 4in; height: 6in; padding: 0.11in; overflow: hidden; font-family: DejaVu Sans, sans-serif; color: #111; background: #fff; }
.label-border { width: 100%; height: 100%; border: 2px solid #111; padding: 7px; position: relative; }
.label-head { width: 100%; border-collapse: collapse; margin: 0 0 4px; }
.label-head td { vertical-align: top; padding: 0; }
.label-code { font-size: 14px; font-weight: 700; line-height: 1.05; }
.label-tub { font-size: 25px; font-weight: 800; line-height: 1; margin-top: 3px; }
.label-qr { width: 86px; height: 86px; text-align: right; }
.label-qr img { width: 82px; height: 82px; display: block; margin-left: auto; }
.label-title { font-size: 13px; font-weight: 800; border-top: 1px solid #333; border-bottom: 1px solid #333; padding: 4px 0; margin: 3px 0 5px; }
.label-grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
.label-grid td { width: 50%; vertical-align: top; padding: 2px 5px 2px 0; font-size: 9px; line-height: 1.16; }
.label-grid .full { width: 100%; }
.k { font-size: 7px; text-transform: uppercase; color: #555; display: block; }
.v { font-size: 9px; font-weight: 700; display: block; }
.weight-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
.weight-table th { font-size: 7px; font-weight: 600; text-transform: uppercase; background: #f1f1f1; padding: 3px 2px; border: 1px solid #bbb; }
.weight-table td { font-size: 10px; font-weight: 700; text-align: center; padding: 4px 2px; border: 1px solid #bbb; }
.accepted { margin-top: 5px; border: 2px solid #111; text-align: center; padding: 4px 2px; }
.accepted .k { font-size: 8px; }
.accepted .v { font-size: 18px; }
.label-bottom { width: 100%; border-collapse: collapse; margin-top: 4px; }
.label-bottom td { vertical-align: middle; padding: 0; }
.label-meta { font-size: 8px; line-height: 1.22; }
.label-barcode { width: 195px; text-align: right; }
.label-barcode img { width: 190px; height: 38px; display: block; margin-left: auto; }
.label-barcode-code { font-size: 7px; text-align: center; margin-top: 1px; }
.reprint-flag { position: absolute; top: 3px; right: 102px; border: 1px solid #a00; color: #a00; font-size: 8px; font-weight: 800; padding: 2px 4px; background: #fff; }
</style>
<div class="label-sheet">
  <div class="label-border">
    <table class="label-head">
      <tr>
        <td>
          <div class="label-code">{{label_code}}</div>
          <div class="label-tub">TINA {{tub}}</div>
          <div style="font-size:8px;margin-top:4px">{{company}}</div>
        </td>
        <td class="label-qr"><img src="{{qr_image}}" alt="QR"></td>
      </tr>
    </table>

    <div class="label-title">{{species}} · {{size}}</div>

    <table class="label-grid">
      <tr>
        <td><span class="k">Barco</span><span class="v">{{vessel}}</span></td>
        <td><span class="k">Marea / RSA</span><span class="v">{{voyage}} · {{rsa}}</span></td>
      </tr>
      <tr>
        <td><span class="k">Lote</span><span class="v">{{lot_code}}</span></td>
        <td><span class="k">Tanque(s)</span><span class="v">{{source_tanks}}</span></td>
      </tr>
      <tr>
        <td><span class="k">MSC</span><span class="v">{{msc}}</span></td>
        <td><span class="k">Dolphin Safe</span><span class="v">{{dolphin_safe}}</span></td>
      </tr>
      <tr>
        <td><span class="k">Temp. congelación</span><span class="v">{{freezing_temperature_c}} °C</span></td>
        <td><span class="k">Fecha de pesaje</span><span class="v">{{date}}</span></td>
      </tr>
    </table>

    <table class="weight-table">
      <tr><th>Bruto kg</th><th>Tara kg</th><th>Neto físico kg</th><th>Castigo</th></tr>
      <tr><td>{{gross_weight_kg}}</td><td>{{tare_kg}}</td><td>{{physical_net_kg}}</td><td>{{penalty_percent}}% / {{penalty_kg}} kg</td></tr>
    </table>

    <div class="accepted"><span class="k">Neto aceptado</span><span class="v">{{accepted_net_kg}} kg</span></div>

    <table class="label-bottom">
      <tr>
        <td class="label-meta">
          <strong>Sorteador:</strong> {{sorter}}<br>
          <strong>Etiquetador:</strong> {{labeler}}<br>
          <strong>Barco:</strong> {{vessel_code}}
        </td>
        <td class="label-barcode">
          <img src="{{barcode_image}}" alt="Código de barras">
          <div class="label-barcode-code">{{barcode_value}}</div>
        </td>
      </tr>
    </table>
  </div>
</div>
HTML;

        $table = $this->db->table('label_templates');
        $v2 = $table->where(['code' => 'TINA_RECEPCION', 'version' => 2, 'format' => 'html'])->get()->getRowArray();
        $now = date('Y-m-d H:i:s');

        if ($v2 === null) {
            $table->insert([
                'code' => 'TINA_RECEPCION',
                'name' => 'Marbete de tina para recepción 4x6',
                'version' => 2,
                'format' => 'html',
                'template_body' => $body,
                'active' => 1,
                'created_by' => 0,
                'created_at' => $now,
            ]);
            $v2Id = (int) $this->db->insertID();
        } else {
            $v2Id = (int) $v2['id'];
            $table->where('id', $v2Id)->update(['name' => 'Marbete de tina para recepción 4x6', 'template_body' => $body, 'active' => 1]);
        }

        $oldIds = $this->db->table('label_templates')
            ->select('id')
            ->where(['code' => 'TINA_RECEPCION', 'format' => 'html'])
            ->where('id !=', $v2Id)
            ->get()->getResultArray();

        foreach ($oldIds as $old) {
            $this->db->table('labels')->where('template_id', (int) $old['id'])->update(['template_id' => $v2Id]);
            $this->db->table('label_templates')->where('id', (int) $old['id'])->update(['active' => 0]);
        }
    }

    public function down(): void
    {
        $v1 = $this->db->table('label_templates')->where(['code' => 'TINA_RECEPCION', 'version' => 1, 'format' => 'html'])->get()->getRowArray();
        $v2 = $this->db->table('label_templates')->where(['code' => 'TINA_RECEPCION', 'version' => 2, 'format' => 'html'])->get()->getRowArray();
        if ($v1 !== null) {
            $this->db->table('label_templates')->where('id', (int) $v1['id'])->update(['active' => 1]);
            if ($v2 !== null) {
                $this->db->table('labels')->where('template_id', (int) $v2['id'])->update(['template_id' => (int) $v1['id']]);
                $this->db->table('label_templates')->where('id', (int) $v2['id'])->update(['active' => 0]);
            }
        }
    }
}
