<?php

namespace App\Modules\Labeling\Adapters;

use App\Modules\Labeling\Contracts\LabelPrinterInterface;
use App\Modules\Labeling\DTO\PrintResult;
use Dompdf\Dompdf;
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
        $banner = $reprint ? '<p style="color:#b42318;font-weight:bold">REIMPRESIÓN: ' . esc((string) $reason) . '</p>' : '';
        if (($label['format'] ?? 'html') !== 'html' || empty($label['rendered_html'])) {
            return new PrintResult(false, 'failed', $reference, null, 'La contingencia PDF requiere una plantilla HTML.');
        }
        $html = '<html><meta charset="utf-8"><body style="font-family:DejaVu Sans,sans-serif;font-size:12px">'
            . $banner . (string) $label['rendered_html'] . '</body></html>';

        try {
            $pdf = new Dompdf();
            $pdf->setPaper([0, 0, 288, 432]);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->render();
            if (file_put_contents($path, $pdf->output(), LOCK_EX) === false) {
                throw new \RuntimeException('No fue posible escribir el archivo PDF.');
            }
        } catch (\Throwable $exception) {
            log_message('error', 'PDF label failure ({exception}).', ['exception' => $exception::class]);

            return new PrintResult(false, 'failed', $reference, null, 'No se pudo generar la etiqueta PDF.');
        }

        return new PrintResult(true, 'generated_pdf', $reference, $path);
    }
}
