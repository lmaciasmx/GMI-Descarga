<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCompactWarehouseLabelV2 extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $html = <<<'HTML'
<style>
@page{size:99mm 50mm;margin:0}
html,body{margin:0;padding:0;width:99mm;height:50mm;background:#fff;color:#111;font-family:Arial,Helvetica,sans-serif}
*{box-sizing:border-box}
.gm-marbete{width:99mm;height:50mm;padding:2.4mm 3mm 2.2mm;display:grid;grid-template-columns:30mm 1fr;grid-template-rows:1fr 8.5mm;column-gap:3mm;overflow:hidden}
.gm-qr-wrap{display:flex;align-items:flex-start;justify-content:center;padding-top:.4mm}
.gm-qr{width:28mm;height:28mm;object-fit:contain;image-rendering:auto}
.gm-info{display:grid;grid-template-columns:minmax(0,1fr) 22mm;column-gap:2mm;min-width:0}
.gm-main,.gm-side{display:grid;align-content:start;row-gap:.8mm;min-width:0}
.gm-line{display:flex;align-items:baseline;gap:1.2mm;min-width:0;line-height:1.05}
.gm-label{flex:0 0 auto;font-size:6.1pt;font-weight:500;letter-spacing:.01em;white-space:nowrap}
.gm-value{min-width:0;font-size:8.4pt;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gm-main .gm-label{width:18mm}
.gm-main .gm-line.wide .gm-label{width:25mm}
.gm-side .gm-line{justify-content:space-between;gap:1mm}
.gm-side .gm-label{font-size:6.1pt}
.gm-side .gm-value{font-size:8.2pt;text-align:right}
.gm-observation .gm-value{font-size:7.2pt;font-weight:500}
.gm-bottom{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr 1fr;gap:3mm;align-items:end;padding-top:1.4mm}
.gm-bottom-item{display:flex;align-items:baseline;gap:2mm;min-width:0}
.gm-bottom-item span{font-size:6.8pt;font-weight:500;white-space:nowrap}
.gm-bottom-item strong{font-size:11.5pt;line-height:1;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gm-bottom-item:nth-child(2){justify-content:center}.gm-bottom-item:nth-child(3){justify-content:flex-end}
</style>
<div class="gm-marbete">
  <div class="gm-qr-wrap"><img class="gm-qr" src="{{qr_image}}" alt="QR"></div>
  <div class="gm-info">
    <div class="gm-main">
      <div class="gm-line"><span class="gm-label">B/M:</span><span class="gm-value">{{vessel}}</span></div>
      <div class="gm-line"><span class="gm-label">PESQUERO:</span><span class="gm-value">{{vessel_owner}}</span></div>
      <div class="gm-line wide"><span class="gm-label">ESPECIE/TALLA:</span><span class="gm-value">{{species_size}}</span></div>
      <div class="gm-line"><span class="gm-label">BODEGA:</span><span class="gm-value">{{actual_tank}}</span></div>
      <div class="gm-line"><span class="gm-label">CASTIGO:</span><span class="gm-value">{{penalty_compact}}%</span></div>
      <div class="gm-line"><span class="gm-label">SORTEADOR:</span><span class="gm-value">{{sorter}}</span></div>
      <div class="gm-line"><span class="gm-label">ETIQUETADOR:</span><span class="gm-value">{{labeler}}</span></div>
      <div class="gm-line wide gm-observation"><span class="gm-label">OBSERVACIONES:</span><span class="gm-value">{{observations}}</span></div>
    </div>
    <div class="gm-side">
      <div class="gm-line"><span class="gm-label">TARA:</span><span class="gm-value">{{tare_compact}}</span></div>
      <div class="gm-line"><span class="gm-label">TEMPERATURA:</span><span class="gm-value">{{temperature_compact}}</span></div>
      <div class="gm-line"><span class="gm-label">MAREA:</span><span class="gm-value">{{voyage}}</span></div>
    </div>
  </div>
  <div class="gm-bottom">
    <div class="gm-bottom-item"><span>LOTE:</span><strong>{{lot_code}}</strong></div>
    <div class="gm-bottom-item"><span>RSA:</span><strong>{{rsa}}</strong></div>
    <div class="gm-bottom-item"><span>FOLIO:</span><strong>{{folio}}</strong></div>
  </div>
</div>
HTML;

        $zpl = <<<'ZPL'
^XA
^CI28
^PW791
^LL400
^LH0,0
^FO18,18^BQN,2,5^FDLA,{{qr_payload}}^FS
^FO225,18^A0N,22,22^FDB/M:^FS
^FO285,18^A0N,27,27^FD{{vessel}}^FS
^FO225,50^A0N,22,22^FDPESQUERO:^FS
^FO345,50^A0N,27,27^FD{{vessel_owner}}^FS
^FO225,82^A0N,21,21^FDESPECIE/TALLA:^FS
^FO382,82^A0N,27,27^FD{{species_size}}^FS
^FO225,114^A0N,21,21^FDBODEGA:^FS
^FO320,114^A0N,27,27^FD{{actual_tank}}^FS
^FO225,146^A0N,21,21^FDCASTIGO:^FS
^FO320,146^A0N,27,27^FD{{penalty_compact}}%^FS
^FO225,178^A0N,21,21^FDSORTEADOR:^FS
^FO340,178^A0N,25,25^FD{{sorter}}^FS
^FO225,208^A0N,21,21^FDETIQUETADOR:^FS
^FO350,208^A0N,25,25^FD{{labeler}}^FS
^FO225,238^A0N,20,20^FDOBSERVACIONES:^FS
^FO375,238^A0N,22,22^FB245,1,0,L,0^FD{{observations}}^FS
^FO610,18^A0N,20,20^FDTARA:^FS
^FO675,18^A0N,27,27^FD{{tare_compact}}^FS
^FO590,58^A0N,18,18^FDTEMPERATURA:^FS
^FO705,58^A0N,24,24^FD{{temperature_compact}}^FS
^FO610,98^A0N,20,20^FDMAREA:^FS
^FO680,98^A0N,27,27^FD{{voyage}}^FS
^FO18,342^A0N,21,21^FDLOTE:^FS
^FO82,338^A0N,34,34^FD{{lot_code}}^FS
^FO292,342^A0N,21,21^FDRSA:^FS
^FO342,338^A0N,34,34^FD{{rsa}}^FS
^FO585,342^A0N,21,21^FDFOLIO:^FS
^FO660,338^A0N,34,34^FD{{folio}}^FS
^XZ
ZPL;

        if ($this->db->table('label_templates')->where(['code' => 'TINA_RECEPCION', 'version' => 2])->countAllResults() === 0) {
            $this->db->table('label_templates')->insert([
                'code' => 'TINA_RECEPCION',
                'name' => 'Marbete compacto 99x50 mm (HTML/PDF)',
                'version' => 2,
                'format' => 'html',
                'template_body' => $html,
                'active' => 1,
                'created_by' => 0,
                'created_at' => $now,
            ]);
        }

        if ($this->db->table('label_templates')->where(['code' => 'TINA_RECEPCION_ZPL', 'version' => 2])->countAllResults() === 0) {
            $this->db->table('label_templates')->insert([
                'code' => 'TINA_RECEPCION_ZPL',
                'name' => 'Marbete compacto 99x50 mm (Zebra 203 dpi, pendiente validar hardware)',
                'version' => 2,
                'format' => 'zpl',
                'template_body' => $zpl,
                'active' => 0,
                'created_by' => 0,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach ([
            ['code' => 'TINA_RECEPCION', 'version' => 2],
            ['code' => 'TINA_RECEPCION_ZPL', 'version' => 2],
        ] as $template) {
            $row = $this->db->table('label_templates')->select('id')->where($template)->get()->getRowArray();
            if ($row === null) {
                continue;
            }

            $used = $this->db->table('labels')->where('template_id', (int) $row['id'])->countAllResults();
            if ($used === 0) {
                $this->db->table('label_templates')->where('id', (int) $row['id'])->delete();
            }
        }
    }
}
