<?php

namespace App\Modules\Weighing\Services;

use App\Common\Exceptions\DomainException;
use App\Common\Services\SettingsService;
use App\Modules\Weighing\Models\LotModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Ramsey\Uuid\Uuid;

final class LotCodeService
{
    private const RULE_VERSION = 2;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly SettingsService $settings,
        private readonly LotModel $lots,
    ) {
    }

    /**
     * Regla operativa de lotes v2.
     *
     * Atún aleta amarilla (código A):
     *   <BARCO>A<MAREA><MM><AA>
     *   Ejemplo: FDA + A + 4 + 08 + 26 = FDAA40826
     *
     * Resto de especies (Barrilete, Big Eye, Bonita, etc.):
     *   consecutivo anual de 4 dígitos + "-" + AA
     *   Ejemplo: 0001-26
     *
     * Un mismo viaje/marea + especie reutiliza el lote ya creado; nunca se
     * genera un consecutivo nuevo por cada tina.
     */
    public function findOrCreateForFilling(int $fillingId, ?string $certification, int $actorUserId): array
    {
        $context = $this->db->query(<<<'SQL'
SELECT
    tf.id AS filling_id,
    tf.species_id,
    v.id AS voyage_id,
    v.annual_number,
    v.year,
    v.company_id,
    v.site_id,
    ve.code AS vessel_code,
    s.code AS species_code,
    s.name AS species_name,
    COALESCE(v.unloading_started_at, dp.planned_start_at, tf.started_at, v.arrival_at, CURRENT_TIMESTAMP) AS discharge_start_date
FROM tub_fillings tf
INNER JOIN discharge_plans dp ON dp.id = tf.discharge_plan_id
INNER JOIN voyages v ON v.id = dp.voyage_id
INNER JOIN vessels ve ON ve.id = v.vessel_id
INNER JOIN species s ON s.id = tf.species_id
WHERE tf.id = ?
LIMIT 1
SQL, [$fillingId])->getRowArray();

        if ($context === null) {
            throw new DomainException('Llenado no encontrado para generar lote.', 'FILLING_NOT_FOUND');
        }

        $configuredVersion = (int) $this->settings->get(
            'lot.rule_version',
            (int) $context['company_id'],
            (int) $context['site_id'],
            self::RULE_VERSION
        );
        $version = max(self::RULE_VERSION, $configuredVersion);

        $date = new \DateTimeImmutable((string) $context['discharge_start_date']);
        $year = $date->format('y');
        $month = $date->format('m');

        // Si el lote v2 ya existe para esta marea y especie, se reutiliza.
        $existing = $this->db->table('lots')
            ->where([
                'voyage_id' => (int) $context['voyage_id'],
                'species_id' => (int) $context['species_id'],
                'rule_version' => $version,
            ])
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if ($existing !== null) {
            return $existing;
        }

        $isYellowfin = $this->isYellowfin($context);
        $sequenceLock = null;

        if ($isYellowfin) {
            $code = $this->yellowfinCode($context, $month, $year);
        } else {
            $sequenceLock = 'gmar_lot_numeric_' . $year;
            $lock = (int) ($this->db->query('SELECT GET_LOCK(?, 5) AS acquired', [$sequenceLock])->getRow('acquired') ?? 0);
            if ($lock !== 1) {
                throw new DomainException('No fue posible reservar el consecutivo de lote. Intenta nuevamente.', 'LOT_SEQUENCE_LOCK_TIMEOUT');
            }

            // Verifica de nuevo dentro del lock por si otra estación creó el lote
            // mientras esperábamos el consecutivo.
            $existing = $this->db->table('lots')
                ->where([
                    'voyage_id' => (int) $context['voyage_id'],
                    'species_id' => (int) $context['species_id'],
                    'rule_version' => $version,
                ])
                ->orderBy('id', 'DESC')
                ->get()
                ->getRowArray();

            if ($existing !== null) {
                $this->db->query('SELECT RELEASE_LOCK(?)', [$sequenceLock]);
                return $existing;
            }

            $code = $this->nextAnnualNumericCode($year);
        }

        $payload = [
            'uuid' => Uuid::uuid7()->toString(),
            'code' => $code,
            'lot_type' => 'discharge',
            'voyage_id' => (int) $context['voyage_id'],
            'vessel_code' => strtoupper(trim((string) $context['vessel_code'])),
            'species_id' => (int) $context['species_id'],
            'species_code' => strtoupper(trim((string) $context['species_code'])),
            'annual_voyage_number' => (int) $context['annual_number'],
            // Se conservan estos componentes por compatibilidad con el esquema histórico.
            'period_component' => $month,
            'year_component' => $year,
            'certification_component' => null,
            'rule_version' => $version,
            'status' => 'open',
            'created_by' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            try {
                $id = $this->lots->insert($payload, true);
            } catch (DatabaseException $exception) {
                // Protección adicional por clave única.
                $row = $this->db->table('lots')
                    ->where(['code' => $code, 'rule_version' => $version])
                    ->get()
                    ->getRowArray();

                if ($row !== null) {
                    return $row;
                }

                throw $exception;
            }

            if (! is_int($id)) {
                throw new DomainException('No se pudo generar el lote de descarga.', 'LOT_CREATE_FAILED');
            }

            return $this->db->table('lots')->where('id', $id)->get()->getRowArray();
        } finally {
            if ($sequenceLock !== null) {
                $this->db->query('SELECT RELEASE_LOCK(?)', [$sequenceLock]);
            }
        }
    }

    private function isYellowfin(array $context): bool
    {
        $code = strtoupper(trim((string) ($context['species_code'] ?? '')));
        if ($code === 'A') {
            return true;
        }

        $name = $this->normalize((string) ($context['species_name'] ?? ''));
        return str_contains($name, 'ALETA AMARILLA') || str_contains($name, 'YELLOWFIN');
    }

    private function yellowfinCode(array $context, string $month, string $year): string
    {
        $vessel = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $context['vessel_code'])) ?: '';
        $voyage = (int) $context['annual_number'];

        if ($vessel === '' || $voyage <= 0) {
            throw new DomainException(
                'El lote de Atún Aleta Amarilla requiere código de barco y número de marea.',
                'YELLOWFIN_LOT_CONTEXT_INCOMPLETE'
            );
        }

        return $vessel . 'A' . $voyage . $month . $year;
    }

    private function nextAnnualNumericCode(string $year): string
    {
        $rows = $this->db->table('lots')
            ->select('code')
            ->like('code', '-' . $year, 'before')
            ->orderBy('code', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();

        $max = 0;
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if (preg_match('/^(\d{4})-' . preg_quote($year, '/') . '$/', $code, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        $next = $max + 1;
        if ($next > 9999) {
            throw new DomainException('Se agotó el consecutivo anual de lotes.', 'LOT_SEQUENCE_EXHAUSTED');
        }

        return str_pad((string) $next, 4, '0', STR_PAD_LEFT) . '-' . $year;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $ascii !== false ? strtoupper($ascii) : $value;
    }
}
