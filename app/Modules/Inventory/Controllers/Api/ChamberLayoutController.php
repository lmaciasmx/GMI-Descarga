<?php

namespace App\Modules\Inventory\Controllers\Api;

use App\Common\Exceptions\DomainException;
use App\Controllers\BaseController;
use App\Modules\Inventory\Services\ChamberLayoutService;
use CodeIgniter\HTTP\ResponseInterface;

final class ChamberLayoutController extends BaseController
{
    public function show(int $chamberId): ResponseInterface
    {
        try {
            return $this->response->setJSON([
                'data' => $this->service()->layout($chamberId),
            ]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'message' => 'No fue posible cargar el mapa de la cámara.',
                ]);
        }
    }

    public function restrict(int $locationId): ResponseInterface
    {
        try {
            $payload = $this->request->getJSON(true);

            if (! is_array($payload)) {
                $payload = [];
            }

            $result = $this->service()->restrict(
                $locationId,
                $payload,
                (int) (auth()->id() ?? 0)
            );

            return $this->response->setJSON([
                'data' => $result,
                'message' => 'Ubicación inhabilitada.',
            ]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'message' => 'No fue posible inhabilitar la ubicación.',
                ]);
        }
    }

    public function release(int $locationId): ResponseInterface
    {
        try {
            $result = $this->service()->release(
                $locationId,
                (int) (auth()->id() ?? 0)
            );

            return $this->response->setJSON([
                'data' => $result,
                'message' => 'Ubicación liberada.',
            ]);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'message' => 'No fue posible liberar la ubicación.',
                ]);
        }
    }

    private function service(): ChamberLayoutService
    {
        return new ChamberLayoutService(db_connect());
    }

    private function domainError(DomainException $exception): ResponseInterface
    {
        return $this->response
            ->setStatusCode(422)
            ->setJSON([
                'message' => $exception->getMessage(),
            ]);
    }
}
