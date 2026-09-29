<?php

namespace Database\Factories;

use App\Enums\BuildingType;
use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    protected $model = Building::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Tower',
            'code' => strtoupper(fake()->unique()->bothify('B-###')),
            'location' => fake()->randomElement(['Juffair', 'Seef', 'Manama', 'Amwaj']),
            'type' => BuildingType::Residential,
            'floors_count' => fake()->numberBetween(2, 20),
        ];
    }
}
