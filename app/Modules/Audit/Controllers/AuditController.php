<?php

namespace App\Modules\Audit\Controllers;

use App\Controllers\BaseController;
use App\Modules\Audit\Services\AuditQueryService;

final class AuditController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope') ?? [];
        $service = new AuditQueryService(db_connect());

        $filters = $service->normalizeFilters([
            'from' => $this->request->getGet('from'),
            'to' => $this->request->getGet('to'),
            'actor' => $this->request->getGet('actor'),
            'aggregate' => $this->request->getGet('aggregate'),
            'action' => $this->request->getGet('action'),
            'q' => $this->request->getGet('q'),
        ]);

        $user = auth()->user();
        $canSeeSecurity = $user !== null
            && $user->inGroup('process_owner', 'identity_admin', 'support');

        $dashboard = $service->dashboard($scope, $filters, $canSeeSecurity);

        return view('App\\Modules\\Audit\\Views\\index', [
            'title' => 'Auditoría',
            'subtitle' => 'Trazabilidad de operaciones, cambios y accesos dentro del alcance actual.',
            'scope' => $scope,
            'filters' => $filters,
            'logs' => $dashboard['logs'],
            'kpis' => $dashboard['kpis'],
            'facets' => $dashboard['facets'],
            'securityEvents' => $dashboard['securityEvents'],
            'canSeeSecurity' => $dashboard['canSeeSecurity'],
            'maxRows' => $dashboard['maxRows'],
        ]);
    }
}
