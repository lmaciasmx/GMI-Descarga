<?php

namespace App\Modules\Integrations\Controllers;

use App\Controllers\BaseController;

final class IntegrationConsoleController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $scaleStatus = $this->probe(static fn (): array => service('scaleAdapter')->getStatus());
        $printerStatus = $this->probe(static fn (): array => service('labelPrinter')->getPrinterStatus());
        $db = db_connect();
        $apiClients = $db->table('api_clients')->select('id, client_id, name, permissions, company_id, site_id, active, last_used_at, created_at')
            ->groupStart()->where('company_id', $scope['company_id'])->orWhere('company_id', null)->groupEnd()
            ->groupStart()->where('site_id', $scope['site_id'])->orWhere('site_id', null)->groupEnd()
            ->orderBy('name')->get()->getResultArray();
        return view('App\\Modules\\Integrations\\Views\\index', [
            'title' => 'Integraciones y dispositivos',
            'subtitle' => 'Diagnóstico desacoplado; los simuladores y contingencias se identifican explícitamente.',
            'scaleStatus' => $scaleStatus,
            'printerStatus' => $printerStatus,
            'scaleDriver' => (string) env('integrations.scale.driver', 'simulator'),
            'printerDriver' => (string) env('integrations.printer.driver', 'pdf'),
            'scales' => $db->table('scales')->where('site_id', $scope['site_id'])->orderBy('name')->get()->getResultArray(),
            'printers' => $db->table('printers')->where('site_id', $scope['site_id'])->orderBy('name')->get()->getResultArray(),
            'apiClients' => $apiClients,
            'events' => $db->table('integration_events ie')->select('ie.id, ie.direction, ie.event_type, ie.aggregate_type, ie.aggregate_id, ie.correlation_id, ie.status, ie.attempt_count, ie.response_code, ie.error_summary, ie.occurred_at, e.code AS endpoint_code')->join('integration_endpoints e', 'e.id = ie.endpoint_id', 'left')->orderBy('ie.occurred_at', 'DESC')->limit(300)->get()->getResultArray(),
            'printJobs' => $db->table('print_jobs pj')->select('pj.id, pj.result, pj.print_number, pj.requested_at, pj.completed_at, pj.error_message, l.code AS label_code, p.code AS printer_code')->join('labels l', 'l.id = pj.label_id')->join('printers p', 'p.id = pj.printer_id')->where('p.site_id', $scope['site_id'])->orderBy('pj.requested_at', 'DESC')->limit(100)->get()->getResultArray(),
        ]);
    }

    private function probe(callable $callback): array
    {
        try {
            return ['reachable' => true, 'data' => $callback(), 'error' => null];
        } catch (\Throwable $exception) {
            service('operationalLogger')->write('integrations', 'warning', 'integration.probe_failed', ['exception' => $exception::class]);
            return ['reachable' => false, 'data' => [], 'error' => 'Consulta no disponible; revisa el log de integración.'];
        }
    }
}
