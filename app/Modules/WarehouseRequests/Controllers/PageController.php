<?php
namespace App\Modules\WarehouseRequests\Controllers;

use App\Controllers\BaseController;
use App\Modules\WarehouseRequests\Repositories\RequestRepository;

final class PageController extends BaseController
{
    public function index() { return $this->page('requests'); }
    public function outgoing() { return $this->page('outgoing'); }
    public function incoming() { return $this->page('incoming'); }

    private function page(string $mode)
    {
        $scope=service('scopeContext')->restrictions();
        $catalogs=!empty($scope['warehouse_id'])?(new RequestRepository(db_connect()))->catalogs():null;
        $labels=['requests'=>'Solicitudes entre almacenes','outgoing'=>'Salidas por solicitud','incoming'=>'Entradas por solicitud'];
        $canCreate=auth()->user()->can('requests.create');
        $canSale=auth()->user()->can('sales.manage');
        $canHistory=$canCreate || auth()->user()->can('requests.approve') || auth()->user()->can('reservations.manage');
        return view('App\\Modules\\WarehouseRequests\\Views\\index',[
            'title'=>$labels[$mode],'subtitle'=>'Un folio, desde la solicitud hasta la recepción completa.',
            'canHistory'=>$canHistory,'canCapture'=>$canCreate || $canSale,
            'mode'=>$mode,'catalogs'=>$catalogs,'canCreate'=>$canCreate,'canSale'=>$canSale,
            'canAccept'=>auth()->user()->can('requests.approve') || auth()->user()->can('reservations.manage'),
            'canSelect'=>auth()->user()->can('reservations.manage'),'canOperate'=>auth()->user()->can('transfers.manage')]);
    }
}
