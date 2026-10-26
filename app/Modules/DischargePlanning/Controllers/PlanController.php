<?php

namespace App\Modules\DischargePlanning\Controllers;

use App\Controllers\BaseController;

final class PlanController extends BaseController
{
    public function index()
    {
        $scope = session('operational_scope');
        $db = db_connect();

        $plans = $db->table('discharge_plans dp')
            ->select(
                'dp.*, ' .
                've.code AS vessel_code, ve.name AS vessel_name, ' .
                'v.annual_number, v.year, v.status AS voyage_status, ' .
                'r.code AS rsa_code, r.issued_on AS rsa_issued_on, ' .
                'r.expires_on AS rsa_expires_on, ' .
                'COALESCE(SUM(dpt.planned_weight_kg), 0) AS planned_weight_kg, ' .
                'COUNT(dpt.id) AS composition_count'
            )
            ->join('voyages v', 'v.id = dp.voyage_id')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->join('rsa_records r', 'r.id = dp.rsa_id', 'left')
            ->join('discharge_plan_tanks dpt', 'dpt.discharge_plan_id = dp.id', 'left')
            ->where([
                'v.company_id' => $scope['company_id'],
                'v.site_id' => $scope['site_id'],
            ])
            ->groupBy(
                'dp.id, ve.code, ve.name, v.annual_number, v.year, ' .
                'v.status, r.code, r.issued_on, r.expires_on'
            )
            ->orderBy('dp.created_at', 'DESC')
            ->limit(300)
            ->get()
            ->getResultArray();

        $allVoyages = $db->table('voyages v')
            ->select('v.*, ve.code AS vessel_code, ve.name AS vessel_name')
            ->join('vessels ve', 've.id = v.vessel_id')
            ->where([
                'v.company_id' => $scope['company_id'],
                'v.site_id' => $scope['site_id'],
            ])
            ->orderBy('v.created_at', 'DESC')
            ->get()
            ->getResultArray();

        $voyages = array_values(array_filter(
            $allVoyages,
            static fn (array $voyage): bool => in_array(
                $voyage['status'],
                ['planned', 'unloading'],
                true
            )
        ));

        $voyageHistory = array_values(array_filter(
            $allVoyages,
            static fn (array $voyage): bool => in_array(
                $voyage['status'],
                ['closed', 'canceled'],
                true
            )
        ));

        $voyageIds = array_map('intval', array_column($voyages, 'id')) ?: [0];

        // Las embarcaciones son catálogos maestros globales.
        $vessels = $db->table('vessels')
            ->where('active', 1)
            ->orderBy('name')
            ->get()
            ->getResultArray();

        $warehouses = $db->table('warehouses')
            ->where([
                'company_id' => $scope['company_id'],
                'site_id' => $scope['site_id'],
                'active' => 1,
            ])
            ->orderBy('name')
            ->get()
            ->getResultArray();

        $tanks = $db->table('voyage_tanks')
            ->whereIn('voyage_id', $voyageIds)
            ->orderBy('tank_number')
            ->get()
            ->getResultArray();

        // Se incluyen RSA activos aunque su fecha haya vencido para marcarlos
        // visualmente como vencidos y evitar su selección.
        $rsas = $db->table('rsa_records')
            ->whereIn('voyage_id', $voyageIds)
            ->where('status', 'active')
            ->orderBy('code')
            ->get()
            ->getResultArray();

        $species = $db->table('species')
            ->where('active', 1)
            ->orderBy('name')
            ->get()
            ->getResultArray();

        $sizes = $db->table('size_grades')
            ->where('active', 1)
            ->orderBy('name')
            ->get()
            ->getResultArray();

        // La llave única es barco + año + número anual. Se consideran todas las
        // mareas para sugerir el siguiente número sin exponer otros datos de alcance.
        $numberRows = $db->table('voyages')
            ->select('vessel_id, year, annual_number, status')
            ->orderBy('annual_number')
            ->get()
            ->getResultArray();

        $voyageNumbers = [];

        foreach ($numberRows as $row) {
            $vesselId = (string) (int) $row['vessel_id'];
            $year = (string) (int) $row['year'];

            $voyageNumbers[$vesselId][$year][] = [
                'number' => (int) $row['annual_number'],
                'status' => (string) $row['status'],
            ];
        }

        $openPlanCountByVoyage = [];

        foreach ($plans as $plan) {
            if (! in_array($plan['status'], ['finished', 'canceled'], true)) {
                $voyageId = (int) $plan['voyage_id'];
                $openPlanCountByVoyage[$voyageId] =
                    ($openPlanCountByVoyage[$voyageId] ?? 0) + 1;
            }
        }

        return view('App\\Modules\\DischargePlanning\\Views\\index', [
            'title' => 'Planeación de descarga',
            'subtitle' => 'Mareas, RSA y composición por tanque con revisión previa y trazabilidad.',
            'plans' => $plans,
            'voyages' => $voyages,
            'voyageHistory' => $voyageHistory,
            'allVoyages' => $allVoyages,
            'vessels' => $vessels,
            'warehouses' => $warehouses,
            'tanks' => $tanks,
            'rsas' => $rsas,
            'species' => $species,
            'sizes' => $sizes,
            'mscValues' => service('settingsService')->get(
                'certification.msc_values',
                (int) $scope['company_id'],
                (int) $scope['site_id'],
                []
            ),
            'dolphinValues' => service('settingsService')->get(
                'certification.dolphin_safe_values',
                (int) $scope['company_id'],
                (int) $scope['site_id'],
                []
            ),
            'voyageNumbers' => $voyageNumbers,
            'openPlanCountByVoyage' => $openPlanCountByVoyage,
            'canManageVoyages' => auth()->user()->can('voyages.manage'),
            'canManagePlans' => auth()->user()->can('plans.manage'),
            'scope' => $scope,
        ]);
    }
}
