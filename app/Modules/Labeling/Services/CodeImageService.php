<?php

namespace App\Modules\Labeling\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Picqer\Barcode\BarcodeGeneratorPNG;

final class CodeImageService
{
    public function qrDataUri(string $payload): string
    {
        return Builder::create()
            ->writer(new PngWriter())
            ->data($payload)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
            ->size(220)
            ->margin(6)
            ->build()
            ->getDataUri();
    }

    public function barcodeDataUri(string $value): string
    {
        $generator = new BarcodeGeneratorPNG();
        $png = $generator->getBarcode($value, $generator::TYPE_CODE_128, 2, 56);

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
