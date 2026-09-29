<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySetting>
 */
class CompanySettingFactory extends Factory
{
    protected $model = CompanySetting::class;

    public function definition(): array
    {
        return [
            'id' => 1,
            'name_en' => 'Demo Properties W.L.L.',
            'name_ar' => 'شركة ديمو للعقارات ذ.م.م',
            'email' => 'office@demo.test',
        ];
    }
}
