<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'type' => 'individual',
            'name_en' => fake()->name(),
            'id_type' => 'cpr',
            'id_number' => (string) fake()->unique()->numerify('#########'),
            'mobile' => '+973'.fake()->numerify('3#######'),
        ];
    }
}
