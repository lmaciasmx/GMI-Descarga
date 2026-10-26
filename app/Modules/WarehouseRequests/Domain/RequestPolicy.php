<?php

namespace App\Modules\WarehouseRequests\Domain;

use App\Common\Exceptions\DomainException;

final class RequestPolicy
{
    public static function require(bool $condition, string $message, string $code = 'WAREHOUSE_REQUEST_INVALID'): void
    {
        if (! $condition) {
            throw new DomainException($message, $code);
        }
    }

    public static function grams(mixed $value): int
    {
        self::require(is_scalar($value) && preg_match('/^\d{1,11}(?:\.\d{1,3})?$/D', (string) $value) === 1,
            'El peso debe ser positivo, con hasta tres decimales.');
        $parts = explode('.', (string) $value);
        $grams = (int) $parts[0] * 1000 + (int) str_pad($parts[1] ?? '', 3, '0');
        self::require($grams > 0, 'El peso debe ser mayor que cero.');
        return $grams;
    }

    public static function matches(array $stock, array $line): bool
    {
        return (int) $stock['species_id'] === (int) $line['species_id']
            && (int) $stock['size_grade_id'] === (int) $line['size_grade_id']
            && (empty($line['lot_id']) || (int) $stock['lot_id'] === (int) $line['lot_id'])
            && (empty($line['owner_company_id']) || (int) $stock['owner_company_id'] === (int) $line['owner_company_id']);
    }

    public static function specificity(array $line): int
    {
        return (! empty($line['lot_id']) ? 1 : 0) + (! empty($line['owner_company_id']) ? 1 : 0);
    }

    /**
     * Devuelve las líneas compatibles de mayor especificidad para una tina.
     * Una línea específica (lote y/o propietario) tiene prioridad sobre una general.
     * Si quedan dos líneas con la misma prioridad, la intersección es ambigua y no debe surtirse
     * hasta ajustar los criterios.
     */
    public static function preferredLines(array $stock, array $lines): array
    {
        $matches = array_values(array_filter($lines, static fn(array $line): bool => self::matches($stock, $line)));
        if ($matches === []) {
            return [];
        }
        $max = max(array_map(static fn(array $line): int => self::specificity($line), $matches));
        return array_values(array_filter($matches, static fn(array $line): bool => self::specificity($line) === $max));
    }

    public static function ownsStock(array $stock, array $line, array $lines): bool
    {
        $preferred = self::preferredLines($stock, $lines);
        if (count($preferred) !== 1) {
            return false;
        }
        if (isset($line['id'], $preferred[0]['id'])) {
            return (int) $line['id'] === (int) $preferred[0]['id'];
        }
        return $preferred[0] === $line;
    }

    public static function open(array $request): void
    {
        self::require($request['supply_finalized_at'] === null && in_array($request['status'], ['accepted','preparing','dispatching'], true),
            'El surtido ya terminó. Registra otra solicitud para cantidades adicionales.', 'WAREHOUSE_REQUEST_FINALIZED');
    }

    public static function qr(string $code): string
    {
        $code = trim($code);
        if (str_starts_with(strtoupper($code),'GMAR|LABEL=')) { $code = substr($code,11); }
        self::require($code !== '' && strlen($code)<=200 && !preg_match('/[\x00-\x1f]/',$code), 'Lee un QR válido.');
        return $code;
    }

    public static function reading(array $reading, int $actor, int $company, int $site): void
    {
        self::require((int) ($reading['actor_user_id']??0)===$actor && $actor>0,
            'La lectura fue capturada por otro usuario.', 'SCALE_READING_ACTOR_MISMATCH');
        $timezone=new \DateTimeZone(config('App')->appTimezone);
        $received=new \DateTimeImmutable((string) $reading['received_at'],$timezone);
        $age=(new \DateTimeImmutable('now',$timezone))->getTimestamp()-$received->getTimestamp();
        $maxAge=max(5,min(3600,(int) service('settingsService')->get('scale.reading_max_age_seconds',$company,$site,120)));
        self::require($age>=-30 && $age<=$maxAge,'La lectura expiró. Solicita un nuevo peso estable.','SCALE_READING_EXPIRED');
        self::require($reading['source']!=='simulator' || ENVIRONMENT!=='production'
            || filter_var(env('integrations.scale.allowSimulatorInProduction',false),FILTER_VALIDATE_BOOLEAN),
            'El simulador está bloqueado en producción.','SIMULATOR_FORBIDDEN_IN_PRODUCTION');
    }

    public static function closeable(array $request, array $units): void
    {
        self::require($request['supply_finalized_at'] !== null && $request['status'] === 'dispatched',
            'Primero debe finalizarse el surtido.', 'WAREHOUSE_REQUEST_NOT_DISPATCHED');
        $sent = array_values(array_filter($units, static fn(array $u): bool => $u['status'] !== 'released'));
        self::require($sent !== [], 'No existen tinas enviadas.');
        foreach ($sent as $unit) {
            self::require(in_array($unit['status'], ['accepted','returned'], true),
                'Faltan tinas por recibir o devoluciones por reingresar al origen.', 'WAREHOUSE_REQUEST_PENDING_UNITS');
        }
    }

    public static function validateObservation(array $line, int $shippedGrams, int $units, string $observation): void
    {
        $differs = self::grams($line['requested_weight_kg']) !== $shippedGrams
            || ($line['requested_unit_count'] !== null && (int) $line['requested_unit_count'] !== $units);
        self::require(! $differs || mb_strlen(trim($observation)) >= 5,
            'Cada línea con diferencia de kilos o tinas requiere una observación de al menos cinco caracteres.');
        self::require(mb_strlen($observation) <= 1000, 'La observación no puede exceder 1000 caracteres.');
    }
}
