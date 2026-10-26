<?php

namespace App\Modules\Sales\Controllers\Api;

use App\Common\Controllers\ApiController;
use App\Modules\Sales\Services\SalesRequestService;

final class SalesRequestController extends ApiController
{
    public function history()
    {
        return $this->execute(function (): array {
            return (new SalesRequestService(db_connect()))->history((array) $this->request->getGet());
        });
    }

    public function show(int $id)
    {
        return $this->execute(function () use ($id): array {
            return (new SalesRequestService(db_connect()))->detail($id);
        });
    }

    public function availability(int $id)
    {
        return $this->execute(function () use ($id): array {
            return (new SalesRequestService(db_connect()))->availability(
                $id,
                (int) $this->request->getGet('line_id'),
                ['search' => (string) $this->request->getGet('search')]
            );
        });
    }

    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->request->getJSON(true);
            if (! is_array($payload)) {
                $payload = [];
            }

            return (new SalesRequestService(db_connect()))->create($payload, $this->actorId());
        }, 201);
    }

    public function createCustomer()
    {
        return $this->execute(function (): array {
            $payload = $this->request->getJSON(true);
            if (! is_array($payload)) {
                $payload = [];
            }

            return (new SalesRequestService(db_connect()))->createCustomer($payload, $this->actorId());
        }, 201);
    }

    public function prepare(int $id)
    {
        return $this->execute(function () use ($id): array {
            $payload = $this->request->getJSON(true);
            if (! is_array($payload)) {
                $payload = [];
            }

            return (new SalesRequestService(db_connect()))->prepare($id, $payload, $this->actorId());
        });
    }

    public function shipUnit(int $id)
    {
        return $this->execute(function () use ($id): array {
            $payload = $this->request->getJSON(true);
            if (! is_array($payload)) {
                $payload = [];
            }

            return (new SalesRequestService(db_connect()))->shipUnit($id, $payload, $this->actorId());
        });
    }

    public function finalizeOutgoing(int $id)
    {
        return $this->execute(function () use ($id): array {
            $payload = $this->request->getJSON(true);
            if (! is_array($payload)) {
                $payload = [];
            }

            return (new SalesRequestService(db_connect()))->finalizeOutgoing(
                $id,
                $payload,
                $this->actorId()
            );
        });
    }

    public function cancelShipment(int $id)
    {
        return $this->execute(function () use ($id): array {
            $payload = $this->request->getJSON(true);
            if (! is_array($payload)) {
                $payload = [];
            }

            return (new SalesRequestService(db_connect()))->cancelShipment(
                $id,
                $payload,
                $this->actorId()
            );
        });
    }

    public function release(int $id)
    {
        return $this->execute(function () use ($id): array {
            $payload = $this->request->getJSON(true);
            if (! is_array($payload)) {
                $payload = [];
            }

            return (new SalesRequestService(db_connect()))->release($id, $payload, $this->actorId());
        });
    }
}
