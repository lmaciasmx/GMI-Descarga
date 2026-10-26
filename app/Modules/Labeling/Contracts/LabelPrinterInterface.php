<?php

namespace App\Modules\Labeling\Contracts;

use App\Modules\Labeling\DTO\PrintResult;

interface LabelPrinterInterface
{
    public function printLabel(array $label): PrintResult;

    public function reprintLabel(array $label, string $reason): PrintResult;

    public function getPrinterStatus(): array;

    public function cancelPrintJob(string $jobId): bool;
}
