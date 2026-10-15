<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Reports\Builder\Engine;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    $this->building = Building::factory()->create();
    $this->admin = matrixUser(RoleName::Management, $this->building);
    activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'], [Unit::factory()->for($this->building)->create()]);
});

test('every field of every subject compiles and runs', function (string $key) {
    $subject = Registry::get($key);
    $fields = array_keys($subject->fields($this->admin, CarbonImmutable::now('Asia/Bahrain')));
    $r = app(Engine::class)->run($this->admin, ReportDefinition::fromArray(['subject' => $key, 'columns' => array_slice($fields, 0, 30)], $this->admin));

    expect($r->columns)->not->toBeEmpty();
})->with(fn () => array_keys(Registry::all()));

test('units read occupancy as at the date, with the current tenant and rent', function () {
    $r = app(Engine::class)->run($this->admin, ReportDefinition::fromArray(['subject' => 'units', 'columns' => ['code', 'occupancy', 'current_tenant', 'current_rent']], $this->admin));

    expect($r->rows[0]['occupancy'])->toBe('occupied')->and($r->rows[0]['current_tenant'])->not->toBeNull()->and($r->rows[0]['current_rent'])->toBe('400.000');

    $before = app(Engine::class)->run($this->admin, ReportDefinition::fromArray(['subject' => 'units', 'columns' => ['code', 'occupancy'], 'as_at' => '2026-08-01'], $this->admin));
    expect($before->rows[0]['occupancy'])->toBe('vacant');
});
