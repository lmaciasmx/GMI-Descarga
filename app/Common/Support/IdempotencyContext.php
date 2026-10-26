<?php

namespace App\Common\Support;

final class IdempotencyContext
{
    private ?string $requestHash = null;

    public function begin(string $requestHash): void
    {
        $this->requestHash = $requestHash;
    }

    public function requestHash(): ?string
    {
        return $this->requestHash;
    }

    public function clear(): void
    {
        $this->requestHash = null;
    }
}
