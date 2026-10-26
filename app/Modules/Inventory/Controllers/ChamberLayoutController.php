<?php

namespace App\Modules\Inventory\Controllers;

use App\Controllers\BaseController;
use App\Modules\Inventory\Services\ChamberLayoutService;

final class ChamberLayoutController extends BaseController
{
    public function index()
    {
        $service = new ChamberLayoutService(db_connect());
        $chambers = $service->chambers();

        $requestedChamberId = (int) $this->request->getGet('chamber_id');

        if ($requestedChamberId <= 0 && $chambers !== []) {
            $requestedChamberId = (int) $chambers[0]['id'];
        }

        return view('App\Modules\Inventory\Views\chamber_layout', [
            'title' => 'Mapa de cámaras',
            'chambers' => $chambers,
            'selectedChamberId' => $requestedChamberId,
        ]);
    }
}
