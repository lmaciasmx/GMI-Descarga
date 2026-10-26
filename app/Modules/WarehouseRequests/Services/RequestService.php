<?php

namespace App\Modules\WarehouseRequests\Services;

use App\Modules\WarehouseRequests\Domain\RequestPolicy as Policy;
use App\Modules\WarehouseRequests\Repositories\RequestRepository;
use App\Modules\Weighing\DTO\WeightReading;
use CodeIgniter\Database\BaseConnection;
use Ramsey\Uuid\Uuid;

final class RequestService
{
    private readonly RequestRepository $repo;
    public function __construct(private readonly BaseConnection $db) { $this->repo = new RequestRepository($db); }

    public function create(array $data, int $actor): array
    {
        $destination = $this->repo->context();
        $source = $this->repo->warehouse((int) ($data['source_warehouse_id'] ?? 0));
        Policy::require((int) $source['id'] !== (int) $destination['id'], 'Origen y destino deben ser distintos.');
        $type = (string) ($data['request_type'] ?? 'transfer');
        Policy::require(in_array($type,['transfer','production'],true),'Selecciona el concepto de la solicitud.');

        $requestTypeConfig=config(\Config\WarehouseRequestTypes::class);
        Policy::require(
            $requestTypeConfig->allows($destination,$type),
            'El almacén actual no está configurado para este tipo de solicitud.',
            'WAREHOUSE_REQUEST_TYPE_NOT_ALLOWED',
            [
                'warehouse_id'=>(int) $destination['id'],
                'warehouse_code'=>(string) $destination['code'],
                'request_type'=>$type,
            ]
        );

        // Sólo Materia prima / Producción requiere un destino operativo.
        // No modifica la semántica de Traspasos.
        $productionDestination = '';
        if ($type === 'production') {
            $productionDestination = trim((string) ($data['production_destination'] ?? ''));
            Policy::require($productionDestination !== '',
                'Captura el área destino de producción.',
                'PRODUCTION_DESTINATION_REQUIRED');
            Policy::require(mb_strlen($productionDestination) <= 120,
                'El área destino de producción admite hasta 120 caracteres.',
                'PRODUCTION_DESTINATION_TOO_LONG');
        }

        $lines = $data['lines'] ?? [];
        Policy::require(is_array($lines) && count($lines)>0 && count($lines)<=200, 'Captura de una a 200 líneas.');
        $required = str_replace('T',' ', trim((string) ($data['required_at'] ?? '')));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $required);
        Policy::require($date !== false && $date->format('Y-m-d H:i') === $required, 'Indica una fecha y hora válidas.');
        $notes = trim((string) ($data['observations'] ?? ''));
        Policy::require(mb_strlen($notes)<=1000, 'Las observaciones admiten hasta 1000 caracteres.');
        $this->db->transException(true)->transBegin();
        try {
            $now = date('Y-m-d H:i:s');
            $folio = service('folioService')->next('SAL');
            $this->db->table('warehouse_requests')->insert([
                'uuid'=>Uuid::uuid7()->toString(),'folio'=>$folio,'company_id'=>$destination['company_id'],
                'provider_company_id'=>$source['company_id'],'request_type'=>$type,
                'source_warehouse_id'=>$source['id'],'destination_warehouse_id'=>$destination['id'],
                'status'=>'open','required_at'=>$date->format('Y-m-d H:i:s'),'observations'=>$notes,
                'requested_by'=>$actor,'created_at'=>$now,'updated_at'=>$now]);
            $id = (int) $this->db->insertID();
            $scopeKeys=[];
            $requesterProposalRefs=[];
            $requesterProposalSeen=[];
            foreach (array_values($lines) as $index=>$line) {
                Policy::require(is_array($line), 'Detalle de solicitud inválido.');
                $species = (int) ($line['species_id'] ?? 0);
                $size = (int) ($line['size_grade_id'] ?? 0);
                Policy::require($this->db->table('species')->where(['id'=>$species,'active'=>1])->countAllResults()===1,
                    'Selecciona una especie activa en cada línea.');
                $sizeRow = $this->db->table('size_grades')->where(['id'=>$size,'active'=>1])->get()->getRowArray();
                Policy::require($sizeRow !== null && (empty($sizeRow['species_id']) || (int) $sizeRow['species_id']===$species),
                    'La talla es obligatoria y debe corresponder a la especie.');
                $lot = ! empty($line['lot_id']) ? (int) $line['lot_id'] : null;
                $owner = ! empty($line['owner_company_id']) ? (int) $line['owner_company_id'] : null;
                if ($lot !== null) {
                    $valid = $this->db->query('SELECT l.id FROM lots l WHERE l.id=? AND l.species_id=? AND EXISTS (SELECT 1 FROM stock_units su WHERE su.lot_id=l.id AND su.warehouse_id=? AND su.species_id=? AND su.size_grade_id=?)',[$lot,$species,$source['id'],$species,$size])->getRowArray();
                    Policy::require($valid !== null,'El lote no corresponde a la especie o al proveedor.');
                }
                if ($owner !== null) {
                    Policy::require($this->db->table('companies')->where(['id'=>$owner,'active'=>1])->countAllResults()===1,
                        'El propietario no está activo.');
                }
                $scopeKey=$species.':'.$size.':'.($lot??0).':'.($owner??0);
                Policy::require(!isset($scopeKeys[$scopeKey]),
                    'Hay dos líneas con la misma Especie, Talla, Lote y Propietario. Modifica la línea existente en lugar de duplicarla.',
                    'WAREHOUSE_DUPLICATE_LINE_SCOPE');
                $scopeKeys[$scopeKey]=true;
                $grams = Policy::grams($line['requested_weight_kg'] ?? '');
                $count = $line['requested_unit_count'] ?? null;
                if ($count === '') { $count = null; }
                if ($count !== null) {
                    Policy::require(filter_var($count,FILTER_VALIDATE_INT)!==false && (int) $count>0 && (int) $count<=1000000,
                        'El número de tinas debe ser un entero positivo.');
                }
                $this->db->table('warehouse_request_lines')->insert([
                    'request_id'=>$id,'line_number'=>$index+1,'species_id'=>$species,'size_grade_id'=>$size,
                    'lot_id'=>$lot,'owner_company_id'=>$owner,'requested_weight_kg'=>$grams/1000,
                    'requested_unit_count'=>$count===null ? null : (int) $count]);

                // Selección opcional hecha por quien solicita:
                // es una REFERENCIA para el almacén proveedor, no una reserva.
                $proposalIds=$line['proposal_stock_unit_ids'] ?? [];
                Policy::require(is_array($proposalIds),'La propuesta de tinas debe ser una lista válida.');
                foreach ($proposalIds as $proposalValue) {
                    $proposalId=filter_var($proposalValue,FILTER_VALIDATE_INT);
                    if ($proposalId===false || (int) $proposalId<=0 || isset($requesterProposalSeen[(int) $proposalId])) {
                        continue;
                    }
                    $requesterProposalSeen[(int) $proposalId]=true;
                    $requesterProposalRefs[]=['line_number'=>$index+1,'stock_unit_id'=>(int) $proposalId];
                    Policy::require(count($requesterProposalRefs)<=500,
                        'La solicitud admite hasta 500 tinas sugeridas.','WAREHOUSE_REQUEST_PROPOSAL_LIMIT');
                }
            }

            // Valida y fotografía únicamente las sugerencias que siguen perteneciendo
            // al proveedor y a la línea correspondiente. Una sugerencia obsoleta
            // NO debe impedir crear la solicitud.
            $requesterProposals=[];
            $ignoredRequesterProposals=0;
            if ($requesterProposalRefs !== []) {
                $savedLines=array_column($this->repo->lines($id),null,'line_number');
                $savedLineList=array_values($savedLines);
                $proposalIds=array_values(array_unique(array_column($requesterProposalRefs,'stock_unit_id')));
                sort($proposalIds,SORT_NUMERIC);
                $marks=implode(',',array_fill(0,count($proposalIds),'?'));
                $proposalStocks=array_column($this->db->query(
                    "SELECT su.id,su.company_id,su.warehouse_id,su.species_id,su.size_grade_id,
                            su.lot_id,su.owner_company_id,su.on_hand_weight_kg
                     FROM stock_units su
                     WHERE su.id IN (".$marks.")
                     ORDER BY su.id",
                    $proposalIds
                )->getResultArray(),null,'id');

                foreach ($requesterProposalRefs as $ref) {
                    $lineNumber=(int) $ref['line_number'];
                    $stockId=(int) $ref['stock_unit_id'];
                    $savedLine=$savedLines[$lineNumber] ?? null;
                    $stock=$proposalStocks[$stockId] ?? null;

                    if (
                        $savedLine===null
                        || $stock===null
                        || (int) $stock['company_id']!==(int) $source['company_id']
                        || (int) $stock['warehouse_id']!==(int) $source['id']
                        || empty($stock['owner_company_id'])
                        || !Policy::ownsStock($stock,$savedLine,$savedLineList)
                    ) {
                        $ignoredRequesterProposals++;
                        continue;
                    }

                    $requesterProposals[]=[
                        'request_line_id'=>(int) $savedLine['id'],
                        'line_number'=>$lineNumber,
                        'stock_unit_id'=>$stockId,
                        'proposed_weight_kg'=>(float) $stock['on_hand_weight_kg'],
                    ];
                }
            }

            $this->db->table('transfers')->insert([
                'uuid'=>Uuid::uuid7()->toString(),'folio'=>service('folioService')->next('TRP'),
                'company_id'=>$source['company_id'],'origin_warehouse_id'=>$source['id'],
                'destination_warehouse_id'=>$destination['id'],'transfer_type'=>'warehouse_request',
                'status'=>'prepared','requested_by'=>$actor,'requested_at'=>$now,
                'observations'=>'Solicitud entre almacenes '.$folio.($type==='production' ? ' · Producción: '.$productionDestination : ''),
                'created_at'=>$now,'updated_at'=>$now]);
            $this->db->table('warehouse_requests')->where('id',$id)->update(['transfer_id'=>(int) $this->db->insertID()]);
            $request = $this->repo->request($id);
            $this->event($request,'created',$actor,[
                'source_warehouse_id'=>$source['id'],
                'destination_warehouse_id'=>$destination['id'],
                'lines'=>count($lines),
                'requester_proposals'=>$requesterProposals,
                'requester_proposal_count'=>count($requesterProposals),
                'requester_proposal_ignored_count'=>$ignoredRequesterProposals,
                'inventory_reserved_by_requester'=>false,
                'production_destination'=>$type==='production' ? $productionDestination : null,
            ]);
            $detail=$this->repo->detail($id);
            Policy::require($this->db->transStatus() !== false,'No fue posible guardar la solicitud.','WAREHOUSE_TRANSACTION_FAILED');
            $this->db->transCommit();
            return $detail;
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }

