<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Maintenance\Requests;
use App\Livewire\Maintenance\WorkOrders;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['name' => 'Seef Tower']);
    $this->b = Building::factory()->create(['name' => 'Juffair Heights']);
    $this->unitA = Unit::factory()->for($this->a)->create(['code' => '101']);
    $this->unitB = Unit::factory()->for($this->b)->create(['code' => '201']);
    $this->pm = matrixUser(RoleName::PropertyManager, $this->a);   // logs requests and work orders (sees every building)
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);      // views building A only
    $this->vendor = maintenanceVendor(['trades' => ['plumbing']]);
});

test('request figures: open and in progress (the tab counts) and urgent, in the viewer\'s buildings, under the filters', function () {
    maintenanceRequest($this->pm, $this->unitA, ['priority' => 'urgent']);                    // urgent, open
    $busy = maintenanceRequest($this->pm, $this->unitA, ['priority' => 'urgent']);
    workOrderFor($this->pm, $busy, $this->vendor);                                            // urgent, in progress
    maintenanceRequest($this->pm, $this->unitA);                                              // normal, open
    maintenanceRequest($this->pm, $this->unitB, ['priority' => 'urgent']);                    // building B: not visible

    Livewire::actingAs($this->leasing)->test(Requests::class)
        ->assertViewHas('counts', fn (array $c) => $c['open'] === 2 && $c['in_progress'] === 1)
        ->assertViewHas('urgent', 2)
        ->assertSeeInOrder(['Open', '2', 'In progress', '1', 'Urgent', '2'])
        ->set('category', 'electrical')->assertViewHas('urgent', 0);
});

test('work order figures: open, and overdue (scheduled before today, still open), under every filter but the status', function () {
    workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitA), $this->vendor, ['scheduled_on' => '2026-10-10']); // overdue
    workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitA), $this->vendor, ['scheduled_on' => '2026-10-20']);
    workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unitB), $this->vendor, ['scheduled_on' => '2026-10-01']); // building B

    Livewire::actingAs($this->leasing)->test(WorkOrders::class)
        ->assertViewHas('stats', ['open' => 2, 'overdue' => 1])
        ->assertSeeInOrder(['Open work orders', '2', 'Overdue work orders', '1'])
        ->set('status', 'completed')->assertViewHas('stats', ['open' => 2, 'overdue' => 1]);
});
