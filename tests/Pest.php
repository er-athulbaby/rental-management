<?php

use App\Models\Agreement;
use App\Models\OwnerContract;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Real commits across two connections. DatabaseTruncation only truncates BEFORE a test,
// so truncate after too, or committed rows leak into RefreshDatabase tests.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->afterEach(fn () => $this->truncateTablesForAllConnections())
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** An active owner contract with units, walked through the status steps the triggers allow. */
function activeOwnerContract(array $attributes, iterable $units): OwnerContract
{
    $contract = OwnerContract::factory()->create($attributes);
    $contract->units()->attach(collect($units)->map(fn ($unit) => is_int($unit) ? $unit : $unit->id)->all());
    $contract->forceFill(['status' => 'pending_approval'])->save();
    $contract->forceFill(['status' => 'active', 'number' => 'OC-TEST-'.$contract->id])->save();

    return $contract->fresh();
}

/** An active agreement with one rent charge per unit, walked through the status steps the triggers allow. No invoices. */
function activeAgreement(array $attributes, iterable $units, string $rent = '400.000'): Agreement
{
    $agreement = Agreement::factory()->create($attributes);

    foreach ($units as $unit) {
        $au = $agreement->agreementUnits()->create([
            'unit_id' => $unit->id, 'list_rent' => $unit->list_rent, 'deposit_amount' => $rent,
            'start_date' => $agreement->start_date, 'end_date' => $agreement->end_date,
        ]);
        $au->charges()->create(['type' => 'rent', 'monthly_amount' => $rent, 'tax_category' => 'exempt']);
    }

    $agreement->forceFill(['status' => 'pending_approval'])->save();
    $agreement->forceFill(['status' => 'active', 'number' => 'AGR-T-'.$agreement->id, 'verify_token' => Str::random(32)])->save();

    return $agreement->fresh();
}
