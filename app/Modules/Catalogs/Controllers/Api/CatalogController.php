<?php

namespace App\Modules\Catalogs\Controllers\Api;

use App\Common\Controllers\ApiController;

final class CatalogController extends ApiController
{
    public function show(string $catalog)
    {
        return $this->execute(function () use ($catalog): array {
            return [
                'items' => service('catalogService')->list(
                    $catalog,
                    $this->request->getGet('q'),
                    (int) ($this->request->getGet('limit') ?? 100),
                    filter_var($this->request->getGet('include_inactive'), FILTER_VALIDATE_BOOLEAN),
                ),
            ];
        });
    }

    public function create(string $catalog)
    {
        return $this->execute(
            fn (): array => service('catalogService')->create($catalog, $this->payload(), $this->actorId()),
            201,
        );
    }

    public function update(string $catalog, int $id)
    {
        return $this->execute(
            fn (): array => service('catalogService')->update($catalog, $id, $this->payload(), $this->actorId()),
        );
    }

    public function deactivate(string $catalog, int $id)
    {
        return $this->execute(
            fn (): array => service('catalogService')->deactivate($catalog, $id, $this->actorId()),
        );
    }
}
