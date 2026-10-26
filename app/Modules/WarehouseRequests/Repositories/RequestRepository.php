<?php
namespace App\Modules\WarehouseRequests\Repositories;

use CodeIgniter\Database\BaseConnection;
use App\Modules\WarehouseRequests\Domain\RequestPolicy as Policy;

final class RequestRepository
{
    public function __construct(private readonly BaseConnection $db) {}

    public function context(): array
    {
        $scope = service('scopeContext')->restrictions();
        Policy::require($scope['configured'] && !empty($scope['company_id']) && !empty($scope['warehouse_id']),
            'Selecciona empresa, instalación y almacén en tu alcance operativo.', 'OPERATIONAL_SCOPE_REQUIRED');
        $warehouse = $this->warehouse((int) $scope['warehouse_id'], (int) $scope['company_id']);
        service('scopeContext')->assertWarehouse((int) $warehouse['id']);
        return $warehouse;
    }

    // companyId=null permite consultar proveedores del grupo. Cada escritura valida el almacén del actor.
    public function warehouse(int $id, ?int $companyId = null, bool $active = true): array
    {
        $sql = 'SELECT w.*, s.name AS site_name, c.trade_name AS company_name FROM warehouses w JOIN sites s ON s.id=w.site_id AND s.company_id=w.company_id JOIN companies c ON c.id=w.company_id WHERE w.id=?';
        $args = [$id];
        if ($companyId !== null) { $sql .= ' AND w.company_id=?'; $args[] = $companyId; }
        if ($active) { $sql .= ' AND w.active=1 AND s.active=1 AND c.active=1'; }
        $row = $this->db->query($sql,$args)->getRowArray();
        Policy::require($row !== null,'El almacén no corresponde a la empresa o no está activo.','WAREHOUSE_SCOPE_MISMATCH');
        return $row;
    }

    public function request(int $id, bool $lock = false): array
    {
        $row = $this->db->query('SELECT * FROM warehouse_requests WHERE id=?'.($lock?' FOR UPDATE':''),[$id])->getRowArray();
        Policy::require($row !== null,'Solicitud no encontrada.','WAREHOUSE_REQUEST_NOT_FOUND');
        $scope = $this->context();
        $provider = (int) $row['source_warehouse_id']===(int) $scope['id'] && (int) $row['provider_company_id']===(int) $scope['company_id'];
        $receiver = (int) $row['destination_warehouse_id']===(int) $scope['id'] && (int) $row['company_id']===(int) $scope['company_id'];
        Policy::require($provider || $receiver,'La solicitud no corresponde a tu almacén.','WAREHOUSE_REQUEST_SCOPE_MISMATCH');
        return $row;
    }

    public function role(array $request, string $role): void
    {
        $scope = $this->context();
        $provider = $role==='provider';
        Policy::require((int) $scope['id']===(int) $request[$provider?'source_warehouse_id':'destination_warehouse_id']
            && (int) $scope['company_id']===(int) $request[$provider?'provider_company_id':'company_id'],
            'Esta operación corresponde al otro almacén.','WAREHOUSE_ACTION_SCOPE_MISMATCH');
    }

    public function listing(int $before = 0, array $filters = []): array
    {
        $scope = $this->context();
        $b = $this->db->table('warehouse_requests r')->select('r.*, sw.name AS source_name, dw.name AS destination_name')
            ->join('warehouses sw','sw.id=r.source_warehouse_id')->join('warehouses dw','dw.id=r.destination_warehouse_id')
            ->groupStart()->groupStart()->where('r.provider_company_id',$scope['company_id'])->where('r.source_warehouse_id',$scope['id'])->groupEnd()
            ->orGroupStart()->where('r.company_id',$scope['company_id'])->where('r.destination_warehouse_id',$scope['id'])->groupEnd()->groupEnd();
        if ($before>0) { $b->where('r.id <',$before); }
        if (!empty($filters['status'])) { $b->where('r.status',(string) $filters['status']); }
        if (($filters['role']??'')==='provider') { $b->where('r.source_warehouse_id',$scope['id']); }
        if (($filters['role']??'')==='receiver') { $b->where('r.destination_warehouse_id',$scope['id']); }
        if (!empty($filters['search'])) { $b->like('r.folio',mb_substr(trim((string) $filters['search']),0,50)); }
        $rows = $b->orderBy('r.id','DESC')->limit(51)->get()->getResultArray();
        $more = count($rows)>50;
        if ($more) { array_pop($rows); }
        return ['rows'=>$rows,'next_before'=>$more?(int) end($rows)['id']:null];
    }

    public function lines(int $id): array
    {
        return $this->db->query('SELECT rl.*, s.name AS species_name, sg.name AS size_name, l.code AS lot_code, c.trade_name AS owner_name FROM warehouse_request_lines rl JOIN species s ON s.id=rl.species_id JOIN size_grades sg ON sg.id=rl.size_grade_id LEFT JOIN lots l ON l.id=rl.lot_id LEFT JOIN companies c ON c.id=rl.owner_company_id WHERE rl.request_id=? ORDER BY rl.line_number',[$id])->getResultArray();
    }

    public function units(int $id, ?int $after = null, string $status = ''): array
    {
        $sql = <<<'SQL'
SELECT u.*, su.unit_code, tb.code AS tub_code, l.code AS lot_code, c.trade_name AS owner_name,
 tl.shipped_weight_kg, tl.received_weight_kg, tl.difference_kg,
 wo.id AS outbound_weighing_id, wo.weight_kg AS outbound_weight_kg,
 wi.id AS inbound_weighing_id, wi.weight_kg AS inbound_weight_kg, loc.path AS location_path
FROM warehouse_request_units u
JOIN stock_units su ON su.id=u.stock_unit_id JOIN tubs tb ON tb.id=su.tub_id JOIN lots l ON l.id=su.lot_id
JOIN companies c ON c.id=u.owner_company_id LEFT JOIN locations loc ON loc.id=su.location_id
JOIN transfer_lines tl ON tl.id=u.transfer_line_id
LEFT JOIN transfer_weighings wo ON wo.transfer_line_id=tl.id AND wo.direction='outbound' AND wo.status='confirmed' AND wo.is_current=1
LEFT JOIN transfer_weighings wi ON wi.transfer_line_id=tl.id AND wi.direction='inbound' AND wi.status='confirmed' AND wi.is_current=1
WHERE u.request_id=?
SQL;
        $args = [$id];
        if ($after!==null) { $sql.=' AND u.id>?'; $args[]=max(0,$after); }
        if ($status!=='') { $sql.=' AND u.status=?'; $args[]=$status; }
        return $this->db->query($sql.' ORDER BY u.id'.($after!==null?' LIMIT 101':''),$args)->getResultArray();
    }

