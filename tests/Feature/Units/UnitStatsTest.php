<?php

use App\Enums\RoleName;
use App\Livewire\Units\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->a = Building::factory()->create();
    $this->b = Building::factory()->create();
    $this->leasing = matrixUser(RoleName::Leasing, $this->a); // assigned buildings only
});

test('unit figures: occupied today, vacant and blocked, in the viewer\'s buildings, under the list\'s filters', function () {
    $let = Unit::factory()->for($this->a)->create(['code' => 'A1']);
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$let]);
    Unit::factory()->for($this->a)->create(['code' => 'A2', 'blocked' => true, 'blocked_reason' => 'Repairs']);
    Unit::factory()->for($this->a)->create(['code' => 'A3']);
    Unit::factory()->for($this->b)->create(['code' => 'B1']); // building B: not visible

    Livewire::actingAs($this->leasing)->test(Index::class)
        ->assertViewHas('stats', ['occupied' => 1, 'vacant' => 1, 'blocked' => 1])
        ->assertSeeInOrder(['Occupied', '1', 'Vacant', '1', 'Blocked', '1'])
        ->set('search', 'A3')->assertViewHas('stats', ['occupied' => 0, 'vacant' => 1, 'blocked' => 0]);
});

test('a unit that is blocked and let counts as occupied, not blocked', function () {
    $both = Unit::factory()->for($this->a)->create(['code' => 'A1', 'blocked' => true, 'blocked_reason' => 'Repairs']);
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$both]);

    Livewire::actingAs($this->leasing)->test(Index::class)
        ->assertViewHas('stats', ['occupied' => 1, 'vacant' => 0, 'blocked' => 0]);
});