    public function select(int $id, array $data, int $actor): array
    {
        return $this->mutate($id,$data,$actor,'provider','preselection',function(array $r) use($data,$actor): array {
            Policy::open($r);
            $selection = $data['selections'] ?? null;
            Policy::require(is_array($selection) && count($selection)<=500,'Envía una selección de hasta 500 tinas.');
            $lines = array_column($this->repo->lines((int) $r['id']),null,'id');
            $lineList=array_values($lines);
            $desired = [];
            foreach ($selection as $s) {
                Policy::require(is_array($s),'Selección inválida.');
                $stockId = (int) ($s['stock_unit_id'] ?? 0); $lineId = (int) ($s['request_line_id'] ?? 0);
                Policy::require($stockId>0 && isset($lines[$lineId]) && ! isset($desired[$stockId]),
                    'Una tina no puede repetirse y debe corresponder a una línea de esta solicitud.');
                $desired[$stockId] = $lineId;
            }
            ksort($desired,SORT_NUMERIC);
            $existing = array_column($this->repo->units((int) $r['id']),null,'stock_unit_id');
            $lockIds = array_unique(array_merge(array_keys($desired),array_keys($existing)));
            sort($lockIds,SORT_NUMERIC);
            $stocks = [];
            if ($lockIds !== []) {
                $marks = implode(',',array_fill(0,count($lockIds),'?'));
                $stocks = array_column($this->db->query('SELECT * FROM stock_units WHERE id IN ('.$marks.') ORDER BY id FOR UPDATE',$lockIds)->getResultArray(),null,'id');
            }
            foreach ($desired as $stockId=>$lineId) {
                $stock = $stocks[$stockId] ?? null;
                Policy::require($stock !== null && (int) $stock['company_id']===(int) $r['provider_company_id']
                    && (int) $stock['warehouse_id']===(int) $r['source_warehouse_id'], 'La tina no pertenece al proveedor elegido.', 'WAREHOUSE_STOCK_SCOPE_MISMATCH');
                Policy::require(Policy::ownsStock($stock,$lines[$lineId],$lineList),
                    'La tina corresponde a otra línea más específica o a un cruce ambiguo de lote/propietario. Ajusta las líneas antes de seleccionarla.',
                    'WAREHOUSE_LINE_SCOPE_CONFLICT');
                Policy::require(! empty($stock['owner_company_id']),'La tina necesita un propietario identificado.');
                Policy::require(!isset($existing[$stockId]) || in_array($existing[$stockId]['status'],['selected','released'],true),'Esta tina ya salió en esta solicitud.');
                $own = isset($existing[$stockId]) && $existing[$stockId]['status']==='selected';
                Policy::require($own ? ($stock['status']==='reserved' && abs((float) $stock['reserved_weight_kg']-(float) $stock['on_hand_weight_kg'])<0.0001)
                    : ($stock['status']==='available' && (float) $stock['reserved_weight_kg']===0.0 && (float) $stock['on_hand_weight_kg']>0),
                    'Una tina fue reservada o modificada por otra operación. Actualiza existencias.', 'CONCURRENT_STOCK_MODIFICATION');
            }
            $released = []; $added = [];
            foreach ($existing as $stockId=>$unit) {
                if ($unit['status']==='selected' && (! isset($desired[$stockId]) || $desired[$stockId] !== (int) $unit['request_line_id'])) {
                    $this->release($r,$unit,$actor); $released[]=(int) $stockId; $existing[$stockId]['status']='released';
                }
            }
            $nextNumber = (int) ($this->db->query('SELECT COALESCE(MAX(line_number),0) AS n FROM transfer_lines WHERE transfer_id=?',[$r['transfer_id']])->getRow('n') ?? 0);
            foreach ($desired as $stockId=>$lineId) {
                if (isset($existing[$stockId]) && $existing[$stockId]['status']==='selected') { continue; }
                $stock = $this->db->query('SELECT * FROM stock_units WHERE id=? FOR UPDATE',[$stockId])->getRowArray();
                service('inventoryService')->warehouseRequestMovement($r,$stockId,'reserve',(float) $stock['on_hand_weight_kg'],null,$actor);
                $now = date('Y-m-d H:i:s');
                if (isset($existing[$stockId])) {
                    $tlid = (int) $existing[$stockId]['transfer_line_id'];
                    $this->db->table('transfer_lines')->where('id',$tlid)->update(['status'=>'prepared','shipped_weight_kg'=>$stock['on_hand_weight_kg'],'received_weight_kg'=>null,'difference_kg'=>null,'updated_at'=>$now]);
                    $this->db->table('warehouse_request_units')->where('id',$existing[$stockId]['id'])->update([
                        'request_line_id'=>$lineId,'status'=>'selected','preselected_weight_kg'=>$stock['on_hand_weight_kg'],
                        'owner_company_id'=>$stock['owner_company_id'],'updated_at'=>$now]);
                } else {
                    $this->db->table('transfer_lines')->insert(['transfer_id'=>$r['transfer_id'],'line_number'=>++$nextNumber,
                        'stock_unit_id'=>$stockId,'shipped_weight_kg'=>$stock['on_hand_weight_kg'],'status'=>'prepared','created_at'=>$now,'updated_at'=>$now]);
                    $tlid = (int) $this->db->insertID();
                    $this->db->table('warehouse_request_units')->insert(['request_id'=>$r['id'],'request_line_id'=>$lineId,
                        'stock_unit_id'=>$stockId,'transfer_line_id'=>$tlid,'status'=>'selected','preselected_weight_kg'=>$stock['on_hand_weight_kg'],
                        'owner_company_id'=>$stock['owner_company_id'],'created_at'=>$now,'updated_at'=>$now]);
                }
                $added[]=(int) $stockId;
            }
            $this->db->table('warehouse_requests')->where('id',$r['id'])->update(['status'=>$r['status']==='dispatching' ? 'dispatching' : ($desired===[] ? 'accepted':'preparing')]);
            return ['added'=>$added,'released'=>$released,'selected'=>array_keys($desired)];
        });
    }

    public function accept(int $id, array $data, int $actor): array
    {
        return $this->mutate($id,$data,$actor,'provider','accepted',function(array $r) use($actor): array {
            Policy::require($r['status']==='open' && $r['supply_finalized_at']===null,'La solicitud ya fue atendida.');
            $this->db->table('warehouse_requests')->where('id',$r['id'])->update([
                'status'=>'accepted','accepted_by'=>$actor,'accepted_at'=>date('Y-m-d H:i:s')]);
            return ['accepted'=>true];
        });
    }

    // Read-only FIFO proposal; reserving requires an explicit select operation.
    public function suggest(int $id, int $lineId): array
    {
        $r=$this->repo->request($id); $this->repo->role($r,'provider'); Policy::open($r);
        $lines=array_column($this->repo->lines($id),null,'id');
        Policy::require(isset($lines[$lineId]),'Producto no encontrado en la solicitud.');
        $line=$lines[$lineId]; $lineList=array_values($lines); $grams=0; $count=0; $selectedCount=0;
        foreach ($this->repo->units($id) as $u) {
            if ($u['status']==='selected') { $selectedCount++; }
            if ((int) $u['request_line_id']!==$lineId || $u['status']==='released') { continue; }
            $grams+=Policy::grams($u['status']==='selected'?$u['preselected_weight_kg']:$u['shipped_weight_kg']); $count++;
        }
        $capacity=max(0,500-$selectedCount);
        $target=Policy::grams($line['requested_weight_kg']); $targetCount=(int) ($line['requested_unit_count']??0);
        $b=$this->db->table('stock_units')->where(['warehouse_id'=>$r['source_warehouse_id'],'company_id'=>$r['provider_company_id'],'status'=>'available','reserved_weight_kg'=>0])
            ->where('on_hand_weight_kg >',0)->where('owner_company_id IS NOT NULL',null,false);
        foreach (['species_id','size_grade_id','lot_id','owner_company_id'] as $f) { if (!empty($line[$f])) { $b->where($f,$line[$f]); } }
        $this->excludeCompetingLineScopes($b,$line,$lineList);
        // A previously shipped unit can never be shipped twice under the same folio.
        $b->where("id NOT IN (SELECT stock_unit_id FROM warehouse_request_units WHERE request_id=".(int) $id." AND status NOT IN ('released','selected'))",null,false);
        $candidates=$b->orderBy('received_at')->orderBy('id')->limit($capacity+1)->get()->getResultArray();
        $proposal=[];
        foreach ($candidates as $stock) {
            if (($grams >= $target && $count >= $targetCount) || count($proposal)>=$capacity) { break; }
            $proposal[]=['stock_unit_id'=>(int) $stock['id'],'request_line_id'=>$lineId,'unit_code'=>$stock['unit_code'],'weight_kg'=>$stock['on_hand_weight_kg'],'received_at'=>$stock['received_at']];
            $grams+=Policy::grams($stock['on_hand_weight_kg']); $count++;
        }
        return ['rows'=>$proposal,'projected_kg'=>$grams/1000,'projected_units'=>$count,
            'target_met'=>$grams >= $target && $count >= $targetCount,
            'selection_limit_reached'=>count($proposal)>=$capacity,'rule'=>'FIFO','reserved'=>false];
    }

