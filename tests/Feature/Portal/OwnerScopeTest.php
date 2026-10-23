<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName as R;
use App\Livewire\Portal\Owner\Buildings;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Models\Unit;
use App\Portal\OwnerPortal;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->admin = matrixUser(R::Admin, Building::factory()->create());
    $this->a = portalOwnerFixture('Ali Owner', '101', $this->admin);
    $this->b = portalOwnerFixture('Badr Owner', '202', $this->admin);
});

test('every owner-portal query returns only the owner\'s own records', function () {
    $id = $this->a['owner']->id;
    (new OwnerPayable)->forceFill(['owner_contract_id' => $this->a['leased']->id, 'period_start' => '2026-12-01', 'period_end' => '2026-12-31',
        'due_date' => '2026-12-01', 'amount' => '300.000', 'status' => 'cancelled'])->save();
    DB::table('disbursements')->insert([
        ['number' => 'DSB-101-9', 'payee_type' => 'owner', 'payee_id' => $id, 'purpose' => 'other', 'amount' => '5.000', 'method' => 'cash', 'status' => 'reversed',
            'paid_on' => '2026-10-06', 'posted_at' => '2026-10-06 10:00:00', 'reversed_at' => '2026-10-07 10:00:00', 'created_by' => $this->admin->id,
            'recorded_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(OwnerPortal::statements($id)->pluck('number')->all())->toBe(['OS-101'])
        ->and(OwnerPortal::payouts($id)->orderBy('number')->pluck('number')->all())->toBe(['DSB-101-1', 'DSB-101-2'])
        ->and(OwnerPortal::payables($id)->orderBy('due_date')->get()->map(fn (OwnerPayable $p) => $p->due_date->toDateString())->all())->toBe(['2026-10-01', '2026-11-01'])
        ->and(OwnerPortal::expenses($id)->orderBy('id')->pluck('description')->all())->toBe(['Leak repair 101', 'Paint touch-up 101'])
        ->and(OwnerPortal::requests($id)->pluck('number')->all())->toBe([$this->a['request']->number])
        ->and(OwnerPortal::costFils($this->a['request'], $id))->toBe(50_000)
        ->and(OwnerPortal::costFils($this->b['request'], $id))->toBe(0)
        ->and(OwnerPortal::summary($this->a['statement']))->toBe(['opening' => 0, 'income' => 500_000, 'charges' => 50_000, 'fees' => 0, 'paid' => 0, 'closing' => 450_000]);
});

test('a managed building shows its rent roll: units, tenant names, agreement dates, monthly rent, overdue and not yet due and vacant units', function () {
    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.buildings'))->assertOk()
        ->assertSeeInOrder(['Ali Owner Tower', '1 of 2 units let', 'Unit 101', 'Renter 101', '01/01/2026 – 31/12/2026', 'Monthly rent: 400.000 BHD',
            'Overdue: 400.000 BHD', 'Not yet due: 0.000 BHD', 'Unit V101', 'Vacant', 'Ali Owner Lease', 'Units: L101'])
        ->assertDontSee('Badr Owner');
});

test('the rent roll shows no tenant contact details, and a leased building no tenant or rent', function () {
    $renter = Customer::query()->where('name_en', 'Renter 101')->sole();

    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.buildings'))->assertOk()
        ->assertDontSee($renter->mobile)->assertDontSee((string) $renter->email)->assertDontSee($renter->id_number)->assertDontSee('Lessee 101');

    $cards = Livewire::actingAs($this->a['account'], 'tenant')->test(Buildings::class)->viewData('buildings');
    $lease = $cards->firstWhere(fn (array $c) => $c['building']->is($this->a['lease']));
    expect($lease['managed'])->toBeFalse()->and($lease['roll'])->toBeNull()->and($lease['units'])->toBe(['L101']);
});

test('draft and pending contracts and their buildings are hidden; an ended managed contract shows its units only', function () {
    $ownerId = $this->a['owner']->id;
    OwnerContract::factory()->create(['owner_id' => $ownerId, 'building_id' => Building::factory()->create(['name' => 'Draft Plaza'])->id]);
    $pendingBuilding = Building::factory()->create(['name' => 'Pending Plaza']);
    $pending = OwnerContract::factory()->create(['owner_id' => $ownerId, 'building_id' => $pendingBuilding->id]);
    $pending->units()->attach(Unit::factory()->for($pendingBuilding)->create()->id);
    $pending->forceFill(['status' => 'pending_approval'])->save();
    $oldCourt = Building::factory()->create(['name' => 'Old Court']);
    $oldUnit = Unit::factory()->for($oldCourt)->create(['code' => 'O1']);
    $ended = activeOwnerContract(['owner_id' => $ownerId, 'building_id' => $oldCourt->id, 'type' => 'managed', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31'], [$oldUnit]);
    $ended->forceFill(['status' => 'ended'])->save();
    activeAgreement(['customer_id' => Customer::factory()->create(['name_en' => 'Later Renter'])->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$oldUnit]);

    $this->actingAs($this->a['account'], 'tenant');
    $this->get(route('portal.owner.buildings'))->assertOk()
        ->assertDontSee('Draft Plaza')->assertDontSee('Pending Plaza')
        ->assertSeeInOrder(['Old Court', 'Units: O1'])->assertDontSee('Later Renter');
    $this->get(route('portal.owner.contracts'))->assertOk()
        ->assertDontSee('Draft Plaza')->assertDontSee('Pending Plaza')
        ->assertSeeInOrder([(string) $ended->number, 'Ended', 'Old Court · Managed', '01/01/2025 – 31/12/2025']);
});

test('contracts list number, status, building, type and dates', function () {
    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.contracts'))->assertOk()
        ->assertSeeInOrder([(string) $this->a['leased']->number, 'Active', 'Ali Owner Lease · Leased', '01/01/2026 – 31/12/2026',
            (string) $this->a['managed']->number, 'Active', 'Ali Owner Tower · Managed', '01/01/2026 – 31/12/2026'])
        ->assertDontSee((string) $this->b['managed']->number)->assertDontSee('Badr Owner');
});

test('overdue and not yet due are blank when the agreement also covers a unit outside the owner\'s live contracts', function () {
    $other = Unit::factory()->for($this->b['tower'])->create(['code' => 'X1']);
    $customer = Customer::factory()->create(['name_en' => 'Mixed Renter']);
    $agreement = activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::query()->where('code', 'V101')->sole(), $other]);
    issuedInvoice($customer, [['net' => '777.000', 'au' => $agreement->agreementUnits->last()]], '2026-10-01', $agreement);

    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.buildings'))->assertOk()
        ->assertSee('Mixed Renter')->assertDontSee('777.000')->assertSee('Overdue: 400.000 BHD')->assertSee('Not yet due: 0.000 BHD');
});

test('several live managed contracts on one building list the units of all of them', function () {
    $second = Unit::factory()->for($this->a['tower'])->create(['code' => 'S1']);
    activeOwnerContract(['owner_id' => $this->a['owner']->id, 'building_id' => $this->a['tower']->id, 'type' => 'managed', 'start_date' => '2026-01-01',
        'end_date' => '2026-12-31', 'fee_type' => 'fixed', 'fee_value' => '0.000', 'deposits_held_by' => 'company', 'created_by' => $this->admin->id], [$second]);

    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.buildings'))->assertOk()
        ->assertSeeInOrder(['Ali Owner Tower', '1 of 3 units let', 'Unit 101'])->assertSee('Unit S1')->assertSee('Unit V101');
});

test('the owner portal shows both figures, split by due date, from the same query as the staff report', function () {
    $agreement = Customer::query()->where('name_en', 'Renter 101')->sole()->agreements()->sole();
    issuedInvoice($agreement->customer, [['net' => '250.000', 'au' => $agreement->agreementUnits->first()]], '2026-11-01', $agreement);

    $roll = collect(OwnerPortal::buildings($this->a['owner']->id)->firstWhere('managed', true)['roll']);
    $row = $roll->firstWhere('unit', '101');

    expect($row['overdue'])->toBe(400_000)->and($row['not_due'])->toBe(250_000)
        ->and($roll->firstWhere('unit', 'V101')['overdue'])->toBeNull()->and($roll->firstWhere('unit', 'V101')['not_due'])->toBeNull();
    $this->actingAs($this->a['account'], 'tenant')->get(route('portal.owner.buildings'))->assertOk()
        ->assertSeeInOrder(['Overdue: 400.000 BHD', 'Not yet due: 250.000 BHD']);
});

test('a mixed-owner agreement shows both blank', function () {
    $other = Unit::factory()->for($this->b['tower'])->create(['code' => 'X2']);
    $customer = Customer::factory()->create(['name_en' => 'Mixed Two']);
    $agreement = activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::query()->where('code', 'V101')->sole(), $other]);
    issuedInvoice($customer, [['net' => '777.000', 'au' => $agreement->agreementUnits->last()]], '2026-10-01', $agreement);
    issuedInvoice($customer, [['net' => '333.000', 'au' => $agreement->agreementUnits->last()]], '2026-12-01', $agreement);

    $row = collect(OwnerPortal::buildings($this->a['owner']->id)->firstWhere('managed', true)['roll'])->firstWhere('unit', 'V101');

    expect($row['tenant'])->toBe('Mixed Two')->and($row['overdue'])->toBeNull()->and($row['not_due'])->toBeNull();
});
