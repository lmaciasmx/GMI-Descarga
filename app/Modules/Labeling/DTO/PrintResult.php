<?php

namespace App\Modules\Labeling\DTO;

final readonly class PrintResult
{
    public function __construct(
        public bool $successful,
        public string $status,
        public ?string $providerReference = null,
        public ?string $outputPath = null,
        public ?string $errorMessage = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'successful' => $this->successful,
            'status' => $this->status,
            'provider_reference' => $this->providerReference,
            'output_path' => $this->outputPath,
            'error_message' => $this->errorMessage,
        ];
    }
}