    public function ship(int $id, array $data, int $actor): array
    {
        return $this->mutate($id,$data,$actor,'provider','unit_shipped',function(array $r) use($data,$actor): array {
            Policy::open($r);
            $stockId=(int) ($data['stock_unit_id']??0);
            $stock=$this->db->query('SELECT * FROM stock_units WHERE id=? FOR UPDATE',[$stockId])->getRowArray();
            Policy::require($stock!==null,'Tina no encontrada.');
            $this->verifyQr($stockId,(string) ($data['code']??''));
            $lines=array_column($this->repo->lines((int) $r['id']),null,'id');
            $lineList=array_values($lines);
            $lineId=(int) ($data['request_line_id']??0);
            Policy::require(isset($lines[$lineId]) && Policy::ownsStock($stock,$lines[$lineId],$lineList),
                'La tina corresponde a otra línea más específica o a un cruce ambiguo de lote/propietario.',
                'WAREHOUSE_LINE_SCOPE_CONFLICT');
            Policy::require((int) $stock['company_id']===(int) $r['provider_company_id'] && (int) $stock['warehouse_id']===(int) $r['source_warehouse_id'],'La tina no corresponde al proveedor.');
            $u=$this->db->table('warehouse_request_units')->where(['request_id'=>$r['id'],'stock_unit_id'=>$stockId])->get()->getRowArray();
            Policy::require($u===null || in_array($u['status'],['released','selected'],true),'La tina ya fue despachada en esta solicitud.','UNIT_ALREADY_PROCESSED');

            // Última protección de concurrencia: la decisión tomada en Salidas
            // debe volver a validarse dentro de la transacción antes de reservar/despachar.
            $otherAssignment=$this->db->query(<<<'SQL'
SELECT u.request_id,r.folio
FROM warehouse_request_units u
JOIN warehouse_requests r ON r.id=u.request_id
WHERE u.stock_unit_id=?
  AND u.status='selected'
  AND u.request_id<>?
  AND r.source_warehouse_id=?
  AND r.provider_company_id=?
  AND r.supply_finalized_at IS NULL
  AND r.status IN ('accepted','preparing','dispatching')
ORDER BY r.id
LIMIT 1
SQL,[$stockId,(int) $r['id'],(int) $r['source_warehouse_id'],(int) $r['provider_company_id']])->getRowArray();
            if ($otherAssignment !== null) {
                Policy::require(
                    false,
                    'La tina fue asignada a '.$otherAssignment['folio'].' mientras se procesaba la salida. Vuelve a leer el QR.',
                    'WAREHOUSE_OUTGOING_ASSIGNMENT_CONFLICT'
                );
            }

            $now=date('Y-m-d H:i:s');
            if ($u===null || $u['status']==='released') {
                Policy::require(!empty($stock['owner_company_id']),'La tina necesita un propietario identificado.');
                service('inventoryService')->warehouseRequestMovement($r,$stockId,'reserve',(float) $stock['on_hand_weight_kg'],null,$actor);
                if ($u===null) {
                    $number=(int) $this->db->query('SELECT COALESCE(MAX(line_number),0)+1 AS n FROM transfer_lines WHERE transfer_id=?',[$r['transfer_id']])->getRow('n');
                    $this->db->table('transfer_lines')->insert(['transfer_id'=>$r['transfer_id'],'line_number'=>$number,'stock_unit_id'=>$stockId,'shipped_weight_kg'=>$stock['on_hand_weight_kg'],'status'=>'prepared','created_at'=>$now,'updated_at'=>$now]);
                    $tlid=(int) $this->db->insertID();
                    $this->db->table('warehouse_request_units')->insert(['request_id'=>$r['id'],'request_line_id'=>$lineId,'stock_unit_id'=>$stockId,'transfer_line_id'=>$tlid,'status'=>'selected','preselected_weight_kg'=>$stock['on_hand_weight_kg'],'owner_company_id'=>$stock['owner_company_id'],'created_at'=>$now,'updated_at'=>$now]);
                    $u=$this->unit($r,(int) $this->db->insertID());
                } else {
                    $this->db->table('transfer_lines')->where('id',$u['transfer_line_id'])->update(['status'=>'prepared','shipped_weight_kg'=>$stock['on_hand_weight_kg'],'received_weight_kg'=>null,'difference_kg'=>null,'updated_at'=>$now]);
                    $this->db->table('warehouse_request_units')->where('id',$u['id'])->update(['request_line_id'=>$lineId,'status'=>'selected','preselected_weight_kg'=>$stock['on_hand_weight_kg'],'owner_company_id'=>$stock['owner_company_id'],'updated_at'=>$now]);
                    $u=$this->unit($r,(int) $u['id']);
                }
            }
            Policy::require((int) $u['request_line_id']===$lineId,'La tina está propuesta para otra línea. Ajusta la propuesta primero.');
            Policy::require((int) $u['owner_company_id']===(int) $stock['owner_company_id'],'El propietario cambió. Revisa la propuesta.');
            $pickLocation=$this->validateOutboundLocation(
                $stock,
                (int) ($data['chamber_id'] ?? 0),
                (int) ($data['row_location_id'] ?? 0),
                (int) $r['source_warehouse_id']
            );
            $weigh=$this->recordWeight($r,$u,'outbound',$data,$actor);
            service('inventoryService')->warehouseRequestMovement($r,$stockId,'ship',(float) $weigh['weight_kg'],null,$actor);
            $this->db->table('warehouse_request_units')->where('id',$u['id'])->update(['status'=>'shipped','shipped_by'=>$actor,'shipped_at'=>$now,'updated_at'=>$now]);
            $this->db->table('transfer_lines')->where('id',$u['transfer_line_id'])->update(['status'=>'shipped','shipped_weight_kg'=>$weigh['weight_kg'],'updated_at'=>$now]);
            $this->db->table('transfers')->where('id',$r['transfer_id'])->update(['status'=>'in_transit','shipped_by'=>$actor,'shipped_at'=>$now,'updated_at'=>$now]);
            $this->db->table('warehouse_requests')->where('id',$r['id'])->update(['status'=>'dispatching']);
            return [
                'unit_id'=>$u['id'],'stock_unit_id'=>$stockId,'weight_kg'=>$weigh['weight_kg'],
                'scale_reading_id'=>$data['scale_reading_id'],
                'source_chamber_id'=>$pickLocation['chamber_id'],
                'source_row_location_id'=>$pickLocation['row_location_id'],
                'source_location_path'=>$pickLocation['path'],
            ];
        });
    }

