<?php

namespace Database\Factories;

use App\Models\Owner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Owner>
 */
class OwnerFactory extends Factory
{
    protected $model = Owner::class;

    public function definition(): array
    {
        return [
            'type' => 'person',
            'name_en' => fake()->name(),
            'id_type' => 'cpr',
            'id_number' => (string) fake()->unique()->numerify('#########'),
            'phone' => '+973'.fake()->numerify('3#######'),
        ];
    }
}
