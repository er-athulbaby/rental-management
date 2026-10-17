<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Enums\RoleName as R;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Dashboard;
use App\Livewire\Units\Form as UnitForm;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
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
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unitA]);
    $this->pm = matrixUser(R::PropertyManager, $this->a);
});

test('the dashboard counts open urgent requests and lists the oldest five, in the viewer\'s buildings', function () {
    $urgentA = collect(range(1, 6))->map(fn (int $i) => maintenanceRequest($this->pm, $this->unitA, ['priority' => 'urgent', 'description' => "Urgent A{$i}"]));
    maintenanceRequest($this->pm, $this->unitB, ['priority' => 'urgent', 'description' => 'Urgent B']);
    maintenanceRequest($this->pm, $this->unitA, ['priority' => 'high', 'description' => 'Only high']);
    app(CancelMaintenanceRequest::class)->handle($this->pm, $urgentA[5], 'Duplicate');

    $card = Dashboard::urgentMaintenance(matrixUser(R::Leasing, $this->a));
    expect($card['count'])->toBe(5)
        ->and($card['requests']->pluck('description')->all())->toBe(['Urgent A1', 'Urgent A2', 'Urgent A3', 'Urgent A4', 'Urgent A5'])
        ->and(Dashboard::urgentMaintenance($this->pm)['count'])->toBe(6) // the property manager sees every building
        ->and(Dashboard::urgentMaintenance(matrixUser(R::Finance, $this->a)))->toBeNull();

    Livewire::actingAs($this->pm)->test(Dashboard::class)->assertSee('Urgent maintenance')->assertSee($urgentA[0]->number)->assertDontSee('Only high');
    Livewire::actingAs(matrixUser(R::Finance, $this->a))->test(Dashboard::class)->assertDontSee('Urgent maintenance');
});

test('the unit and tenant pages list their maintenance requests for maintenance.view holders', function () {
    $mine = maintenanceRequest($this->pm, $this->unitA, ['description' => 'Kitchen sink leaking']);
    $other = maintenanceRequest($this->pm, $this->unitB, ['description' => 'Lift noise']);

    Livewire::actingAs($this->pm)->test(UnitForm::class, ['unit' => $this->unitA])
        ->assertSee('Maintenance')->assertSee($mine->number)->assertSee('Kitchen sink leaking')->assertDontSee($other->number);
    Livewire::actingAs($this->pm)->test(CustomerForm::class, ['customer' => $this->tenant])
        ->assertSee($mine->number)->assertDontSee($other->number);

    $finance = matrixUser(R::Finance, $this->a); // can open both pages, has no maintenance.view
    Livewire::actingAs($finance)->test(UnitForm::class, ['unit' => $this->unitA])->assertDontSee($mine->number);
    Livewire::actingAs($finance)->test(CustomerForm::class, ['customer' => $this->tenant])->assertDontSee($mine->number);
});

test('a tenant page hides requests in buildings the viewer cannot see', function () {
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unitB]);
    $pmAll = matrixUser(R::PropertyManager, $this->a);
    $pmAll->buildings()->attach($this->b->id);
    $inA = maintenanceRequest($pmAll, $this->unitA, ['description' => 'Request in A']);
    $inB = maintenanceRequest($pmAll, $this->unitB, ['description' => 'Request in B', 'customer_id' => $this->tenant->id]);

    Livewire::actingAs($pmAll)->test(CustomerForm::class, ['customer' => $this->tenant])->assertSee($inA->number)->assertSee($inB->number);
    Livewire::actingAs(matrixUser(R::Leasing, $this->a))->test(CustomerForm::class, ['customer' => $this->tenant])
        ->assertSee($inA->number)->assertDontSee($inB->number);
});
