<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Operational extends BaseConfig
{
    public int $defaultCompanyId = 1;
    public int $defaultSiteId = 1;
    public string $timezone = 'America/Mexico_City';
    public float $estimatedTareKg = 176.000;
    public int $lotRuleVersion = 1;
    public int $reservationMinutes = 120;
    public int $stableWeightReadings = 3;
    public float $stableWeightToleranceKg = 0.50;
}
