<?php

namespace App\Modules\Maritime\Controllers\Api;

use App\Common\Controllers\ApiController;

final class VoyageController extends ApiController
{
    public function active()
    {
        return $this->execute(function (): array {
            $scope = service('scopeContext')->restrictions();

            $builder = db_connect()->table('voyages v')
                ->select('v.*, ve.code AS vessel_code, ve.name AS vessel_name')
                ->join('vessels ve', 've.id = v.vessel_id')
                ->whereIn('v.status', ['planned', 'unloading'])
                ->orderBy('v.arrival_at', 'ASC');

            if ($scope['company_id'] !== null) {
                $builder->where('v.company_id', $scope['company_id']);
            }

            if ($scope['site_id'] !== null) {
                $builder->where('v.site_id', $scope['site_id']);
            }

            if ($this->request->getGet('company_id') !== null) {
                service('scopeContext')->assertCompanySite((int) $this->request->getGet('company_id'), null);
                $builder->where('v.company_id', (int) $this->request->getGet('company_id'));
            }

            if ($this->request->getGet('site_id') !== null) {
                service('scopeContext')->assertCompanySite(null, (int) $this->request->getGet('site_id'));
                $builder->where('v.site_id', (int) $this->request->getGet('site_id'));
            }

            return ['items' => $builder->get()->getResultArray()];
        });
    }

    public function create()
    {
        return $this->execute(function (): array {
            $payload = $this->payload();

            /*
             * La empresa e instalación de una marea SIEMPRE provienen del
             * alcance operativo autenticado. No se confía en hidden inputs ni
             * en valores enviados por JavaScript para determinar el alcance.
             */
            $scope = service('scopeContext')->restrictions();

            if (
                ! $scope['configured']
                || $scope['company_id'] === null
                || $scope['site_id'] === null
            ) {
                throw new \App\Common\Exceptions\DomainException(
                    'Selecciona una empresa e instalación operativa antes de crear la marea.',
                    'OPERATIONAL_SCOPE_REQUIRED'
                );
            }

            $payload['company_id'] = (int) $scope['company_id'];
            $payload['site_id'] = (int) $scope['site_id'];

            $rules = [
                'company_id' => 'required|is_natural_no_zero',
                'site_id' => 'required|is_natural_no_zero',
                'vessel_id' => 'required|is_natural_no_zero',
                'annual_number' => 'required|is_natural_no_zero',
                'year' => 'required|integer|greater_than_equal_to[2020]|less_than_equal_to[2100]',
                'receiving_warehouse_id' => 'permit_empty|is_natural_no_zero',
                'port_name' => 'permit_empty|max_length[120]',
                'arrival_at' => 'permit_empty|valid_date[Y-m-d H:i:s]',
                'notes' => 'permit_empty|max_length[1000]',
                'tanks' => 'permit_empty|is_array',
            ];

            /*
             * Si el cliente NO envía tanks, VoyageService toma automáticamente
             * los tanques maestros activos de la embarcación.
             *
             * El error anterior ocurría porque las reglas wildcard
             * tanks.*.tank_number se evaluaban aun cuando tanks no venía en el
             * request, bloqueando antes de llegar al servicio.
             *
             * Sólo agregamos reglas de detalle cuando realmente se envían
             * tanques explícitos.
             */
            if (! empty($payload['tanks']) && is_array($payload['tanks'])) {
                foreach (array_keys($payload['tanks']) as $index) {
                    $rules["tanks.{$index}.tank_number"] = 'required|max_length[20]|alpha_numeric_punct';
                    $rules["tanks.{$index}.vessel_tank_id"] = 'permit_empty|is_natural_no_zero';
                    $rules["tanks.{$index}.side"] = 'permit_empty|max_length[20]';
                    $rules["tanks.{$index}.capacity_kg"] = 'permit_empty|decimal|greater_than[0]';
                    $rules["tanks.{$index}.planned_weight_kg"] = 'permit_empty|decimal|greater_than[0]';
                }
            }

            $this->validatePayload($payload, $rules);

            return service('voyageService')->create($payload, $this->actorId());
        }, 201);
    }

    public function transition(int $id)
    {
        return $this->execute(function () use ($id): array {
            $this->assertAggregateScope('voyage', $id);
            $payload = $this->payload();

            $this->validatePayload($payload, [
                'target' => 'required|in_list[planned,unloading,closed,canceled]',
                'reason' => 'permit_empty|min_length[5]|max_length[500]',
            ]);

            return service('voyageService')->transition(
                $id,
                (string) $payload['target'],
                $this->actorId(),
                isset($payload['reason']) ? (string) $payload['reason'] : null,
            );
        });
    }
}
