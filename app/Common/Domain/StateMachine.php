<?php

namespace App\Common\Domain;

use App\Common\Exceptions\DomainException;

final class StateMachine
{
    public function __construct(private readonly array $transitions)
    {
    }

    public function assertCanTransition(string $from, string $to): void
    {
        if (! in_array($to, $this->transitions[$from] ?? [], true)) {
            throw new DomainException(
                "Transición no permitida: {$from} → {$to}.",
                'INVALID_STATE_TRANSITION',
                ['from' => $from, 'to' => $to]
            );
        }
    }

    public function allowedFrom(string $from): array
    {
        return $this->transitions[$from] ?? [];
    }
}
