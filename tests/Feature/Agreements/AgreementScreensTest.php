<?php

use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Enums\RoleName;
use App\Livewire\Agreements\Form;
use App\Livewire\Agreements\Index;
use App\Livewire\Agreements\Show;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureDefaultContractTemplate::class)();
    $this->building = Building::factory()->create(['name' => 'Juffair Heights']);
    $this->flat = Unit::factory()->for($this->building)->create(['code' => 'J-101', 'list_rent' => '500.000', 'list_deposit' => '500.000', 'list_service_charge' => '25.000']);
    $this->customer = Customer::factory()->create(['name_en' => 'Hassan Qasim']);
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($this->building->id);
});

test('Leasing drafts an agreement: finds the customer, adds a unit with its list terms, and saves', function () {
    Livewire::actingAs($this->leasing)->test(Form::class)
        ->set('customerSearch', 'Hassan')
        ->assertSee('Hassan Qasim')
        ->call('selectCustomer', $this->customer->id)
        ->set('form.start_date', '2026-11-01')
        ->set('form.end_date', '2027-10-31')
        ->set('pickBuilding', $this->building->id)
        ->set('pickUnit', $this->flat->id)
        ->call('addUnit')
        ->assertSet('units.0.deposit_amount', '500.000')
        ->assertSet('units.0.charges.0.monthly_amount', '500.000')
        ->assertSet('units.0.charges.1.type', 'service_charge')
        ->set('units.0.charges.0.monthly_amount', '460')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $agreement = Agreement::sole()->load('agreementUnits.charges');
    expect($agreement->monthlyRentFils())->toBe(460_000)
        ->and($agreement->discountFils())->toBe(40_000);
});

test('the unit picker lists only buildings in the user\'s scope', function () {
    Building::factory()->create(['name' => 'Seef Tower']);

    Livewire::actingAs($this->leasing)->test(Form::class)
        ->assertSee('Juffair Heights')
        ->assertDontSee('Seef Tower');
});

test('errors land on the header fields and on the unit lines', function () {
    Livewire::actingAs($this->leasing)->test(Form::class)
        ->set('pickBuilding', $this->building->id)
        ->set('pickUnit', $this->flat->id)
        ->call('addUnit')
        ->set('units.0.charges.0.monthly_amount', '0')
        ->call('save')
        ->assertHasErrors(['form.customer_id', 'form.start_date'])
        // Cross-field checks run once the basic rules pass.
        ->call('selectCustomer', $this->customer->id)
        ->set('form.start_date', '2026-11-01')
        ->set('form.end_date', '2027-10-31')
        ->call('save')
        ->assertHasErrors(['units.0.charges']);
});

test('the show page shows the discount; drafts can be edited and deleted', function () {
    $draft = Agreement::factory()->create(['customer_id' => $this->customer->id, 'created_by' => $this->leasing->id]);
    $au = $draft->agreementUnits()->create(['unit_id' => $this->flat->id, 'list_rent' => '500.000', 'deposit_amount' => '500.000', 'start_date' => $draft->start_date, 'end_date' => $draft->end_date]);
    $au->charges()->create(['type' => 'rent', 'monthly_amount' => '450.000', 'tax_category' => 'exempt']);

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $draft])
        ->assertSee('Hassan Qasim')
        ->assertSee('50.000')       // discount in BHD
        ->assertSee('10.0%')
        ->assertSee(route('agreements.edit', $draft))
        ->call('deleteDraft')
        ->assertRedirect(route('agreements.index'));

    expect(Agreement::find($draft->id))->toBeNull();
});

test('the list follows the scope and filters agreements expiring soon', function () {
    $soon = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => now()->subMonths(11)->toDateString(), 'end_date' => now()->addDays(20)->toDateString()], [$this->flat]);
    $later = activeAgreement(['start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString()], [Unit::factory()->for($this->building)->create()]);
    $hidden = activeAgreement([], [Unit::factory()->create()]);

    Livewire::actingAs($this->leasing)->test(Index::class)
        ->assertSee($soon->number)->assertSee($later->number)->assertDontSee($hidden->number)
        ->set('expiring', 30)
        ->assertSee($soon->number)->assertDontSee($later->number);

    $this->actingAs($this->leasing)->get(route('agreements.show', $hidden))->assertForbidden();
    $this->actingAs($this->leasing)->get(route('agreements.show', $soon))->assertOk();
});

test('saving with no unit shows the units error', function () {
    Livewire::actingAs($this->leasing)->test(Form::class)
        ->call('selectCustomer', $this->customer->id)
        ->set('form.start_date', '2026-11-01')
        ->set('form.end_date', '2027-10-31')
        ->call('save')
        ->assertHasErrors('units')
        ->assertSee('units field is required');
});

test('unit line and charge errors are displayed', function () {
    Livewire::actingAs($this->leasing)->test(Form::class)
        ->call('selectCustomer', $this->customer->id)
        ->set('form.start_date', '2026-11-01')
        ->set('form.end_date', '2027-10-31')
        ->set('pickBuilding', $this->building->id)
        ->set('pickUnit', $this->flat->id)
        ->call('addUnit')
        ->set('units.0.deposit_amount', 'abc')
        ->set('units.0.charges.0.monthly_amount', 'abc')
        ->call('save')
        ->assertHasErrors(['units.0.deposit_amount', 'units.0.charges.0.monthly_amount'])
        ->assertSee('units.0.deposit_amount field format is invalid')
        ->assertSee('units.0.charges.0.monthly_amount field format is invalid');
});
