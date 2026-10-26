<?php

namespace App\Database\Factories;

use Faker\Generator;
use Ramsey\Uuid\Uuid;

final class StockUnitFactory
{
    public static function definition(Generator $faker, array $foreignKeys): array
    {
        $weight = $faker->randomFloat(3, 500, 1100);
        return array_merge($foreignKeys, [
            'uuid' => Uuid::uuid7()->toString(),
            'unit_code' => 'TEST-UNIT-' . strtoupper($faker->unique()->bothify('####??')),
            'quality_grade' => null,
            'original_weight_kg' => $weight,
            'on_hand_weight_kg' => $weight,
            'reserved_weight_kg' => 0,
            'status' => 'held',
            'received_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