    public function cancelShipment(int $id, array $data, int $actor): array
    {
        return $this->mutate($id,$data,$actor,'provider','unit_shipment_canceled',function(array $r) use($data,$actor): array {
            Policy::require(
                in_array((string) $r['status'], ['dispatching','dispatched'], true),
                'La solicitud no tiene un despacho cancelable.',
                'WAREHOUSE_SHIPMENT_NOT_CANCELABLE'
            );

            $reason=trim((string) ($data['reason']??''));
            Policy::require(
                mb_strlen($reason)>=5 && mb_strlen($reason)<=1000,
                'Cancelar una salida requiere un motivo de 5 a 1000 caracteres.',
                'WAREHOUSE_SHIPMENT_CANCEL_REASON_REQUIRED'
            );

            $u=$this->unit($r,(int) ($data['unit_id']??0));
            Policy::require(
                (string) $u['status']==='shipped',
                'La tina ya fue recibida, devuelta, reingresada o cancelada; ya no se puede cancelar la salida.',
                'WAREHOUSE_SHIPMENT_ALREADY_RESOLVED'
            );

            $stockId=(int) $u['stock_unit_id'];
            $movement=service('inventoryService')->cancelWarehouseRequestShipment(
                $r,
                $stockId,
                $reason,
                $actor
            );

            $restoredWeight=(float) ($movement['restored_weight_kg']??0);
            Policy::require(
                $restoredWeight>0,
                'No fue posible restaurar el saldo de la tina.',
                'WAREHOUSE_SHIPMENT_RESTORE_FAILED'
            );

            // Una vez reversado el embarque, se libera la reserva de esta tina.
            service('inventoryService')->warehouseRequestMovement(
                $r,
                $stockId,
                'release',
                $restoredWeight,
                null,
                $actor
            );

            $now=date('Y-m-d H:i:s');

            $outbound=$this->db->table('transfer_weighings')
                ->where([
                    'transfer_line_id'=>(int) $u['transfer_line_id'],
                    'direction'=>'outbound',
                    'status'=>'confirmed',
                    'is_current'=>1,
                ])
                ->get()
                ->getRowArray();

            Policy::require(
                $outbound!==null,
                'No se encontró el pesaje de salida vigente de la tina.',
                'WAREHOUSE_OUTBOUND_WEIGHT_NOT_FOUND'
            );

            $this->db->table('transfer_weighings')
                ->where('id',(int) $outbound['id'])
                ->update([
                    'status'=>'reversed',
                    'is_current'=>null,
                    'reversed_by'=>$actor,
                    'reversed_at'=>$now,
                    'reversal_reason'=>$reason,
                    'updated_at'=>$now,
                ]);

            $this->db->table('warehouse_request_units')
                ->where('id',(int) $u['id'])
                ->update([
                    'status'=>'released',
                    'updated_at'=>$now,
                ]);

            $this->db->table('transfer_lines')
                ->where('id',(int) $u['transfer_line_id'])
                ->update([
                    'status'=>'canceled',
                    'received_weight_kg'=>null,
                    'difference_kg'=>null,
                    'updated_at'=>$now,
                ]);

            $remainingSent=(int) $this->db->query(
                "SELECT COUNT(*) AS n
                 FROM warehouse_request_units
                 WHERE request_id=?
                   AND status IN ('shipped','accepted','returning','returned')",
                [(int) $r['id']]
            )->getRow('n');

            $requestUpdate=[
                'status'=>'dispatching',
                'updated_at'=>$now,
            ];

            if ($r['supply_finalized_at']!==null || (string) $r['status']==='dispatched') {
                $requestUpdate['supply_finalized_by']=null;
                $requestUpdate['supply_finalized_at']=null;
            }

            $this->db->table('warehouse_requests')
                ->where('id',(int) $r['id'])
                ->update($requestUpdate);

            if ($remainingSent>0) {
                $this->db->table('transfers')
                    ->where('id',(int) $r['transfer_id'])
                    ->update([
                        'status'=>'in_transit',
                        'updated_at'=>$now,
                    ]);
            } else {
                $this->db->table('transfers')
                    ->where('id',(int) $r['transfer_id'])
                    ->update([
                        'status'=>'prepared',
                        'shipped_by'=>null,
                        'shipped_at'=>null,
                        'updated_at'=>$now,
                    ]);
            }

            return [
                'unit_id'=>(int) $u['id'],
                'stock_unit_id'=>$stockId,
                'transfer_line_id'=>(int) $u['transfer_line_id'],
                'inventory_reversal_document_id'=>$movement['reversal_document_id']??null,
                'inventory_release_document_id'=>$movement['release_document_id']??null,
                'restored_weight_kg'=>$restoredWeight,
                'request_reopened'=>($r['supply_finalized_at']!==null || (string) $r['status']==='dispatched'),
                'reason'=>$reason,
            ];
        });
    }

    public function dispatch(int $id, array $data, int $actor): array
    {
        if ((string) ($data['operation']??'')==='reopen') {
            return $this->reopenDispatch($id,$data,$actor);
        }
        return $this->mutate($id,$data,$actor,'provider','supply_finalized',function(array $r) use($data,$actor): array {
            Policy::open($r);
            $all=$this->repo->units((int) $r['id']); $byLine=[]; $sent=0;
            foreach ($all as $u) {
                if (!in_array($u['status'],['shipped','accepted','returning','returned'],true)) { continue; }
                $lineId=(int) $u['request_line_id'];
                $byLine[$lineId]['grams']=($byLine[$lineId]['grams']??0)+Policy::grams($u['shipped_weight_kg']);
                $byLine[$lineId]['units']=($byLine[$lineId]['units']??0)+1; $sent++;
            }
            Policy::require($sent>0,'No hay salidas registradas. Si no puedes surtir, rechaza la solicitud con motivo.');
            $observations=$data['line_observations']??[];
            Policy::require(is_array($observations),'Observaciones por línea inválidas.');
            foreach ($this->repo->lines((int) $r['id']) as $line) {
                $note=trim((string) ($observations[$line['id']]??''));
                Policy::validateObservation($line,(int) ($byLine[$line['id']]['grams']??0),(int) ($byLine[$line['id']]['units']??0),$note);
                $this->db->table('warehouse_request_lines')->where('id',$line['id'])->update(['supply_observation'=>$note]);
            }
            foreach ($all as $u) { if ($u['status']==='selected') { $this->release($r,$u,$actor); } }
            $this->db->table('warehouse_requests')->where('id',$r['id'])->update(['status'=>'dispatched','supply_finalized_by'=>$actor,'supply_finalized_at'=>date('Y-m-d H:i:s')]);
            $closure=$this->syncAutomaticClosure((int) $r['id'],$actor,false);
            return ['sent_units'=>$sent,'line_observations'=>$observations,'automatic_closure'=>$closure];
        });
    }

    public function reopenDispatch(int $id, array $data, int $actor): array
    {
        return $this->mutate($id,$data,$actor,'provider','dispatch_reopened',function(array $r) use($data,$actor): array {
            Policy::require(
                $r['supply_finalized_at']!==null && in_array((string) $r['status'],['dispatched','closed'],true),
                'El despacho no está finalizado o la solicitud no puede reabrirse.',
                'WAREHOUSE_DISPATCH_NOT_REOPENABLE'
            );

            $reason=trim((string) ($data['reason']??''));
            Policy::require(
                mb_strlen($reason)>=5 && mb_strlen($reason)<=1000,
                'Reabrir el despacho requiere un motivo de 5 a 1000 caracteres.',
                'WAREHOUSE_DISPATCH_REOPEN_REASON_REQUIRED'
            );

            $lines=$this->repo->lines((int) $r['id']);
            $units=$this->repo->units((int) $r['id']);
            $byLine=[];
            foreach ($units as $u) {
                if (!in_array((string) $u['status'],['shipped','accepted','returning','returned'],true)) { continue; }
                $lineId=(int) $u['request_line_id'];
                $byLine[$lineId]['units']=($byLine[$lineId]['units']??0)+1;
                $byLine[$lineId]['grams']=($byLine[$lineId]['grams']??0)+Policy::grams($u['shipped_weight_kg']);
            }

            $pending=[];
            foreach ($lines as $line) {
                $lineId=(int) $line['id'];
                $sentUnits=(int) ($byLine[$lineId]['units']??0);
                $sentGrams=(int) ($byLine[$lineId]['grams']??0);
                $requestedUnits=$line['requested_unit_count']===null?null:(int) $line['requested_unit_count'];
                $requestedGrams=Policy::grams($line['requested_weight_kg']);
                $needsMore=$requestedUnits!==null ? $sentUnits<$requestedUnits : $sentGrams<$requestedGrams;
                if ($needsMore) {
                    $pending[]=[
                        'request_line_id'=>$lineId,
                        'line_number'=>(int) $line['line_number'],
                        'requested_units'=>$requestedUnits,
                        'sent_units'=>$sentUnits,
                        'requested_weight_kg'=>$requestedGrams/1000,
                        'sent_weight_kg'=>$sentGrams/1000,
                    ];
                }
            }
            Policy::require(
                $pending!==[],
                'El despacho ya cubre lo solicitado. Para enviar producto adicional genera otra solicitud.',
                'WAREHOUSE_DISPATCH_ALREADY_COVERED'
            );

            $inTransit=0;
            foreach ($units as $u) {
                if (in_array((string) $u['status'],['shipped','returning'],true)) { $inTransit++; }
            }
            $now=date('Y-m-d H:i:s');
            $this->db->table('warehouse_requests')->where('id',$r['id'])->update([
                'status'=>'dispatching',
                'supply_finalized_by'=>null,
                'supply_finalized_at'=>null,
                'receiver_validated_by'=>null,
                'receiver_validated_at'=>null,
                'closed_at'=>null,
            ]);
            $this->db->table('transfers')->where('id',$r['transfer_id'])->update([
                'status'=>$inTransit>0?'in_transit':'prepared',
                'received_by'=>null,
                'received_at'=>null,
                'updated_at'=>$now,
            ]);
            return ['reason'=>$reason,'pending_lines'=>$pending,'preserved_units'=>count($units),'in_transit_units'=>$inTransit];
        });
    }

