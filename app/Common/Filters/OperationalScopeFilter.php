<?php

namespace App\Common\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class OperationalScopeFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        $scope = $session->get('operational_scope');

        if (! is_array($scope) || empty($scope['company_id']) || empty($scope['site_id'])) {
            if ($request->isAJAX() || str_starts_with($request->getUri()->getPath(), 'api/') || str_starts_with($request->getUri()->getPath(), 'internal/')) {
                return service('response')->setStatusCode(403)->setJSON([
                    'success' => false,
                    'data' => null,
                    'meta' => [],
                    'error' => ['code' => 'SCOPE_REQUIRED', 'message' => 'Selecciona empresa e instalación.', 'details' => []],
                ]);
            }

            return redirect()->to('/scope/select')->with('warning', 'Selecciona el alcance operativo.');
        }

        $authorized = db_connect()->table('operational_user_scopes ous')
            ->select('ous.*, c.active AS company_active, s.active AS site_active, w.active AS warehouse_active, s.company_id AS site_company_id, w.company_id AS warehouse_company_id, w.site_id AS warehouse_site_id')
            ->join('companies c', 'c.id = ous.company_id')
            ->join('sites s', 's.id = ous.site_id', 'left')
            ->join('warehouses w', 'w.id = ous.warehouse_id', 'left')
            ->where([
                'ous.id' => (int) ($scope['scope_id'] ?? 0),
                'ous.user_id' => (int) auth()->id(),
                'ous.active' => 1,
            ])->get()->getRowArray();
        $scopeIsCurrent = $authorized !== null
            && (int) $authorized['company_active'] === 1
            && (int) ($authorized['site_active'] ?? 0) === 1
            && ($authorized['warehouse_id'] === null || (int) ($authorized['warehouse_active'] ?? 0) === 1)
            && (int) $authorized['site_company_id'] === (int) $authorized['company_id']
            && ($authorized['warehouse_id'] === null
                || ((int) $authorized['warehouse_company_id'] === (int) $authorized['company_id']
                    && (int) $authorized['warehouse_site_id'] === (int) $authorized['site_id']))
            && (int) $authorized['company_id'] === (int) $scope['company_id']
            && (int) ($authorized['site_id'] ?? 0) === (int) $scope['site_id']
            && (int) ($authorized['warehouse_id'] ?? 0) === (int) ($scope['warehouse_id'] ?? 0);

        if (! $scopeIsCurrent) {
            $session->remove('operational_scope');

            if ($request->isAJAX() || str_starts_with($request->getUri()->getPath(), 'internal/')) {
                return service('response')->setStatusCode(403)->setJSON([
                    'success' => false,
                    'data' => null,
                    'meta' => [],
                    'error' => ['code' => 'SCOPE_REVOKED', 'message' => 'El alcance operativo expiró o fue revocado.', 'details' => []],
                ]);
            }

            return redirect()->to('/scope/select')->with('warning', 'El alcance operativo expiró o fue revocado.');
        }

        service('scopeContext')->configure(
            (int) $authorized['company_id'],
            (int) $authorized['site_id'],
            empty($authorized['warehouse_id']) ? null : (int) $authorized['warehouse_id'],
        );

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        service('scopeContext')->clear();
    }
}
