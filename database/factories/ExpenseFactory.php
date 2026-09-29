<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'category' => 'maintenance',
            'description' => fake()->sentence(3),
            'expense_date' => now()->toDateString(),
            'net' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'charge_to' => 'company',
            'status' => 'recorded',
            'posted_at' => now(),
            'recorded_by' => User::factory(),
        ];
    }
}