    public function receive(int $id, array $data, int $actor): array
    {
        if ((string) ($data['operation']??'')==='cancel_receipt') {
            return $this->cancelReceipt($id,$data,$actor);
        }

        // La recepción se confirma primero y cierra su propia transacción.
        // La salida terminal a Producción se intenta después para que una falla
        // nunca revierta una recepción física válida.
        $detail=$this->mutate($id,$data,$actor,'receiver','receipt_registered',function(array $r) use($data,$actor): array {
            Policy::require(in_array($r['status'],['dispatching','dispatched'],true),'La solicitud no está en recepción.');
            $u=$this->unit($r,(int) ($data['unit_id']??0));
            Policy::require($u['status']==='shipped','La tina ya fue registrada o no pertenece al envío.','UNIT_ALREADY_PROCESSED');
            $this->db->query('SELECT id FROM stock_units WHERE id=? FOR UPDATE',[$u['stock_unit_id']]);
            $this->verifyQr((int) $u['stock_unit_id'],(string) ($data['code']??''));
            $decision=(string) ($data['decision']??'');
            Policy::require(in_array($decision,['accept','return'],true),'Selecciona aceptar o devolver.');
            $note=trim((string) ($data['checklist_notes']??''));
            Policy::require(mb_strlen($note)<=1000,'La observación admite hasta 1000 caracteres.');
            if ($decision==='accept') {
                Policy::require(($data['identity_checked']??false)===true && ($data['condition_checked']??false)===true,'Verifica identidad y condición de la tina.');
            }
            $withWeight=$decision==='accept' || ($data['weigh_return']??false)===true;
            Policy::require($withWeight || empty($data['scale_reading_id']),'Confirma si usarás el peso de devolución o desactiva el pesaje.');
            $weigh=$withWeight?$this->recordWeight($r,$u,'inbound',$data,$actor):null;
            $now=date('Y-m-d H:i:s');
            $update=['identity_checked'=>($data['identity_checked']??false)===true?1:0,'condition_checked'=>($data['condition_checked']??false)===true?1:0,'checklist_notes'=>$note,'updated_at'=>$now];
            if ($decision==='accept') {
                $placement=$this->validateInboundLocation(
                    (int) ($data['chamber_id']??0),
                    (int) ($data['row_location_id']??0),
                    (int) $r['destination_warehouse_id']
                );
                service('inventoryService')->warehouseRequestMovement($r,(int) $u['stock_unit_id'],'accept',(float) $weigh['weight_kg'],(int) $placement['row_location_id'],$actor);
                $update+=['status'=>'accepted','accepted_by'=>$actor,'accepted_at'=>$now]; $status='received';
            } else {
                $reason=trim((string) ($data['return_reason']??''));
                Policy::require(mb_strlen($reason)>=5 && mb_strlen($reason)<=1000,'La devolución requiere un motivo de 5 a 1000 caracteres.');
                $reference=(float) ($weigh['weight_kg']??$this->currentWeight((int) $u['transfer_line_id'],'outbound')['weight_kg']);
                service('inventoryService')->warehouseRequestMovement($r,(int) $u['stock_unit_id'],'return_start',$reference,null,$actor);
                $update+=['status'=>'returning','return_reason'=>$reason,'return_started_by'=>$actor,'return_started_at'=>$now,'return_without_weight'=>$withWeight?0:1]; $status='returning';
            }
            $this->db->table('warehouse_request_units')->where('id',$u['id'])->update($update);
            $this->db->table('transfer_lines')->where('id',$u['transfer_line_id'])->update(['status'=>$status,'received_weight_kg'=>$weigh['weight_kg']??null,
                'difference_kg'=>$weigh===null?null:round((float) $weigh['weight_kg']-(float) $this->currentWeight((int) $u['transfer_line_id'],'outbound')['weight_kg'],3),'updated_at'=>$now]);
            $closure=$this->syncAutomaticClosure((int) $r['id'],$actor,true);
            return ['unit_id'=>$u['id'],'decision'=>$decision,'weight_kg'=>$weigh['weight_kg']??null,'checklist'=>$update,'automatic_closure'=>$closure];
        });

        if (
            (string) ($data['decision']??'')==='accept'
            && (string) ($detail['request']['request_type']??'')==='production'
        ) {
            $detail['_production_issue']=$this->attemptProductionIssue(
                $id,
                (int) ($data['unit_id']??0),
                $actor
            );
            // Refresca después del intento para devolver estados/eventos reales.
            $fresh=$this->repo->detail($id);
            $fresh['_production_issue']=$detail['_production_issue'];
            return $fresh;
        }

        return $detail;
    }

    public function retryProductionIssue(int $id, array $data, int $actor): array
    {
        $request=$this->repo->request($id);
        $this->repo->role($request,'receiver');
        Policy::require(
            (string) ($request['request_type']??'')==='production',
            'La solicitud no corresponde a Materia prima para Producción.',
            'PRODUCTION_RETRY_REQUEST_TYPE_INVALID'
        );

        $requestedUnitId=(int) ($data['unit_id']??0);
        $builder=$this->db->table('warehouse_request_units')
            ->select('id')
            ->where(['request_id'=>$id,'status'=>'accepted'])
            ->orderBy('id');

        if ($requestedUnitId>0) {
            $builder->where('id',$requestedUnitId);
        }

        $units=$builder->get()->getResultArray();
        Policy::require($units!==[],'No hay tinas recibidas pendientes de salida a Producción.','PRODUCTION_RETRY_NO_RECEIVED_UNITS');

        $results=[];
        foreach ($units as $unit) {
            $results[]=$this->attemptProductionIssue($id,(int) $unit['id'],$actor);
        }

        $detail=$this->repo->detail($id);
        $detail['_production_issue_results']=$results;
        return $detail;
    }

    public function cancelReceipt(int $id, array $data, int $actor): array
    {
        return $this->mutate($id,$data,$actor,'receiver','receipt_canceled',function(array $r) use($data,$actor): array {
            Policy::require(
                in_array((string) $r['status'],['dispatching','dispatched','closed'],true),
                'La solicitud no tiene una recepción anulable.',
                'WAREHOUSE_RECEIPT_NOT_CANCELABLE'
            );
            $reason=trim((string) ($data['reason']??''));
            Policy::require(
                mb_strlen($reason)>=5 && mb_strlen($reason)<=1000,
                'Anular una recepción requiere un motivo de 5 a 1000 caracteres.',
                'WAREHOUSE_RECEIPT_CANCEL_REASON_REQUIRED'
            );
            $u=$this->unit($r,(int) ($data['unit_id']??0));
            Policy::require(
                (string) $u['status']==='accepted',
                'Sólo puede anularse una tina recibida y aceptada.',
                'WAREHOUSE_RECEIPT_ALREADY_RESOLVED'
            );

            $movement=(string) ($r['request_type']??'')==='production'
                ? service('inventoryService')->cancelWarehouseRequestProductionReceipt(
                    $r,
                    (int) $u['id'],
                    (int) $u['stock_unit_id'],
                    $reason,
                    $actor
                )
                : service('inventoryService')->cancelWarehouseRequestReceipt(
                    $r,
                    (int) $u['stock_unit_id'],
                    $reason,
                    $actor
                );

            $inbound=$this->db->table('transfer_weighings')->where([
                'transfer_line_id'=>(int) $u['transfer_line_id'],
                'direction'=>'inbound',
                'status'=>'confirmed',
                'is_current'=>1,
            ])->get()->getRowArray();
            Policy::require($inbound!==null,'No se encontró el pesaje de recepción vigente.','WAREHOUSE_INBOUND_WEIGHT_NOT_FOUND');

            $now=date('Y-m-d H:i:s');
            $this->db->table('transfer_weighings')->where('id',(int) $inbound['id'])->update([
                'status'=>'reversed','is_current'=>null,'reversed_by'=>$actor,'reversed_at'=>$now,
                'reversal_reason'=>$reason,'updated_at'=>$now,
            ]);
            $this->db->table('warehouse_request_units')->where('id',$u['id'])->update([
                'status'=>'shipped','accepted_by'=>null,'accepted_at'=>null,
                'identity_checked'=>0,'condition_checked'=>0,'checklist_notes'=>null,
                'inbound_tare_kg'=>null,'updated_at'=>$now,
            ]);
            $this->db->table('transfer_lines')->where('id',$u['transfer_line_id'])->update([
                'status'=>'shipped','received_weight_kg'=>null,'difference_kg'=>null,'updated_at'=>$now,
            ]);
            $this->db->table('warehouse_requests')->where('id',$r['id'])->update([
                'status'=>$r['supply_finalized_at']===null?'dispatching':'dispatched',
                'receiver_validated_by'=>null,'receiver_validated_at'=>null,'closed_at'=>null,
            ]);
            $this->db->table('transfers')->where('id',$r['transfer_id'])->update([
                'status'=>'in_transit','received_by'=>null,'received_at'=>null,'updated_at'=>$now,
            ]);

            return [
                'unit_id'=>(int) $u['id'],
                'stock_unit_id'=>(int) $u['stock_unit_id'],
                'reason'=>$reason,
                'inventory_reversal_document_id'=>$movement['reversal_document_id']??null,
                'inventory_reversal_movement_id'=>$movement['reversal_movement_id']??null,
                'status'=>'shipped',
            ];
        });
    }

