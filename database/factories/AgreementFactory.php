<?php

namespace Database\Factories;

use App\Models\Agreement;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Always a draft with no units; activeAgreement() in tests/Pest.php walks the allowed status steps.
 *
 * @extends Factory<Agreement>
 */
class AgreementFactory extends Factory
{
    protected $model = Agreement::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->startOfMonth()->addYear()->subDay()->toDateString(),
            'frequency' => 'monthly',
            'grace_days' => 5,
            'notice_period_days' => 30,
            'created_by' => User::factory(),
        ];
    }
}
