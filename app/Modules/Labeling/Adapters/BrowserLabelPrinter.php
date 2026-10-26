<?php

namespace App\Modules\Labeling\Adapters;

use App\Modules\Labeling\Contracts\LabelPrinterInterface;
use App\Modules\Labeling\DTO\PrintResult;
use Ramsey\Uuid\Uuid;

final class BrowserLabelPrinter implements LabelPrinterInterface
{
    public function printLabel(array $label): PrintResult
    {
        return new PrintResult(true, 'awaiting_browser', Uuid::uuid7()->toString());
    }

    public function reprintLabel(array $label, string $reason): PrintResult
    {
        return new PrintResult(true, 'awaiting_browser_reprint', Uuid::uuid7()->toString());
    }

    public function getPrinterStatus(): array
    {
        return ['connected' => null, 'status' => 'browser_managed', 'physical_printer' => null];
    }

    public function cancelPrintJob(string $jobId): bool
    {
        return false;
    }
}