    private function requesterProposals(int $id): array
    {
        $event=$this->db->table('warehouse_request_events')
            ->select('details')
            ->where(['request_id'=>$id,'action'=>'created'])
            ->orderBy('id','ASC')
            ->limit(1)
            ->get()
            ->getRowArray();

        if ($event===null || trim((string) ($event['details'] ?? ''))==='') {
            return [];
        }

        try {
            $details=json_decode((string) $event['details'],true,512,JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        $proposal=$details['requester_proposals'] ?? [];
        if (!is_array($proposal) || $proposal===[]) {
            return [];
        }

        $ids=[];
        foreach ($proposal as $row) {
            if (!is_array($row)) { continue; }
            $stockId=(int) ($row['stock_unit_id'] ?? 0);
            if ($stockId>0) { $ids[$stockId]=true; }
        }

        $current=[];
        if ($ids!==[]) {
            $stockIds=array_keys($ids);
            sort($stockIds,SORT_NUMERIC);
            $marks=implode(',',array_fill(0,count($stockIds),'?'));
            $rows=$this->db->query(
                "SELECT su.id AS stock_unit_id,su.unit_code,su.on_hand_weight_kg AS current_weight_kg,
                        su.reserved_weight_kg,su.status AS stock_status,
                        tb.code AS tub_code,l.code AS lot_code,c.trade_name AS owner_name,
                        loc.path AS location_path
                 FROM stock_units su
                 JOIN tubs tb ON tb.id=su.tub_id
                 JOIN lots l ON l.id=su.lot_id
                 LEFT JOIN companies c ON c.id=su.owner_company_id
                 LEFT JOIN locations loc ON loc.id=su.location_id
                 WHERE su.id IN (".$marks.")
                 ORDER BY su.id",
                $stockIds
            )->getResultArray();
            $current=array_column($rows,null,'stock_unit_id');
        }

        $result=[];
        foreach ($proposal as $row) {
            if (!is_array($row)) { continue; }
            $stockId=(int) ($row['stock_unit_id'] ?? 0);
            if ($stockId<=0) { continue; }

            $now=$current[$stockId] ?? [];
            $stockStatus=(string) ($now['stock_status'] ?? '');
            $reserved=(float) ($now['reserved_weight_kg'] ?? 0);
            $weight=(float) ($now['current_weight_kg'] ?? $row['proposed_weight_kg'] ?? 0);

            $result[]=[
                'request_line_id'=>(int) ($row['request_line_id'] ?? 0),
                'line_number'=>(int) ($row['line_number'] ?? 0),
                'stock_unit_id'=>$stockId,
                'proposed_weight_kg'=>(float) ($row['proposed_weight_kg'] ?? 0),
                'current_weight_kg'=>$weight,
                'unit_code'=>(string) ($now['unit_code'] ?? ''),
                'tub_code'=>(string) ($now['tub_code'] ?? ''),
                'lot_code'=>(string) ($now['lot_code'] ?? ''),
                'owner_name'=>(string) ($now['owner_name'] ?? ''),
                'location_path'=>(string) ($now['location_path'] ?? ''),
                'stock_status'=>$stockStatus,
                'reserved_weight_kg'=>$reserved,
                'available_now'=>$stockStatus==='available' && abs($reserved)<0.0001 && $weight>0,
            ];
        }

        return $result;
    }


    private function productionDetail(int $requestId, array $request): ?array
    {
        if ((string) ($request['request_type']??'')!=='production') {
            return null;
        }

        $created=$this->db->table('warehouse_request_events')
            ->select('details')
            ->where(['request_id'=>$requestId,'action'=>'created'])
            ->orderBy('id','ASC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $area='';
        if ($created!==null) {
            try {
                $details=json_decode((string) ($created['details']??''),true,512,JSON_THROW_ON_ERROR);
                $area=trim((string) ($details['production_destination']??''));
            } catch (\Throwable) {
                $area='';
            }
        }

        $rows=$this->db->query(
            <<<'SQL'
SELECT
    u.id AS unit_id,
    u.stock_unit_id,
    u.status AS receipt_status,
    u.accepted_at,
    u.accepted_by,
    su.unit_code,
    tb.code AS tub_code,
    wi.weight_kg AS inbound_weight_kg,
    p.id AS production_document_id,
    p.folio AS production_folio,
    p.confirmed_at AS production_at,
    p.confirmed_by AS production_by,
    pl.quantity_kg AS production_weight_kg,
    pr.id AS production_reversal_document_id,
    pr.confirmed_at AS production_reversed_at,
    pr.confirmed_by AS production_reversed_by
FROM warehouse_request_units u
INNER JOIN stock_units su ON su.id=u.stock_unit_id
INNER JOIN tubs tb ON tb.id=su.tub_id
LEFT JOIN transfer_weighings wi
    ON wi.transfer_line_id=u.transfer_line_id
   AND wi.direction='inbound'
   AND wi.status='confirmed'
   AND wi.is_current=1
LEFT JOIN inventory_documents p
    ON p.id=(
        SELECT MAX(p2.id)
        FROM inventory_documents p2
        WHERE p2.source_document_type='warehouse_request_production'
          AND p2.source_document_id=u.id
          AND p2.movement_type='production_issue'
          AND p2.status='confirmed'
    )
LEFT JOIN inventory_document_lines pl
    ON pl.inventory_document_id=p.id
   AND pl.stock_unit_id=u.stock_unit_id
LEFT JOIN inventory_documents pr
    ON pr.reversal_of_id=p.id
   AND pr.status='confirmed'
WHERE u.request_id=?
ORDER BY u.id
SQL,
            [$requestId]
        )->getResultArray();

        $units=[];
        $completed=0;
        $pending=0;
        $reversed=0;
        $completedKg=0.0;
        $lastAt=null;
        $lastBy=null;

        foreach ($rows as $row) {
            $state='not_received';
            if ($row['production_document_id']!==null && $row['production_reversal_document_id']!==null) {
                $state='reversed';
                $reversed++;
            } elseif ($row['production_document_id']!==null) {
                $state='completed';
                $completed++;
                $completedKg+=round((float) ($row['production_weight_kg']??0),3);
                if ($lastAt===null || strcmp((string) $row['production_at'],$lastAt)>0) {
                    $lastAt=(string) $row['production_at'];
                    $lastBy=(int) $row['production_by'];
                }
            } elseif ((string) $row['receipt_status']==='accepted') {
                $state='pending';
                $pending++;
            }

            $units[]=[
                'unit_id'=>(int) $row['unit_id'],
                'stock_unit_id'=>(int) $row['stock_unit_id'],
                'unit_code'=>(string) $row['unit_code'],
                'tub_code'=>(string) $row['tub_code'],
                'receipt_status'=>(string) $row['receipt_status'],
                'received_at'=>$row['accepted_at'],
                'received_by'=>$row['accepted_by']!==null?(int) $row['accepted_by']:null,
                'inbound_weight_kg'=>$row['inbound_weight_kg']!==null?(float) $row['inbound_weight_kg']:null,
                'production_state'=>$state,
                'production_document_id'=>$row['production_document_id']!==null?(int) $row['production_document_id']:null,
                'production_folio'=>$row['production_folio'],
                'production_weight_kg'=>$row['production_weight_kg']!==null?(float) $row['production_weight_kg']:null,
                'production_at'=>$row['production_at'],
                'production_by'=>$row['production_by']!==null?(int) $row['production_by']:null,
                'production_reversal_document_id'=>$row['production_reversal_document_id']!==null?(int) $row['production_reversal_document_id']:null,
                'production_reversed_at'=>$row['production_reversed_at'],
                'production_reversed_by'=>$row['production_reversed_by']!==null?(int) $row['production_reversed_by']:null,
            ];
        }

        return [
            'area'=>$area,
            'state'=>$pending>0?'pending':($completed>0?'completed':($reversed>0?'reversed':'waiting')),
            'total_units'=>count($units),
            'completed_units'=>$completed,
            'pending_units'=>$pending,
            'reversed_units'=>$reversed,
            'completed_weight_kg'=>round($completedKg,3),
            'last_completed_at'=>$lastAt,
            'last_completed_by'=>$lastBy,
            'units'=>$units,
        ];
    }


    public function detail(int $id, int $after = 0, string $status = ''): array
    {
        $request = $this->request($id);
        $rows = $this->units($id,$after,$status);
        $more = count($rows)>100;
        if ($more) { array_pop($rows); }
        return ['request'=>$request,'lines'=>$this->lines($id),'units'=>$rows,
            'next_unit_after'=>$more?(int) end($rows)['id']:null,
            'selected_units'=>$this->units($id,null,'selected'),
            'requester_proposals'=>$this->requesterProposals($id),
            'summary'=>$this->db->query("SELECT request_line_id,u.status,COUNT(*) AS units,COALESCE(SUM(CASE WHEN u.status='selected' THEN preselected_weight_kg ELSE tl.shipped_weight_kg END),0) AS kg FROM warehouse_request_units u JOIN transfer_lines tl ON tl.id=u.transfer_line_id WHERE request_id=? GROUP BY request_line_id,u.status",[$id])->getResultArray(),
            'source'=>$this->warehouse((int) $request['source_warehouse_id'],(int) $request['provider_company_id'],false),
            'destination'=>$this->warehouse((int) $request['destination_warehouse_id'],(int) $request['company_id'],false),
            'production'=>$this->productionDetail($id,$request),
            'events'=>$this->db->table('warehouse_request_events')->where('request_id',$id)->orderBy('id','DESC')->limit(100)->get()->getResultArray()];
    }

    public function availability(int $warehouseId, array $filters): array
    {
        $this->context();
        $warehouse = $this->warehouse($warehouseId);
        [$speciesId,$sizeId,$currentScope,$otherScopes] = $this->availabilityScopes($warehouseId,$filters);

        $base = $this->db->table('stock_units su')
            ->where([
                'su.company_id'=>$warehouse['company_id'],
                'su.warehouse_id'=>$warehouseId,
                'su.status'=>'available',
                'su.reserved_weight_kg'=>0,
            ])
            ->where('su.on_hand_weight_kg >',0)
            ->where('su.owner_company_id IS NOT NULL',null,false);
        if ($speciesId>0) { $base->where('su.species_id',$speciesId); }
        if ($sizeId>0) { $base->where('su.size_grade_id',$sizeId); }
        if (!empty($currentScope['lot_id'])) { $base->where('su.lot_id',(int) $currentScope['lot_id']); }
        if (!empty($currentScope['owner_company_id'])) { $base->where('su.owner_company_id',(int) $currentScope['owner_company_id']); }
        $this->excludeCompetingScopes($base,$currentScope,$otherScopes);

        $recordsTotal = (int) ((clone $base)->select('COUNT(*) AS units',false)->get()->getRowArray()['units'] ?? 0);

        $b = $this->db->table('stock_units su')
            ->join('species s','s.id=su.species_id')
            ->join('size_grades sg','sg.id=su.size_grade_id')
            ->join('lots l','l.id=su.lot_id')
            ->join('tubs tb','tb.id=su.tub_id')
            ->join('locations loc','loc.id=su.location_id','left')
            ->join('companies c','c.id=su.owner_company_id','left')
            ->where([
                'su.company_id'=>$warehouse['company_id'],
                'su.warehouse_id'=>$warehouseId,
                'su.status'=>'available',
                'su.reserved_weight_kg'=>0,
            ])
            ->where('su.on_hand_weight_kg >',0)
            ->where('su.owner_company_id IS NOT NULL',null,false);

        if ($speciesId>0) { $b->where('su.species_id',$speciesId); }
        if ($sizeId>0) { $b->where('su.size_grade_id',$sizeId); }
        if (!empty($currentScope['lot_id'])) { $b->where('su.lot_id',(int) $currentScope['lot_id']); }
        if (!empty($currentScope['owner_company_id'])) { $b->where('su.owner_company_id',(int) $currentScope['owner_company_id']); }
        $this->excludeCompetingScopes($b,$currentScope,$otherScopes);

        // Los filtros de la modal son adicionales al criterio de la línea. No redefinen la línea.
        $modalLot = (int) ($filters['filter_lot_id'] ?? $filters['lot_id'] ?? 0);
        $modalOwner = (int) ($filters['filter_owner_company_id'] ?? $filters['owner_company_id'] ?? 0);
        if ($modalLot>0 && empty($currentScope['lot_id'])) { $b->where('su.lot_id',$modalLot); }
        if ($modalOwner>0 && empty($currentScope['owner_company_id'])) { $b->where('su.owner_company_id',$modalOwner); }

        $lotCode = mb_substr(trim((string) ($filters['lot_code'] ?? '')),0,80);
        if ($lotCode !== '' && $modalLot===0 && empty($currentScope['lot_id'])) {
            $b->like('l.code',$lotCode);
        }

        $search = mb_substr(trim((string) ($filters['search'] ?? '')),0,100);
        if ($search !== '') {
            $b->groupStart()
                ->like('su.unit_code',$search)
                ->orLike('tb.code',$search)
                ->orLike('l.code',$search)
                ->groupEnd();
        }

        $totals = (clone $b)
            ->select('COUNT(*) AS units,COALESCE(SUM(su.on_hand_weight_kg),0) AS kg',false)
            ->get()->getRowArray();

        if (array_key_exists('start',$filters) || array_key_exists('length',$filters)) {
            $start = max(0,(int) ($filters['start'] ?? 0));
            $length = max(10,min(100,(int) ($filters['length'] ?? 25)));
            $rows = $b->select('su.id,su.unit_code,su.species_id,su.size_grade_id,su.lot_id,su.owner_company_id,su.on_hand_weight_kg,su.received_at,s.name AS species_name,sg.name AS size_name,l.code AS lot_code,c.trade_name AS owner_name,tb.code AS tub_code,loc.path AS location_path')
                ->orderBy('su.received_at')
                ->orderBy('su.id')
                ->limit($length,$start)
                ->get()->getResultArray();

            return [
                'rows'=>$rows,
                'totals'=>[
                    'units'=>(int) ($totals['units'] ?? 0),
                    'kg'=>(float) ($totals['kg'] ?? 0),
                ],
                'records_total'=>$recordsTotal,
                'records_filtered'=>(int) ($totals['units'] ?? 0),
                'next_after'=>null,
            ];
        }

        $after = (int) ($filters['after'] ?? 0);
        if ($after>0) {
            $cursor = $this->db->table('stock_units')
                ->select('id,received_at')
                ->where(['id'=>$after,'warehouse_id'=>$warehouseId])
                ->get()->getRowArray();
            Policy::require($cursor!==null,'Actualiza el filtro para reiniciar la paginación.');
            $b->groupStart()
                ->where('su.received_at >',$cursor['received_at'])
                ->orGroupStart()
                    ->where('su.received_at',$cursor['received_at'])
                    ->where('su.id >',$after)
                ->groupEnd()
            ->groupEnd();
        }

        $rows = $b->select('su.id,su.unit_code,su.species_id,su.size_grade_id,su.lot_id,su.owner_company_id,su.on_hand_weight_kg,su.received_at,s.name AS species_name,sg.name AS size_name,l.code AS lot_code,c.trade_name AS owner_name,tb.code AS tub_code,loc.path AS location_path')
            ->orderBy('su.received_at')
            ->orderBy('su.id')
            ->limit(51)
            ->get()->getResultArray();

        $more = count($rows)>50;
        if ($more) { array_pop($rows); }

        return [
            'rows'=>$rows,
            'totals'=>[
                'units'=>(int) ($totals['units'] ?? 0),
                'kg'=>(float) ($totals['kg'] ?? 0),
            ],
            'records_total'=>$recordsTotal,
            'records_filtered'=>(int) ($totals['units'] ?? 0),
            'next_after'=>$more?(int) end($rows)['id']:null,
        ];
    }

    public function availabilityFacets(int $warehouseId, array $filters): array
    {
        $this->context();
        $warehouse=$this->warehouse($warehouseId);
        [$speciesId,$sizeId,$currentScope,$otherScopes]=$this->availabilityScopes($warehouseId,$filters);
        Policy::require($speciesId>0 && $sizeId>0,'Selecciona Especie y Talla para consultar lotes y propietarios.');

        $base=function() use($warehouse,$warehouseId,$speciesId,$sizeId,$currentScope,$otherScopes) {
            $b=$this->db->table('stock_units su')
                ->where([
                    'su.company_id'=>$warehouse['company_id'],'su.warehouse_id'=>$warehouseId,
                    'su.status'=>'available','su.reserved_weight_kg'=>0,'su.species_id'=>$speciesId,'su.size_grade_id'=>$sizeId,
                ])->where('su.on_hand_weight_kg >',0)
                ->where('su.owner_company_id IS NOT NULL',null,false);
            if (!empty($currentScope['lot_id'])) { $b->where('su.lot_id',(int) $currentScope['lot_id']); }
            if (!empty($currentScope['owner_company_id'])) { $b->where('su.owner_company_id',(int) $currentScope['owner_company_id']); }
            $this->excludeCompetingScopes($b,$currentScope,$otherScopes);
            return $b;
        };

        $filterLot=(int) ($filters['filter_lot_id'] ?? 0);
        $filterOwner=(int) ($filters['filter_owner_company_id'] ?? 0);

        $lots=$base()->select('l.id,l.code,COUNT(*) AS units,COALESCE(SUM(su.on_hand_weight_kg),0) AS kg',false)
            ->join('lots l','l.id=su.lot_id');
        if ($filterOwner>0 && empty($currentScope['owner_company_id'])) { $lots->where('su.owner_company_id',$filterOwner); }
        $lots=$lots->groupBy(['l.id','l.code'])->orderBy('l.code')->get()->getResultArray();

        $owners=$base()->select('c.id,c.trade_name,COUNT(*) AS units,COALESCE(SUM(su.on_hand_weight_kg),0) AS kg',false)
            ->join('companies c','c.id=su.owner_company_id');
        if ($filterLot>0 && empty($currentScope['lot_id'])) { $owners->where('su.lot_id',$filterLot); }
        $owners=$owners->groupBy(['c.id','c.trade_name'])->orderBy('c.trade_name')->get()->getResultArray();

        return ['lots'=>$lots,'owners'=>$owners];
    }

    /** @return array{0:int,1:int,2:array{lot_id:?int,owner_company_id:?int},3:array<int,array{lot_id:?int,owner_company_id:?int}>} */
    private function availabilityScopes(int $warehouseId, array $filters): array
    {
        $speciesId=(int) ($filters['species_id'] ?? 0);
        $sizeId=(int) ($filters['size_grade_id'] ?? 0);
        $current=['lot_id'=>($v=(int) ($filters['scope_lot_id'] ?? 0))>0?$v:null,
            'owner_company_id'=>($v=(int) ($filters['scope_owner_company_id'] ?? 0))>0?$v:null];
        $others=$this->parseCompetingScopes((string) ($filters['competing_scopes'] ?? ''));

        $requestId=(int) ($filters['request_id'] ?? 0);
        $lineId=(int) ($filters['line_id'] ?? 0);
        if ($requestId>0 || $lineId>0) {
            Policy::require($requestId>0 && $lineId>0,'La línea de solicitud está incompleta.');
            $request=$this->request($requestId);
            $this->role($request,'provider');
            Policy::require((int) $request['source_warehouse_id']===$warehouseId,'La consulta no corresponde al almacén proveedor.');
            $lines=$this->lines($requestId);
            $line=null;
            foreach ($lines as $candidate) { if ((int) $candidate['id']===$lineId) { $line=$candidate; break; } }
            Policy::require($line!==null,'Producto no encontrado en la solicitud.');
            $speciesId=(int) $line['species_id'];$sizeId=(int) $line['size_grade_id'];
            $current=['lot_id'=>!empty($line['lot_id'])?(int) $line['lot_id']:null,
                'owner_company_id'=>!empty($line['owner_company_id'])?(int) $line['owner_company_id']:null];
            $others=[];
            foreach ($lines as $candidate) {
                if ((int) $candidate['id']===$lineId || (int) $candidate['species_id']!==$speciesId || (int) $candidate['size_grade_id']!==$sizeId) { continue; }
                $others[]=['lot_id'=>!empty($candidate['lot_id'])?(int) $candidate['lot_id']:null,
                    'owner_company_id'=>!empty($candidate['owner_company_id'])?(int) $candidate['owner_company_id']:null];
            }
        }
        return [$speciesId,$sizeId,$current,$others];
    }

    private function parseCompetingScopes(string $raw): array
    {
        $raw=trim($raw);
        if ($raw==='') { return []; }
        Policy::require(strlen($raw)<=4000,'Los criterios de líneas exceden el tamaño permitido.');
        $rows=[];
        foreach (explode(';',$raw) as $item) {
            if ($item==='') { continue; }
            Policy::require(preg_match('/^(\d+):(\d+)$/D',$item,$m)===1,'Los criterios de líneas son inválidos.');
            $lot=(int) $m[1];$owner=(int) $m[2];
            $rows[]=['lot_id'=>$lot>0?$lot:null,'owner_company_id'=>$owner>0?$owner:null];
        }
        return $rows;
    }

    private function excludeCompetingScopes($builder, array $current, array $others): void
    {
        $currentSpecificity=Policy::specificity($current);
        foreach ($others as $other) {
            if (Policy::specificity($other)<$currentSpecificity) { continue; }
            $parts=[];
            if (!empty($other['lot_id'])) { $parts[]='su.lot_id <> '.(int) $other['lot_id']; }
            if (!empty($other['owner_company_id'])) { $parts[]='su.owner_company_id <> '.(int) $other['owner_company_id']; }
            // Otra línea general con la misma especie/talla sería duplicada: no debe apropiarse silenciosamente de las mismas tinas.
            $builder->where($parts===[]?'1=0':'('.implode(' OR ',$parts).')',null,false);
        }
    }

    public function lookup(string $rawCode, ?int $requestId = null): array
    {
        $scope = $this->context();
        $code = Policy::qr($rawCode);
        $args = [$scope['id']];
        $sql = 'SELECT su.*, tb.code AS tub_code, tb.current_tare_kg, lb.code AS label_code, loc.path AS location_path, s.name AS species_name, sg.name AS size_name, l.code AS lot_code, c.trade_name AS owner_name FROM stock_units su JOIN tubs tb ON tb.id=su.tub_id LEFT JOIN labels lb ON lb.id=su.label_id LEFT JOIN locations loc ON loc.id=su.location_id JOIN species s ON s.id=su.species_id JOIN size_grades sg ON sg.id=su.size_grade_id JOIN lots l ON l.id=su.lot_id LEFT JOIN companies c ON c.id=su.owner_company_id';
        if ($requestId!==null) {
            $request = $this->request($requestId);
            $this->role($request,'provider');
            Policy::open($request);
            $sql.=" WHERE su.warehouse_id=? AND su.status IN ('available','reserved') AND su.on_hand_weight_kg>0";
        } else {
            $sql.=" JOIN warehouse_request_units u ON u.active_stock_id=su.id JOIN warehouse_requests r ON r.id=u.request_id WHERE ((u.status='shipped' AND r.destination_warehouse_id=?) OR (u.status='returning' AND r.source_warehouse_id=?))";
            $args[]=$scope['id'];
        }
        $sql.=' AND (su.unit_code=? OR su.uuid=? OR tb.code=? OR tb.barcode=? OR lb.code=?) LIMIT 2';
        array_push($args,$code,$code,$code,$code,$code);
        $matches=$this->db->query($sql,$args)->getResultArray();
        Policy::require(count($matches)===1,count($matches)>1?'El código identifica más de una tina. Usa la etiqueta de inventario.':'No hay una tina pendiente o disponible con ese QR en este almacén.','WAREHOUSE_QR_NOT_UNIQUE');
        $stock=$matches[0];
        if ($requestId!==null) {
            $unit=$this->db->table('warehouse_request_units')->where('active_stock_id',$stock['id'])->get()->getRowArray();
            Policy::require($unit===null || ((int) $unit['request_id']===$requestId && $unit['status']==='selected'),'La tina está comprometida en otra solicitud.');
            Policy::require($unit!==null || ($stock['status']==='available' && (float) $stock['reserved_weight_kg']===0.0),'La tina no está disponible.');
            $lines=Policy::preferredLines($stock,$this->lines($requestId));
            Policy::require($lines!==[],'La tina no cumple los productos solicitados.');
            Policy::require(count($lines)===1,'La tina coincide con más de una línea de igual prioridad. Ajusta lote o propietario en la solicitud antes de surtir.','WAREHOUSE_LINE_SCOPE_AMBIGUOUS');
            return ['stock'=>$stock,'unit'=>$unit,'matching_lines'=>$lines];
        }
        $unit=$this->db->table('warehouse_request_units')->where('active_stock_id',$stock['id'])->get()->getRowArray();
        Policy::require($unit!==null,'La tina cambió. Vuelve a leer el QR.');
        $detail=$this->detail((int) $unit['request_id']);
        $this->role($detail['request'],$unit['status']==='returning'?'provider':'receiver');
        return ['stock'=>$stock,'unit'=>$unit,'operation'=>$unit['status']==='returning'?'return_entry':'receive','detail'=>$detail];
    }

    public function lookupOutgoing(string $rawCode): array
    {
        $scope = $this->context();
        $code = Policy::qr($rawCode);

        $sql = <<<'SQL'
SELECT su.*, tb.code AS tub_code, tb.current_tare_kg, lb.code AS label_code,
 loc.path AS location_path, loc.chamber_id AS location_chamber_id,
 s.name AS species_name, sg.name AS size_name, l.code AS lot_code, c.trade_name AS owner_name
FROM stock_units su
JOIN tubs tb ON tb.id=su.tub_id
LEFT JOIN labels lb ON lb.id=su.label_id
LEFT JOIN locations loc ON loc.id=su.location_id
JOIN species s ON s.id=su.species_id
JOIN size_grades sg ON sg.id=su.size_grade_id
JOIN lots l ON l.id=su.lot_id
LEFT JOIN companies c ON c.id=su.owner_company_id
WHERE su.warehouse_id=?
  AND su.status IN ('available','reserved')
  AND su.on_hand_weight_kg>0
  AND (su.unit_code=? OR su.uuid=? OR tb.code=? OR tb.barcode=? OR lb.code=?)
LIMIT 2
SQL;
        $matches = $this->db->query($sql,[$scope['id'],$code,$code,$code,$code,$code])->getResultArray();
        Policy::require(count($matches)===1,count($matches)>1
            ? 'El código identifica más de una tina. Usa la etiqueta de inventario.'
            : 'No hay una tina disponible con ese código en este almacén.','WAREHOUSE_QR_NOT_UNIQUE');

        $stock=$matches[0];
        $currentRow=$this->resolveRowLocation((int) ($stock['location_id']??0),(int) $scope['id']);
        $stock['current_row_id']=$currentRow['id']??null;
        $stock['current_chamber_id']=$currentRow['chamber_id']??($stock['location_chamber_id']??null);

        // 1) La selección operativa del almacén manda sobre cualquier propuesta.
        // Buscamos primero compromisos explícitos tanto de solicitudes entre almacenes
        // como de ventas integradas.
        $assigned=$this->db->query(<<<'SQL'
SELECT u.*, r.folio
FROM warehouse_request_units u
JOIN warehouse_requests r ON r.id=u.request_id
WHERE u.active_stock_id=?
  AND u.status='selected'
  AND r.source_warehouse_id=?
  AND r.provider_company_id=?
  AND r.supply_finalized_at IS NULL
  AND r.status IN ('accepted','preparing','dispatching')
ORDER BY r.required_at ASC,r.id ASC
LIMIT 2
SQL,[(int) $stock['id'],(int) $scope['id'],(int) $scope['company_id']])->getResultArray();

        $saleAssigned=$this->db->query(<<<'SQL'
SELECT
    sol.id AS sale_order_line_id,
    sol.sales_order_id,
    sol.request_line_id,
    sol.stock_unit_id,
    sol.quantity_kg,
    sol.status AS allocation_status,
    so.folio,
    so.required_at,
    so.status AS order_status,
    COALESCE(NULLIF(c.trade_name,''),c.legal_name,'Cliente') AS customer_name,
    srl.line_number,
    srl.species_id,
    srl.size_grade_id,
    srl.lot_id,
    srl.owner_company_id,
    srl.requested_weight_kg,
    s.name AS species_name,
    sg.name AS size_name,
    l.code AS lot_code,
    o.trade_name AS owner_name
FROM sales_order_lines sol
JOIN sales_orders so ON so.id=sol.sales_order_id
JOIN sales_request_lines srl ON srl.id=sol.request_line_id
JOIN companies c ON c.id=so.customer_company_id
JOIN species s ON s.id=srl.species_id
JOIN size_grades sg ON sg.id=srl.size_grade_id
LEFT JOIN lots l ON l.id=srl.lot_id
LEFT JOIN companies o ON o.id=srl.owner_company_id
WHERE sol.stock_unit_id=?
  AND sol.status IN ('selected','reserved')
  AND so.source_warehouse_id=?
  AND so.company_id=?
  AND so.site_id=?
  AND so.status IN ('draft','allocated')
ORDER BY so.required_at ASC,so.id ASC
LIMIT 2
SQL,[(int) $stock['id'],(int) $scope['id'],(int) $scope['company_id'],(int) $scope['site_id']])->getResultArray();

        Policy::require(
            count($assigned)+count($saleAssigned)<=1,
            'La tina está asignada a más de una solicitud o venta activa. Revisa el surtido antes de continuar.',
            'WAREHOUSE_OUTGOING_ASSIGNMENT_CONFLICT'
        );

        if ($assigned!==[]) {
            $unit=$assigned[0];
            $detail=$this->detail((int) $unit['request_id']);
            $this->role($detail['request'],'provider');
            Policy::open($detail['request']);
            $lines=array_values(array_filter($detail['lines'],static fn(array $line): bool=>(int) $line['id']===(int) $unit['request_line_id']));
            Policy::require(count($lines)===1 && Policy::ownsStock($stock,$lines[0],$detail['lines']),
                'La tina reservada ya no coincide con la línea de la solicitud. Actualiza la propuesta antes de surtir.',
                'WAREHOUSE_LINE_SCOPE_CONFLICT');

            return [
                'source'=>'warehouse',
                'stock'=>$stock,
                'unit'=>$unit,
                'matching_lines'=>$lines,
                'operation'=>'ship',
                'detail'=>$detail,
                'resolution'=>'preselected',
            ];
        }

        if ($saleAssigned!==[]) {
            $allocation=$saleAssigned[0];
            $line=[
                'id'=>(int) $allocation['request_line_id'],
                'line_number'=>(int) $allocation['line_number'],
                'species_id'=>(int) $allocation['species_id'],
                'size_grade_id'=>(int) $allocation['size_grade_id'],
                'lot_id'=>empty($allocation['lot_id'])?null:(int) $allocation['lot_id'],
                'owner_company_id'=>empty($allocation['owner_company_id'])?null:(int) $allocation['owner_company_id'],
                'requested_weight_kg'=>(float) $allocation['requested_weight_kg'],
                'species_name'=>(string) $allocation['species_name'],
                'size_name'=>(string) $allocation['size_name'],
                'lot_code'=>(string) ($allocation['lot_code']??''),
                'owner_name'=>(string) ($allocation['owner_name']??''),
            ];
            $shipped=(float) $this->db->query(<<<'SQL'
SELECT COALESCE(SUM(COALESCE((
    SELECT idl.quantity_kg
    FROM inventory_documents idoc
    JOIN inventory_document_lines idl ON idl.inventory_document_id=idoc.id
    WHERE idoc.status='confirmed'
      AND idoc.movement_type='sale_dispatch'
      AND idoc.source_document_type='sales_order'
      AND idoc.source_document_id=sol.sales_order_id
      AND idl.stock_unit_id=sol.stock_unit_id
    ORDER BY idoc.id DESC
    LIMIT 1
),sol.quantity_kg)),0) AS kg
FROM sales_order_lines sol
WHERE sol.sales_order_id=? AND sol.request_line_id=? AND sol.status='dispatched'
SQL,[(int) $allocation['sales_order_id'],(int) $allocation['request_line_id']])->getRow('kg');

            return [
                'source'=>'sale',
                'stock'=>$stock,
                'unit'=>null,
                'sale_order_line'=>$allocation,
                'sale_order_id'=>(int) $allocation['sales_order_id'],
                'matching_lines'=>[$line],
                'operation'=>'sale_ship',
                'resolution'=>'preselected_sale',
                'candidate'=>[
                    'source'=>'sale',
                    'request'=>[
                        'id'=>(int) $allocation['sales_order_id'],
                        'folio'=>(string) $allocation['folio'],
                        'required_at'=>(string) $allocation['required_at'],
                        'request_type'=>'sale',
                        'destination_name'=>'Cliente: '.(string) $allocation['customer_name'],
                        'requested_weight_kg'=>(float) $allocation['requested_weight_kg'],
                        'shipped_weight_kg'=>$shipped,
                        'pending_weight_kg'=>max(0,round((float) $allocation['requested_weight_kg']-$shipped,3)),
                    ],
                    'line'=>$line,
                ],
            ];
        }

        // Sin asignación explícita sólo una tina realmente disponible puede
        // resolverse por compatibilidad. Una reserva ajena nunca se reutiliza.
        Policy::require(
            (string) $stock['status']==='available' && (float) $stock['reserved_weight_kg']===0.0,
            'La tina está reservada o comprometida por otra operación. Libérala antes de usarla en esta salida.',
            'CONCURRENT_STOCK_MODIFICATION'
        );

        $candidates=[];

        // 2) Solicitudes entre almacenes: se conserva el comportamiento existente.
        $requests=$this->db->query(<<<'SQL'
SELECT r.id,r.folio,r.required_at,r.request_type,r.destination_warehouse_id,
       dw.name AS destination_name
FROM warehouse_requests r
JOIN warehouses dw ON dw.id=r.destination_warehouse_id
WHERE r.source_warehouse_id=?
  AND r.provider_company_id=?
  AND r.supply_finalized_at IS NULL
  AND r.status IN ('accepted','preparing','dispatching')
ORDER BY r.required_at ASC,r.id ASC
SQL,[(int) $scope['id'],(int) $scope['company_id']])->getResultArray();

        foreach ($requests as $request) {
            $lines=$this->lines((int) $request['id']);
            $preferred=Policy::preferredLines($stock,$lines);
            if (count($preferred)===1) {
                $line=$preferred[0];
                $shipped=(float) $this->db->query(
                    "SELECT COALESCE(SUM(tl.shipped_weight_kg),0) AS kg
                     FROM warehouse_request_units u
                     JOIN transfer_lines tl ON tl.id=u.transfer_line_id
                     WHERE u.request_id=?
                       AND u.request_line_id=?
                       AND u.status IN ('shipped','accepted','returning','returned')",
                    [(int) $request['id'],(int) $line['id']]
                )->getRow('kg');
                $request['requested_weight_kg']=(float) $line['requested_weight_kg'];
                $request['shipped_weight_kg']=$shipped;
                $request['pending_weight_kg']=max(0,round((float) $line['requested_weight_kg']-$shipped,3));
                $candidates[]=['source'=>'warehouse','request'=>$request,'line'=>$line];
            }
        }

        // 3) Ventas a cliente integradas: la propuesta del solicitante NO participa.
        // Sólo se toma la necesidad de material y, si existe, la selección definitiva
        // del almacén ya habría sido resuelta en el bloque saleAssigned anterior.
        $saleOrders=$this->db->query(<<<'SQL'
SELECT
    so.id,
    so.folio,
    so.required_at,
    so.status,
    COALESCE(NULLIF(c.trade_name,''),c.legal_name,'Cliente') AS customer_name
FROM sales_orders so
JOIN companies c ON c.id=so.customer_company_id
WHERE so.company_id=?
  AND so.site_id=?
  AND so.source_warehouse_id=?
  AND so.status IN ('draft','allocated')
  AND EXISTS (
      SELECT 1 FROM sales_request_lines srl WHERE srl.sales_order_id=so.id
  )
ORDER BY so.required_at ASC,so.id ASC
SQL,[(int) $scope['company_id'],(int) $scope['site_id'],(int) $scope['id']])->getResultArray();

        foreach ($saleOrders as $saleOrder) {
            $saleLines=$this->db->table('sales_request_lines srl')
                ->select('srl.*,s.name AS species_name,sg.name AS size_name,l.code AS lot_code,o.trade_name AS owner_name')
                ->join('species s','s.id=srl.species_id')
                ->join('size_grades sg','sg.id=srl.size_grade_id')
                ->join('lots l','l.id=srl.lot_id','left')
                ->join('companies o','o.id=srl.owner_company_id','left')
                ->where('srl.sales_order_id',(int) $saleOrder['id'])
                ->orderBy('srl.line_number')
                ->get()->getResultArray();

            $preferred=Policy::preferredLines($stock,$saleLines);
            if (count($preferred)!==1) {
                continue;
            }

            $line=$preferred[0];
            $totals=$this->db->query(<<<'SQL'
SELECT
    COALESCE(SUM(
        CASE
            WHEN sol.status IN ('selected','reserved') THEN sol.quantity_kg
            WHEN sol.status='dispatched' THEN COALESCE((
                SELECT idl.quantity_kg
                FROM inventory_documents idoc
                JOIN inventory_document_lines idl ON idl.inventory_document_id=idoc.id
                WHERE idoc.status='confirmed'
                  AND idoc.movement_type='sale_dispatch'
                  AND idoc.source_document_type='sales_order'
                  AND idoc.source_document_id=sol.sales_order_id
                  AND idl.stock_unit_id=sol.stock_unit_id
                ORDER BY idoc.id DESC
                LIMIT 1
            ),sol.quantity_kg)
            ELSE 0
        END
    ),0) AS covered_kg,
    COALESCE(SUM(
        CASE
            WHEN sol.status='dispatched' THEN COALESCE((
                SELECT idl.quantity_kg
                FROM inventory_documents idoc
                JOIN inventory_document_lines idl ON idl.inventory_document_id=idoc.id
                WHERE idoc.status='confirmed'
                  AND idoc.movement_type='sale_dispatch'
                  AND idoc.source_document_type='sales_order'
                  AND idoc.source_document_id=sol.sales_order_id
                  AND idl.stock_unit_id=sol.stock_unit_id
                ORDER BY idoc.id DESC
                LIMIT 1
            ),sol.quantity_kg)
            ELSE 0
        END
    ),0) AS shipped_kg
FROM sales_order_lines sol
WHERE sol.sales_order_id=? AND sol.request_line_id=?
SQL,[(int) $saleOrder['id'],(int) $line['id']])->getRowArray();

            $covered=(float) ($totals['covered_kg']??0);
            $shipped=(float) ($totals['shipped_kg']??0);
            $requested=(float) $line['requested_weight_kg'];

            // Si la línea ya está totalmente cubierta por tinas seleccionadas/reservadas,
            // una tina adicional no debe competir por esa venta.
            if ($covered >= $requested-0.0001) {
                continue;
            }

            $request=[
                'id'=>(int) $saleOrder['id'],
                'folio'=>(string) $saleOrder['folio'],
                'required_at'=>(string) $saleOrder['required_at'],
                'request_type'=>'sale',
                'destination_name'=>'Cliente: '.(string) $saleOrder['customer_name'],
                'requested_weight_kg'=>$requested,
                'shipped_weight_kg'=>$shipped,
                'pending_weight_kg'=>max(0,round($requested-$shipped,3)),
            ];
            $candidates[]=['source'=>'sale','request'=>$request,'line'=>$line];
        }

        Policy::require(
            $candidates!==[],
            'La tina no corresponde a ninguna solicitud o venta activa lista para surtir.',
            'WAREHOUSE_OUTGOING_REQUEST_NOT_FOUND'
        );

        if (count($candidates)>1) {
            return [
                'source'=>'mixed',
                'stock'=>$stock,
                'unit'=>null,
                'matching_lines'=>[],
                'operation'=>'ship',
                'resolution'=>'choose_request',
                'candidates'=>$candidates,
            ];
        }

        $candidate=$candidates[0];

        if (($candidate['source']??'warehouse')==='sale') {
            return [
                'source'=>'sale',
                'stock'=>$stock,
                'unit'=>null,
                'sale_order_line'=>null,
                'sale_order_id'=>(int) $candidate['request']['id'],
                'matching_lines'=>[$candidate['line']],
                'operation'=>'sale_ship',
                'resolution'=>'unique_sale',
                'candidate'=>$candidate,
            ];
        }

        $detail=$this->detail((int) $candidate['request']['id']);
        $this->role($detail['request'],'provider');
        Policy::open($detail['request']);

        return [
            'source'=>'warehouse',
            'stock'=>$stock,
            'unit'=>null,
            'matching_lines'=>[$candidate['line']],
            'operation'=>'ship',
            'detail'=>$detail,
            'resolution'=>'unique_request',
        ];
    }

    public function recoverOutgoing(string $rawCode): array
    {
        $scope=$this->context();
        $code=Policy::qr($rawCode);

        $warehouse=$this->db->query(<<<'SQL'
SELECT
    u.id AS unit_id,
    u.request_id,
    u.request_line_id,
    u.stock_unit_id,
    u.status AS unit_status,
    u.shipped_at,
    r.folio,
    r.status AS request_status,
    r.destination_warehouse_id,
    dw.name AS destination_name,
    su.unit_code,
    su.uuid,
    su.status AS stock_status,
    su.on_hand_weight_kg,
    su.reserved_weight_kg,
    su.location_id,
    su.species_id,
    su.size_grade_id,
    su.lot_id,
    su.owner_company_id,
    tb.code AS tub_code,
    tb.barcode AS tub_barcode,
    tb.current_tare_kg,
    lb.code AS label_code,
    loc.path AS location_path,
    loc.chamber_id AS location_chamber_id,
    s.name AS species_name,
    sg.name AS size_name,
    l.code AS lot_code,
    c.trade_name AS owner_name,
    tl.shipped_weight_kg,
    tw.id AS outbound_weighing_id,
    tw.weight_kg AS outbound_weight_kg,
    tw.status AS outbound_weight_status,
    tw.reversed_at AS outbound_reversed_at
FROM warehouse_request_units u
JOIN warehouse_requests r ON r.id=u.request_id
JOIN warehouses dw ON dw.id=r.destination_warehouse_id
JOIN stock_units su ON su.id=u.stock_unit_id
JOIN tubs tb ON tb.id=su.tub_id
LEFT JOIN labels lb ON lb.id=su.label_id
LEFT JOIN locations loc ON loc.id=su.location_id
JOIN species s ON s.id=su.species_id
JOIN size_grades sg ON sg.id=su.size_grade_id
JOIN lots l ON l.id=su.lot_id
LEFT JOIN companies c ON c.id=su.owner_company_id
JOIN transfer_lines tl ON tl.id=u.transfer_line_id
LEFT JOIN transfer_weighings tw ON tw.id=(
    SELECT tw2.id
    FROM transfer_weighings tw2
    WHERE tw2.transfer_line_id=tl.id
      AND tw2.direction='outbound'
    ORDER BY tw2.id DESC
    LIMIT 1
)
WHERE r.source_warehouse_id=?
  AND r.provider_company_id=?
  AND u.shipped_at IS NOT NULL
  AND (su.unit_code=? OR su.uuid=? OR tb.code=? OR tb.barcode=? OR lb.code=?)
ORDER BY u.shipped_at DESC,u.id DESC
LIMIT 1
SQL,[
            (int) $scope['id'],
            (int) $scope['company_id'],
            $code,$code,$code,$code,$code,
        ])->getRowArray();

        $sale=$this->db->query(<<<'SQL'
SELECT
    sol.id AS sales_order_line_id,
    sol.sales_order_id,
    sol.request_line_id,
    sol.stock_unit_id,
    sol.status AS allocation_status,
    so.folio,
    so.status AS order_status,
    so.customer_company_id,
    COALESCE(NULLIF(customer.trade_name,''),customer.legal_name,'Cliente') AS customer_name,
    su.unit_code,
    su.uuid,
    su.status AS stock_status,
    su.on_hand_weight_kg,
    su.reserved_weight_kg,
    su.location_id,
    su.species_id,
    su.size_grade_id,
    su.lot_id,
    su.owner_company_id,
    tb.code AS tub_code,
    tb.barcode AS tub_barcode,
    tb.current_tare_kg,
    lb.code AS label_code,
    loc.path AS location_path,
    loc.chamber_id AS location_chamber_id,
    s.name AS species_name,
    sg.name AS size_name,
    l.code AS lot_code,
    owner.trade_name AS owner_name,
    idoc.id AS dispatch_document_id,
    idoc.created_at AS dispatched_at,
    idl.quantity_kg AS outbound_weight_kg,
    reversal.id AS reversal_document_id,
    reversal.created_at AS reversed_at
FROM sales_order_lines sol
JOIN sales_orders so ON so.id=sol.sales_order_id
JOIN companies customer ON customer.id=so.customer_company_id
JOIN stock_units su ON su.id=sol.stock_unit_id
JOIN tubs tb ON tb.id=su.tub_id
LEFT JOIN labels lb ON lb.id=su.label_id
LEFT JOIN locations loc ON loc.id=su.location_id
JOIN species s ON s.id=su.species_id
JOIN size_grades sg ON sg.id=su.size_grade_id
JOIN lots l ON l.id=su.lot_id
LEFT JOIN companies owner ON owner.id=su.owner_company_id
JOIN inventory_document_lines idl ON idl.stock_unit_id=sol.stock_unit_id
JOIN inventory_documents idoc
  ON idoc.id=idl.inventory_document_id
 AND idoc.status='confirmed'
 AND idoc.movement_type='sale_dispatch'
 AND idoc.source_document_type='sales_order'
 AND idoc.source_document_id=sol.sales_order_id
LEFT JOIN inventory_documents reversal ON reversal.reversal_of_id=idoc.id
WHERE so.source_warehouse_id=?
  AND so.company_id=?
  AND so.site_id=?
  AND (su.unit_code=? OR su.uuid=? OR tb.code=? OR tb.barcode=? OR lb.code=?)
ORDER BY idoc.id DESC
LIMIT 1
SQL,[
            (int) $scope['id'],
            (int) $scope['company_id'],
            (int) $scope['site_id'],
            $code,$code,$code,$code,$code,
        ])->getRowArray();

        Policy::require(
            $warehouse!==null || $sale!==null,
            'No se encontró una salida anterior para esa tina en este almacén.',
            'WAREHOUSE_OUTGOING_HISTORY_NOT_FOUND'
        );

        $warehouseTime=$warehouse===null?0:(strtotime((string) ($warehouse['shipped_at']??''))?:0);
        $saleTime=$sale===null?0:(strtotime((string) ($sale['dispatched_at']??''))?:0);

        if ($sale!==null && $saleTime>$warehouseTime) {
            $stock=[
                'id'=>(int) $sale['stock_unit_id'],
                'unit_code'=>(string) $sale['unit_code'],
                'uuid'=>(string) $sale['uuid'],
                'status'=>(string) $sale['stock_status'],
                'on_hand_weight_kg'=>(float) $sale['on_hand_weight_kg'],
                'reserved_weight_kg'=>(float) $sale['reserved_weight_kg'],
                'location_id'=>empty($sale['location_id'])?null:(int) $sale['location_id'],
                'species_id'=>(int) $sale['species_id'],
                'size_grade_id'=>(int) $sale['size_grade_id'],
                'lot_id'=>(int) $sale['lot_id'],
                'owner_company_id'=>empty($sale['owner_company_id'])?null:(int) $sale['owner_company_id'],
                'tub_code'=>(string) $sale['tub_code'],
                'current_tare_kg'=>(float) $sale['current_tare_kg'],
                'label_code'=>(string) ($sale['label_code']??''),
                'location_path'=>(string) ($sale['location_path']??''),
                'location_chamber_id'=>empty($sale['location_chamber_id'])?null:(int) $sale['location_chamber_id'],
                'species_name'=>(string) $sale['species_name'],
                'size_name'=>(string) $sale['size_name'],
                'lot_code'=>(string) $sale['lot_code'],
                'owner_name'=>(string) ($sale['owner_name']??''),
            ];

            return [
                'source'=>'sale',
                'stock'=>$stock,
                'sale_order_id'=>(int) $sale['sales_order_id'],
                'sales_order_line_id'=>(int) $sale['sales_order_line_id'],
                'request_line_id'=>(int) $sale['request_line_id'],
                'folio'=>(string) $sale['folio'],
                'destination_name'=>'Cliente: '.(string) $sale['customer_name'],
                'status'=>(string) $sale['allocation_status'],
                'document_status'=>(string) $sale['order_status'],
                'occurred_at'=>(string) $sale['dispatched_at'],
                'weight_kg'=>(float) $sale['outbound_weight_kg'],
                'cancelable'=>(string) $sale['allocation_status']==='dispatched' && empty($sale['reversal_document_id']),
                'already_reversed'=>! empty($sale['reversal_document_id']),
            ];
        }

        $stock=[
            'id'=>(int) $warehouse['stock_unit_id'],
            'unit_code'=>(string) $warehouse['unit_code'],
            'uuid'=>(string) $warehouse['uuid'],
            'status'=>(string) $warehouse['stock_status'],
            'on_hand_weight_kg'=>(float) $warehouse['on_hand_weight_kg'],
            'reserved_weight_kg'=>(float) $warehouse['reserved_weight_kg'],
            'location_id'=>empty($warehouse['location_id'])?null:(int) $warehouse['location_id'],
            'species_id'=>(int) $warehouse['species_id'],
            'size_grade_id'=>(int) $warehouse['size_grade_id'],
            'lot_id'=>(int) $warehouse['lot_id'],
            'owner_company_id'=>empty($warehouse['owner_company_id'])?null:(int) $warehouse['owner_company_id'],
            'tub_code'=>(string) $warehouse['tub_code'],
            'current_tare_kg'=>(float) $warehouse['current_tare_kg'],
            'label_code'=>(string) ($warehouse['label_code']??''),
            'location_path'=>(string) ($warehouse['location_path']??''),
            'location_chamber_id'=>empty($warehouse['location_chamber_id'])?null:(int) $warehouse['location_chamber_id'],
            'species_name'=>(string) $warehouse['species_name'],
            'size_name'=>(string) $warehouse['size_name'],
            'lot_code'=>(string) $warehouse['lot_code'],
            'owner_name'=>(string) ($warehouse['owner_name']??''),
        ];

        return [
            'source'=>'warehouse',
            'stock'=>$stock,
            'request_id'=>(int) $warehouse['request_id'],
            'request_line_id'=>(int) $warehouse['request_line_id'],
            'unit_id'=>(int) $warehouse['unit_id'],
            'folio'=>(string) $warehouse['folio'],
            'destination_name'=>(string) $warehouse['destination_name'],
            'status'=>(string) $warehouse['unit_status'],
            'document_status'=>(string) $warehouse['request_status'],
            'occurred_at'=>(string) $warehouse['shipped_at'],
            'weight_kg'=>(float) ($warehouse['outbound_weight_kg']??$warehouse['shipped_weight_kg']??0),
            'cancelable'=>(string) $warehouse['unit_status']==='shipped'
                && (string) ($warehouse['outbound_weight_status']??'confirmed')==='confirmed',
            'already_reversed'=>(string) ($warehouse['outbound_weight_status']??'')==='reversed',
        ];
    }

    public function recentOutgoing(array $filters=[]): array
    {
        $scope=$this->context();
        $search=mb_substr(trim((string) ($filters['search']??'')),0,100);
        $limit=max(10,min(100,(int) ($filters['limit']??60)));
        $like='%'.$search.'%';

        $warehouseSql=<<<'SQL'
SELECT
    'warehouse' AS source,
    u.id AS operation_line_id,
    r.id AS document_id,
    r.folio,
    tb.code AS tub_code,
    su.unit_code,
    COALESCE(tw.weight_kg,tl.shipped_weight_kg,0) AS weight_kg,
    u.status AS item_status,
    r.status AS document_status,
    dw.name AS destination_name,
    u.shipped_at AS occurred_at
FROM warehouse_request_units u
JOIN warehouse_requests r ON r.id=u.request_id
JOIN warehouses dw ON dw.id=r.destination_warehouse_id
JOIN stock_units su ON su.id=u.stock_unit_id
JOIN tubs tb ON tb.id=su.tub_id
JOIN transfer_lines tl ON tl.id=u.transfer_line_id
LEFT JOIN transfer_weighings tw ON tw.id=(
    SELECT tw2.id
    FROM transfer_weighings tw2
    WHERE tw2.transfer_line_id=tl.id
      AND tw2.direction='outbound'
    ORDER BY tw2.id DESC
    LIMIT 1
)
WHERE r.source_warehouse_id=?
  AND r.provider_company_id=?
  AND u.shipped_at IS NOT NULL
SQL;
        $warehouseArgs=[(int) $scope['id'],(int) $scope['company_id']];
        if ($search!=='') {
            $warehouseSql.=" AND (r.folio LIKE ? OR tb.code LIKE ? OR su.unit_code LIKE ? OR dw.name LIKE ?)";
            array_push($warehouseArgs,$like,$like,$like,$like);
        }
        $warehouseSql.=" ORDER BY u.shipped_at DESC,u.id DESC LIMIT ".$limit;
        $warehouseRows=$this->db->query($warehouseSql,$warehouseArgs)->getResultArray();

        $saleSql=<<<'SQL'
SELECT
    'sale' AS source,
    sol.id AS operation_line_id,
    so.id AS document_id,
    so.folio,
    tb.code AS tub_code,
    su.unit_code,
    idl.quantity_kg AS weight_kg,
    CASE WHEN reversal.id IS NULL AND sol.status='dispatched' THEN 'dispatched' ELSE 'released' END AS item_status,
    so.status AS document_status,
    COALESCE(NULLIF(customer.trade_name,''),customer.legal_name,'Cliente') AS destination_name,
    idoc.created_at AS occurred_at
FROM sales_order_lines sol
JOIN sales_orders so ON so.id=sol.sales_order_id
JOIN companies customer ON customer.id=so.customer_company_id
JOIN stock_units su ON su.id=sol.stock_unit_id
JOIN tubs tb ON tb.id=su.tub_id
JOIN inventory_document_lines idl ON idl.stock_unit_id=sol.stock_unit_id
JOIN inventory_documents idoc
  ON idoc.id=idl.inventory_document_id
 AND idoc.status='confirmed'
 AND idoc.movement_type='sale_dispatch'
 AND idoc.source_document_type='sales_order'
 AND idoc.source_document_id=sol.sales_order_id
LEFT JOIN inventory_documents reversal ON reversal.reversal_of_id=idoc.id
WHERE so.source_warehouse_id=?
  AND so.company_id=?
  AND so.site_id=?
SQL;
        $saleArgs=[(int) $scope['id'],(int) $scope['company_id'],(int) $scope['site_id']];
        if ($search!=='') {
            $saleSql.=" AND (so.folio LIKE ? OR tb.code LIKE ? OR su.unit_code LIKE ? OR customer.trade_name LIKE ? OR customer.legal_name LIKE ?)";
            array_push($saleArgs,$like,$like,$like,$like,$like);
        }
        $saleSql.=" ORDER BY idoc.id DESC LIMIT ".$limit;
        $saleRows=$this->db->query($saleSql,$saleArgs)->getResultArray();

        $rows=[];
        foreach ($warehouseRows as $row) {
            $row['cancelable']=(string) $row['item_status']==='shipped';
            $row['type_label']='Traspaso entre almacenes';
            $rows[]=$row;
        }
        foreach ($saleRows as $row) {
            $row['cancelable']=(string) $row['item_status']==='dispatched';
            $row['type_label']='Venta a cliente';
            $row['destination_name']='Cliente: '.(string) $row['destination_name'];
            $rows[]=$row;
        }

        usort($rows,static function(array $a,array $b): int {
            $timeCompare=strcmp((string) ($b['occurred_at']??''),(string) ($a['occurred_at']??''));
            if ($timeCompare!==0) { return $timeCompare; }
            return (int) ($b['operation_line_id']??0)<=>(int) ($a['operation_line_id']??0);
        });

        return [
            'rows'=>array_slice($rows,0,$limit),
            'search'=>$search,
            'limit'=>$limit,
        ];
    }

    private function resolveRowLocation(int $locationId, int $warehouseId): ?array
    {
        if ($locationId<=0) { return null; }
        $row=$this->db->query(<<<'SQL'
WITH RECURSIVE location_chain AS (
    SELECT id,parent_id,warehouse_id,chamber_id,location_type,path,active,0 AS depth
    FROM locations WHERE id=?
    UNION ALL
    SELECT p.id,p.parent_id,p.warehouse_id,p.chamber_id,p.location_type,p.path,p.active,c.depth+1
    FROM locations p JOIN location_chain c ON c.parent_id=p.id
    WHERE c.depth<20
)
SELECT id,chamber_id
FROM location_chain
WHERE warehouse_id=? AND active=1 AND location_type IN ('row','aisle')
ORDER BY depth ASC
LIMIT 1
SQL,[$locationId,$warehouseId])->getRowArray();
        return $row===null?null:['id'=>(int) $row['id'],'chamber_id'=>empty($row['chamber_id'])?null:(int) $row['chamber_id']];
    }

    public function catalogs(): array
    {
        $scope=$this->context();
        return ['scope'=>$scope,
            'warehouses'=>$this->db->query('SELECT w.id,w.name,w.code,w.company_id,s.name AS site_name,c.trade_name AS company_name FROM warehouses w JOIN sites s ON s.id=w.site_id AND s.company_id=w.company_id JOIN companies c ON c.id=w.company_id WHERE w.active=1 AND s.active=1 AND c.active=1 AND w.id<>? ORDER BY c.trade_name,s.name,w.name',[$scope['id']])->getResultArray(),
            'species'=>$this->db->table('species')->where('active',1)->orderBy('name')->get()->getResultArray(),
            'sizes'=>$this->db->table('size_grades')->where('active',1)->orderBy('name')->get()->getResultArray(),
            'owners'=>$this->db->table('companies')->select('id,trade_name')->where('active',1)->orderBy('trade_name')->get()->getResultArray(),
            'customers'=>$this->db->table('companies')->select('id,code,trade_name,legal_name')->where(['active'=>1,'company_type'=>'customer'])->orderBy('trade_name')->get()->getResultArray(),
            'chambers'=>$this->db->query('SELECT id,code,name FROM chambers WHERE warehouse_id=? AND active=1 ORDER BY name,code',[$scope['id']])->getResultArray(),
            'rows'=>$this->db->query("SELECT id,chamber_id,code,name,path FROM locations WHERE warehouse_id=? AND active=1 AND location_type IN ('row','aisle') ORDER BY chamber_id,path",[$scope['id']])->getResultArray(),
            'scales'=>$this->db->query(<<<'SQL'
SELECT id,name,code,station_code,model,connection_type,adapter_key,agent_url,port_name,
       baud_rate,data_bits,parity,stop_bits,read_command,command_terminator,response_timeout_ms,
       settle_delay_ms,stable_reads,stable_tolerance_kg,weight_regex,unit,is_default,last_test_status
FROM scales
WHERE site_id=? AND active=1
  AND connection_type IN ('rs232','usb_serial')
  AND port_name IS NOT NULL AND TRIM(port_name)<>''
ORDER BY is_default DESC,name,id
SQL,[$scope['site_id']])->getResultArray()];
    }
}
