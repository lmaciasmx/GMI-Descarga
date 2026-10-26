<?php

namespace App\Modules\WarehouseEntry\Controllers;

use App\Controllers\BaseController;

final class WarehouseEntryController extends BaseController
{
    public function index()
    {
        $context = service('warehouseEntryService')->stationContext();

        return view('App\\Modules\\WarehouseEntry\\Views\\entry', $context + [
            'title' => 'Entrada a Frigorífico',
            'subtitle' => 'Registra tina, pesaje, marbete, recepción e inventario con ubicación inicial automática.',
        ]);
    }

    public function placement()
    {
        $context = service('warehouseEntryService')->placementContext();

        return view('App\\Modules\\WarehouseEntry\\Views\\placement', $context + [
            'title' => 'Acomodo de tinas',
            'subtitle' => 'Confirma o corrige Cámara y Fila/Pasillo; la ubicación interna se asigna automáticamente.',
        ]);
    }
}
