<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'code' => (string) fake()->unique()->numberBetween(100, 9999),
            'floor' => (string) fake()->numberBetween(0, 20),
            'use' => 'residential',
            'type' => 'flat',
            'furnishing' => 'unfurnished',
            'bedrooms' => 2,
            'bathrooms' => 2,
            'list_rent' => '400.000',
            'list_deposit' => '400.000',
            'list_service_charge' => '0.000',
        ];
    }
}
