<?php

namespace App\Modules\Labeling\Adapters;

use App\Modules\Labeling\Contracts\LabelPrinterInterface;
use App\Modules\Labeling\DTO\PrintResult;
use Dompdf\Dompdf;
use Dompdf\Options;
use Ramsey\Uuid\Uuid;

final class PdfLabelPrinter implements LabelPrinterInterface
{
    public function printLabel(array $label): PrintResult
    {
        return $this->render($label, false, null);
    }

    public function reprintLabel(array $label, string $reason): PrintResult
    {
        return $this->render($label, true, $reason);
    }

    public function getPrinterStatus(): array
    {
        return ['connected' => true, 'status' => 'pdf_contingency', 'physical_printer' => false];
    }

    public function cancelPrintJob(string $jobId): bool
    {
        return false;
    }

    private function render(array $label, bool $reprint, ?string $reason): PrintResult
    {
        $reference = Uuid::uuid7()->toString();
        $directory = WRITEPATH . 'labels';

        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            return new PrintResult(false, 'failed', $reference, null, 'No fue posible crear el directorio de etiquetas.');
        }

        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($label['code'] ?? $reference));
        $path = $directory . DIRECTORY_SEPARATOR . $safeCode . '-' . substr($reference, -8) . '.pdf';
        if (($label['format'] ?? 'html') !== 'html' || empty($label['rendered_html'])) {
            return new PrintResult(false, 'failed', $reference, null, 'La contingencia PDF requiere una plantilla HTML.');
        }

        $banner = $reprint
            ? '<div class="reprint-flag">REIMPRESIÓN · ' . esc((string) $reason) . '</div>'
            : '';
        $rendered = (string) $label['rendered_html'];
        if ($banner !== '') {
            $rendered = preg_replace('/(<div class="label-border">)/', '$1' . $banner, $rendered, 1) ?? ($banner . $rendered);
        }
        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>@page{size:4in 6in;margin:0}html,body{margin:0;padding:0;width:4in;height:6in;overflow:hidden}</style></head><body>'
            . $rendered . '</body></html>';

        try {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $pdf = new Dompdf($options);
            $pdf->setPaper([0, 0, 288, 432]);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->render();
            if ($pdf->getCanvas()->get_page_count() !== 1) {
                throw new \RuntimeException('La etiqueta excedió una página 4x6.');
            }
            if (file_put_contents($path, $pdf->output(), LOCK_EX) === false) {
                throw new \RuntimeException('No fue posible escribir el archivo PDF.');
            }
        } catch (\Throwable $exception) {
            log_message('error', 'PDF label failure ({exception}): {message}', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return new PrintResult(false, 'failed', $reference, null, 'No se pudo generar la etiqueta PDF en formato 4x6.');
        }

        return new PrintResult(true, 'generated_pdf', $reference, $path);
    }
}