    public function returnEntry(int $id, array $data, int $actor): array
    {
        return $this->mutate($id,$data,$actor,'provider','return_reentered',function(array $r) use($data,$actor): array {
            Policy::require(in_array($r['status'],['dispatching','dispatched'],true),'La solicitud no está pendiente de devoluciones.');
            $u=$this->unit($r,(int) ($data['unit_id'] ?? 0));
            Policy::require($u['status']==='returning','La tina no tiene una devolución pendiente.', 'RETURN_ALREADY_PROCESSED');
            $this->db->query('SELECT id FROM stock_units WHERE id=? FOR UPDATE',[$u['stock_unit_id']]);
            $this->verifyQr((int) $u['stock_unit_id'],(string) ($data['code']??''));
            $readingId=(int) ($data['scale_reading_id'] ?? 0);
            $reading=$this->db->query('SELECT sr.*,sc.site_id AS scale_site_id,sc.active AS scale_active FROM scale_readings sr JOIN scales sc ON sc.id=sr.scale_id WHERE sr.id=? FOR UPDATE',[$readingId])->getRowArray();
            $origin=$this->repo->warehouse((int) $r['source_warehouse_id'],(int) $r['provider_company_id']);
            Policy::require($reading !== null && (bool) $reading['stable'] && (bool) $reading['scale_active'] && $reading['unit']==='kg' && $reading['weighing_id']===null && (int) $reading['scale_site_id']===(int) $origin['site_id'],
                'Se requiere lectura estable de la báscula del origen.');
            Policy::require($this->db->table('transfer_weighings')->where('scale_reading_id',$readingId)->countAllResults()===0
                && $this->db->table('weighings')->where('scale_reading_id',$readingId)->countAllResults()===0
                && $this->db->table('warehouse_request_units')->where('return_reading_id',$readingId)->countAllResults()===0,
                'La lectura ya fue utilizada.', 'SCALE_READING_ALREADY_USED');
            Policy::reading($reading,$actor,(int) $origin['company_id'],(int) $origin['site_id']);
            $tub=$this->db->query('SELECT tb.current_tare_kg FROM tubs tb JOIN stock_units su ON su.tub_id=tb.id WHERE su.id=? FOR UPDATE',[$u['stock_unit_id']])->getRowArray();
            $tare=(float) $tub['current_tare_kg'];
            Policy::require($tare>=0 && is_finite($tare),'La tara de la tina no es válida.');
            $weight=round(Policy::grams($reading['weight'])/1000-$tare,3);
            Policy::require($weight>0,'El peso bruto debe ser mayor que la tara.');
            $inbound=$this->db->table('transfer_weighings')->where(['transfer_line_id'=>$u['transfer_line_id'],'direction'=>'inbound','status'=>'confirmed','is_current'=>1])->get()->getRowArray();
            $baseline=(float) ($inbound['weight_kg']??$this->currentWeight((int) $u['transfer_line_id'],'outbound')['weight_kg']);
            $allowed=max((float) env('inventory.transfer_weight_tolerance_kg',2),round($baseline*(float) env('inventory.transfer_weight_tolerance_percent',0.25)/100,3));
            $reason=trim((string) ($data['variance_reason'] ?? ''));
            Policy::require(mb_strlen($reason)<=255 && (abs($weight-$baseline)<=$allowed+0.0001 || mb_strlen($reason)>=5),
                'La diferencia de peso de retorno requiere un motivo de al menos cinco caracteres.');
            $placement=$this->validateInboundLocation(
                (int) ($data['chamber_id']??0),
                (int) ($data['row_location_id']??0),
                (int) $r['source_warehouse_id']
            );
            service('inventoryService')->warehouseRequestMovement($r,(int) $u['stock_unit_id'],'return_entry',$weight,(int) $placement['row_location_id'],$actor);
            $now=date('Y-m-d H:i:s');
            $this->db->table('warehouse_request_units')->where('id',$u['id'])->update(['status'=>'returned','return_reading_id'=>$readingId,
                'returned_weight_kg'=>$weight,'return_tare_kg'=>$tare,'return_variance_reason'=>$reason,'returned_by'=>$actor,'returned_at'=>$now,'updated_at'=>$now]);
            $this->db->table('transfer_lines')->where('id',$u['transfer_line_id'])->update(['status'=>'returned','updated_at'=>$now]);
            $closure=$this->syncAutomaticClosure((int) $r['id'],$actor,false);
            return ['unit_id'=>$u['id'],'returned_weight_kg'=>$weight,'scale_reading_id'=>$readingId,'variance_reason'=>$reason,'automatic_closure'=>$closure];
        });
    }

    public function close(int $id, array $data, int $actor): array
    {
        return $this->mutate($id,$data,$actor,'receiver','closed',function(array $r) use($data,$actor): array {
            Policy::require(($data['receipt_validated'] ?? false)===true,'Confirma que registraste todo lo recibido y revisaste las devoluciones.');
            Policy::closeable($r,$this->repo->units((int) $r['id']));
            $now=date('Y-m-d H:i:s');
            $this->db->table('warehouse_requests')->where('id',$r['id'])->update(['status'=>'closed','receiver_validated_by'=>$actor,'receiver_validated_at'=>$now,'closed_at'=>$now]);
            $this->db->table('transfers')->where('id',$r['transfer_id'])->update(['status'=>'received','received_by'=>$actor,'received_at'=>$now,'updated_at'=>$now]);
            return ['all_sent_units_resolved'=>true];
        });
    }

    public function reject(int $id, array $data, int $actor): array
    {
        return $this->mutate($id,$data,$actor,'provider','rejected',function(array $r) use($data,$actor): array {
            Policy::require($r['supply_finalized_at']===null && in_array($r['status'],['open','accepted','preparing'],true),'Ya existen salidas o la solicitud terminó; no puede rechazarse completa.');
            $reason=trim((string) ($data['reason'] ?? ''));
            Policy::require(mb_strlen($reason)>=5 && mb_strlen($reason)<=1000,'Indica el motivo del rechazo completo (5 a 1000 caracteres).');
            foreach ($this->repo->units((int) $r['id']) as $u) { if ($u['status']==='selected') { $this->release($r,$u,$actor); } }
            $now=date('Y-m-d H:i:s');
            $this->db->table('warehouse_requests')->where('id',$r['id'])->update(['status'=>'rejected','rejected_by'=>$actor,'rejection_reason'=>$reason,'supply_finalized_by'=>$actor,'supply_finalized_at'=>$now,'closed_at'=>$now]);
            $this->db->table('transfers')->where('id',$r['transfer_id'])->update(['status'=>'canceled','updated_at'=>$now]);
            return ['reason'=>$reason];
        });
    }

    public function captureAgentScale(array $data, int $actor): array
    {
        $scope=$this->repo->context();
        $scaleId=(int) ($data['scale_id']??0);
        $scale=$this->db->query(<<<'SQL'
SELECT sc.*, s.company_id
FROM scales sc
JOIN sites s ON s.id=sc.site_id AND s.active=1
WHERE sc.id=? AND sc.site_id=? AND sc.active=1
SQL,[$scaleId,(int) $scope['site_id']])->getRowArray();
        Policy::require($scale!==null,'Selecciona una báscula activa de esta instalación.','SCALE_NOT_AVAILABLE');

        $testMode=filter_var($data['test_mode']??false,FILTER_VALIDATE_BOOLEAN);
        if ($testMode) {
            Policy::require(defined('ENVIRONMENT') && ENVIRONMENT !== 'production',
                'El modo de peso ficticio está deshabilitado en producción.','SCALE_TEST_MODE_DISABLED');
            $weight=(float) ($data['weight']??0);
            Policy::require(is_finite($weight) && $weight>=0.001 && $weight<=9999.999,
                'El peso ficticio debe estar entre 0.001 y 9999.999 kg.','INVALID_SCALE_TEST_WEIGHT');
            $timezone=new \DateTimeZone(config('App')->appTimezone);
            $capturedAt=new \DateTimeImmutable('now',$timezone);
            $baseIdentifier=trim((string) ($scale['station_code']?:$scale['code']));
            $identifier=mb_substr('TEST-UI:'.($baseIdentifier!==''?$baseIdentifier:'BASCULA'),0,100);
            $raw='TEST_UI|'.$capturedAt->format('YmdHis.u').'|'.number_format($weight,3,'.','');
            $reading=new WeightReading(
                round($weight,3),'kg',true,$capturedAt,$identifier,$raw,'test-ui','simulator'
            );
            $captured=service('scaleReadingService')->capture($reading,$scaleId,$actor);
            return $captured+[
                'test_mode'=>true,
                'scale_name'=>$scale['name'],
                'port_name'=>strtoupper(trim((string) ($scale['port_name']??''))),
            ];
        }

        Policy::require(in_array((string) $scale['connection_type'],['rs232','usb_serial'],true),
            'La estación requiere una báscula serial configurada en Catálogos.','SCALE_CONNECTION_NOT_SUPPORTED');
        Policy::require(trim((string) $scale['port_name'])!=='','La báscula no tiene puerto COM configurado.','SCALE_PORT_NOT_CONFIGURED');

        $weight=(float) ($data['weight']??0);
        $unit=strtolower(trim((string) ($data['unit']??'')));
        $stable=filter_var($data['stable']??false,FILTER_VALIDATE_BOOLEAN);
        $raw=trim((string) ($data['original_value']??''));
        $identifier=trim((string) ($data['scale_identifier']??''));
        $port=strtoupper(trim((string) ($data['port_name']??'')));
        $connectionStatus=trim((string) ($data['connection_status']??'connected'));
        Policy::require(is_finite($weight) && $weight>0 && $unit==='kg','La lectura de la báscula debe ser positiva y expresada en kg.','INVALID_SCALE_READING');
        Policy::require($stable,'La báscula todavía no reporta un peso estable.','UNSTABLE_WEIGHT');
        Policy::require($raw!=='' && mb_strlen($raw)<=255,'La respuesta RAW de la báscula es obligatoria.','INVALID_SCALE_RAW_VALUE');
        Policy::require($port===strtoupper((string) $scale['port_name']),'La lectura proviene de un puerto COM distinto al configurado.','SCALE_PORT_MISMATCH');
        $expectedIdentifier=trim((string) ($scale['station_code']?:$scale['code']));
        Policy::require($identifier!=='' && hash_equals($expectedIdentifier,$identifier),'La lectura no corresponde a la báscula seleccionada.','SCALE_IDENTIFIER_MISMATCH');

        $regex=trim((string) ($scale['weight_regex']??''));
        Policy::require($regex!=='' && mb_strlen($regex)<=160,'El patrón de lectura de la báscula no está configurado.','SCALE_REGEX_NOT_CONFIGURED');
        $compiled='~'.str_replace('~','\\~',$regex).'~u';
        $matched=@preg_match($compiled,$raw,$matches);
        Policy::require($matched===1 && isset($matches[0]),'La respuesta RAW no cumple el patrón configurado para la báscula.','SCALE_RAW_PATTERN_MISMATCH');
        $parsed=(float) str_replace(',','.',(string) $matches[0]);
        Policy::require(abs($parsed-$weight)<=0.0015,'El peso recibido no coincide con la respuesta RAW del agente.','SCALE_WEIGHT_TAMPERED');

        try { $capturedAt=new \DateTimeImmutable((string) ($data['captured_at']??'')); }
        catch (\Throwable) { $capturedAt=false; }
        Policy::require($capturedAt instanceof \DateTimeImmutable,'La lectura no contiene una fecha válida.','INVALID_SCALE_TIMESTAMP');
        $age=abs(time()-$capturedAt->getTimestamp());
        Policy::require($age<=120,'La lectura del agente es demasiado antigua. Vuelve a leer la báscula.','SCALE_AGENT_READING_EXPIRED');
        Policy::require($connectionStatus!=='' && mb_strlen($connectionStatus)<=30,'El estado de conexión del agente no es válido.','INVALID_SCALE_READING');

        $reading=new WeightReading(
            $weight,'kg',true,$capturedAt,$identifier,$raw,$connectionStatus,'local_middleware:scale_agent'
        );
        $captured=service('scaleReadingService')->capture($reading,$scaleId,$actor);
        $now=date('Y-m-d H:i:s');
        $this->db->table('scales')->where('id',$scaleId)->update([
            'last_test_at'=>$now,'last_test_status'=>'ok',
            'last_test_message'=>'Lectura operativa desde estación de solicitudes entre almacenes.',
            'last_read_weight_kg'=>round($weight,3),'last_raw_value'=>$raw,'updated_at'=>$now,
        ]);
        return $captured+['port_name'=>$port,'scale_name'=>$scale['name']];
    }

