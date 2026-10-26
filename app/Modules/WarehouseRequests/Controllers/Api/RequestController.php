<?php
namespace App\Modules\WarehouseRequests\Controllers\Api;

use App\Common\Controllers\ApiController;
use App\Modules\WarehouseRequests\Domain\RequestPolicy as Policy;
use App\Modules\WarehouseRequests\Repositories\RequestRepository;
use App\Modules\WarehouseRequests\Services\RequestService;

final class RequestController extends ApiController
{
    public function index() { return $this->execute(fn(): array=>(new RequestRepository(db_connect()))->listing((int) $this->request->getGet('before'),(array) $this->request->getGet())); }
    public function show(int $id) { return $this->execute(fn(): array=>(new RequestRepository(db_connect()))->detail($id,(int) $this->request->getGet('after'),(string) $this->request->getGet('status'))); }
    public function byTransfer(int $id)
    {
        return $this->execute(function() use($id): array {
            $row=db_connect()->table('warehouse_requests')->select('id')->where('transfer_id',$id)->get()->getRowArray();
            Policy::require($row!==null,'Solicitud no encontrada.','WAREHOUSE_REQUEST_NOT_FOUND');
            return (new RequestRepository(db_connect()))->detail((int) $row['id']);
        });
    }
    public function availability()
    {
        return $this->execute(fn(): array=>(new RequestRepository(db_connect()))->availability(
            (int) $this->request->getGet('warehouse_id'),(array) $this->request->getGet()));
    }
    public function availabilityFacets()
    {
        return $this->execute(fn(): array=>(new RequestRepository(db_connect()))->availabilityFacets(
            (int) $this->request->getGet('warehouse_id'),(array) $this->request->getGet()));
    }
    public function lookup() { return $this->execute(fn(): array=>(new RequestRepository(db_connect()))->lookup((string) $this->request->getGet('code'),$this->request->getGet('request_id')?(int) $this->request->getGet('request_id'):null)); }
    public function lookupOutgoing() { return $this->execute(fn(): array=>(new RequestRepository(db_connect()))->lookupOutgoing((string) $this->request->getGet('code'))); }
    public function recoverOutgoing() { return $this->execute(fn(): array=>(new RequestRepository(db_connect()))->recoverOutgoing((string) $this->request->getGet('code'))); }
    public function recentOutgoing() { return $this->execute(fn(): array=>(new RequestRepository(db_connect()))->recentOutgoing((array) $this->request->getGet())); }
    public function captureAgentScale() { return $this->execute(fn(): array=>(new RequestService(db_connect()))->captureAgentScale($this->body(),$this->actorId()),201); }
    public function suggest(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->suggest($id,(int) $this->request->getGet('line_id'))); }
    public function accept(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->accept($id,$this->body(),$this->actorId())); }
    public function ship(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->ship($id,$this->body(),$this->actorId())); }
    public function create() { return $this->execute(fn(): array=>(new RequestService(db_connect()))->create($this->body(),$this->actorId()),201); }
    public function select(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->select($id,$this->body(),$this->actorId())); }
    public function dispatch(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->dispatch($id,$this->body(),$this->actorId())); }
    public function cancelShipment(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->cancelShipment($id,$this->body(),$this->actorId())); }
    public function receive(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->receive($id,$this->body(),$this->actorId())); }
    public function retryProductionIssue(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->retryProductionIssue($id,$this->body(),$this->actorId())); }
    public function returnEntry(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->returnEntry($id,$this->body(),$this->actorId())); }
    public function close(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->close($id,$this->body(),$this->actorId())); }
    public function reject(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->reject($id,$this->body(),$this->actorId())); }
    public function readScale(int $id) { return $this->execute(fn(): array=>(new RequestService(db_connect()))->readScale($id,$this->body(),$this->actorId())); }

    private function body(): array
    {
        // Este flujo valida alcance por actor y por almacén en RequestRepository.
        // El payload genérico antiguo presupone solicitud de la instalación origen.
        $data=$this->request->getJSON(true);
        Policy::require(is_array($data),'El cuerpo debe ser JSON válido.');
        return $data;
    }
}
