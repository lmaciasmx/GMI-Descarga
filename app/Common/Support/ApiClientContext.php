<?php

namespace App\Common\Support;

final class ApiClientContext
{
    private ?array $client = null;

    public function authenticate(array $client): void
    {
        $this->client = $client;
    }

    public function clear(): void
    {
        $this->client = null;
    }

    public function client(): ?array
    {
        return $this->client;
    }

    public function can(string $permission): bool
    {
        $permissions = $this->client['permissions'] ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
