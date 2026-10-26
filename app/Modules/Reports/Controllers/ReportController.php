<?php

namespace App\Modules\Reports\Controllers;

use App\Controllers\BaseController;
use App\Modules\Reports\Services\ReportExportHistoryService;
use App\Modules\Reports\Services\ReportExportService;
use App\Modules\Reports\Services\ReportService;
use Dompdf\Dompdf;
use Dompdf\Options;
use InvalidArgumentException;

final class ReportController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $service = new ReportService();
        $catalog = $service->catalog();
        $enabledReportKeys = [
            'general.summary',
            'inventory.current',
            'inventory.movements',
            'requests.warehouse',
            'transfers.summary',
            'production.raw-material',
            'sales.dispatches',
            'traceability.unit',
            'discharge.summary',
            'warehouse.entries',
        ];

        $exporter = new ReportExportService();
        $historyService = new ReportExportHistoryService();
        $exportableReportKeys = $exporter->supportedKeys();

        $reportKey = (string) ($this->request->getGet('report') ?: 'general.summary');
        if (! isset($catalog[$reportKey]) || ! in_array($reportKey, $enabledReportKeys, true)) {
            $reportKey = 'general.summary';
        }

        $filters = [
            'from' => $this->validDate((string) $this->request->getGet('from')),
            'to' => $this->validDate((string) $this->request->getGet('to')),
        ];

        $format = strtolower((string) $this->request->getGet('format'));

        if (in_array($format, ['pdf', 'xlsx'], true) && $exporter->supports($reportKey)) {
            $user = auth()->user();
            $userId = auth()->id();
            $generatedBy = (string) (
                $user->username
                ?? $user->name
                ?? $user->email
                ?? $userId
                ?? 'Usuario'
            );

            $historyId = $historyService->begin(
                $reportKey,
                (string) ($catalog[$reportKey]['title'] ?? $reportKey),
                $format,
                $scope,
                $filters,
                $userId ? (int) $userId : null,
                $generatedBy
            );

            try {
                $exportFilters = $filters;
                if ($format === 'xlsx') {
                    $exportFilters['export_full'] = true;
                }

                $exportReport = $service->get($reportKey, $scope, $exportFilters);
                $exportData = $exporter->prepare(
                    $reportKey,
                    $catalog[$reportKey],
                    $exportReport,
                    $scope,
                    $filters,
                    $generatedBy
                );

                if ($format === 'xlsx') {
                    $binary = $exporter->buildXlsx($exportData);
                    $historyService->complete($historyId, (int) ($exportData['rowCount'] ?? 0));

                    return $this->response
                        ->setHeader(
                            'Content-Type',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                        )
                        ->setHeader(
                            'Content-Disposition',
                            'attachment; filename="' . $exporter->filename($exportData['title'], 'xlsx') . '"'
                        )
                        ->setHeader('Cache-Control', 'private, no-store, max-age=0')
                        ->setBody($binary);
                }

                $options = new Options();
                $options->set('defaultFont', 'DejaVu Sans');
                $options->set('isRemoteEnabled', false);
                $options->set('isHtml5ParserEnabled', true);

                $dompdf = new Dompdf($options);
                $exportData['pdfRows'] = $exporter->pdfRows($exportData, 150);
                $dompdf->loadHtml(
                    view('App\\Modules\\Reports\\Views\\report_export_pdf', [
                        'export' => $exportData,
                    ]),
                    'UTF-8'
                );
                $dompdf->setPaper('letter', 'landscape');
                $dompdf->render();
                $binary = $dompdf->output();

                $historyService->complete($historyId, (int) ($exportData['rowCount'] ?? 0));

                return $this->response
                    ->setHeader('Content-Type', 'application/pdf')
                    ->setHeader(
                        'Content-Disposition',
                        'attachment; filename="' . $exporter->filename($exportData['title'], 'pdf') . '"'
                    )
                    ->setHeader('Cache-Control', 'private, no-store, max-age=0')
                    ->setBody($binary);
            } catch (\Throwable $e) {
                $historyService->fail($historyId, $e);
                throw $e;
            }
        }

        $overview = $service->overview($scope, $filters);

        try {
            $report = $service->get($reportKey, $scope, $filters);
        } catch (InvalidArgumentException) {
            $reportKey = 'general.summary';
            $report = $service->get($reportKey, $scope, $filters);
        }

        $exportHistory = $historyService->recent($scope, 20);

        return view('App\\Modules\\Reports\\Views\\index', [
            'title' => 'Centro de Reportes',
            'subtitle' => 'Consulta, filtra y analiza la operación completa sin alterar los procesos operativos.',
            'catalog' => $catalog,
            'enabledReportKeys' => $enabledReportKeys,
            'exportableReportKeys' => $exportableReportKeys,
            'overview' => $overview,
            'activeReportKey' => $reportKey,
            'activeReport' => $catalog[$reportKey],
            'report' => $report,
            'filters' => $filters,
            'scope' => $scope,
            'exportHistory' => $exportHistory,
        ]);
    }

    private function validDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
