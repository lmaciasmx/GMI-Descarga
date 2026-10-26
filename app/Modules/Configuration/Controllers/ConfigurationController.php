<?php

namespace App\Modules\Configuration\Controllers;

use App\Controllers\BaseController;

final class ConfigurationController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        return view('App\\Modules\\Configuration\\Views\\index', [
            'title' => 'Configuración operativa',
            'subtitle' => 'Reglas versionables por empresa e instalación; los secretos permanecen fuera del código.',
            'settings' => service('settingsService')->listEditable((int) $scope['company_id'], (int) $scope['site_id']),
        ]);
    }
}
