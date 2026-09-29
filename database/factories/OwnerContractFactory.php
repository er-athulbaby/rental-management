<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Always a draft: go through the allowed status steps (activeOwnerContract() in tests/Pest.php) for others.
 *
 * @extends Factory<OwnerContract>
 */
class OwnerContractFactory extends Factory
{
    protected $model = OwnerContract::class;

    public function definition(): array
    {
        return [
            'owner_id' => Owner::factory(),
            'building_id' => Building::factory(),
            'type' => 'managed',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->startOfMonth()->addYear()->subDay()->toDateString(),
            'fee_type' => 'percent_collected',
            'fee_value' => '5.000',
            'deposits_held_by' => 'company',
            'created_by' => User::factory(),
        ];
    }

    public function leased(string $rent = '1000.000'): static
    {
        return $this->state([
            'type' => 'leased', 'rent_amount' => $rent, 'payment_frequency' => 'monthly',
            'fee_type' => null, 'fee_value' => null, 'expense_approval_limit' => null, 'deposits_held_by' => null,
        ]);
    }
}