    public function readScale(int $id, array $data, int $actor): array
    {
        $request=$this->repo->request($id);
        $direction=(string) ($data['direction'] ?? '');
        Policy::require(in_array($direction,['outbound','inbound','return'],true),'Dirección inválida.');
        $this->repo->role($request,$direction==='inbound'?'receiver':'provider');
        if ($direction==='outbound') { Policy::open($request); }
        else { Policy::require(in_array($request['status'],['dispatching','dispatched'],true),'El surtido no está en tránsito.'); }
        $scope=$this->repo->context();
        $scale=$this->db->table('scales')->where(['id'=>(int) ($data['scale_id'] ?? 0),'site_id'=>$scope['site_id'],'active'=>1])->get()->getRowArray();
        Policy::require($scale !== null,'Selecciona una báscula activa de esta instalación.');
        $driver=strtolower((string) env('integrations.scale.driver','simulator'));
        Policy::require($scale['adapter_key']===$driver && $scale['connection_type']===$driver,'La báscula no corresponde al controlador configurado.');
        $adapter=service('scaleAdapter'); $adapter->connect();
        try { return service('scaleReadingService')->capture($adapter->readStableWeight(),(int) $scale['id'],$actor); }
        finally { try { $adapter->disconnect(); } catch (\Throwable) {} }
    }

    /** @return array{chamber_id:int,row_location_id:int,path:string} */
    private function validateInboundLocation(int $chamberId, int $rowLocationId, int $warehouseId): array
    {
        Policy::require($chamberId>0 && $rowLocationId>0,'Selecciona Cámara y Fila antes de registrar la recepción.','WAREHOUSE_INCOMING_LOCATION_REQUIRED');
        $chamber=$this->db->table('chambers')->select('id,name,code')->where([
            'id'=>$chamberId,'warehouse_id'=>$warehouseId,'active'=>1
        ])->get()->getRowArray();
        Policy::require($chamber!==null,'La Cámara seleccionada no pertenece al almacén receptor.','WAREHOUSE_INCOMING_CHAMBER_MISMATCH');
        $row=$this->db->table('locations')->select('id,chamber_id,path,location_type')->where([
            'id'=>$rowLocationId,'warehouse_id'=>$warehouseId,'chamber_id'=>$chamberId,'active'=>1
        ])->whereIn('location_type',['row','aisle'])->get()->getRowArray();
        Policy::require($row!==null,'La Fila seleccionada no pertenece a la Cámara indicada.','WAREHOUSE_INCOMING_ROW_MISMATCH');
        return ['chamber_id'=>$chamberId,'row_location_id'=>$rowLocationId,'path'=>(string) $row['path']];
    }

    private function validateOutboundLocation(array $stock, int $chamberId, int $rowLocationId, int $warehouseId): array
    {
        Policy::require($chamberId>0 && $rowLocationId>0,'Selecciona cámara y fila antes de registrar la salida.','WAREHOUSE_OUTGOING_LOCATION_REQUIRED');
        $chamber=$this->db->table('chambers')->select('id,name,code')->where(['id'=>$chamberId,'warehouse_id'=>$warehouseId,'active'=>1])->get()->getRowArray();
        Policy::require($chamber!==null,'La cámara seleccionada no pertenece al almacén origen.','WAREHOUSE_CHAMBER_MISMATCH');
        $row=$this->db->table('locations')->select('id,chamber_id,path,location_type')->where([
            'id'=>$rowLocationId,'warehouse_id'=>$warehouseId,'chamber_id'=>$chamberId,'active'=>1
        ])->whereIn('location_type',['row','aisle'])->get()->getRowArray();
        Policy::require($row!==null,'La fila seleccionada no pertenece a la cámara indicada.','WAREHOUSE_ROW_MISMATCH');

        $currentLocationId=(int) ($stock['location_id']??0);
        if ($currentLocationId>0) {
            $belongs=$this->db->query(<<<'SQL'
WITH RECURSIVE location_chain AS (
    SELECT id,parent_id,warehouse_id,0 AS depth FROM locations WHERE id=?
    UNION ALL
    SELECT p.id,p.parent_id,p.warehouse_id,c.depth+1
    FROM locations p JOIN location_chain c ON c.parent_id=p.id
    WHERE c.depth<20
)
SELECT id FROM location_chain WHERE id=? AND warehouse_id=? LIMIT 1
SQL,[$currentLocationId,$rowLocationId,$warehouseId])->getRowArray();
            if ($belongs===null) {
                $current=$this->db->table('locations')->select('path')->where('id',$currentLocationId)->get()->getRowArray();
                Policy::require(false,'La tina está registrada en '.($current['path']??'otra ubicación').'. Corrige Cámara/Fila o actualiza primero la ubicación física.','WAREHOUSE_OUTGOING_LOCATION_MISMATCH');
            }
        }
        return ['chamber_id'=>$chamberId,'row_location_id'=>$rowLocationId,'path'=>(string) $row['path']];
    }

    private function excludeCompetingLineScopes($builder, array $line, array $lines): void
    {
        $specificity=Policy::specificity($line);
        foreach ($lines as $other) {
            if ((int) ($other['id']??0)===(int) ($line['id']??0)
                || (int) $other['species_id']!==(int) $line['species_id']
                || (int) $other['size_grade_id']!==(int) $line['size_grade_id']
                || Policy::specificity($other)<$specificity) {
                continue;
            }
            $parts=[];
            if (!empty($other['lot_id'])) { $parts[]='lot_id <> '.(int) $other['lot_id']; }
            if (!empty($other['owner_company_id'])) { $parts[]='owner_company_id <> '.(int) $other['owner_company_id']; }
            $builder->where($parts===[]?'1=0':'('.implode(' OR ',$parts).')',null,false);
        }
    }

    private function verifyQr(int $stockId, string $code): void
    {
        $code=Policy::qr($code);
        $row=$this->db->query('SELECT su.id FROM stock_units su JOIN tubs tb ON tb.id=su.tub_id LEFT JOIN labels lb ON lb.id=su.label_id WHERE su.id=? AND (su.unit_code=? OR su.uuid=? OR tb.code=? OR tb.barcode=? OR lb.code=?)',[$stockId,$code,$code,$code,$code,$code])->getRowArray();
        Policy::require($row!==null,'Vuelve a leer el QR de la tina que vas a registrar.','WAREHOUSE_QR_MISMATCH');
    }

    private function recordWeight(array $r, array $unit, string $direction, array $data, int $actor): array
    {
        Policy::require(mb_strlen((string) ($data['variance_reason']??''))<=255,'El motivo admite hasta 255 caracteres.');
        $result=service('transferService')->recordWeight((int) $r['transfer_id'],$direction,[
            'stock_unit_id'=>$unit['stock_unit_id'],'scale_reading_id'=>(int) ($data['scale_reading_id']??0),
            'variance_reason'=>trim((string) ($data['variance_reason']??''))],$actor,true);
        $this->db->table('warehouse_request_units')->where('id',$unit['id'])->update([$direction.'_tare_kg'=>$result['tare_weight_kg']]);
        return $result;
    }

