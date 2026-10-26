<?php

namespace App\Modules\WarehouseRequests\Controllers;

use App\Controllers\BaseController;
use App\Modules\WarehouseRequests\Repositories\RequestRepository;
use App\Modules\WarehouseRequests\Services\RequestExcelExporter;
use Dompdf\Dompdf;
use Dompdf\Options;

final class ReportController extends BaseController
{
    public function pdf(int $id)
    {
        $data = $this->reportData($id);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(
            view('App\\Modules\\WarehouseRequests\\Views\\report_pdf', $data),
            'UTF-8'
        );
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $filename = $this->filename((string) $data['request']['folio'], 'pdf');

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setBody($dompdf->output());
    }

    public function excel(int $id)
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'La extensión ZIP de PHP no está disponible para generar Excel XLSX.'
            );
        }

        $data = $this->reportData($id);
        $exporter = new RequestExcelExporter();
        $binary = $exporter->build($data);
        $filename = $this->filename((string) $data['request']['folio'], 'xlsx');

        return $this->response
            ->setHeader(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            )
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setBody($binary);
    }

    private function reportData(int $id): array
    {
        $repository = new RequestRepository(db_connect());

        // detail() conserva el mismo control de alcance que usa la pantalla actual.
        $detail = $repository->detail($id);

        // El documento incluye el expediente completo, no la paginación visual.
        $detail['units'] = $repository->units($id, null, '');

        $request = $detail['request'];
        $lines = $detail['lines'];
        $units = $detail['units'];
        $events = $detail['events'] ?? [];
        $production = $detail['production'] ?? null;

        $lineStats = [];
        foreach ($lines as $line) {
            $lineStats[(int) $line['id']] = $this->emptyLineStats();
        }

        $totals = [
            'units' => count($units),
            'selected_units' => 0,
            'dispatched_units' => 0,
            'received_units' => 0,
            'returning_units' => 0,
            'returned_units' => 0,
            'outbound_kg' => 0.0,
            'inbound_kg' => 0.0,
            'difference_kg' => 0.0,
        ];

        foreach ($units as $unit) {
            $lineId = (int) ($unit['request_line_id'] ?? 0);
            $lineStats[$lineId] ??= $this->emptyLineStats();

            $status = (string) ($unit['status'] ?? '');
            $outbound = $this->nullableFloat(
                $unit['outbound_weight_kg']
                    ?? $unit['shipped_weight_kg']
                    ?? null
            );
            $inbound = $this->nullableFloat(
                $unit['inbound_weight_kg']
                    ?? $unit['received_weight_kg']
                    ?? null
            );
            $difference = $this->nullableFloat($unit['difference_kg'] ?? null);

            if ($status === 'selected') {
                $lineStats[$lineId]['selected_units']++;
                $totals['selected_units']++;
            }
            if (in_array($status, ['shipped', 'accepted', 'returning', 'returned'], true)) {
                $lineStats[$lineId]['dispatched_units']++;
                $totals['dispatched_units']++;
            }
            if ($status === 'accepted') {
                $lineStats[$lineId]['received_units']++;
                $totals['received_units']++;
            }
            if ($status === 'returning') {
                $lineStats[$lineId]['returning_units']++;
                $totals['returning_units']++;
            }
            if ($status === 'returned') {
                $lineStats[$lineId]['returned_units']++;
                $totals['returned_units']++;
            }

            if ($outbound !== null) {
                $lineStats[$lineId]['outbound_kg'] += $outbound;
                $totals['outbound_kg'] += $outbound;
            }
            if ($inbound !== null) {
                $lineStats[$lineId]['inbound_kg'] += $inbound;
                $totals['inbound_kg'] += $inbound;
            }
            if ($difference !== null) {
                $lineStats[$lineId]['difference_kg'] += $difference;
                $totals['difference_kg'] += $difference;
            }
        }

        foreach ($lines as &$line) {
            $line['report'] = $lineStats[(int) $line['id']] ?? $this->emptyLineStats();
        }
        unset($line);

        foreach (['outbound_kg', 'inbound_kg', 'difference_kg'] as $key) {
            $totals[$key] = round((float) $totals[$key], 3);
        }

        return [
            'request' => $request,
            'lines' => $lines,
            'units' => $units,
            'source' => $detail['source'],
            'destination' => $detail['destination'],
            'events' => $events,
            'production' => $production,
            'totals' => $totals,
            'requestTypeLabel' => $this->requestTypeLabel((string) ($request['request_type'] ?? '')),
            'requestStatusLabel' => $this->requestStatusLabel((string) ($request['status'] ?? '')),
            'unitStatusLabels' => [
                'selected' => 'Propuesta',
                'shipped' => 'En tránsito',
                'accepted' => 'Recibida',
                'returning' => 'Devolución pendiente',
                'returned' => 'Reingresada al origen',
                'released' => 'Liberada',
            ],
            'eventLabels' => [
                'created' => 'Solicitud enviada',
                'accepted' => 'Aceptada por proveedor',
                'preselection' => 'Propuesta actualizada',
                'unit_shipped' => 'Salida de tina',
                'supply_finalized' => 'Despacho finalizado',
                'dispatch_reopened' => 'Despacho reabierto',
                'receipt_registered' => 'Recepción o devolución registrada',
                'receipt_canceled' => 'Recepción anulada',
                'return_reentered' => 'Devolución reingresada',
                'production_issue_pending' => 'Salida a Producción pendiente',
                'production_issue_completed' => 'Salida a Producción completada',
                'closed' => 'Cierre validado',
                'rejected' => 'Solicitud rechazada',
            ],
            'generatedAt' => date('Y-m-d H:i:s'),
            'generatedBy' => (string) (auth()->user()->username ?? auth()->id()),
        ];
    }

    private function emptyLineStats(): array
    {
        return [
            'selected_units' => 0,
            'dispatched_units' => 0,
            'received_units' => 0,
            'returning_units' => 0,
            'returned_units' => 0,
            'outbound_kg' => 0.0,
            'inbound_kg' => 0.0,
            'difference_kg' => 0.0,
        ];
    }

    private function requestTypeLabel(string $type): string
    {
        return match ($type) {
            'transfer' => 'Traspaso interno',
            'production' => 'Materia prima para producción',
            'sale' => 'Venta a cliente',
            default => $type !== '' ? $type : 'Solicitud',
        };
    }

    private function requestStatusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'Pendiente de aceptación',
            'accepted' => 'Aceptada',
            'preparing' => 'En preparación',
            'dispatching' => 'Despacho abierto',
            'dispatched' => 'Despacho finalizado',
            'closed' => 'Cerrada',
            'rejected' => 'Rechazada',
            default => $status !== '' ? $status : '—',
        };
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function filename(string $folio, string $extension): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($folio)) ?: 'solicitud';

        return 'Solicitud_' . $safe . '_' . date('Ymd_His') . '.' . $extension;
    }
}
