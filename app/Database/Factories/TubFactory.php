<?php

namespace App\Database\Factories;

use Faker\Generator;

final class TubFactory
{
    public static function definition(Generator $faker): array
    {
        $code = 'T-' . strtoupper($faker->unique()->bothify('###??'));

        return [
            'code' => $code,
            'barcode' => 'TEST-' . $code,
            'current_tare_kg' => $faker->randomFloat(3, 160, 190),
            'status' => 'available',
            'active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }
}