    private function syncAutomaticClosure(int $requestId, int $actor, bool $actorIsReceiver): array
    {
        $request=$this->db->query('SELECT * FROM warehouse_requests WHERE id=? FOR UPDATE',[$requestId])->getRowArray();
        Policy::require($request!==null,'Solicitud no encontrada.','WAREHOUSE_REQUEST_NOT_FOUND');

        $units=$this->db->table('warehouse_request_units')
            ->select('status,accepted_by,accepted_at,return_started_by,return_started_at')
            ->where('request_id',$requestId)
            ->whereIn('status',['shipped','accepted','returning','returned'])
            ->get()->getResultArray();

        $counts=['shipped'=>0,'accepted'=>0,'returning'=>0,'returned'=>0];
        foreach ($units as $unit) {
            $state=(string) $unit['status'];
            if (isset($counts[$state])) { $counts[$state]++; }
        }

        $resolved=$units!==[] && $counts['shipped']===0 && $counts['returning']===0;
        $finalized=$request['supply_finalized_at']!==null;
        if (!$resolved || !$finalized) {
            return [
                'closed'=>false,
                'reception_status'=>$units===[]?'pending':($resolved?'up_to_date':'in_progress'),
                'counts'=>$counts,
            ];
        }

        if ((string) $request['status']==='closed') {
            return ['closed'=>true,'reception_status'=>'finalized','counts'=>$counts];
        }

        $receiverActor=$actorIsReceiver?$actor:0;
        if ($receiverActor<=0) {
            $decision=$this->db->query(<<<'SQL'
SELECT actor_user_id
FROM (
    SELECT accepted_by AS actor_user_id,accepted_at AS occurred_at
    FROM warehouse_request_units
    WHERE request_id=? AND accepted_by IS NOT NULL AND accepted_at IS NOT NULL
    UNION ALL
    SELECT return_started_by AS actor_user_id,return_started_at AS occurred_at
    FROM warehouse_request_units
    WHERE request_id=? AND return_started_by IS NOT NULL AND return_started_at IS NOT NULL
) x
ORDER BY occurred_at DESC
LIMIT 1
SQL,[$requestId,$requestId])->getRowArray();
            $receiverActor=(int) ($decision['actor_user_id']??0);
        }

        $now=date('Y-m-d H:i:s');
        $this->db->table('warehouse_requests')->where('id',$requestId)->update([
            'status'=>'closed',
            'receiver_validated_by'=>$receiverActor>0?$receiverActor:null,
            'receiver_validated_at'=>$now,
            'closed_at'=>$now,
        ]);
        $this->db->table('transfers')->where('id',$request['transfer_id'])->update([
            'status'=>'received','received_by'=>$receiverActor>0?$receiverActor:null,'received_at'=>$now,'updated_at'=>$now,
        ]);

        return ['closed'=>true,'reception_status'=>'finalized','counts'=>$counts];
    }


    private function productionDestination(int $requestId): string
    {
        $events=$this->db->table('warehouse_request_events')
            ->select('details')
            ->where(['request_id'=>$requestId,'action'=>'created'])
            ->orderBy('id','DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        if ($events!==null) {
            try {
                $details=json_decode((string) ($events['details']??''),true,512,JSON_THROW_ON_ERROR);
                $area=trim((string) ($details['production_destination']??''));
                if ($area!=='') {
                    return $area;
                }
            } catch (\Throwable) {
                // Se valida abajo con un mensaje operativo claro.
            }
        }

        return '';
    }

    private function attemptProductionIssue(int $requestId, int $unitId, int $actor): array
    {
        $area=$this->productionDestination($requestId);

        try {
            Policy::require(
                $area!=='',
                'La solicitud de Materia prima no tiene Área destino de producción. Corrige la solicitud antes de reintentar.',
                'PRODUCTION_DESTINATION_MISSING'
            );

            $result=service('inventoryService')->issueWarehouseRequestProductionUnit(
                $requestId,
                $unitId,
                $area,
                $actor
            );

            $this->standaloneProductionEvent(
                $requestId,
                'production_issue_completed',
                $actor,
                $result
            );

            return $result+['pending'=>false];
        } catch (\Throwable $e) {
            $pending=[
                'request_id'=>$requestId,
                'unit_id'=>$unitId,
                'production_area'=>$area!==''?$area:null,
                'pending'=>true,
                'status'=>'pending',
                'message'=>$e->getMessage(),
                'error_class'=>$e::class,
            ];

            $this->standaloneProductionEvent(
                $requestId,
                'production_issue_pending',
                $actor,
                $pending
            );

            // No relanzar: la recepción ya quedó confirmada y no debe revertirse.
            return $pending;
        }
    }

    private function standaloneProductionEvent(int $requestId, string $action, int $actor, array $details): void
    {
        $request=$this->repo->request($requestId);
        $now=date('Y-m-d H:i:s');

        $this->db->table('warehouse_request_events')->insert([
            'request_id'=>$requestId,
            'action'=>$action,
            'actor_user_id'=>$actor,
            'details'=>json_encode($details,JSON_THROW_ON_ERROR),
            'created_at'=>$now,
        ]);

        $scope=$this->repo->context();
        $auditDetails=$details;
        $auditDetails['destination_warehouse_id']=(int) ($request['destination_warehouse_id']??0) ?: null;

        service('auditService')->record(
            'warehouse_request.'.$action,
            'warehouse_request',
            $requestId,
            $actor,
            (int) $scope['company_id'],
            (int) $scope['site_id'],
            after: $auditDetails
        );
    }


    private function mutate(int $id, array $data, int $actor, string $role, string $action, callable $callback): array
    {
        Policy::require(isset($data['lock_version']) && filter_var($data['lock_version'],FILTER_VALIDATE_INT)!==false,
            'Actualiza el detalle antes de guardar.', 'STALE_WAREHOUSE_REQUEST');
        $this->db->transException(true)->transBegin();
        try {
            $r=$this->repo->request($id,true); $this->repo->role($r,$role);
            Policy::require((int) $r['lock_version']===(int) $data['lock_version'],
                'Otro usuario modificó la solicitud. Actualiza y revisa los datos.', 'STALE_WAREHOUSE_REQUEST');
            $this->db->query('SELECT id FROM transfers WHERE id=? FOR UPDATE',[$r['transfer_id']]);
            $result=$callback($r);
            $this->db->table('warehouse_requests')->where(['id'=>$id,'lock_version'=>$r['lock_version']])->update([
                'lock_version'=>(int) $r['lock_version']+1,'updated_at'=>date('Y-m-d H:i:s')]);
            Policy::require($this->db->affectedRows()===1,'La solicitud cambió durante la operación.', 'CONCURRENT_REQUEST_MODIFICATION');
            $this->event($r,$action,$actor,$result);
            $detail=$this->repo->detail($id);
            Policy::require($this->db->transStatus() !== false,'La operación no pudo completarse; no se guardaron cambios.','WAREHOUSE_TRANSACTION_FAILED');
            $this->db->transCommit();
            return $detail;
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }

    private function unit(array $request, int $id): array
    {
        $u=$this->db->query('SELECT * FROM warehouse_request_units WHERE id=? AND request_id=? FOR UPDATE',[$id,$request['id']])->getRowArray();
        Policy::require($u !== null,'La tina no pertenece a esta solicitud.', 'WAREHOUSE_UNIT_NOT_FOUND');
        return $u;
    }

    private function currentWeight(int $transferLineId, string $direction): array
    {
        $w=$this->db->table('transfer_weighings')->where(['transfer_line_id'=>$transferLineId,'direction'=>$direction,'status'=>'confirmed','is_current'=>1])->get()->getRowArray();
        Policy::require($w !== null,'La tina necesita pesaje confirmado de '.($direction==='outbound'?'salida':'recepción').'.');
        return $w;
    }

    private function release(array $r, array $unit, int $actor): void
    {
        service('inventoryService')->warehouseRequestMovement($r,(int) $unit['stock_unit_id'],'release',(float) $unit['preselected_weight_kg'],null,$actor);
        $now=date('Y-m-d H:i:s');
        $this->db->table('warehouse_request_units')->where('id',$unit['id'])->update(['status'=>'released','updated_at'=>$now]);
        $this->db->table('transfer_lines')->where('id',$unit['transfer_line_id'])->update(['status'=>'canceled','updated_at'=>$now]);
        $this->db->table('transfer_weighings')->where(['transfer_line_id'=>$unit['transfer_line_id'],'is_current'=>1])->update([
            'is_current'=>null,'status'=>'reversed','reversed_by'=>$actor,'reversed_at'=>$now,'reversal_reason'=>'Tina retirada de la preselección antes del envío.','updated_at'=>$now]);
    }

    private function event(array $r, string $action, int $actor, array $details): void
    {
        $this->db->table('warehouse_request_events')->insert(['request_id'=>$r['id'],'action'=>$action,'actor_user_id'=>$actor,
            'details'=>json_encode($details,JSON_THROW_ON_ERROR),'created_at'=>date('Y-m-d H:i:s')]);
        service('auditService')->record('warehouse_request.'.$action,'warehouse_request',(int) $r['id'],$actor,(int) $this->repo->context()['company_id'],
            (int) $this->repo->context()['site_id'],null,$details);
    }
}
