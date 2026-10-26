<?php

namespace App\Common\Exceptions;

use RuntimeException;

class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $domainCode = 'DOMAIN_ERROR',
        public readonly array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
